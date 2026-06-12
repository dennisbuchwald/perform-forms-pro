<?php
/**
 * SMTP settings admin page (Phase A — SMTP module).
 *
 * Phase A is split into three slices, each shipped as its own
 * independent feat: commit so the operator can verify on the sandbox
 * between increments:
 *
 *   A-a (THIS SLICE) — Settings page + storage + crypto helper.
 *                       Renders the UI, persists the credentials
 *                       (password encrypted via Settings\Secret),
 *                       offers provider presets that pre-fill
 *                       host / port / encryption. NOT YET wired to
 *                       PHPMailer — saving settings here has no
 *                       runtime effect until A-b lands.
 *
 *   A-b              — phpmailer_init hook + plugin-conflict
 *                       detection (WP Mail SMTP / FluentSMTP / etc.)
 *                       + wp_mail_from{,_name} overrides.
 *
 *   A-c              — "Send test email" button + connection-error
 *                       surfacing + per-provider inline help.
 *
 * Phase D (post-launch) — OAuth2 for Google Workspace + Microsoft 365.
 *                          App-registration strategy (BYO vs. hosted
 *                          proxy) decided then based on real-user
 *                          feedback.
 *
 * Storage shape — single wp_options key `flinkform_smtp_settings`:
 *
 *   [ *     'enabled'    => bool,                   // master toggle
 *     'provider'   => string,                 // preset key, '' = custom
 *     'host'       => string,                 // SMTP host
 *     'port'       => int,                    // 1-65535
 *     'encryption' => 'none'|'ssl'|'tls',
 *     'auth'       => bool,                   // SMTP-Auth on/off
 *     'username'   => string,
 *     'password'   => string,                 // ENCRYPTED cipher (Settings\Secret)
 *     'from_email' => string,                 // overrides wp_mail_from in A-b
 *     'from_name'  => string,                 // overrides wp_mail_from_name in A-b
 *   ]
 *
 * Single autoloaded option, not nine — settings are atomic to the
 * operator and an array of nine entries is one row, one autoload
 * fetch, one save.
 *
 * Why not Settings API (register_setting + options.php): the
 * options.php endpoint redirects after save which would force us to
 * handle the encrypt-on-save flow in a separate sanitize_callback
 * disconnected from the form context. Keeping the POST handler local
 * matches SubmissionsPage / FormsPage / WebhookLogPage and lets us
 * cleanly express "empty password field means keep the existing
 * cipher" — which the Settings API would force into a register_setting
 * filter that has no visibility into the previous value.
 *
 * @package FlinkformPro
 * @since 0.2.1
 */

declare( strict_types = 1 );

namespace FlinkformPro\Smtp;

use Flinkform\Admin\Menu;
use FlinkformPro\Settings\Secret;

defined( 'ABSPATH' ) || exit;

/**
 * Controller for the Flinkform → SMTP page.
 */
final class SmtpPage {

	/**
	 * Submenu slug.
	 *
	 * @var string
	 */
	public const SLUG = 'flinkform-smtp';

	/**
	 * wp_options key for the settings array.
	 *
	 * @var string
	 */
	public const OPTION_KEY = 'flinkform_smtp_settings';

	/**
	 * Nonce action for the save form.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'flinkform_smtp_save';

	/**
	 * Nonce action for the test-email button.
	 *
	 * @var string
	 */
	private const TEST_NONCE_ACTION = 'flinkform_smtp_test';

	/**
	 * wp_options key recording the last test-email outcome.
	 * Read by the status block to show "Last test" row;
	 * written by handle_test_send().
	 *
	 * Shape:
	 *   [ *     'timestamp' => int,             // UTC unix ts
	 *     'success'   => bool,
	 *     'recipient' => string,
	 *     'error'     => string,           // empty on success
	 *   ]
	 *
	 * @var string
	 */
	public const LAST_TEST_OPTION_KEY = 'flinkform_smtp_last_test';

	/**
	 * Default settings — applied with array_merge() on every read so
	 * partial / legacy option shapes still produce a complete config.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'enabled'            => false,
			'provider'           => '',
			'host'               => '',
			'port'               => 587,
			'encryption'         => 'tls',
			'auth'               => true,
			'username'           => '',
			'password'           => '',
			'from_email'         => '',
			'from_name'          => '',
			'log_enabled'        => true,
			'log_retention_days' => 30,
		];
	}

	/**
	 * Read the current settings, merged with defaults.
	 *
	 * Public so the Phase-A-b PHPMailer hook can pull the active
	 * config without re-implementing the merge.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Persist a settings array, casting each field through its
	 * sanitiser. The caller is expected to have validated the
	 * request (nonce + capability) already.
	 *
	 * Returns the array as it was actually stored (with defaults
	 * applied + sanitisation), so the render path can immediately
	 * display the canonical state.
	 *
	 * @param array<string, mixed> $input         Raw input.
	 * @param array<string, mixed> $previous      Previously stored settings (for password preservation).
	 * @return array<string, mixed>
	 */
	public static function save_settings( array $input, array $previous ): array {
		$next = self::defaults();

		$next['enabled']    = ! empty( $input['enabled'] );
		$next['provider']   = self::sanitize_provider_key( (string) ( $input['provider'] ?? '' ) );
		$next['host']       = sanitize_text_field( (string) ( $input['host'] ?? '' ) );
		$next['port']       = self::sanitize_port( (int) ( $input['port'] ?? 0 ) );
		$next['encryption'] = self::sanitize_encryption( (string) ( $input['encryption'] ?? '' ) );
		$next['auth']       = ! empty( $input['auth'] );
		$next['username']   = sanitize_text_field( (string) ( $input['username'] ?? '' ) );

		// Password handling: an empty submitted password keeps the
		// existing cipher. This is the only safe way to handle the
		// "show settings on page reload" case without ever sending
		// plaintext back to the browser. The placeholder in the
		// input is empty too — operators see "no value" and know
		// to type a new password only if they want to change it.
		$submitted_password = (string) ( $input['password'] ?? '' );
		if ( '' === $submitted_password ) {
			$next['password'] = (string) ( $previous['password'] ?? '' );
		} else {
			$next['password'] = Secret::encrypt( $submitted_password );
		}

		$from_email          = sanitize_email( (string) ( $input['from_email'] ?? '' ) );
		$next['from_email']  = is_email( $from_email ) ? $from_email : '';
		$next['from_name']   = sanitize_text_field( (string) ( $input['from_name'] ?? '' ) );

		$next['log_enabled'] = ! empty( $input['log_enabled'] );
		$retention           = (int) ( $input['log_retention_days'] ?? 30 );
		$next['log_retention_days'] = max( 1, min( 365, $retention > 0 ? $retention : 30 ) );

		update_option( self::OPTION_KEY, $next, false );

		return $next;
	}

