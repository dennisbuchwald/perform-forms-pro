<?php
/**
 * Flinkform Pro activation handler.
 *
 * Runs once when the Pro add-on is activated. Creates the webhook tables and
 * schedules the dispatcher cron — the same routine the free core used to run
 * before webhooks became a Pro feature (slice M-c-d-2).
 *
 * @package FlinkformPro
 * @since 0.2.5
 */

declare( strict_types = 1 );

namespace FlinkformPro;

use FlinkformPro\Database\Schema;
use FlinkformPro\Webhooks\Dispatcher;

defined( 'ABSPATH' ) || exit;

/**
 * Activation routines.
 */
final class Activator {

	/**
	 * Run the activation routine.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Schema::create();

		// The `flinkform_every_minute` schedule is normally added to
		// wp_get_schedules() by Dispatcher::register() on the `cron_schedules`
		// filter — but that runs on `flinkform_register_modules` (plugins_loaded),
		// which does NOT fire during an activation request. Add the same
		// schedule inline here so wp_schedule_event() sees it.
		add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				if ( ! isset( $schedules[ Dispatcher::CRON_SCHEDULE ] ) ) {
					$schedules[ Dispatcher::CRON_SCHEDULE ] = [
						'interval' => 60,
						// Untranslated on purpose — cron_schedules fires before
						// init; __() that early trips WP 6.7+'s JIT-load notice.
						'display'  => 'Every Minute (Flinkform)',
					];
				}
				return $schedules;
			}
		);

		if ( ! wp_next_scheduled( Dispatcher::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, Dispatcher::CRON_SCHEDULE, Dispatcher::CRON_HOOK );
		}
	}
}
