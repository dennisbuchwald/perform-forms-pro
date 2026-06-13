<?php
/**
 * Newsletter integrations module (Flinkform Pro).
 *
 * Per-form newsletter signup for Brevo, Mailchimp and CleverReach:
 *
 *   - Global credentials live on the Flinkform → Newsletter settings page;
 *     API keys/secrets are AES-encrypted at rest (Settings\Secret, same
 *     scheme as the SMTP password).
 *   - Per-form config is a `newsletter` block attribute (provider, list id,
 *     field mapping, double opt-in), edited via the Pro inspector panel.
 *   - CONSENT IS MANDATORY: a submission is only forwarded when the form
 *     author mapped a consent field AND the visitor actually checked it.
 *     No mapping → no signup. This is deliberate (GDPR Art. 7) and not
 *     overridable.
 *   - Dispatch is asynchronous via a single cron event so the visitor's
 *     submit never waits on a third-party API; one automatic retry on
 *     transient failures. The last result per provider is shown on the
 *     settings page for diagnostics.
 *
 * @package FlinkformPro
 * @since 0.4.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Newsletter;

use Flinkform\Admin\Menu;
use FlinkformPro\Newsletter\Providers\Brevo;
use FlinkformPro\Newsletter\Providers\CleverReach;
use FlinkformPro\Newsletter\Providers\Mailchimp;
use FlinkformPro\Newsletter\Providers\ProviderInterface;
use FlinkformPro\Settings\Secret;

defined( 'ABSPATH' ) || exit;

/**
 * Wires newsletter signups into the submission pipeline.
 */
final class Module {

	public const SLUG               = 'flinkform-newsletter';
	public const OPTION_KEY         = 'flinkform_newsletter_settings';
	public const LAST_RESULT_OPTION = 'flinkform_newsletter_last_result';
	private const CRON_HOOK         = 'flinkform_newsletter_dispatch';
	private const MAX_ATTEMPTS      = 2;

	/**
	 * Encrypted option keys (stored as Secret ciphers).
	 */
	private const SECRET_KEYS = [ 'brevo_api_key', 'mailchimp_api_key', 'cleverreach_client_secret' ];

	/**
	 * Register the WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'register_block_type_args', [ $this, 'add_attribute' ], 10, 2 );
		add_action( 'flinkform_after_submission', [ $this, 'maybe_queue' ], 10, 4 );
		add_action( self::CRON_HOOK, [ $this, 'dispatch' ], 10, 1 );

		add_action( 'admin_menu', [ $this, 'register_page' ], 20 );
		add_action( 'admin_init', [ $this, 'handle_save' ] );
	}

	/**
	 * Registered providers.
	 *
	 * @return array<string, class-string<ProviderInterface>>
	 */
	public static function providers(): array {
		return [
			Brevo::key()       => Brevo::class,
			Mailchimp::key()   => Mailchimp::class,
			CleverReach::key() => CleverReach::class,
		];
	}

	/**
	 * Dynamically add the `newsletter` attribute to the form block.
	 *
	 * @param array<string, mixed> $args       Block type arguments.
	 * @param string               $block_type Block type name.
	 * @return array<string, mixed>
	 */
	public function add_attribute( array $args, string $block_type ): array {
		if ( 'flinkform/form' !== $block_type ) {
			return $args;
		}

		$args['attributes']['newsletter'] = [
			'type'    => 'object',
			'default' => [],
		];

		return $args;
	}