	/**
	 * Provider-preset registry.
	 *
	 * Keys are stable identifiers persisted in the `provider` field;
	 * 'label' is shown in the dropdown; host/port/encryption fill
	 * the form when the operator picks the preset (JS handler in
	 * render_preset_filler_script()).
	 *
	 * The two `disabled` entries (Microsoft 365, Google Workspace)
	 * are intentionally listed in the dropdown so operators looking
	 * for them see a clear status message instead of getting
	 * confused by their absence. Both require OAuth2 and ship in
	 * Phase D.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function providers(): array {
		return [
			''        => [
				'label'      => __( 'Custom SMTP server', 'flinkform-pro' ),
				'host'       => '',
				'port'       => 587,
				'encryption' => 'tls',
				'help'       => __( 'Enter the SMTP details supplied by your mail provider.', 'flinkform-pro' ),
			],
			'gmail'   => [
				'label'      => __( 'Gmail (App-Password)', 'flinkform-pro' ),
				'host'       => 'smtp.gmail.com',
				'port'       => 587,
				'encryption' => 'tls',
				'help'       => __( 'Requires 2-Step-Verification on your Google account. Create an App-Password at https://myaccount.google.com/apppasswords and use it as the password below. Workspace admins can disable App-Passwords by policy — in that case wait for the OAuth2 release in v0.2.', 'flinkform-pro' ),
			],
			'outlook' => [
				'label'      => __( 'Outlook.com / Hotmail (personal)', 'flinkform-pro' ),
				'host'       => 'smtp-mail.outlook.com',
				'port'       => 587,
				'encryption' => 'tls',
				'help'       => __( 'Personal Outlook.com / Hotmail accounts only. Microsoft 365 (business) accounts require OAuth2 — coming in Flinkform v0.2.', 'flinkform-pro' ),
			],
			'sendgrid' => [
				'label'      => __( 'SendGrid', 'flinkform-pro' ),
				'host'       => 'smtp.sendgrid.net',
				'port'       => 587,
				'encryption' => 'tls',
				'help'       => __( 'Username is literally "apikey" (lowercase). Password is your SendGrid API key.', 'flinkform-pro' ),
			],
			'mailgun' => [
				'label'      => __( 'Mailgun', 'flinkform-pro' ),
				'host'       => 'smtp.mailgun.org',
				'port'       => 587,
				'encryption' => 'tls',
				'help'       => __( 'Find your SMTP credentials in Mailgun under Sending → Domain Settings → SMTP credentials. EU customers: change the host to smtp.eu.mailgun.org.', 'flinkform-pro' ),
			],
			'brevo'   => [
				'label'      => __( 'Brevo (formerly Sendinblue)', 'flinkform-pro' ),
				'host'       => 'smtp-relay.brevo.com',
				'port'       => 587,
				'encryption' => 'tls',
				'help'       => __( 'SMTP credentials are at Brevo → SMTP & API → SMTP. The username is your Brevo account email.', 'flinkform-pro' ),
			],
			'postmark' => [
				'label'      => __( 'Postmark', 'flinkform-pro' ),
				'host'       => 'smtp.postmarkapp.com',
				'port'       => 587,
				'encryption' => 'tls',
				'help'       => __( 'Username and password are both your Postmark Server API token (the same string).', 'flinkform-pro' ),
			],
			'ses'     => [
				'label'      => __( 'Amazon SES', 'flinkform-pro' ),
				'host'       => 'smtp.us-east-1.amazonaws.com',
				'port'       => 587,
				'encryption' => 'tls',
				'help'       => __( 'Replace "us-east-1" in the host with your SES region. Username and password are SMTP-specific credentials (created under IAM → SMTP settings), NOT your normal AWS access key.', 'flinkform-pro' ),
			],
			'm365'    => [
				'label'      => __( 'Microsoft 365 — requires OAuth2 (coming in v0.2)', 'flinkform-pro' ),
				'host'       => '',
				'port'       => 0,
				'encryption' => '',
				'help'       => __( 'Microsoft disabled basic SMTP authentication for Microsoft 365 tenants. OAuth2 support is scheduled for Flinkform v0.2.', 'flinkform-pro' ),
				'disabled'   => true,
			],
			'workspace' => [
				'label'      => __( 'Google Workspace — use App-Password preset or wait for OAuth2 (v0.2)', 'flinkform-pro' ),
				'host'       => '',
				'port'       => 0,
				'encryption' => '',
				'help'       => __( 'Google Workspace accounts can use the Gmail preset above if your admin allows App-Passwords. Otherwise wait for the OAuth2 release in Flinkform v0.2.', 'flinkform-pro' ),
				'disabled'   => true,
			],
		];
	}

	/**
	 * Pre-headers POST handler. Bound from Menu::dispatch_actions().
	 *
	 * @return void
	 */
	public function dispatch(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified per action.
		$action = isset( $_POST['flinkform_smtp_action'] ) ? sanitize_key( wp_unslash( $_POST['flinkform_smtp_action'] ) ) : '';

		switch ( $action ) {
			case 'save':
				$this->handle_save();
				break;
			case 'send_test':
				$this->handle_test_send();
				break;
		}
	}

