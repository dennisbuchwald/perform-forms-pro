<?php
/**
 * CSV export controller (Flinkform Pro).
 *
 * Owns the submissions CSV export end to end now that it lives in Pro:
 *
 *   - render_button()      — hooks the free core's `flinkform_submissions_table_actions`
 *                            seam to print the "Export CSV" button inline with the
 *                            Filter button, so the free core ships no export UI.
 *   - maybe_handle_export() — runs on admin_init, intercepts the export request the
 *                            button points at, re-checks capability + nonce (the free
 *                            core's dispatch() no longer routes 'export', so Pro is
 *                            solely responsible for the auth gate here) and streams.
 *
 * Capability and page slug are read from the free core's Menu so the gate stays
 * identical to every other submissions action.
 *
 * @package FlinkformPro
 * @since 0.2.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Export;

use Flinkform\Admin\Menu;
use Flinkform\Submissions\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Wires and handles the Pro CSV export.
 */
final class ExportController {

	private const NONCE_ACTION = 'flinkform_export';

	/**
	 * Register the WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_handle_export' ] );
		add_action( 'flinkform_submissions_table_actions', [ $this, 'render_button' ] );
	}

	/**
	 * Print the Export CSV button into the submissions filter bar.
	 *
	 * @param mixed $current Active filter values passed by the core seam.
	 * @return void
	 */
	public function render_button( $current ): void {
		$current = is_array( $current ) ? $current : [];

		$export_url = add_query_arg(
			array_merge(
				[
					'page'           => Menu::PARENT_SLUG,
					'flinkform_action' => 'export',
					'_wpnonce'       => wp_create_nonce( self::NONCE_ACTION ),
				],
				$current
			),
			admin_url( 'admin.php' )
		);

		printf(
			'<a href="%s" class="button">%s</a>',
			esc_url( $export_url ),
			esc_html__( 'Export CSV', 'flinkform-pro' )
		);
	}

	/**
	 * Intercept and handle the export request on admin_init.
	 *
	 * Streams a CSV (which exit()s) when the request targets the submissions
	 * page with `flinkform_action=export`, the user is capable, and the nonce
	 * checks out. Any other request falls straight through.
	 *
	 * @return void
	 */
	public function maybe_handle_export(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- routing check only; the nonce is verified below before any output.
		if ( ! isset( $_GET['page'], $_GET['flinkform_action'] ) ) {
			return;
		}
		$page   = sanitize_key( wp_unslash( $_GET['page'] ) );
		$action = sanitize_key( wp_unslash( $_GET['flinkform_action'] ) );
		// phpcs:enable

		if ( Menu::PARENT_SLUG !== $page || 'export' !== $action ) {
			return;
		}

		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		( new CsvExporter( new Repository() ) )->stream( $this->read_filters() );
		// stream() exits; nothing runs after this.
	}

	/**
	 * Pull the filter args off the request for the exporter.
	 *
	 * @return array<string, string>
	 */
	private function read_filters(): array {
		$filters = [];
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter inputs; export nonce verified in maybe_handle_export().
		foreach ( [ 'form_id', 'status', 'date_from', 'date_to', 'search' ] as $key ) {
			if ( ! empty( $_GET[ $key ] ) ) {
				$filters[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		// phpcs:enable
		return $filters;
	}
}
