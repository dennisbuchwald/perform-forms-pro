<?php
/**
 * SMTP module wiring (Flinkform Pro).
 *
 * Boots the SMTP subsystem now that it lives in Pro:
 *
 *   - Transport — the phpmailer_init / wp_mail_from overrides + conflict
 *     detection (registers its own hooks on `init`).
 *   - SMTP settings page — re-attached as a submenu under the free core's
 *     Flinkform menu (Menu::PARENT_SLUG) and dispatched on admin_init, exactly
 *     as the free core's Menu did before the move.
 *
 * The free core no longer registers the SMTP page or transport; this wirer is
 * the single replacement, hooked from `flinkform_register_modules`.
 *
 * @package FlinkformPro
 * @since 0.2.1
 */

declare( strict_types = 1 );

namespace FlinkformPro\Smtp;

use Flinkform\Admin\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the Pro SMTP transport + settings page into wp-admin.
 */
final class Module {

	/**
	 * Register the WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		( new Transport() )->register();
		( new MailLog() )->register();

		// Priority 20: after the free core's Menu::register_pages() (priority
		// 10) so the parent Flinkform menu exists before we add the submenu.
		add_action( 'admin_menu', [ $this, 'register_page' ], 20 );
		add_action( 'admin_init', [ $this, 'dispatch' ] );
	}

	/**
	 * Add the SMTP settings submenu under the Flinkform menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'SMTP', 'flinkform-pro' ),
			__( 'SMTP', 'flinkform-pro' ),
			Menu::CAPABILITY,
			SmtpPage::SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the SMTP settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		( new SmtpPage() )->render();
	}

	/**
	 * Run the SMTP page's pre-headers POST/GET handler on admin_init.
	 *
	 * @return void
	 */
	public function dispatch(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page identifier, not a security boundary; SmtpPage::dispatch() verifies its own nonces.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( SmtpPage::SLUG === $page ) {
			( new SmtpPage() )->dispatch();
		}
	}
}
