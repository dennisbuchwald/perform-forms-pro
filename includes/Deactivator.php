<?php
/**
 * Flinkform Pro deactivation handler.
 *
 * Clears the dispatcher cron so WordPress stops firing into a hook with no
 * callback once Pro is inactive. User data (webhooks, deliveries) is NEVER
 * touched here — that contract belongs to uninstall.php — so a license lapse
 * or temporary deactivation preserves everything.
 *
 * @package FlinkformPro
 * @since 0.2.5
 */

declare( strict_types = 1 );

namespace FlinkformPro;

use FlinkformPro\Webhooks\Dispatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Deactivation routines.
 */
final class Deactivator {

	/**
	 * Run the deactivation routine.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Runtime state only — clear the cron. Re-activation reschedules it.
		// The webhook tables are intentionally left intact.
		wp_clear_scheduled_hook( Dispatcher::CRON_HOOK );
	}
}
