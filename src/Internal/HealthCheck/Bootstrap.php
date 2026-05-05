<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

/**
 * Entry point for the Subscriptions Health Check tool.
 *
 * The Health Check tool surfaces subscriptions that are on manual renewal
 * but have a payment method that supports automatic renewal, so merchants
 * can review and act on them. Gating is layered:
 *
 *   - `wcs_health_check_tool_enabled` filter (support-level escape hatch,
 *     default `true`) — flip via mu-plugin to disable the entire tool on a
 *     specific store without a code release. Hides the Status tab and
 *     prevents the schedule manager from registering at all.
 *   - `woocommerce_subscriptions_enable_health_check_nightly_scan` option
 *     (merchant-facing checkbox under WC > Settings > Subscriptions,
 *     default `'no'`) — controls only the nightly scheduled scan. The
 *     Status tab and "Run now" button stay available regardless: merchants
 *     can always trigger an on-demand scan even with the schedule disabled.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class Bootstrap {

	/**
	 * Admin stylesheet handle for the Health Check Status tab.
	 */
	private const ADMIN_STYLE_HANDLE = 'wcs-health-check-admin';

	/**
	 * Admin script handle for the Health Check Status tab.
	 */
	private const ADMIN_SCRIPT_HANDLE = 'wcs-health-check-admin';

	/**
	 * Whether the Health Check tool surface is enabled at the support level.
	 *
	 * Distinct from the merchant nightly-scan toggle in `CircuitBreaker`:
	 * this filter is the entire-feature kill switch (no Status tab, no
	 * scheduled scan, no admin assets). The merchant toggle only gates
	 * the nightly scan.
	 *
	 * @return bool
	 */
	public function is_tool_enabled(): bool {
		/**
		 * Support-level kill switch for the Health Check tool as a whole —
		 * a superset of `wcs_health_check_scans_enabled` (CircuitBreaker),
		 * which only gates scan execution. When this filter returns false
		 * the admin Status tab disappears, the scheduled scan never
		 * registers, admin assets don't enqueue, and the settings-page
		 * checkbox stops rendering as well — there is no surface for the
		 * merchant to interact with the feature when support has forced
		 * it off.
		 *
		 * Drop-in delivery: this filter must be registered BEFORE the
		 * `plugins_loaded` tick that bootstraps the module, which in
		 * practice means an mu-plugin. A regular plugin's
		 * `plugins_loaded` callback is too late — we've already read the
		 * filter by then. For a site-level force-off ship a mu-plugin
		 * at `wp-content/mu-plugins/wcs-disable-health-check.php` with
		 * `add_filter( 'wcs_health_check_tool_enabled', '__return_false' )`.
		 *
		 * @since 8.7.0
		 *
		 * @param bool $enabled Whether the Health Check module is active. Defaults to true.
		 */
		return (bool) apply_filters( 'wcs_health_check_tool_enabled', true );
	}

	/**
	 * Wire up the Health Check components.
	 *
	 * Layout:
	 *   1. Tool-wide gate via `is_tool_enabled()` — when off, bail entirely.
	 *      No Status tab, no schedule, no settings UI.
	 *   2. Tables + StatusTab + admin assets always register when the tool
	 *      is enabled, so the merchant can always reach the tab and the
	 *      "Run now" button.
	 *   3. ScheduleManager handler bindings always register so the
	 *      SCAN_BATCH chain works for run-now invocations.
	 *   4. Recurring DAILY_SCAN registration is reconciled against the
	 *      merchant nightly-scan option on every Bootstrap run — see
	 *      `ScheduleManager::reconcile_daily_schedule()`. Both UI
	 *      surfaces redirect after writing the option, so the very
	 *      next request lands here again with the new value and the
	 *      schedule converges.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->is_tool_enabled() ) {
			return;
		}

		// Hook at priority 999 so we run after every other extension's
		// `woocommerce_subscription_settings` callback (Gifting,
		// Downloads, Synchronization, Switching, etc., which use the
		// default priority 10). Without this our setting lands in the
		// middle of the page rather than at the bottom — Tim asked for
		// it to be the last setting on the Subscriptions tab.
		add_filter( 'woocommerce_subscription_settings', array( $this, 'add_settings' ), 999 );

		( new \WCS_Health_Check_Table_Maker() )->register_tables();

		$schedule_manager = new ScheduleManager();
		$schedule_manager->register();
		// Bootstrap runs on every admin request, so reconciling here is
		// enough — both UI surfaces (the in-tab button and the WC
		// settings form) `wp_safe_redirect()` after writing the option,
		// and the redirect is a fresh request that lands here again
		// with the new option value.
		$schedule_manager->reconcile_daily_schedule();

		// Admin surface — only in wp-admin. The Status tab hooks into
		// WooCommerce > Status via `woocommerce_admin_status_tabs`.
		// Meaningless on the frontend request path, so skipping the
		// registration keeps non-admin requests clean.
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			( new StatusTab() )->register();
		}
	}

	/**
	 * Append a dedicated Subscriptions Health Check section to the
	 * bottom of WC > Settings > Subscriptions. The section contains a
	 * single nightly-scan checkbox bound to
	 * `CircuitBreaker::OPTION_SCHEDULE_ENABLED`.
	 *
	 * Defaults to `'no'`: a fresh install does not run nightly scans
	 * until the merchant explicitly opts in. The Health Check tab
	 * itself stays visible regardless — only the AS-driven nightly
	 * scan is gated by this option.
	 *
	 * @param array<int, array<string, mixed>> $settings Existing settings array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function add_settings( $settings ) {
		$section_id = 'woocommerce_subscriptions_health_check_options';

		$health_check_section = array(
			array(
				'name' => __( 'Subscriptions Health Check', 'woocommerce-subscriptions' ),
				'type' => 'title',
				'id'   => $section_id,
			),
			array(
				'id'       => CircuitBreaker::OPTION_SCHEDULE_ENABLED,
				'name'     => __( 'Enable scans', 'woocommerce-subscriptions' ),
				'desc'     => __( 'Enable subscriptions health check scans', 'woocommerce-subscriptions' ),
				'desc_tip' => __( 'When enabled, Subscriptions runs a nightly scan to surface subscriptions that may need attention under WooCommerce > Status > Subscriptions. Disabling this stops the nightly scan only — you can still run an on-demand scan from the health check tool at any time.', 'woocommerce-subscriptions' ),
				'default'  => 'no',
				'type'     => 'checkbox',
			),
			array(
				'type' => 'sectionend',
				'id'   => $section_id,
			),
		);

		return array_merge( $settings, $health_check_section );
	}

	/**
	 * Enqueue Health Check admin assets. Scoped to WooCommerce > Status so
	 * other admin pages stay unaffected.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 *
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'woocommerce_page_wc-status' !== $hook_suffix ) {
			return;
		}

		$core_plugin = \WC_Subscriptions_Core_Plugin::instance();
		$core_url    = $core_plugin->get_subscriptions_core_directory_url();

		// Build the filesystem path from the plugin root — not from
		// `get_subscriptions_core_directory()`, which appends `/includes`
		// and points below where the CSS actually lives.
		$plugin_dir = \WC_Subscriptions_Plugin::instance()->get_plugin_directory();
		$css_path   = $plugin_dir . 'assets/css/health-check-admin.css';

		// Use the file's mtime as the cache-bust token so every edit to the
		// CSS rewrites the `?ver=` query arg and browsers + CDNs pull fresh.
		// Falls back to the library version when the file is unreadable
		// (packaged release path, symlink edge cases).
		$version = file_exists( $css_path )
			? (string) filemtime( $css_path )
			: $core_plugin->get_library_version();

		wp_enqueue_style(
			self::ADMIN_STYLE_HANDLE,
			$core_url . 'assets/css/health-check-admin.css',
			array(),
			$version
		);

		// Floating-tooltip script: portals the warning bubble to <body> so it
		// escapes the candidates wrapper's overflow clipping. Same mtime
		// cache-bust strategy as the stylesheet above.
		$js_path    = $plugin_dir . 'assets/js/admin/health-check-admin.js';
		$js_version = file_exists( $js_path )
			? (string) filemtime( $js_path )
			: $core_plugin->get_library_version();

		wp_enqueue_script(
			self::ADMIN_SCRIPT_HANDLE,
			$core_url . 'assets/js/admin/health-check-admin.js',
			array(),
			$js_version,
			true
		);
	}
}
