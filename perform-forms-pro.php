<?php
/**
 * Plugin Name:       PerForm Pro
 * Plugin URI:        https://dbw-media.de/perform-forms-pro/
 * Description:       Pro add-on for PerForm — conditional logic, multi-step, webhooks, CSV export, SMTP & external CAPTCHA. Docks onto the free PerForm core.
 * Version:           0.2.3
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Requires Plugins:  perform-forms
 * Author:            dbw media
 * Author URI:        https://dbw-media.de
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       perform-forms-pro
 *
 * @package PerFormPro
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants — single source of truth.
 */
define( 'PERFORM_PRO_VERSION', '0.2.3' );
// Minimum free-core version. 0.2.2 adds the form-container inspector-panel
// filter the Pro editor script docks onto; against an older core the Pro
// panels simply would not render, so require the matching core.
define( 'PERFORM_PRO_MIN_CORE', '0.2.2' );
define( 'PERFORM_PRO_FILE', __FILE__ );
define( 'PERFORM_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'PERFORM_PRO_URL', plugin_dir_url( __FILE__ ) );

// Register the Pro autoloader before anything touches the PerFormPro namespace.
require_once PERFORM_PRO_DIR . 'includes/Autoloader.php';
\PerFormPro\Autoloader::register();

/**
 * Dock onto the free PerForm core via the bridge layer.
 *
 * Two listeners are attached at top-level (this runs before `plugins_loaded`),
 * so they are already in place when the core fires its hooks:
 *
 *   - `perform_pro_features`   — advertise which Pro capabilities are available
 *                                 so the free core's Features façade flips on.
 *   - `perform_register_modules` — wire Pro subsystems once the core is ready.
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
function perform_pro_advertise_features( array $features ): array {
	// Capabilities the Pro add-on owns per the Free/Pro matrix (PERFORM_ROADMAP.md,
	// Phase M). Conditional logic and multi-step deliberately stay in the FREE
	// core (intrinsic to form-building, not cleanly separable) — Pro differentiates
	// on integrations / infrastructure.
	$features[] = 'submissions_export'; // CSV export (M-c-a)
	$features[] = 'smtp';               // SMTP transport + settings (M-c-b)
	// 'webhooks' is advertised once the module actually moves to Pro (M-c-d);
	// until then webhooks still runs from the free core.

	return $features;
}
add_filter( 'perform_pro_features', 'perform_pro_advertise_features' );

/**
 * Wire Pro subsystems once the free core has booted.
 *
 * Fires on the core's `perform_register_modules` action (end of
 * `Plugin::init()`). In slice M-b this is intentionally near-empty — the
 * dock itself is what we are proving. Real modules attach here from M-c on.
 *
 * @return void
 */
function perform_pro_register_modules(): void {
	if ( ! perform_pro_core_is_compatible() ) {
		return;
	}

	// M-c-a: CSV export. Owns the Export CSV button + the export request
	// handler, reading through the free core's submissions Repository.
	( new \PerFormPro\Export\ExportController() )->register();

	// M-c-b: SMTP. Transport (phpmailer_init overrides + conflict detection)
	// and the SMTP settings page, re-attached under the free core's menu.
	( new \PerFormPro\Smtp\Module() )->register();

	// M-c-c: block-editor extensions. Enqueues the Pro editor script that
	// docks onto the free core's inspector-panel filter(s).
	( new \PerFormPro\Editor\Extensions() )->register();

	// Further modules dock here from the next M-c slices: conditional logic,
	// multi-step, webhooks, external CAPTCHA providers.
}
add_action( 'perform_register_modules', 'perform_pro_register_modules' );

/**
 * Is the installed free core present and new enough to expose the bridge?
 *
 * @return bool
 */
function perform_pro_core_is_compatible(): bool {
	// The bridge-layer keystone class is the cheapest, most direct probe.
	if ( ! class_exists( '\\PerForm\\Bridge\\Features' ) ) {
		return false;
	}

	if ( defined( 'PERFORM_VERSION' ) && version_compare( (string) PERFORM_VERSION, PERFORM_PRO_MIN_CORE, '<' ) ) {
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
function perform_pro_admin_guard(): void {
	if ( ! is_admin() ) {
		return;
	}

	if ( ! perform_pro_core_is_compatible() ) {
		add_action(
			'admin_notices',
			static function (): void {
				$min = esc_html( PERFORM_PRO_MIN_CORE );
				echo '<div class="notice notice-error"><p>';
				echo wp_kses_post(
					sprintf(
						/* translators: %s: minimum required PerForm core version. */
						__( '<strong>PerForm Pro</strong> needs the free <strong>PerForm</strong> plugin (version %s or newer) to be active. Pro features are paused until the core is available.', 'perform-forms-pro' ),
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
			if ( ! class_exists( '\\PerForm\\Bridge\\Features' ) ) {
				return;
			}
			$active = array_keys( \PerForm\Bridge\Features::all() );
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo wp_kses_post(
				sprintf(
					/* translators: 1: Pro version, 2: comma-separated list of active capabilities. */
					__( '<strong>PerForm Pro %1$s</strong> is active and docked onto the PerForm core. Pro capabilities: %2$s', 'perform-forms-pro' ),
					esc_html( PERFORM_PRO_VERSION ),
					esc_html( implode( ', ', $active ) )
				)
			);
			echo '</p></div>';
		}
	);
}
add_action( 'plugins_loaded', 'perform_pro_admin_guard', 20 );