	/**
	 * Persist a submitted settings form.
	 *
	 * Extracted from dispatch() so the dispatch() switch reads
	 * symmetrically with handle_test_send() — same nonce check
	 * shape, same redirect-with-notice exit.
	 *
	 * @return void
	 */
	private function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$raw = isset( $_POST['flinkform_smtp'] ) && is_array( $_POST['flinkform_smtp'] )
			? wp_unslash( $_POST['flinkform_smtp'] )
			: [];

		$previous = self::get_settings();
		self::save_settings( $raw, $previous );

		wp_safe_redirect(
			add_query_arg(
				'flinkform_notice',
				rawurlencode( __( 'SMTP settings saved.', 'flinkform-pro' ) ),
				$this->page_url()
			)
		);
		exit;
	}

	/**
	 * Send a one-shot test email to the current admin user.
	 *
	 * Hooks wp_mail_failed temporarily so we can capture the
	 * PHPMailer error string. wp_mail() itself only tells us
	 * "true | false" — the WP_Error passed into wp_mail_failed
	 * carries the actual SMTP-level message (auth failed, host
	 * unreachable, TLS handshake failed, etc.) which is what
	 * operators actually need to debug a misconfig.
	 *
	 * Result is persisted to wp_options (LAST_TEST_OPTION_KEY) so
	 * the status block can show a "Last test" row on subsequent
	 * page loads, AND stashed in a per-user transient so the
	 * post-redirect notice can show the full message inline.
	 *
	 * @return never  Always redirects + exits.
	 */
	private function handle_test_send(): void {
		check_admin_referer( self::TEST_NONCE_ACTION );

		$user = wp_get_current_user();
		if ( ! $user || ! is_email( (string) $user->user_email ) ) {
			$this->finish_test_send( [
				'success'   => false,
				'recipient' => '',
				'error'     => __( 'Could not determine a recipient — your WordPress user has no email address on file.', 'flinkform-pro' ),
			] );
			return;
		}

		$recipient = (string) $user->user_email;
		$subject   = __( 'Flinkform SMTP test email', 'flinkform-pro' );
		$body      = sprintf(
			/* translators: 1: site name, 2: ISO timestamp */
			__( "This is a test email from Flinkform SMTP on %1\$s.\n\nIf you can read this, your SMTP configuration is working — Flinkform form submissions will be delivered via this configured server.\n\nTimestamp: %2\$s\n", 'flinkform-pro' ),
			get_bloginfo( 'name' ),
			gmdate( 'Y-m-d H:i:s' ) . ' UTC'
		);

		// Capture the WP_Error wp_mail() fires on failure. PHPMailer
		// passes the SMTP-level message into the error's data array
		// AND the message string — we collect both so the operator
		// gets the most informative possible single line.
		$captured = '';
		$capture  = static function ( \WP_Error $err ) use ( &$captured ): void {
			$captured = (string) $err->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture );

		$sent = wp_mail( $recipient, $subject, $body );

		remove_action( 'wp_mail_failed', $capture );

		$this->finish_test_send( [
			'success'   => (bool) $sent,
			'recipient' => $recipient,
			'error'     => $sent ? '' : ( '' !== $captured ? $captured : __( 'wp_mail() returned false but no error detail was reported. Check the SMTP host, port, and credentials.', 'flinkform-pro' ) ),
		] );
	}

	/**
	 * Persist + stash + redirect after a test send.
	 *
	 * Split out so handle_test_send() has a single happy-path /
	 * sad-path tail.
	 *
	 * @param array{success: bool, recipient: string, error: string} $result
	 * @return never
	 */
	private function finish_test_send( array $result ): void {
		$record = [
			'timestamp' => time(),
			'success'   => (bool) $result['success'],
			'recipient' => (string) $result['recipient'],
			// Keep the persistent record small — truncate the error
			// to the same 500-char limit we show inline. Long PHPMailer
			// traces are not useful 2 hours later.
			'error'     => mb_substr( (string) $result['error'], 0, 500 ),
		];
		update_option( self::LAST_TEST_OPTION_KEY, $record, false );

		// Stash the full result in a per-user transient so the
		// post-redirect notice can render the untruncated error
		// for the operator who just clicked. 60s lifetime — long
		// enough to survive a slow redirect, short enough that a
		// stale notice never resurfaces.
		set_transient(
			$this->test_transient_key(),
			$record,
			60
		);

		wp_safe_redirect(
			add_query_arg(
				'flinkform_smtp_test_result',
				$record['success'] ? 'success' : 'fail',
				$this->page_url()
			)
		);
		exit;
	}

	/**
	 * Per-user transient key for the freshly-completed test-send
	 * result. Per-user so two operators clicking the test button
	 * simultaneously don't see each other's results.
	 *
	 * @return string
	 */
	private function test_transient_key(): string {
		return 'flinkform_smtp_test_result_' . get_current_user_id();
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Flinkform SMTP settings.', 'flinkform-pro' ) );
		}

		$this->print_inline_styles();

		$settings  = self::get_settings();
		$providers = self::providers();

		?>
		<div class="wrap flinkform-smtp">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'SMTP Settings', 'flinkform-pro' ); ?></h1>
			<hr class="wp-header-end" />

			<?php $this->maybe_print_notice(); ?>
			<?php $this->maybe_print_test_result(); ?>

			<p class="description" style="max-width: 720px;">
				<?php esc_html_e( 'Configure an SMTP server so Flinkform can deliver admin notifications and submitter confirmations through your own mail provider instead of the WordPress default. The status panel below shows whether the configured override is actually in effect for the next wp_mail() call.', 'flinkform-pro' ); ?>
			</p>

			<?php $this->render_status_block(); ?>
			<?php $this->render_test_button_form(); ?>

			<form method="post" action="<?php echo esc_url( $this->page_url() ); ?>" class="flinkform-smtp__form">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="flinkform_smtp_action" value="save" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Enable Flinkform SMTP', 'flinkform-pro' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="flinkform_smtp[enabled]" value="1" <?php checked( (bool) $settings['enabled'] ); ?> />
									<?php esc_html_e( 'Route plugin emails through this SMTP configuration.', 'flinkform-pro' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'When disabled, Flinkform falls back to the default wp_mail() transport (PHP mail or whatever any other SMTP plugin provides).', 'flinkform-pro' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="flinkform-smtp-provider"><?php esc_html_e( 'Provider preset', 'flinkform-pro' ); ?></label>
							</th>
							<td>
								<select id="flinkform-smtp-provider" name="flinkform_smtp[provider]" class="regular-text">
									<?php foreach ( $providers as $key => $provider ) : ?>
										<option
											value="<?php echo esc_attr( $key ); ?>"
											<?php selected( $settings['provider'], $key ); ?>
											<?php disabled( ! empty( $provider['disabled'] ) ); ?>
										>
											<?php echo esc_html( $provider['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p id="flinkform-smtp-provider-help" class="description">
									<?php
									$current_help = $providers[ $settings['provider'] ]['help'] ?? $providers['']['help'];
									echo esc_html( (string) $current_help );
									?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="flinkform-smtp-host"><?php esc_html_e( 'SMTP host', 'flinkform-pro' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="flinkform-smtp-host"
									name="flinkform_smtp[host]"
									value="<?php echo esc_attr( (string) $settings['host'] ); ?>"
									class="regular-text"
									autocomplete="off"
									placeholder="smtp.example.com"
								/>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="flinkform-smtp-port"><?php esc_html_e( 'Port', 'flinkform-pro' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									id="flinkform-smtp-port"
									name="flinkform_smtp[port]"
									value="<?php echo esc_attr( (string) $settings['port'] ); ?>"
									class="small-text"
									min="1"
									max="65535"
								/>
								<p class="description">
									<?php esc_html_e( 'Common values: 587 (STARTTLS), 465 (SSL), 25 (none / legacy).', 'flinkform-pro' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="flinkform-smtp-encryption"><?php esc_html_e( 'Encryption', 'flinkform-pro' ); ?></label>
							</th>
							<td>
								<select id="flinkform-smtp-encryption" name="flinkform_smtp[encryption]">
									<option value="tls"  <?php selected( $settings['encryption'], 'tls' ); ?>><?php esc_html_e( 'TLS (STARTTLS)', 'flinkform-pro' ); ?></option>
									<option value="ssl"  <?php selected( $settings['encryption'], 'ssl' ); ?>><?php esc_html_e( 'SSL', 'flinkform-pro' ); ?></option>
									<option value="none" <?php selected( $settings['encryption'], 'none' ); ?>><?php esc_html_e( 'None (not recommended)', 'flinkform-pro' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Authentication', 'flinkform-pro' ); ?></th>
							<td>
								<label>
									<input type="checkbox" id="flinkform-smtp-auth" name="flinkform_smtp[auth]" value="1" <?php checked( (bool) $settings['auth'] ); ?> />
									<?php esc_html_e( 'My SMTP server requires authentication (almost always yes).', 'flinkform-pro' ); ?>
								</label>
							</td>
						</tr>

						<tr class="flinkform-smtp__auth-row">
							<th scope="row">
								<label for="flinkform-smtp-username"><?php esc_html_e( 'Username', 'flinkform-pro' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="flinkform-smtp-username"
									name="flinkform_smtp[username]"
									value="<?php echo esc_attr( (string) $settings['username'] ); ?>"
									class="regular-text"
									autocomplete="off"
								/>
							</td>
						</tr>

						<tr class="flinkform-smtp__auth-row">
							<th scope="row">
								<label for="flinkform-smtp-password"><?php esc_html_e( 'Password', 'flinkform-pro' ); ?></label>
							</th>
							<td>
								<input
									type="password"
									id="flinkform-smtp-password"
									name="flinkform_smtp[password]"
									value=""
									class="regular-text"
									autocomplete="new-password"
									placeholder="<?php echo esc_attr( '' !== (string) $settings['password'] ? __( '•••••••• (leave empty to keep current)', 'flinkform-pro' ) : __( 'Enter your SMTP password or API key', 'flinkform-pro' ) ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Stored encrypted (AES-256-CBC, key derived from wp_salt). The plaintext password is never sent back to your browser — leave this field empty to keep the existing value when re-saving.', 'flinkform-pro' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="flinkform-smtp-from-email"><?php esc_html_e( 'From email', 'flinkform-pro' ); ?></label>
							</th>
							<td>
								<input
									type="email"
									id="flinkform-smtp-from-email"
									name="flinkform_smtp[from_email]"
									value="<?php echo esc_attr( (string) $settings['from_email'] ); ?>"
									class="regular-text"
									placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"
								/>
								<p class="description">
									<?php esc_html_e( 'Overrides the WordPress default From-address for plugin emails. Leave empty to keep wp_mail’s default.', 'flinkform-pro' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="flinkform-smtp-from-name"><?php esc_html_e( 'From name', 'flinkform-pro' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="flinkform-smtp-from-name"
									name="flinkform_smtp[from_name]"
									value="<?php echo esc_attr( (string) $settings['from_name'] ); ?>"
									class="regular-text"
									placeholder="<?php echo esc_attr( (string) get_bloginfo( 'name' ) ); ?>"
								/>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Send log', 'flinkform-pro' ); ?></th>
							<td>
								<label for="flinkform-smtp-log-enabled">
									<input
										type="checkbox"
										id="flinkform-smtp-log-enabled"
										name="flinkform_smtp[log_enabled]"
										value="1"
										<?php checked( ! empty( $settings['log_enabled'] ) ); ?>
									/>
									<?php esc_html_e( 'Keep a send history (recipient, subject, result - never the mail body)', 'flinkform-pro' ); ?>
								</label>
								<p class="description">
									<?php esc_html_e( 'Shows whether each notification actually left the server, with the exact error when it did not.', 'flinkform-pro' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="flinkform-smtp-log-retention"><?php esc_html_e( 'Log retention (days)', 'flinkform-pro' ); ?></label>
							</th>
							<td>
								<input
									type="number"
									id="flinkform-smtp-log-retention"
									name="flinkform_smtp[log_retention_days]"
									value="<?php echo esc_attr( (string) (int) ( $settings['log_retention_days'] ?? 30 ) ); ?>"
									min="1"
									max="365"
									class="small-text"
								/>
								<p class="description">
									<?php esc_html_e( 'Older entries are deleted automatically (data minimisation).', 'flinkform-pro' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save SMTP settings', 'flinkform-pro' ) ); ?>
			</form>

			<?php $this->render_send_log( $settings ); ?>
		</div>

		<?php $this->render_preset_filler_script( $providers ); ?>
		<?php
	}

	/**
	 * Render the send-log table below the settings form.
	 *
	 * @param array<string, mixed> $settings Current SMTP settings.
	 * @return void
	 */
	private function render_send_log( array $settings ): void {
		if ( empty( $settings['log_enabled'] ) ) {
			return;
		}

		$rows = MailLog::latest();
		?>
		<h2><?php esc_html_e( 'Send Log', 'flinkform-pro' ); ?></h2>
		<?php if ( empty( $rows ) ) : ?>
			<p><?php esc_html_e( 'No emails logged yet. Entries appear here as soon as WordPress sends mail.', 'flinkform-pro' ); ?></p>
			<?php
			return;
		endif;
		?>
		<table class="widefat striped" style="max-width: 1000px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time (UTC)', 'flinkform-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'flinkform-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Recipient', 'flinkform-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Subject', 'flinkform-pro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Error', 'flinkform-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['created_at'] ?? '' ) ); ?></td>
						<td>
							<?php if ( 'failed' === ( $row['status'] ?? '' ) ) : ?>
								<span style="color: #b32d2e; font-weight: 600;"><?php esc_html_e( 'Failed', 'flinkform-pro' ); ?></span>
							<?php else : ?>
								<span style="color: #1a7f37; font-weight: 600;"><?php esc_html_e( 'Sent', 'flinkform-pro' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( (string) ( $row['recipients'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['subject'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['error'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the live diagnostic status block at the top of the
	 * settings page.
	 *
	 * The block answers — at a glance, without leaving the page —
	 * the five questions an operator asks when their SMTP override
	 * doesn't seem to be working:
	 *
	 *   1. Is the Transport class even loaded? (Defends against
	 *      partial FTP uploads where SmtpPage.php is newer than
	 *      Smtp/Transport.php.)
	 *   2. Is the master toggle on?
	 *   3. Is the stored config usable (host + port + decryptable
	 *      password)?
	 *   4. Is a rival SMTP plugin active?
	 *   5. Net answer: will the next wp_mail() route via Flinkform?
	 *
	 * Status is read fresh on every render — no caching across
	 * requests, no AJAX. Settings page is admin-only and rarely
	 * loaded; the cost is trivial.
	 *
	 * @return void
	 */
	private function render_status_block(): void {
		$transport_loaded = class_exists( Transport::class );

		// Partial-upload defense: if Transport isn't loadable we
		// can't call get_status() without a fatal. Show a single
		// red row + bail.
		if ( ! $transport_loaded ) {
			?>
			<div class="flinkform-smtp__status flinkform-smtp__status--effective-false">
				<h2><?php esc_html_e( 'SMTP Status', 'flinkform-pro' ); ?></h2>
				<table>
					<tbody>
						<tr>
							<td class="flinkform-smtp__status-label"><?php esc_html_e( 'Transport module', 'flinkform-pro' ); ?></td>
							<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--bad"><?php esc_html_e( 'Missing', 'flinkform-pro' ); ?></span></td>
							<td class="flinkform-smtp__status-detail">
								<?php esc_html_e( 'The FlinkformPro\\Smtp\\Transport class could not be loaded. Reinstall Flinkform Pro to fix this.', 'flinkform-pro' ); ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<?php
			return;
		}

		$status = Transport::get_status();
		$wrapper_modifier = $status['effective'] ? 'effective-true' : 'effective-false';
		?>
		<div class="flinkform-smtp__status flinkform-smtp__status--<?php echo esc_attr( $wrapper_modifier ); ?>">
			<h2><?php esc_html_e( 'SMTP Status', 'flinkform-pro' ); ?></h2>
			<table>
				<tbody>
					<tr>
						<td class="flinkform-smtp__status-label"><?php esc_html_e( 'Transport module', 'flinkform-pro' ); ?></td>
						<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--ok"><?php esc_html_e( 'Loaded', 'flinkform-pro' ); ?></span></td>
						<td class="flinkform-smtp__status-detail">
							<?php esc_html_e( 'FlinkformPro\\Smtp\\Transport is active on this request.', 'flinkform-pro' ); ?>
						</td>
					</tr>

					<tr>
						<td class="flinkform-smtp__status-label"><?php esc_html_e( 'Master toggle', 'flinkform-pro' ); ?></td>
						<?php if ( $status['enabled'] ) : ?>
							<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--ok"><?php esc_html_e( 'Enabled', 'flinkform-pro' ); ?></span></td>
							<td class="flinkform-smtp__status-detail">
								<?php esc_html_e( '"Enable Flinkform SMTP" is on.', 'flinkform-pro' ); ?>
							</td>
						<?php else : ?>
							<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--bad"><?php esc_html_e( 'Disabled', 'flinkform-pro' ); ?></span></td>
							<td class="flinkform-smtp__status-detail">
								<?php esc_html_e( '"Enable Flinkform SMTP" is off — wp_mail() falls back to the WordPress default transport.', 'flinkform-pro' ); ?>
							</td>
						<?php endif; ?>
					</tr>

					<tr>
						<td class="flinkform-smtp__status-label"><?php esc_html_e( 'Configuration', 'flinkform-pro' ); ?></td>
						<?php if ( $status['configured'] ) : ?>
							<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--ok"><?php esc_html_e( 'Complete', 'flinkform-pro' ); ?></span></td>
							<td class="flinkform-smtp__status-detail">
								<?php
								$settings = self::get_settings();
								echo esc_html(
									sprintf(
										/* translators: 1: host, 2: port, 3: encryption mode (TLS / SSL / none) */
										__( '%1$s:%2$d (%3$s)', 'flinkform-pro' ),
										(string) $settings['host'],
										(int) $settings['port'],
										strtoupper( (string) $settings['encryption'] )
									)
								);
								?>
							</td>
						<?php else : ?>
							<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--bad"><?php esc_html_e( 'Incomplete', 'flinkform-pro' ); ?></span></td>
							<td class="flinkform-smtp__status-detail"><?php echo esc_html( (string) $status['configured_notes'] ); ?></td>
						<?php endif; ?>
					</tr>

					<tr>
						<td class="flinkform-smtp__status-label"><?php esc_html_e( 'Plugin conflict', 'flinkform-pro' ); ?></td>
						<?php if ( false === $status['conflict'] ) : ?>
							<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--neutral"><?php esc_html_e( 'None', 'flinkform-pro' ); ?></span></td>
							<td class="flinkform-smtp__status-detail">
								<?php esc_html_e( 'No competing SMTP plugin detected (WP Mail SMTP / FluentSMTP / Easy WP SMTP / Post SMTP).', 'flinkform-pro' ); ?>
							</td>
						<?php else : ?>
							<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--warn"><?php esc_html_e( 'Detected', 'flinkform-pro' ); ?></span></td>
							<td class="flinkform-smtp__status-detail">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: name of the conflicting plugin */
										__( '"%s" is active — Flinkform SMTP self-disabled to avoid a double configuration. Deactivate the other plugin to let Flinkform SMTP take over.', 'flinkform-pro' ),
										(string) $status['conflict']
									)
								);
								?>
							</td>
						<?php endif; ?>
					</tr>

					<tr>
						<td class="flinkform-smtp__status-label"><strong><?php esc_html_e( 'Effective', 'flinkform-pro' ); ?></strong></td>
						<?php if ( $status['effective'] ) : ?>
							<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--ok"><?php esc_html_e( 'Active', 'flinkform-pro' ); ?></span></td>
							<td class="flinkform-smtp__status-detail">
								<strong><?php esc_html_e( 'The next wp_mail() call WILL route through this SMTP configuration.', 'flinkform-pro' ); ?></strong>
							</td>
						<?php else : ?>
							<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--bad"><?php esc_html_e( 'Inactive', 'flinkform-pro' ); ?></span></td>
							<td class="flinkform-smtp__status-detail">
								<strong><?php esc_html_e( 'wp_mail() will use the WordPress default transport (not your configured SMTP).', 'flinkform-pro' ); ?></strong>
								<?php esc_html_e( 'Resolve the rows marked above to fix this.', 'flinkform-pro' ); ?>
							</td>
						<?php endif; ?>
					</tr>

					<?php $this->render_last_test_row(); ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render the "Last test" row of the status block.
	 *
	 * Reads the LAST_TEST_OPTION_KEY record handle_test_send() writes.
	 * Three states:
	 *   - never tested → grey "Never" + hint to use the button below
	 *   - last success → green "OK" + recipient + relative timestamp
	 *   - last fail    → red "Failed" + truncated error + relative timestamp
	 *
	 * Separate method (not inline in the status table) so the
	 * timestamp formatting and the badge-modifier logic stay in one
	 * place instead of cluttering the status-table markup.
	 *
	 * @return void
	 */
	private function render_last_test_row(): void {
		$record = get_option( self::LAST_TEST_OPTION_KEY, [] );

		if ( ! is_array( $record ) || empty( $record['timestamp'] ) ) {
			?>
			<tr>
				<td class="flinkform-smtp__status-label"><?php esc_html_e( 'Last test', 'flinkform-pro' ); ?></td>
				<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--neutral"><?php esc_html_e( 'Never', 'flinkform-pro' ); ?></span></td>
				<td class="flinkform-smtp__status-detail">
					<?php esc_html_e( 'No test email has been sent yet. Use the button below to verify your configuration end-to-end.', 'flinkform-pro' ); ?>
				</td>
			</tr>
			<?php
			return;
		}

		$timestamp = (int) $record['timestamp'];
		$relative  = human_time_diff( $timestamp, time() );
		$absolute  = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
		$success   = ! empty( $record['success'] );
		$recipient = (string) ( $record['recipient'] ?? '' );
		$error     = (string) ( $record['error'] ?? '' );

		?>
		<tr>
			<td class="flinkform-smtp__status-label"><?php esc_html_e( 'Last test', 'flinkform-pro' ); ?></td>
			<?php if ( $success ) : ?>
				<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--ok"><?php esc_html_e( 'OK', 'flinkform-pro' ); ?></span></td>
				<td class="flinkform-smtp__status-detail" title="<?php echo esc_attr( $absolute ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: recipient, 2: relative time like "3 minutes" */
							__( 'Sent to %1$s, %2$s ago.', 'flinkform-pro' ),
							$recipient,
							$relative
						)
					);
					?>
				</td>
			<?php else : ?>
				<td><span class="flinkform-smtp__status-badge flinkform-smtp__status-badge--bad"><?php esc_html_e( 'Failed', 'flinkform-pro' ); ?></span></td>
				<td class="flinkform-smtp__status-detail" title="<?php echo esc_attr( $absolute ); ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: relative time like "3 minutes" */
							__( 'Failed %s ago.', 'flinkform-pro' ),
							$relative
						)
					);
					?>
					<?php if ( '' !== $error ) : ?>
						<br>
						<code class="flinkform-smtp__status-error"><?php echo esc_html( $error ); ?></code>
					<?php endif; ?>
				</td>
			<?php endif; ?>
		</tr>
		<?php
	}

	/**
	 * Render the "Send test email" button as its own POST form
	 * beneath the diagnostic status block.
	 *
	 * Own form (not a second submit inside the settings form) so the
	 * test button never accidentally saves stale settings — it always
	 * acts on whatever was last saved to wp_options. The button is
	 * always rendered so an operator can still test from a known-
	 * inactive state ("see, this falls back to PHP mail") — when SMTP
	 * is inactive we show a yellow notice next to the button so the
	 * operator knows the test won't go through the configured server.
	 *
	 * @return void
	 */
	private function render_test_button_form(): void {
		$transport_loaded = class_exists( Transport::class );
		$status           = $transport_loaded ? Transport::get_status() : [ 'effective' => false ];
		$user             = wp_get_current_user();
		$recipient        = $user ? (string) $user->user_email : '';
		?>
		<form method="post" action="<?php echo esc_url( $this->page_url() ); ?>" class="flinkform-smtp__test-form">
			<?php wp_nonce_field( self::TEST_NONCE_ACTION ); ?>
			<input type="hidden" name="flinkform_smtp_action" value="send_test" />

			<button type="submit" class="button button-secondary">
				<?php esc_html_e( 'Send test email', 'flinkform-pro' ); ?>
			</button>

			<?php if ( '' !== $recipient ) : ?>
				<span class="flinkform-smtp__test-recipient">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: recipient email address */
							__( 'Recipient: %s (your WordPress profile email).', 'flinkform-pro' ),
							$recipient
						)
					);
					?>
				</span>
			<?php endif; ?>

			<?php if ( ! $status['effective'] ) : ?>
				<p class="flinkform-smtp__test-warning description">
					<?php esc_html_e( 'SMTP override is currently inactive (see status panel above). The test will fall through to the WordPress default transport — useful to verify your fallback path, but it does NOT confirm your SMTP credentials work.', 'flinkform-pro' ); ?>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * After a test-send redirect, render an inline notice with the
	 * full result (untruncated error on failure) and clear the
	 * transient so a page refresh doesn't replay it.
	 *
	 * Query-arg `flinkform_smtp_test_result=success|fail` is the
	 * trigger — the transient holds the actual payload. Two-step
	 * pattern (query arg + transient) survives the WP "remove
	 * trailing args on next page load" behaviour while still
	 * keeping the notice ephemeral.
	 *
	 * @return void
	 */
	private function maybe_print_test_result(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flash signal.
		if ( ! isset( $_GET['flinkform_smtp_test_result'] ) ) {
			return;
		}

		$record = get_transient( $this->test_transient_key() );
		delete_transient( $this->test_transient_key() );

		if ( ! is_array( $record ) ) {
			return;
		}

		if ( ! empty( $record['success'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Test email sent.', 'flinkform-pro' ),
				esc_html(
					sprintf(
						/* translators: %s: recipient email address */
						__( 'Delivered to %s via the configured SMTP path. Check your inbox (or Mailtrap / sandbox inbox if that\'s where this server delivers).', 'flinkform-pro' ),
						(string) $record['recipient']
					)
				)
			);
			return;
		}

		printf(
			'<div class="notice notice-error is-dismissible"><p><strong>%1$s</strong> %2$s</p>%3$s</div>',
			esc_html__( 'Test email failed.', 'flinkform-pro' ),
			esc_html(
				sprintf(
					/* translators: %s: recipient email address */
					__( 'wp_mail() returned false when attempting to deliver to %s.', 'flinkform-pro' ),
					(string) $record['recipient']
				)
			),
			'' !== (string) $record['error']
				? '<pre class="flinkform-smtp__test-error">' . esc_html( (string) $record['error'] ) . '</pre>'
				: ''
		);
	}

	/**
	 * Tiny vanilla-JS handler that fills host/port/encryption when
	 * the operator picks a provider preset, and toggles the
	 * username/password row visibility based on the auth checkbox.
	 *
	 * No build pipeline involvement on purpose — this is one
	 * settings page, ~40 lines of JS, inline is the right cost.
	 *
	 * @param array<string, array<string, mixed>> $providers
	 * @return void
	 */
	private function render_preset_filler_script( array $providers ): void {
		// Strip the (already-translated) help text to plain strings
		// for the JS payload — wp_json_encode will escape correctly.
		$payload = [];
		foreach ( $providers as $key => $provider ) {
			$payload[ $key ] = [
				'host'       => (string) ( $provider['host'] ?? '' ),
				'port'       => (int) ( $provider['port'] ?? 0 ),
				'encryption' => (string) ( $provider['encryption'] ?? '' ),
				'help'       => (string) ( $provider['help'] ?? '' ),
				'disabled'   => ! empty( $provider['disabled'] ),
			];
		}
		?>
		<script>
		(function () {
			var presets = <?php echo wp_json_encode( $payload ); ?>;

			var providerSelect = document.getElementById('flinkform-smtp-provider');
			var hostInput      = document.getElementById('flinkform-smtp-host');
			var portInput      = document.getElementById('flinkform-smtp-port');
			var encSelect      = document.getElementById('flinkform-smtp-encryption');
			var helpEl         = document.getElementById('flinkform-smtp-provider-help');
			var authCheckbox   = document.getElementById('flinkform-smtp-auth');
			var authRows       = document.querySelectorAll('.flinkform-smtp__auth-row');

			if (providerSelect) {
				providerSelect.addEventListener('change', function () {
					var preset = presets[providerSelect.value];
					if (!preset || preset.disabled) {
						return;
					}
					// Only overwrite the host/port/encryption when we
					// have a preset value — picking "Custom SMTP server"
					// (which has host '') must not wipe what the
					// operator already typed.
					if (preset.host && hostInput) {
						hostInput.value = preset.host;
					}
					if (preset.port && portInput) {
						portInput.value = preset.port;
					}
					if (preset.encryption && encSelect) {
						encSelect.value = preset.encryption;
					}
					if (helpEl) {
						helpEl.textContent = preset.help || '';
					}
				});
			}

			function syncAuthVisibility() {
				if (!authCheckbox) {
					return;
				}
				var visible = authCheckbox.checked;
				authRows.forEach(function (row) {
					row.style.display = visible ? '' : 'none';
				});
			}

			if (authCheckbox) {
				authCheckbox.addEventListener('change', syncAuthVisibility);
				syncAuthVisibility();
			}
		})();
		</script>
		<?php
	}

	/**
	 * Constrain the port to the TCP range.
	 *
	 * @param int $port
	 * @return int
	 */
	private static function sanitize_port( int $port ): int {
		if ( $port < 1 ) {
			return 587;
		}
		if ( $port > 65535 ) {
			return 65535;
		}
		return $port;
	}

	/**
	 * Whitelist the encryption mode.
	 *
	 * @param string $encryption
	 * @return string
	 */
	private static function sanitize_encryption( string $encryption ): string {
		$encryption = strtolower( $encryption );
		return in_array( $encryption, [ 'none', 'ssl', 'tls' ], true ) ? $encryption : 'tls';
	}

	/**
	 * Validate the provider key against the preset registry; unknown
	 * keys fall back to '' (custom) so a tampered POST cannot persist
	 * arbitrary strings.
	 *
	 * @param string $provider
	 * @return string
	 */
	private static function sanitize_provider_key( string $provider ): string {
		$provider = sanitize_key( $provider );
		$known    = array_keys( self::providers() );
		return in_array( $provider, $known, true ) ? $provider : '';
	}

	/**
	 * Print a one-shot success notice if the URL carries one.
	 *
	 * @return void
	 */
	private function maybe_print_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flash message.
		$notice = isset( $_GET['flinkform_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['flinkform_notice'] ) ) : '';
		if ( '' === $notice ) {
			return;
		}
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $notice ) );
	}

	/**
	 * Canonical URL of this page.
	 *
	 * @return string
	 */
	private function page_url(): string {
		return add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) );
	}

	/**
	 * Inline CSS. Tiny — one extra style block beats enqueueing a
	 * dedicated admin stylesheet for a single page.
	 *
	 * @return void
	 */
	private function print_inline_styles(): void {
		?>
		<style>
			.flinkform-smtp__form .form-table th { width: 220px; }
			.flinkform-smtp__form input[type="text"],
			.flinkform-smtp__form input[type="email"],
			.flinkform-smtp__form input[type="password"],
			.flinkform-smtp__form select { max-width: 420px; }
			/* Keep description text in the right column from running
			   past the form's visual width on wide monitors. */
			.flinkform-smtp__form .form-table td .description { max-width: 560px; }

			/* Diagnostic status block. Border-left colour tracks the
			   "effective" boolean: green = SMTP override will fire on
			   the next wp_mail(), red = something is blocking it. */
			.flinkform-smtp .flinkform-smtp__status {
				margin: 20px 0 24px;
				padding: 14px 20px 16px;
				background: #f6f7f7;
				border-left: 4px solid #2271b1;
				max-width: 920px;
			}
			.flinkform-smtp .flinkform-smtp__status--effective-true  { border-left-color: #00a32a; }
			.flinkform-smtp .flinkform-smtp__status--effective-false { border-left-color: #d63638; }
			.flinkform-smtp .flinkform-smtp__status h2 {
				margin: 0 0 10px;
				font-size: 12px;
				text-transform: uppercase;
				letter-spacing: 0.06em;
				color: #1d2327;
			}
			.flinkform-smtp .flinkform-smtp__status table {
				border-collapse: collapse;
				width: 100%;
			}
			.flinkform-smtp .flinkform-smtp__status td {
				padding: 4px 12px 4px 0;
				vertical-align: middle;
				border: 0;
			}
			.flinkform-smtp .flinkform-smtp__status td:last-child { padding-right: 0; }
			.flinkform-smtp .flinkform-smtp__status-label {
				font-weight: 600;
				width: 150px;
				color: #1d2327;
				white-space: nowrap;
			}
			.flinkform-smtp .flinkform-smtp__status-badge {
				display: inline-block;
				padding: 2px 10px;
				border-radius: 9999px;
				font-size: 11px;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: 0.04em;
				white-space: nowrap;
			}
			.flinkform-smtp .flinkform-smtp__status-badge--ok      { background: #d1e7d8; color: #1a6c2f; }
			.flinkform-smtp .flinkform-smtp__status-badge--bad     { background: #fce4e7; color: #8e2933; }
			.flinkform-smtp .flinkform-smtp__status-badge--warn    { background: #fcf0d4; color: #7c4910; }
			.flinkform-smtp .flinkform-smtp__status-badge--neutral { background: #f0f0f1; color: #50575e; }
			.flinkform-smtp .flinkform-smtp__status-detail {
				color: #50575e;
				font-size: 13px;
				line-height: 1.5;
			}
			.flinkform-smtp .flinkform-smtp__status-error {
				display: inline-block;
				margin-top: 4px;
				padding: 2px 6px;
				background: #fce4e7;
				color: #8e2933;
				font-size: 12px;
				border-radius: 3px;
				max-width: 100%;
				overflow-wrap: anywhere;
			}

			/* Test-send button form. Lives between the status block
			   and the settings form — own POST endpoint so it never
			   accidentally saves form values. */
			.flinkform-smtp .flinkform-smtp__test-form {
				margin: 0 0 28px;
				padding: 14px 20px 16px;
				background: #f6f7f7;
				border-left: 4px solid #c3c4c7;
				max-width: 920px;
			}
			.flinkform-smtp .flinkform-smtp__test-recipient {
				margin-left: 10px;
				color: #50575e;
				font-size: 13px;
			}
			.flinkform-smtp .flinkform-smtp__test-warning {
				margin: 10px 0 0;
				padding: 8px 12px;
				background: #fcf0d4;
				color: #7c4910;
				border-left: 3px solid #dba617;
				max-width: 720px;
			}
			.flinkform-smtp__test-error {
				margin: 8px 0 0;
				padding: 10px 12px;
				background: #f6f7f7;
				color: #1d2327;
				font-size: 12px;
				line-height: 1.5;
				white-space: pre-wrap;
				word-break: break-word;
				max-height: 200px;
				overflow: auto;
				border-radius: 3px;
			}
		</style>
		<?php
	}
}