	/**
	 * After-submission listener: validate config + consent, then queue the
	 * actual API call as a cron single event.
	 *
	 * @param int                  $submission_id
	 * @param string               $form_id
	 * @param array<string, mixed> $clean      Validated values keyed by field name.
	 * @param array<string, mixed> $definition Located form definition.
	 * @return void
	 */
	public function maybe_queue( $submission_id, $form_id, $clean, $definition ): void {
		if ( ! is_array( $clean ) || ! is_array( $definition ) ) {
			return;
		}

		$attrs  = isset( $definition['attributes'] ) && is_array( $definition['attributes'] ) ? $definition['attributes'] : [];
		$config = isset( $attrs['newsletter'] ) && is_array( $attrs['newsletter'] ) ? $attrs['newsletter'] : [];

		if ( empty( $config['enabled'] ) ) {
			return;
		}

		$provider_key = sanitize_key( (string) ( $config['provider'] ?? '' ) );
		if ( ! isset( self::providers()[ $provider_key ] ) ) {
			return;
		}

		// MANDATORY consent gate — see the class docblock.
		$consent_field = sanitize_text_field( (string) ( $config['consentField'] ?? '' ) );
		if ( '' === $consent_field ) {
			return;
		}
		$consent_value = $clean[ $consent_field ] ?? '';
		if ( '' === trim( (string) ( is_array( $consent_value ) ? implode( '', $consent_value ) : $consent_value ) ) ) {
			return; // Not checked — the visitor did not opt in.
		}

		$email_field = sanitize_text_field( (string) ( $config['emailField'] ?? '' ) );
		$email       = sanitize_email( (string) ( $clean[ $email_field ] ?? '' ) );
		if ( '' === $email_field || ! is_email( $email ) ) {
			return;
		}

		$attributes = [];
		foreach ( [ 'firstNameField' => 'first_name', 'lastNameField' => 'last_name' ] as $cfg_key => $attr_key ) {
			$field = sanitize_text_field( (string) ( $config[ $cfg_key ] ?? '' ) );
			$value = '' !== $field ? sanitize_text_field( (string) ( $clean[ $field ] ?? '' ) ) : '';
			if ( '' !== $value ) {
				$attributes[ $attr_key ] = $value;
			}
		}

		$payload = [
			'provider'   => $provider_key,
			'email'      => $email,
			'attributes' => $attributes,
			'config'     => [
				'list_id'       => sanitize_text_field( (string) ( $config['listId'] ?? '' ) ),
				'double_opt_in' => ! empty( $config['doubleOptIn'] ),
			],
			'attempt'    => 1,
		];

		// Subscriber PII (email, name) must NOT travel through the cron
		// args — wp_schedule_single_event serialises them into the
		// wp_options('cron') row in plaintext until the event fires. Stash
		// the payload in a transient under an opaque key and pass only that
		// key through cron (mirrors how the webhook queue references rows
		// by id rather than inlining the data).
		$ticket = 'flinkform_nl_' . wp_generate_password( 20, false, false );
		set_transient( $ticket, $payload, HOUR_IN_SECONDS );

		wp_schedule_single_event( time(), self::CRON_HOOK, [ $ticket ] );
	}

	/**
	 * Cron worker: call the provider, record the result, retry once on
	 * transient failures.
	 *
	 * @param string $ticket Opaque transient key holding the queued payload.
	 * @return void
	 */
	public function dispatch( $ticket ): void {
		$ticket = (string) $ticket;
		if ( '' === $ticket || 0 !== strpos( $ticket, 'flinkform_nl_' ) ) {
			return;
		}

		$payload = get_transient( $ticket );
		if ( ! is_array( $payload ) ) {
			return; // Already processed, or expired — nothing to do.
		}

		$provider_key = (string) ( $payload['provider'] ?? '' );
		$providers    = self::providers();
		if ( ! isset( $providers[ $provider_key ] ) ) {
			delete_transient( $ticket );
			return;
		}

		$settings = self::get_settings( true );
		$class    = $providers[ $provider_key ];
		if ( ! $class::is_configured( $settings ) ) {
			delete_transient( $ticket );
			$this->record_result( $provider_key, false, 'Provider not configured (missing credentials).' );
			return;
		}

		$provider = new $class();
		$result   = $provider->subscribe(
			(string) ( $payload['email'] ?? '' ),
			is_array( $payload['attributes'] ?? null ) ? $payload['attributes'] : [],
			is_array( $payload['config'] ?? null ) ? $payload['config'] : [],
			$settings
		);

		if ( true === $result ) {
			delete_transient( $ticket );
			$this->record_result( $provider_key, true, 'OK' );
			return;
		}

		$message = $result instanceof \WP_Error ? $result->get_error_message() : 'Unknown error';
		$attempt = (int) ( $payload['attempt'] ?? 1 );

		if ( $result instanceof \WP_Error && 'transient' === $result->get_error_code() && $attempt < self::MAX_ATTEMPTS ) {
			$payload['attempt'] = $attempt + 1;
			// Refresh the stash (incremented attempt) and re-arm cron with
			// the same opaque ticket — still no PII in the cron args.
			set_transient( $ticket, $payload, HOUR_IN_SECONDS );
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_HOOK, [ $ticket ] );
			$this->record_result( $provider_key, false, $message . ' (retry scheduled)' );
			return;
		}

