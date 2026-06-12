<?php
/**
 * Plugin Name:       Flinkform Pro
 * Plugin URI:        https://dbw-media.de/flinkform-pro/
 * Description:       Pro add-on for Flinkform — webhooks, CSV export, SMTP & (coming) external CAPTCHA and payments. Docks onto the free Flinkform core.
 * Version:           0.4.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  flinkform
 * Author:            dbw media
 * Author URI:        https://dbw-media.de
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       flinkform-pro
 *
 * @package FlinkformPro
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants — single source of truth.
 */
define( 'FLINKFORM_PRO_VERSION', '0.4.0' );
// Minimum free-core version. 0.4.0 is the Flinkform rename baseline
// (flinkform_ hook prefix, FLINKFORM_VERSION constant); older cores
// fire perffo_/perform_ hooks this add-on no longer listens to.
define( 'FLINKFORM_PRO_MIN_CORE', '0.4.0' );
define( 'FLINKFORM_PRO_FILE', __FILE__ );
define( 'FLINKFORM_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'FLINKFORM_PRO_URL', plugin_dir_url( __FILE__ ) );

// Register the Pro autoloader before anything touches the FlinkformPro namespace.
require_once FLINKFORM_PRO_DIR . 'includes/Autoloader.php';
\FlinkformPro\Autoloader::register();

// Webhook tables + dispatcher cron live with the Pro add-on (M-c-d-2).
register_activation_hook( __FILE__, [ \FlinkformPro\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \FlinkformPro\Deactivator::class, 'deactivate' ] );

/**
 * Dock onto the free Flinkform core via the bridge layer.
 *
 * Two listeners are attached at top-level (this runs before `plugins_loaded`),
 * so they are already in place when the core fires its hooks:
 *
 *   - `flinkform_pro_features`   — advertise which Pro capabilities are available
 *                                 so the free core's Features façade flips on.
 *   - `flinkform_register_modules` — wire Pro subsystems once the core is ready.
 *
 * Each callback is defensively guarded: if the core (or its bridge layer) is
 * absent, they do nothing. The hard "core must be present" dependency is
 * enforced by the `Requires Plugins` header above (WP 6.5+); the runtime guard
 * below covers the *version* case — core present but too old to have the bridge.
 */

/**
 * Advertise Pro capabilities to the free core's Features façade.
 *
 * @param array<int|string, mixed> $features Capabilities collected so far.
 * @return array<int|string, mixed>
 */
function flinkform_pro_advertise_features( array $features ): array {
	// Capabilities the Pro add-on owns per the Free/Pro matrix.
	$features[] = 'submissions_export'; // CSV export (M-c-a)
	$features[] = 'smtp';               // SMTP transport + settings (M-c-b)
	$features[] = 'webhooks';           // REST + dispatcher + log + tables (M-c-d)
	$features[] = 'custom_css';         // Per-form custom CSS
	$features[] = 'file_upload';        // File Upload field block + processing

	// NOTE: multi_step and spam_challenge are no longer advertised — both
	// live in the free core since 0.4.0 (per the published feature matrix).

	return $features;
}
add_filter( 'flinkform_pro_features', 'flinkform_pro_advertise_features' );

/**
 * Wire Pro subsystems once the free core has booted.
 *
 * Fires on the core's `flinkform_register_modules` action (end of
 * `Plugin::init()`). In slice M-b this is intentionally near-empty — the
 * dock itself is what we are proving. Real modules attach here from M-c on.
 *
 * @return void
 */
function flinkform_pro_register_modules(): void {
	if ( ! flinkform_pro_core_is_compatible() ) {
		return;
	}

	// M-c-a: CSV export. Owns the Export CSV button + the export request
	// handler, reading through the free core's submissions Repository.
	( new \FlinkformPro\Export\ExportController() )->register();

	// M-c-b: SMTP. Transport (phpmailer_init overrides + conflict detection)
	// and the SMTP settings page, re-attached under the free core's menu.
	( new \FlinkformPro\Smtp\Module() )->register();

	// M-c-c: block-editor extensions. Enqueues the Pro editor script that
	// docks onto the free core's inspector-panel filter(s).
	( new \FlinkformPro\Editor\Extensions() )->register();

	// M-c-d: webhooks — REST CRUD, cron dispatcher, submission listener,
	// the Webhook Log page + the deliveries section, and the schema/cron
	// lifecycle. (The Integrations editor panel was moved in M-c-d-1.)
	( new \FlinkformPro\Webhooks\Module() )->register();

	// Custom CSS: re-adds the per-form CSS feature (attribute + render output).
	// The editor panel is handled by the JS bundle (custom-css-panel.js).
	( new \FlinkformPro\CustomCss\Module() )->register();

	// File uploads: registers the field-file block + the upload processing
	// via the core's field-type seams, plus the file-deletion cascade.
	( new \FlinkformPro\Uploads\Module() )->register();

	// GDPR: privacy-policy content (webhooks + SMTP), a delivery-log personal-
	// data exporter, and the erasure cascade for webhook delivery rows.
	( new \FlinkformPro\Privacy() )->register();

	// Future modules dock here: external CAPTCHA providers, payments, etc.
}
add_action( 'flinkform_register_modules', 'flinkform_pro_register_modules' );

/**
 * Is the installed free core present and new enough to expose the bridge?
 *
 * @return bool
 */
function flinkform_pro_core_is_compatible(): bool {
	// The bridge-layer keystone class is the cheapest, most direct probe.
	if ( ! class_exists( '\\Flinkform\\Bridge\\Features' ) ) {
		return false;
	}

	if ( defined( 'FLINKFORM_VERSION' ) && version_compare( (string) FLINKFORM_VERSION, FLINKFORM_PRO_MIN_CORE, '<' ) ) {
		return false;
	}

	return true;
}

/**
 * Runtime compatibility guard + dock confirmation in the admin.
 *
 * Runs after the core has booted (priority 20 > the core's 10 on
 * `plugins_loaded`). Shows an error notice when the core is missing/too old,
 * and a one-time success confirmation when the dock succeeded — the visible
 * proof that the Free/Pro separation works end-to-end.
 *
 * @return void
 */
function flinkform_pro_admin_guard(): void {
	if ( ! is_admin() ) {
		return;
	}

	if ( ! flinkform_pro_core_is_compatible() ) {
		add_action(
			'admin_notices',
			static function (): void {
				$min = esc_html( FLINKFORM_PRO_MIN_CORE );
				echo '<div class="notice notice-error"><p>';
				echo wp_kses_post(
					sprintf(
						/* translators: %s: minimum required Flinkform core version. */
						__( '<strong>Flinkform Pro</strong> needs the free <strong>Flinkform</strong> plugin (version %s or newer) to be active. Pro features are paused until the core is available.', 'flinkform-pro' ),
						$min
					)
				);
				echo '</p></div>';
			}
		);
		return;
	}

	// Dock succeeded — surface the active capabilities as proof.
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! class_exists( '\\Flinkform\\Bridge\\Features' ) ) {
				return;
			}
			$active = array_keys( \Flinkform\Bridge\Features::all() );
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo wp_kses_post(
				sprintf(
					/* translators: 1: Pro version, 2: comma-separated list of active capabilities. */
					__( '<strong>Flinkform Pro %1$s</strong> is active and docked onto the Flinkform core. Pro capabilities: %2$s', 'flinkform-pro' ),
					esc_html( FLINKFORM_PRO_VERSION ),
					esc_html( implode( ', ', $active ) )
				)
			);
			echo '</p></div>';
		}
	);
}
add_action( 'plugins_loaded', 'flinkform_pro_admin_guard', 20 );
