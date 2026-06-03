<?php
/**
 * Block-editor asset wiring (PerForm Pro).
 *
 * Enqueues the Pro editor script that docks onto the free core's editor
 * extension points (the `perform.formContainer.inspectorPanels` filter and
 * those added in later slices). The script is a dependency-light vanilla
 * `wp.*` bundle — see assets/editor.js for the rationale.
 *
 * @package PerFormPro
 * @since 0.2.2
 */

declare( strict_types = 1 );

namespace PerFormPro\Editor;

defined( 'ABSPATH' ) || exit;

/**
 * Registers PerForm Pro's block-editor assets.
 */
final class Extensions {

	private const HANDLE = 'perform-forms-pro-editor';

	/**
	 * Register the WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue the Pro editor extension script.
	 *
	 * Loads on every block-editor screen; the registered filters only fire for
	 * PerForm blocks that apply them, so this is inert everywhere else.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		wp_enqueue_script(
			self::HANDLE,
			PERFORM_PRO_URL . 'assets/editor.js',
			[ 'wp-hooks', 'wp-element', 'wp-components', 'wp-i18n' ],
			PERFORM_PRO_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'perform-forms-pro' );
	}
}
