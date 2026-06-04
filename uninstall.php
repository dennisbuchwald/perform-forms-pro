<?php
/**
 * PerForm Pro uninstall.
 *
 * Fires only when the user explicitly deletes the Pro add-on (not on
 * deactivation). Drops the webhook tables and the Pro schema-version option.
 * Submissions and free-core data are owned by the free PerForm plugin and are
 * left untouched here.
 *
 * @package PerFormPro
 * @since 0.2.5
 */

declare( strict_types = 1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/Autoloader.php';
\PerFormPro\Autoloader::register();

// Define the dir constant the autoloader-resolved classes expect, in case the
// main plugin file (which normally defines it) did not run this request.
if ( ! defined( 'PERFORM_PRO_DIR' ) ) {
	define( 'PERFORM_PRO_DIR', plugin_dir_path( __FILE__ ) );
}

// Drop the webhook tables + the Pro schema-version option.
\PerFormPro\Database\Schema::drop();

// Remove SMTP options + the per-user SMTP test-result transients (the SMTP
// module is owned by Pro since M-c-b).
delete_option( 'perform_smtp_settings' );
delete_option( 'perform_smtp_last_test' );

$admins = get_users( [ 'role' => 'administrator', 'fields' => 'ID' ] );
foreach ( $admins as $admin_id ) {
	delete_transient( 'perform_smtp_test_result_' . $admin_id );
}