		delete_transient( $ticket );
		$this->record_result( $provider_key, false, $message );
	}

	/**
	 * Persist the last dispatch result per provider for the settings page.
	 *
	 * @param string $provider
	 * @param bool   $ok
	 * @param string $message
	 * @return void
	 */
	private function record_result( string $provider, bool $ok, string $message ): void {
		$results = get_option( self::LAST_RESULT_OPTION, [] );
		if ( ! is_array( $results ) ) {
			$results = [];
		}
		$results[ $provider ] = [
			'ok'      => $ok,
			'message' => mb_substr( $message, 0, 300 ),
			'time'    => current_time( 'mysql', true ),
		];
		update_option( self::LAST_RESULT_OPTION, $results, false );
	}

	/**
	 * Read the global settings; secrets stay encrypted unless requested.
	 *
	 * @param bool $decrypt Decrypt the secret values.
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $decrypt = false ): array {
		$stored = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$defaults = [
			'brevo_api_key'             => '',
			'mailchimp_api_key'         => '',
			'cleverreach_client_id'     => '',
			'cleverreach_client_secret' => '',
			'cleverreach_form_id'       => '',
		];
		$settings = array_merge( $defaults, $stored );

		if ( $decrypt ) {
			foreach ( self::SECRET_KEYS as $key ) {
				$settings[ $key ] = '' !== (string) $settings[ $key ] ? Secret::decrypt( (string) $settings[ $key ] ) : '';
			}
		}

		return $settings;
	}

	/**
	 * Add the Newsletter submenu under the Flinkform menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Newsletter', 'flinkform-pro' ),
			__( 'Newsletter', 'flinkform-pro' ),
			Menu::CAPABILITY,
			self::SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Save handler (admin_init, pre-headers).
	 *
	 * @return void
	 */
	public function handle_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page identifier only; the POST below is nonce-checked.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $page || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( 'flinkform_newsletter_save' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key is sanitised individually below.
		$input    = isset( $_POST['flinkform_newsletter'] ) && is_array( $_POST['flinkform_newsletter'] ) ? wp_unslash( $_POST['flinkform_newsletter'] ) : [];
		$previous = self::get_settings();

		$next = [
			'cleverreach_client_id' => sanitize_text_field( (string) ( $input['cleverreach_client_id'] ?? '' ) ),
			'cleverreach_form_id'   => sanitize_text_field( (string) ( $input['cleverreach_form_id'] ?? '' ) ),
		];

		// Secrets: empty submitted value keeps the stored cipher (same
		// contract as the SMTP password field).
		foreach ( self::SECRET_KEYS as $key ) {
			$submitted    = trim( (string) ( $input[ $key ] ?? '' ) );
			$next[ $key ] = '' === $submitted ? (string) ( $previous[ $key ] ?? '' ) : Secret::encrypt( sanitize_text_field( $submitted ) );
		}

		update_option( self::OPTION_KEY, $next, false );
		add_action(
			'admin_notices',
			static function (): void {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Newsletter settings saved.', 'flinkform-pro' ) . '</p></div>';
			}
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		$settings = self::get_settings();
		$results  = get_option( self::LAST_RESULT_OPTION, [] );
		if ( ! is_array( $results ) ) {
			$results = [];
		}

		$secret_placeholder = static function ( string $cipher ): string {
			return '' !== $cipher ? __( 'Saved — enter a new value to replace', 'flinkform-pro' ) : '';
		};
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Newsletter Integrations', 'flinkform-pro' ); ?></h1>
			<p>
				<?php esc_html_e( 'Connect your newsletter provider(s) here, then enable the signup per form in the editor (Form block → Newsletter panel). A signup is only ever sent when the visitor ticked the consent field you map there.', 'flinkform-pro' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'flinkform_newsletter_save' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row" colspan="2"><h2 style="margin:0;">Brevo</h2></th>
						</tr>
						<tr>
							<th scope="row"><label for="ff-nl-brevo-key"><?php esc_html_e( 'API key', 'flinkform-pro' ); ?></label></th>
							<td>
								<input type="password" id="ff-nl-brevo-key" name="flinkform_newsletter[brevo_api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $secret_placeholder( (string) $settings['brevo_api_key'] ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Brevo → Settings → SMTP & API → API keys (v3).', 'flinkform-pro' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row" colspan="2"><h2 style="margin:0;">Mailchimp</h2></th>
						</tr>
						<tr>
							<th scope="row"><label for="ff-nl-mc-key"><?php esc_html_e( 'API key', 'flinkform-pro' ); ?></label></th>
							<td>
								<input type="password" id="ff-nl-mc-key" name="flinkform_newsletter[mailchimp_api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $secret_placeholder( (string) $settings['mailchimp_api_key'] ) ); ?>" />
								<p class="description"><?php esc_html_e( 'Mailchimp → Account → Extras → API keys. Includes the datacenter suffix (…-us21).', 'flinkform-pro' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row" colspan="2"><h2 style="margin:0;">CleverReach</h2></th>
						</tr>
						<tr>
							<th scope="row"><label for="ff-nl-cr-id"><?php esc_html_e( 'OAuth Client ID', 'flinkform-pro' ); ?></label></th>
							<td>
								<input type="text" id="ff-nl-cr-id" name="flinkform_newsletter[cleverreach_client_id]" value="<?php echo esc_attr( (string) $settings['cleverreach_client_id'] ); ?>" class="regular-text" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ff-nl-cr-secret"><?php esc_html_e( 'OAuth Client Secret', 'flinkform-pro' ); ?></label></th>
							<td>
								<input type="password" id="ff-nl-cr-secret" name="flinkform_newsletter[cleverreach_client_secret]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $secret_placeholder( (string) $settings['cleverreach_client_secret'] ) ); ?>" />
								<p class="description"><?php esc_html_e( 'CleverReach → My Account → Extras → REST API → OAuth2 app.', 'flinkform-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="ff-nl-cr-form"><?php esc_html_e( 'DOI form ID (optional)', 'flinkform-pro' ); ?></label></th>
							<td>
								<input type="text" id="ff-nl-cr-form" name="flinkform_newsletter[cleverreach_form_id]" value="<?php echo esc_attr( (string) $settings['cleverreach_form_id'] ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Required for double opt-in: the CleverReach form whose activation email is sent to new subscribers.', 'flinkform-pro' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Save newsletter settings', 'flinkform-pro' ) ); ?>
			</form>

			<?php if ( ! empty( $results ) ) : ?>
				<h2><?php esc_html_e( 'Last dispatch per provider', 'flinkform-pro' ); ?></h2>
				<table class="widefat striped" style="max-width: 800px;">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Provider', 'flinkform-pro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Time (UTC)', 'flinkform-pro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Result', 'flinkform-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $results as $provider => $entry ) : ?>
							<tr>
								<td><?php echo esc_html( ucfirst( (string) $provider ) ); ?></td>
								<td><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></td>
								<td>
									<?php if ( ! empty( $entry['ok'] ) ) : ?>
										<span style="color:#1a7f37;font-weight:600;"><?php esc_html_e( 'OK', 'flinkform-pro' ); ?></span>
									<?php else : ?>
										<span style="color:#b32d2e;font-weight:600;"><?php echo esc_html( (string) ( $entry['message'] ?? '' ) ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
