<?php
/**
 * Flinkform Pro uninstall.
 *
 * Fires only when the user explicitly deletes the Pro add-on (not on
 * deactivation). Drops the webhook tables and the Pro schema-version option.
 * Submissions and free-core data are owned by the free Flinkform plugin and are
 * left untouched here.
 *
 * @package FlinkformPro
 * @since 0.2.5
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Autoloader.php';
\FlinkformPro\Autoloader::register();

// Define the dir constant the autoloader-resolved classes expect, in case the
// main plugin file (which normally defines it) did not run this request.
if ( ! defined( 'FLINKFORM_PRO_DIR' ) ) {
	define( 'FLINKFORM_PRO_DIR', plugin_dir_path( __FILE__ ) );
}

// Drop the webhook tables + the Pro schema-version option.
\FlinkformPro\Database\Schema::drop();

// Remove SMTP options + the per-user SMTP test-result transients (the SMTP
// module is owned by Pro since M-c-b).
delete_option( 'flinkform_smtp_settings' );
delete_option( 'flinkform_smtp_last_test' );

// Newsletter module options (encrypted API credentials + last-result cache).
delete_option( 'flinkform_newsletter_settings' );
delete_option( 'flinkform_newsletter_last_result' );

$admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
foreach ( $admins as $admin_id ) {
	delete_transient( 'flinkform_smtp_test_result_' . $admin_id );
}

// Remove visitor-uploaded files (personal data) from the File Upload field.
// The per-submission deletion cascade runs on row deletion, but uninstall
// drops the tables wholesale without firing those hooks — so the
// uploads/flinkform/ directory would otherwise be left behind on disk.
flinkform_pro_delete_upload_dir();

/**
 * Recursively delete the plugin's uploads subdirectory via WP_Filesystem.
 *
 * @return void
 */
function flinkform_pro_delete_upload_dir(): void {
	$uploads = wp_upload_dir( null, false );
	if ( empty( $uploads['basedir'] ) ) {
		return;
	}

	$dir = trailingslashit( (string) $uploads['basedir'] ) . 'flinkform';

	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( ! WP_Filesystem() ) {
		return; // No filesystem access — leave the files rather than risk a fatal.
	}

	global $wp_filesystem;
	if ( $wp_filesystem && $wp_filesystem->is_dir( $dir ) ) {
		$wp_filesystem->delete( $dir, true ); // Recursive.
	}
}
