<?php

namespace Automattic\WooCommerce_Subscriptions\Internal\HealthCheck;

use RuntimeException;

/**
 * Thrown by the Health Check pipeline when a database write fails in a
 * way that prevents the caller from making progress (e.g. the INSERT
 * for a new scan-run row fails at the SQL layer).
 *
 * Having a dedicated type lets callers discriminate DB-insert failures
 * from other `RuntimeException` sources without matching on the
 * exception message string — a rewrite of the message in
 * `ScheduleManager::start_scan()` would otherwise silently change
 * which admin notice the merchant sees on the Health Check tab.
 *
 * @internal This class may be modified, moved or removed in future releases.
 */
class HealthCheckDbException extends RuntimeException {
}
