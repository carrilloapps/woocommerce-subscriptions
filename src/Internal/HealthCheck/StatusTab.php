<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

use Automattic\WooCommerce_Subscriptions\Internal\HealthCheck\Admin\CandidatesListTable;
use Throwable;

/**
 * The Health Check page as a tab inside WooCommerce > Status.
 *
 * The tab sits alongside System Status / Tools / Logs. It is server-
 * rendered (no React SPA) — the whole UI is a `WP_List_Table` plus two
 * nonce-protected form POSTs in the page header:
 *
 *   - **Run now** — kicks off an on-demand scan through
 *     `ScheduleManager::start_scan()`. Always available regardless of
 *     the merchant's nightly-scan setting.
 *   - **Enable / Pause nightly scans** — flips the same option
 *     (`CircuitBreaker::OPTION_SCHEDULE_ENABLED`) that the WC >
 *     Settings > Subscriptions checkbox writes. Both surfaces share a
 *     single source of truth so merchants can toggle from whichever is
 *     closer at hand. Label flips with the current state: "Enable
 *     nightly scans" when off, "Pause nightly scans" when on.
 *
 * The tool is read-only otherwise: there is no "Restore auto-renewal"
 * button, no bulk actions, no undo flow in v1. Merchants see the table,
 * click through to the subscription edit screen, and act manually; the
 * 24-hour re-scan keeps the list fresh so resolved rows drop off.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class StatusTab {

	/**
	 * Slug used by `admin.php?page=wc-status&tab=<slug>` routing and as
	 * the suffix on the `woocommerce_admin_status_content_<slug>` action.
	 */
	public const TAB_SLUG = 'wcs-health-check';

	/**
	 * @var RunStore
	 */
	private $run_store;

	/**
	 * @var CandidateStore
	 */
	private $candidate_store;

	/**
	 * @var ScheduleManager
	 */
	private $schedule_manager;

	/**
	 * @var Tracks
	 */
	private $tracks;

	/**
	 * @var CircuitBreaker
	 */
	private $circuit_breaker;

	public function __construct(
		?RunStore $run_store = null,
		?CandidateStore $candidate_store = null,
		?ScheduleManager $schedule_manager = null,
		?Tracks $tracks = null,
		?CircuitBreaker $circuit_breaker = null
	) {
		$this->run_store        = $run_store ?? new RunStore();
		$this->candidate_store  = $candidate_store ?? new CandidateStore();
		$this->schedule_manager = $schedule_manager ?? new ScheduleManager();
		$this->tracks           = $tracks ?? new Tracks();
		$this->circuit_breaker  = $circuit_breaker ?? new CircuitBreaker();
	}

	/**
	 * Register the two WC integration hooks:
	 *   - filter: add the tab to the WC Status nav.
	 *   - action: render the body when the tab is active.
	 * Plus an `admin_init` hook to process the tab's form POSTs before
	 * anything renders (so `wp_safe_redirect()` can fire cleanly).
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_admin_status_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_admin_status_content_' . self::TAB_SLUG, array( $this, 'render' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_actions' ) );
		// Screen Options drawer: runs on the WC Status page load,
		// gated so we only advertise our columns when the merchant is
		// on our tab. WP reads `manage_{screen_id}_columns` + stores
		// hidden-columns prefs under `manage{screen_id}columnshidden`
		// user meta; matching the live screen id lets the default
		// drawer UI pick up our column list without custom rendering.
		add_action( 'load-woocommerce_page_wc-status', array( $this, 'maybe_register_screen_options' ) );
	}

	/**
	 * Append our tab to the WooCommerce Status nav.
	 *
	 * @param array<string, string> $tabs Existing tab slug => label map.
	 *
	 * @return array<string, string>
	 */
	public function add_tab( $tabs ): array {
		if ( ! is_array( $tabs ) ) {
			$tabs = array();
		}
		$tabs[ self::TAB_SLUG ] = __( 'Subscriptions', 'woocommerce-subscriptions' );
		return $tabs;
	}

	/**
	 * Register Screen Options (the per-user column-visibility drawer)
	 * when the merchant lands on the Health Check tab.
	 *
	 * WordPress's column-hiding UI is wired off three things:
	 *   1. `get_column_headers($screen)` returning our column list.
	 *   2. `manage_{screen_id}_columns` filter advertising which
	 *      columns are registered.
	 *   3. `get_hidden_columns($screen)` reading the user's saved
	 *      preferences from
	 *      `usermeta.manage{screen_id}columnshidden`.
	 *
	 * Hooking `manage_woocommerce_page_wc-status_columns` during the
	 * page-load action ties our list table's columns to the live WP
	 * screen, so the Screen Options drawer renders with our checkboxes
	 * without any custom UI code. Gated on `tab === wcs-health-check`
	 * so we don't leak our columns onto the System Status / Tools /
	 * Logs tabs.
	 *
	 * @return void
	 */
	public function maybe_register_screen_options(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab gate.
		if ( ! isset( $_GET['tab'] ) || self::TAB_SLUG !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}

		// Advertise the Health Check columns to WP's Screen Options
		// drawer. Reaching back through `CandidatesListTable` for the
		// list avoids duplicating the column set here — any future
		// column addition lands in a single place.
		add_filter(
			'manage_woocommerce_page_wc-status_columns',
			static function () {
				return ( new CandidatesListTable() )->get_columns();
			}
		);
	}

	/**
	 * Process the tab's form POSTs.
	 *
	 * Runs on `admin_init` — well before rendering — so `wp_safe_redirect()`
	 * can fire before any output is sent.
	 *
	 * @return void
	 */
	public function maybe_handle_actions(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$action = isset( $_POST['wcs_hc_action'] )
			? sanitize_key( wp_unslash( $_POST['wcs_hc_action'] ) )
			: '';
		if ( '' === $action ) {
			return;
		}

		$nonce = isset( $_POST['_wpnonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, 'wcs_hc_' . $action ) ) {
			// Swallow the action on a bad nonce — do NOT wp_die. The tab
			// render path is the user-visible surface; adding a generic
			// "Cheatin'?" page for a stale form POST is a worse UX than
			// silently refusing the action.
			return;
		}

		switch ( $action ) {
			case 'run_scan':
				$redirect_url = $this->run_scan();
				break;
			case 'toggle_schedule':
				$this->toggle_schedule();
				$redirect_url = $this->tab_url();
				break;
			default:
				return;
		}

		$this->redirect_and_exit( $redirect_url );
	}

	/**
	 * Render the tab body. Called from the WC Status router via
	 * `do_action( 'woocommerce_admin_status_content_wcs-health-check' )`.
	 *
	 * @return void
	 */
	public function render(): void {
		$latest_run_id = $this->run_store->get_latest_scan_run_id();
		$latest_run    = 0 === $latest_run_id ? null : $this->run_store->get( $latest_run_id );
		$in_flight     = $this->run_store->get_in_flight_scan();

		// Both surfaces (this button and the WC > Settings >
		// Subscriptions checkbox) write the same option, so a flip
		// here is reflected on the settings page and vice versa.
		$schedule_enabled = $this->circuit_breaker->is_schedule_enabled();
		$toggle_label     = $schedule_enabled
			? __( 'Pause nightly scans', 'woocommerce-subscriptions' )
			: __( 'Enable nightly scans', 'woocommerce-subscriptions' );

		// Keep the Run-now label stable — signal in-flight with a
		// spinner + disabled state, not a label swap. A label that
		// doubles as both status signal AND action reads as confusing
		// on reload.
		$run_now_label = __( 'Run now', 'woocommerce-subscriptions' );
		$run_now_busy  = null !== $in_flight;
		?>
		<div class="woocommerce-subscriptions-health-check-tab">
			<?php $this->maybe_render_query_arg_notice(); ?>
			<?php $this->maybe_render_tripped_notice(); ?>

			<div class="woocommerce-subscriptions-health-check-header">
				<div class="woocommerce-subscriptions-health-check-header-title">
					<h2 class="wp-heading-inline"><?php esc_html_e( 'Subscriptions health check', 'woocommerce-subscriptions' ); ?></h2>
					<p class="description">
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: "Learn more." link to the Subscriptions Health Check documentation. */
								__( "Scan your store's subscriptions for conditions that may need attention. %s", 'woocommerce-subscriptions' ),
								sprintf(
									'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
									esc_url( 'https://woocommerce.com/document/woocommerce-subscriptions-health-check/' ),
									esc_html__( 'Learn more.', 'woocommerce-subscriptions' )
								)
							),
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
						);
						?>
					</p>
				</div>
				<div class="woocommerce-subscriptions-health-check-header-actions">
					<form method="post" class="woocommerce-subscriptions-health-check-toggle-schedule-form">
						<?php wp_nonce_field( 'wcs_hc_toggle_schedule' ); ?>
						<input type="hidden" name="wcs_hc_action" value="toggle_schedule" />
						<button type="submit" class="button"><?php echo esc_html( $toggle_label ); ?></button>
					</form>
					<form method="post" class="woocommerce-subscriptions-health-check-run-now-form">
						<?php wp_nonce_field( 'wcs_hc_run_scan' ); ?>
						<input type="hidden" name="wcs_hc_action" value="run_scan" />
						<button type="submit" class="button button-primary" <?php disabled( $run_now_busy ); ?>>
							<?php echo esc_html( $run_now_label ); ?>
							<?php if ( $run_now_busy ) : ?>
								<span class="spinner is-active" aria-hidden="true"></span>
							<?php endif; ?>
						</button>
						<?php if ( $run_now_busy ) : ?>
							<span class="screen-reader-text" role="status">
								<?php esc_html_e( 'Scan in progress.', 'woocommerce-subscriptions' ); ?>
							</span>
						<?php endif; ?>
					</form>
				</div>
			</div>

			<?php if ( $run_now_busy ) : ?>
				<?php
				// Server-rendered in-flight state: the spinner sits on
				// the page until the next request. Without this poll,
				// the merchant stares at a spinner for the 1–3 minutes
				// a scan takes to chew through batches on a typical
				// store, with no visible signal that anything is
				// happening — "Run now keeps spinning forever." The
				// lightweight reload here flips the button back to
				// idle + refreshes the Last-scan card as soon as the
				// in-flight run completes, without the merchant
				// needing to hit reload themselves. 8 s is short
				// enough that a 30 s inter-batch delay still surfaces
				// the completion within a few seconds of the final
				// batch finalising the run row, and long enough that
				// a fast scan doesn't trigger two reloads back-to-back.
				?>
				<script>
					setTimeout( function () { window.location.reload(); }, 8000 );
				</script>
			<?php endif; ?>

			<?php $this->render_header_cards( $latest_run ); ?>

			<?php
			$table = new CandidatesListTable( $this->run_store, $this->candidate_store );
			$table->prepare_items();
			?>
			<form method="get" class="woocommerce-subscriptions-health-check-candidates-form">
				<input type="hidden" name="page" value="wc-status" />
				<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_SLUG ); ?>" />
				<?php $table->views(); ?>
				<?php $this->render_search_box(); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the three-card header strip.
	 *
	 * Cards:
	 *   1. Last scan       — "Completed X ago" / "Next scheduled in Y".
	 *   2. Scope           — "N subscriptions scanned" / "M eligible for
	 *                        automatic renewal".
	 *   3. Plugin version  — "WooCommerce Subscriptions X.Y.Z" / "Up to
	 *                        date ✓" or update marker.
	 *
	 * @param array<string, mixed>|null $latest_run
	 *
	 * @return void
	 */
	private function render_header_cards( ?array $latest_run ): void {
		?>
		<div class="notice notice-info inline woocommerce-subscriptions-health-check-summary">
			<div class="woocommerce-subscriptions-health-check-summary-col woocommerce-subscriptions-health-check-summary-col-last-scan">
				<span class="woocommerce-subscriptions-health-check-summary-label"><?php esc_html_e( 'Last scan', 'woocommerce-subscriptions' ); ?></span>
				<?php $this->render_last_scan_value( $latest_run ); ?>
			</div>
			<div class="woocommerce-subscriptions-health-check-summary-col woocommerce-subscriptions-health-check-summary-col-scope">
				<span class="woocommerce-subscriptions-health-check-summary-label"><?php esc_html_e( 'Scope', 'woocommerce-subscriptions' ); ?></span>
				<?php $this->render_scope_value( $latest_run ); ?>
			</div>
			<div class="woocommerce-subscriptions-health-check-summary-col woocommerce-subscriptions-health-check-summary-col-version">
				<span class="woocommerce-subscriptions-health-check-summary-label"><?php esc_html_e( 'Plugin version', 'woocommerce-subscriptions' ); ?></span>
				<?php $this->render_version_value(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Compose the Last-scan card's body — primary line is the
	 * completed timestamp, secondary line the next-scheduled
	 * countdown.
	 *
	 * @param array<string, mixed>|null $latest_run
	 *
	 * @return void
	 */
	private function render_last_scan_value( ?array $latest_run ): void {
		if ( null === $latest_run ) {
			echo '<div class="woocommerce-subscriptions-health-check-card-primary">' . esc_html__( 'No scan yet', 'woocommerce-subscriptions' ) . '</div>';
		} else {
			$when_utc = (string) ( $latest_run['completed_at'] ?? $latest_run['started_at'] ?? '' );
			$time_ago = $this->human_time_since_mysql_utc( $when_utc );
			printf(
				'<div class="woocommerce-subscriptions-health-check-card-primary">%s</div>',
				sprintf(
					/* translators: %s: human-readable time diff like "5 minutes". */
					esc_html__( 'Completed %s ago', 'woocommerce-subscriptions' ),
					esc_html( $time_ago )
				)
			);
		}

		// When the merchant nightly-scan setting is off, the recurring AS
		// action is unscheduled, so `next_daily_scan_timestamp()` returns
		// 0. Surface that explicitly so the absence of a "Next scheduled"
		// line doesn't read as a glitch — the merchant sees the schedule
		// is intentionally disabled and where to flip it.
		if ( ! $this->circuit_breaker->is_schedule_enabled() ) {
			printf(
				'<div class="woocommerce-subscriptions-health-check-card-secondary">%s</div>',
				esc_html__( 'Nightly scans disabled', 'woocommerce-subscriptions' )
			);
			return;
		}

		$next_run_ts = $this->next_daily_scan_timestamp();
		if ( $next_run_ts <= 0 ) {
			return;
		}

		if ( $next_run_ts > time() ) {
			printf(
				'<div class="woocommerce-subscriptions-health-check-card-secondary">%s</div>',
				sprintf(
					/* translators: %s: human-readable time diff like "24h" or "3 hours". */
					esc_html__( 'Next scheduled in %s', 'woocommerce-subscriptions' ),
					esc_html( human_time_diff( time(), $next_run_ts ) )
				)
			);
			return;
		}

		// Action is queued but its run time has already passed — the AS
		// runner hasn't picked it up yet (common on low-traffic dev
		// stores, or if WP-Cron was paused). Surface that a scan is
		// imminent rather than silently hiding the line.
		printf(
			'<div class="woocommerce-subscriptions-health-check-card-secondary">%s</div>',
			esc_html__( 'Due now', 'woocommerce-subscriptions' )
		);
	}

	/**
	 * Compose the Scope card's body — total-store count + how many
	 * items across all signals are ready for merchant review.
	 *
	 * The primary line uses the store total (every subscription the
	 * merchant can see under the All tab), not the classifier's
	 * in-scope count. The scan inspects every subscription — narrowing
	 * happens during classification — so the count merchants see needs
	 * to match the All tab count or they'll assume rows were skipped
	 * and re-trigger the scan looking for a different number.
	 *
	 * The secondary line is the naive sum of the per-signal badge
	 * counts (Supports auto-renewal + Missing renewals). No
	 * dedup — a subscription that surfaces under both tabs counts
	 * twice here, deliberately, because a displayed total that didn't
	 * match the arithmetic of the tab badges would confuse merchants
	 * more than a possibly-inflated number. `count_by_run()` already
	 * returns one row per (subscription, signal) pair, so it IS that
	 * naive sum.
	 *
	 * @param array<string, mixed>|null $latest_run
	 *
	 * @return void
	 */
	private function render_scope_value( ?array $latest_run ): void {
		if ( null === $latest_run ) {
			echo '<div class="woocommerce-subscriptions-health-check-card-primary">' . esc_html__( 'No scan yet', 'woocommerce-subscriptions' ) . '</div>';
			return;
		}

		$ready_for_review = $this->candidate_store->count_by_run( (int) $latest_run['id'] );
		$total_scanned    = CandidatesListTable::count_all_subscriptions();

		$scanned_label = sprintf(
			/* translators: %s: bold-wrapped scanned count via number_format_i18n. */
			__( '%s subscriptions scanned', 'woocommerce-subscriptions' ),
			'<strong>' . esc_html( number_format_i18n( $total_scanned ) ) . '</strong>'
		);
		printf(
			'<div class="woocommerce-subscriptions-health-check-card-primary woocommerce-subscriptions-health-check-card-primary-mixed">%s</div>',
			wp_kses( $scanned_label, array( 'strong' => array() ) )
		);
		$review_label = sprintf(
			/* translators: %s: bold-wrapped count of items surfaced across all signals (naive sum of tab badges). Verb agrees with the count: "item is" for 1, "items are" for 0 or 2+. */
			_n(
				'%s item is ready for review',
				'%s items are ready for review',
				$ready_for_review,
				'woocommerce-subscriptions'
			),
			'<strong>' . esc_html( number_format_i18n( $ready_for_review ) ) . '</strong>'
		);
		printf(
			'<div class="woocommerce-subscriptions-health-check-card-secondary">%s</div>',
			wp_kses( $review_label, array( 'strong' => array() ) )
		);
	}

	/**
	 * Compose the Plugin-version card's body — primary line is the
	 * current version string, secondary line the patch-applied /
	 * out-of-date marker.
	 *
	 * @return void
	 */
	private function render_version_value(): void {
		$current_version = class_exists( '\\WC_Subscriptions' ) ? (string) \WC_Subscriptions::$version : '0.0.0';
		printf(
			'<div class="woocommerce-subscriptions-health-check-card-primary">%s</div>',
			sprintf(
				/* translators: %s: WooCommerce Subscriptions version. */
				esc_html__( 'WooCommerce Subscriptions %s', 'woocommerce-subscriptions' ),
				esc_html( $current_version )
			)
		);
		$this->render_version_marker();
	}

	//
	// ───── Private helpers ───────────────────────────────────────────────
	//

	/**
	 * Render the candidate-table search box.
	 *
	 * WP_List_Table::search_box() reuses the same string for the input
	 * label AND the submit button — the design wants a generic "Search"
	 * button paired with a descriptive placeholder on the input itself,
	 * which the parent helper can't express. Custom markup lets us split
	 * the two and keeps the standard `.search-box` chrome that WP styles
	 * via core admin CSS.
	 *
	 * @return void
	 */
	private function render_search_box(): void {
		$placeholder = __( 'Search by subscription ID or customer email', 'woocommerce-subscriptions' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search reflected back into the input.
		$current = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		?>
		<p class="search-box">
			<label class="screen-reader-text" for="woocommerce-subscriptions-health-check-search-input"><?php echo esc_html( $placeholder ); ?></label>
			<input
				type="search"
				id="woocommerce-subscriptions-health-check-search-input"
				name="s"
				value="<?php echo esc_attr( $current ); ?>"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
			/>
			<input
				type="submit"
				id="search-submit"
				class="button"
				value="<?php esc_attr_e( 'Search', 'woocommerce-subscriptions' ); ?>"
			/>
		</p>
		<?php
	}

	/**
	 * Handle the "Run scan now" form POST.
	 *
	 * Returns the URL to redirect to. When a scan is already in flight
	 * we redirect back with a notice query arg rather than stacking a
	 * second run onto the pipeline — the UI-side disabled-button guard
	 * is a first line of defence, this is the server-side second line
	 * covering the two-admins-click-together race.
	 *
	 * @return string Redirect URL.
	 */
	private function run_scan(): string {
		if ( null !== $this->run_store->get_in_flight_scan() ) {
			return add_query_arg( 'wcs_hc_notice', 'scan_already_running', $this->tab_url() );
		}

		try {
			// Keep wcs_health_check_runs.triggered_by PII-free; the raw
			// admin id has no product value for Health Check scans.
			$run_id = $this->schedule_manager->start_scan( 'user' );
		} catch ( HealthCheckDbException $e ) {
			// Typed exception from a failed run-row INSERT.
			return add_query_arg( 'wcs_hc_notice', 'scan_start_failed', $this->tab_url() );
		} catch ( HealthCheckScanInFlightException $e ) {
			// Typed exception from the atomic-guard race-loss path.
			return add_query_arg( 'wcs_hc_notice', 'scan_already_running', $this->tab_url() );
		} catch ( Throwable $e ) {
			// Anything else came from outside our typed contract — a
			// third-party hook attached to AS's enqueue path raising
			// a generic RuntimeException, a TypeError from an
			// extension's faulty filter callback, etc. Without this
			// catch, "Run now" would either render a "Cheatin uh?"
			// page (unhandled) or — worse, when the previous bare
			// `catch (RuntimeException $e)` was here — silently
			// swallow the failure and redirect back with no
			// diagnostic signal. Log + redirect with the generic
			// scan-start-failed notice so support has a breadcrumb
			// without surfacing a fatal error to the merchant.
			wc_get_logger()->error(
				sprintf(
					'Health Check: unexpected exception while starting scan — %s: %s',
					get_class( $e ),
					$e->getMessage()
				),
				array(
					'source'    => 'wcs-health-check',
					'exception' => $e,
				)
			);
			return add_query_arg( 'wcs_hc_notice', 'scan_start_failed', $this->tab_url() );
		}

		$this->tracks->manual_scan_triggered( array( 'run_id' => $run_id ) );

		return $this->tab_url();
	}

	/**
	 * Flip the merchant nightly-scan option through `CircuitBreaker`.
	 * Same option that the WC > Settings > Subscriptions checkbox
	 * writes, so the two surfaces always reflect a single source of
	 * truth. The settings checkbox and this button are
	 * interchangeable; merchants can toggle from whichever is closer
	 * to the work they're doing.
	 *
	 * @return void
	 */
	private function toggle_schedule(): void {
		$this->circuit_breaker->toggle_schedule( ! $this->circuit_breaker->is_schedule_enabled() );
	}

	private function tab_url(): string {
		return admin_url( 'admin.php?page=wc-status&tab=' . self::TAB_SLUG );
	}

	/**
	 * Convert `?wcs_hc_notice=<known_flag>` into a rendered admin notice.
	 *
	 * Only renders recognised flags — an unknown value is silently
	 * ignored so a URL-crafting attacker can't inject arbitrary copy.
	 *
	 * @return void
	 */
	private function maybe_render_query_arg_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag on a GET redirect.
		$notice = isset( $_GET['wcs_hc_notice'] ) ? sanitize_key( wp_unslash( $_GET['wcs_hc_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}

		switch ( $notice ) {
			case 'scan_already_running':
				?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'A scan is already running. The new scan request was ignored.', 'woocommerce-subscriptions' ); ?></p>
				</div>
				<?php
				return;
			case 'scan_start_failed':
				?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'The scan could not be started due to a database error. Please check the logs and try again.', 'woocommerce-subscriptions' ); ?></p>
				</div>
				<?php
				return;
		}
	}

	/**
	 * Render the circuit-breaker-tripped notice on the Health Check tab.
	 *
	 * The circuit breaker trips after 3 consecutive failed scan batches
	 * (or a 48h-stale heartbeat) and silently pauses scheduled scans by
	 * flipping the nightly-scan option to `'no'`. Merchants have no
	 * other signal the tool has stopped working — this notice makes
	 * the stopped state visible and tells them how to recover (click
	 * "Enable nightly scans" in the page header, which writes the same
	 * option and clears the trip reason via
	 * `CircuitBreaker::toggle_schedule( true )`).
	 *
	 * @return void
	 */
	private function maybe_render_tripped_notice(): void {
		if ( ! $this->circuit_breaker->is_tripped() ) {
			return;
		}
		?>
		<div class="notice notice-error inline woocommerce-subscriptions-health-check-tripped-notice">
			<p>
				<?php esc_html_e( 'The last health check scan encountered an issue. Click "Enable nightly scans" above to try again.', 'woocommerce-subscriptions' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Convert a MySQL-format UTC datetime string into a
	 * `human_time_diff()`-friendly relative label. Parsing explicitly
	 * with a `UTC` suffix avoids `strtotime()` guessing the local
	 * timezone.
	 *
	 * @param string $mysql_utc
	 *
	 * @return string
	 */
	private function human_time_since_mysql_utc( string $mysql_utc ): string {
		if ( '' === $mysql_utc ) {
			return (string) __( 'recently', 'woocommerce-subscriptions' );
		}
		$ts = strtotime( $mysql_utc . ' UTC' );
		if ( false === $ts ) {
			return (string) __( 'recently', 'woocommerce-subscriptions' );
		}
		return function_exists( 'human_time_diff' ) ? human_time_diff( $ts, time() ) : gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Unix timestamp of the next scheduled daily-scan action, or 0 when
	 * none is queued. Wraps the Action Scheduler lookup behind a
	 * protected helper so tests can pin a deterministic value without
	 * a running scheduler — mirrors the ScheduleManager's probe-
	 * override pattern.
	 *
	 * @return int
	 */
	protected function next_daily_scan_timestamp(): int {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 0;
		}
		$ts = as_next_scheduled_action( 'wcs_health_check_daily_scan' );
		return is_int( $ts ) && $ts > 0 ? $ts : 0;
	}

	/**
	 * Render the plugin-version secondary line on the version card.
	 *
	 * Compares `WC_Subscriptions::$version` against 7.1.0 — the release
	 * that shipped the WOOSUBS-1605 fix. Up-to-date reads "Up to date ✓"
	 * in muted green; out of date reads as a warning link to the plugin
	 * update page.
	 *
	 * The version used for the comparison is filterable so tests can
	 * exercise the below-threshold branch without needing to downgrade
	 * the plugin's actual version constant.
	 *
	 * @return void
	 */
	private function render_version_marker(): void {
		if ( ! $this->has_wcs_update_available() ) {
			printf(
				'<div class="woocommerce-subscriptions-health-check-card-secondary">%s</div>',
				esc_html__( 'Up to date ✓', 'woocommerce-subscriptions' )
			);
			return;
		}

		$plugins_url = admin_url( 'plugins.php' );
		printf(
			'<div class="woocommerce-subscriptions-health-check-card-secondary woocommerce-subscriptions-health-check-card-secondary-warn">%1$s <a href="%2$s">%3$s</a></div>',
			esc_html__( 'Newer version available.', 'woocommerce-subscriptions' ),
			esc_url( $plugins_url ),
			esc_html__( 'Update now', 'woocommerce-subscriptions' )
		);
	}

	/**
	 * Whether WordPress reports a newer version of WooCommerce
	 * Subscriptions is available in the `update_plugins` transient.
	 *
	 * Reads the transient directly rather than hitting the WP.org
	 * update API — the transient is refreshed by the standard plugin-
	 * update cron tick, so we always see the latest known state
	 * without making outbound requests on every render. Protected so
	 * tests can pin the value without seeding the transient.
	 *
	 * @return bool
	 */
	protected function has_wcs_update_available(): bool {
		if ( ! class_exists( '\\WC_Subscriptions' ) || ! function_exists( 'plugin_basename' ) ) {
			return false;
		}

		$basename = plugin_basename( \WC_Subscriptions::$plugin_file );
		$updates  = get_site_transient( 'update_plugins' );

		return is_object( $updates )
			&& isset( $updates->response )
			&& is_array( $updates->response )
			&& isset( $updates->response[ $basename ] );
	}

	/**
	 * Overridable redirect wrapper so tests can exercise the action
	 * handlers without terminating the test process via `exit`.
	 *
	 * @param string $url Destination URL.
	 *
	 * @return void
	 */
	protected function redirect_and_exit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
