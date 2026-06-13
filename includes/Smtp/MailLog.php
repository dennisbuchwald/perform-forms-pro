<?php
/**
 * SMTP send log (Flinkform Pro).
 *
 * Records the outcome of every wp_mail() call while logging is enabled in
 * the SMTP settings — successes via the `wp_mail_succeeded` action (WP 5.9+),
 * failures via `wp_mail_failed` with the PHPMailer error detail. This is the
 * deliverability paper trail the free core deliberately doesn't keep: "did
 * the confirmation for submission X actually leave the server, and if not,
 * why?".
 *
 * SCOPE: the SMTP feature routes ALL of the site's wp_mail() through the
 * configured provider, so this log is deliberately site-wide — it records
 * every outgoing mail while logging is on, not just Flinkform's own
 * notifications (password resets, WooCommerce order mails, etc. are
 * included). That is the point of a deliverability log for an SMTP relay;
 * restricting it to Flinkform mails would defeat the diagnostic purpose.
 * The settings-page help text and the privacy-policy disclosure both state
 * this explicitly so operators can make an informed choice (it is opt-in,
 * default on only once SMTP itself is enabled).
 *
 * GDPR posture: rows store recipients + subject + error only — never the
 * mail body — and are purged after the configured retention period (default
 * 30 days, 'log_retention_days' in the SMTP settings). The Pro privacy
 * eraser removes rows matching a data-subject's email address.
 *
 * @package FlinkformPro
 * @since 0.4.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Smtp;

use FlinkformPro\Database\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads the SMTP send log.
 */
final class MailLog {

	/**
	 * Hard cap on rows returned to the admin UI.
	 */
	private const LIST_LIMIT = 50;

	/**
	 * Length cap applied to stored subject / error strings.
	 */
	private const TEXT_CAP = 500;

	/**
	 * Register the WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_mail_succeeded', [ $this, 'log_success' ] );
		add_action( 'wp_mail_failed', [ $this, 'log_failure' ] );
	}

	/**
	 * Whether logging is currently enabled (SMTP settings toggle).
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$settings = SmtpPage::get_settings();
		return ! empty( $settings['log_enabled'] );
	}

	/**
	 * Retention period in days (0 falls back to the default).
	 *
	 * @return int
	 */
	public static function retention_days(): int {
		$settings = SmtpPage::get_settings();
		$days     = isset( $settings['log_retention_days'] ) ? (int) $settings['log_retention_days'] : 30;
		return $days >= 1 ? $days : 30;
	}

	/**
	 * wp_mail_succeeded callback.
	 *
	 * @param array<string, mixed> $mail_data { to, subject, headers, attachments }.
	 * @return void
	 */
	public function log_success( $mail_data ): void {
		if ( ! self::is_enabled() || ! is_array( $mail_data ) ) {
			return;
		}
		$this->insert( 'sent', $mail_data, '' );
	}

	/**
	 * wp_mail_failed callback.
	 *
	 * @param \WP_Error $error Carries the original mail data plus the PHPMailer detail.
	 * @return void
	 */
	public function log_failure( $error ): void {
		if ( ! self::is_enabled() || ! is_wp_error( $error ) ) {
			return;
		}
		$mail_data = $error->get_error_data( 'wp_mail_failed' );
		$this->insert(
			'failed',
			is_array( $mail_data ) ? $mail_data : [],
			$error->get_error_message()
		);
	}

	/**
	 * Persist one log row, then opportunistically purge expired rows.
	 *
	 * @param string               $status    'sent' | 'failed'.
	 * @param array<string, mixed> $mail_data wp_mail() argument bag.
	 * @param string               $error     Error detail ('' on success).
	 * @return void
	 */
	private function insert( string $status, array $mail_data, string $error ): void {
		global $wpdb;

		$to = $mail_data['to'] ?? [];
		if ( ! is_array( $to ) ) {
			$to = explode( ',', (string) $to );
		}
		$recipients = implode( ', ', array_filter( array_map( 'sanitize_email', array_map( 'trim', array_map( 'strval', $to ) ) ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- dedicated custom table.
		$wpdb->insert(
			Schema::mail_log_table_name(),
			[
				'status'     => 'failed' === $status ? 'failed' : 'sent',
				'recipients' => mb_substr( $recipients, 0, self::TEXT_CAP ),
				'subject'    => mb_substr( sanitize_text_field( (string) ( $mail_data['subject'] ?? '' ) ), 0, self::TEXT_CAP ),
				'error'      => mb_substr( sanitize_text_field( $error ), 0, self::TEXT_CAP ),
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);

		$this->purge_expired();
	}

	/**
	 * Delete rows older than the retention period. Indexed on created_at,
	 * so this is one cheap range delete per logged mail.
	 *
	 * @return void
	 */
	private function purge_expired(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::retention_days() * DAY_IN_SECONDS ) );
		$table  = Schema::mail_log_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dedicated custom table; name cannot be parameterised.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Latest log rows for the admin UI, newest first.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function latest(): array {
		global $wpdb;

		$table = Schema::mail_log_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dedicated custom table; name cannot be parameterised.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status, recipients, subject, error, created_at FROM {$table} ORDER BY id DESC LIMIT %d",
				self::LIST_LIMIT
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Erase log rows whose recipients contain the given email address.
	 * Consumed by the Pro privacy eraser.
	 *
	 * @param string $email
	 * @return int Number of rows removed.
	 */
	public static function erase_for_email( string $email ): int {
		global $wpdb;

		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return 0;
		}

		$table = Schema::mail_log_table_name();

		// Recipients are stored as a ", "-joined list. Match the address on
		// token boundaries so erasing anna@example.com does NOT also nuke
		// rows for susanna@example.com (a plain LIKE '%email%' over-matches
		// and would delete other data subjects' log entries). esc_like only
		// neutralises SQL wildcards, not the word-boundary problem.
		$like   = $wpdb->esc_like( $email );
		$sep     = ', ';
		$as_only  = $email;                          // sole recipient
		$as_first = $like . $wpdb->esc_like( $sep ) . '%'; // "email, …"
		$as_last  = '%' . $wpdb->esc_like( $sep ) . $like;  // "…, email"
		$as_mid   = '%' . $wpdb->esc_like( $sep ) . $like . $wpdb->esc_like( $sep ) . '%'; // "…, email, …"

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- dedicated custom table; name cannot be parameterised.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE recipients = %s OR recipients LIKE %s OR recipients LIKE %s OR recipients LIKE %s",
				$as_only,
				$as_first,
				$as_last,
				$as_mid
			)
		);

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
}
