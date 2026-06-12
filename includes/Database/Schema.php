<?php
/**
 * Database schema owner for Flinkform Pro.
 *
 * Owns the webhook tables now that webhooks are a Pro feature (slice M-c-d-2).
 * The table NAMES are unchanged (`{prefix}flinkform_webhooks`,
 * `{prefix}flinkform_webhook_deliveries`) so an install that already had them
 * created by an older free core is adopted seamlessly — `dbDelta()` is
 * idempotent, so re-running `create()` over an existing table is a no-op.
 *
 * Mirrors the free core's auto-migrate recipe: `maybe_upgrade()` runs on the
 * Pro boot hook and only touches the DB when the stored version is behind,
 * which also covers the FTP-update path where the activation hook never fires.
 * Tables are NEVER dropped on deactivation — only on explicit uninstall — so a
 * license lapse never destroys a customer's webhook configuration or log.
 *
 * @package FlinkformPro
 * @since 0.2.5
 */

declare( strict_types = 1 );

namespace FlinkformPro\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and tracks the Pro (webhook) tables.
 */
final class Schema {

	/**
	 * Pro schema version. Bumped whenever a Pro table changes.
	 *
	 *   1 — webhooks + webhook_deliveries (adopted from free core schema v2)
	 */
	public const DB_VERSION = '1';

	/**
	 * Option key holding the installed Pro schema version.
	 */
	public const OPTION_DB_VERSION = 'flinkform_pro_db_version';

	/**
	 * Resolve the fully-qualified webhooks table name.
	 *
	 * @return string
	 */
	public static function webhooks_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'flinkform_webhooks';
	}

	/**
	 * Resolve the fully-qualified webhook-deliveries table name.
	 *
	 * @return string
	 */
	public static function webhook_deliveries_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'flinkform_webhook_deliveries';
	}

	/**
	 * Run the schema upgrade only when the stored version is behind.
	 *
	 * Cheap option read on the hot path; the actual DDL runs once after an
	 * install/update and then never again until DB_VERSION bumps. Called from
	 * the Pro boot hook so a file-only update (no activation) still migrates.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( (string) get_option( self::OPTION_DB_VERSION, '0' ) !== self::DB_VERSION ) {
			self::create();
		}
	}

	/**
	 * Create or migrate the Pro tables and persist the schema version.
	 *
	 * Safe to call multiple times — dbDelta() makes it idempotent.
	 *
	 * @return void
	 */
	public static function create(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::create_webhooks_table();
		self::create_webhook_deliveries_table();

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
	}

	/**
	 * Webhook configurations. One row per configured webhook.
	 *
	 * @return void
	 */
	private static function create_webhooks_table(): void {
		global $wpdb;

		$table   = self::webhooks_table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			form_id varchar(36) NOT NULL,
			label varchar(255) NOT NULL DEFAULT '',
			url text NOT NULL,
			method varchar(10) NOT NULL DEFAULT 'POST',
			format varchar(10) NOT NULL DEFAULT 'json',
			headers longtext NOT NULL,
			field_mapping longtext NOT NULL,
			condition_field varchar(255) NOT NULL DEFAULT '',
			condition_operator varchar(20) NOT NULL DEFAULT '',
			condition_value text NOT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY is_active (is_active)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Webhook delivery log. One row per dispatch attempt.
	 *
	 * @return void
	 */
	private static function create_webhook_deliveries_table(): void {
		global $wpdb;

		$table   = self::webhook_deliveries_table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			webhook_id bigint(20) unsigned NOT NULL,
			submission_id bigint(20) unsigned DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			response_code smallint(5) unsigned DEFAULT NULL,
			response_body text NOT NULL,
			attempt tinyint(3) unsigned NOT NULL DEFAULT 1,
			next_retry_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY webhook_id (webhook_id),
			KEY submission_id (submission_id),
			KEY status_retry (status, next_retry_at)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Drop the Pro tables and forget the schema version.
	 *
	 * Called from the Pro uninstall.php only — NEVER from deactivation, so a
	 * license lapse preserves the customer's webhook data.
	 *
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;

		$tables = [
			self::webhook_deliveries_table_name(),
			self::webhooks_table_name(),
		];

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name cannot be parameterised.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::OPTION_DB_VERSION );
	}
}
