<?php
/**
 * CleverReach newsletter provider (DACH favourite).
 *
 * OAuth2 client-credentials flow against rest.cleverreach.com; the access
 * token is cached in a transient until shortly before expiry. Subscribes
 * via POST /v3/groups.json/{group}/receivers. With double opt-in enabled
 * and a DOI form id configured, the official activation email is triggered
 * via /v3/forms.json/{form}/send/activate — the receiver stays inactive
 * until the contact confirms.
 *
 * Privacy note: CleverReach's doidata block (ip, referer, user agent) is
 * deliberately sent empty — Flinkform does not capture visitor IPs. The
 * activation click itself is CleverReach's consent proof.
 *
 * @package FlinkformPro
 * @since 0.4.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Newsletter\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * CleverReach REST v3 connector.
 */
final class CleverReach implements ProviderInterface {

	private const API_BASE        = 'https://rest.cleverreach.com';
	private const TOKEN_TRANSIENT = 'flinkform_cleverreach_token';

	public static function key(): string {
		return 'cleverreach';
	}

	public static function label(): string {
		return 'CleverReach';
	}

	public static function is_configured( array $settings ): bool {
		return '' !== (string) ( $settings['cleverreach_client_id'] ?? '' )
			&& '' !== (string) ( $settings['cleverreach_client_secret'] ?? '' );
	}

	/**
	 * @inheritDoc
	 */
	public function subscribe( string $email, array $attributes, array $config, array $settings ) {
		$group_id = sanitize_text_field( (string) ( $config['list_id'] ?? '' ) );
		if ( '' === $group_id ) {
			return new \WP_Error( 'permanent', 'CleverReach: missing group id.' );
		}

		$token = $this->token( $settings );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$doi  = ! empty( $config['double_opt_in'] );
		$body = [
			'email'      => $email,
			'registered' => time(),
			// With DOI the receiver starts deactivated; the activation mail
			// flips it on confirmation. Without DOI it is active immediately.
			'activated'  => $doi ? 0 : time(),
		];

		$global_attrs = [];
		if ( '' !== (string) ( $attributes['first_name'] ?? '' ) ) {
			$global_attrs['firstname'] = (string) $attributes['first_name'];
		}
		if ( '' !== (string) ( $attributes['last_name'] ?? '' ) ) {
			$global_attrs['lastname'] = (string) $attributes['last_name'];
		}
		if ( ! empty( $global_attrs ) ) {
			$body['global_attributes'] = $global_attrs;
		}

		$response = wp_remote_post(
			self::API_BASE . '/v3/groups.json/' . rawurlencode( $group_id ) . '/receivers',
			[
				'timeout' => 10,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		$result = $this->evaluate( $response, 'CleverReach' );
		if ( true !== $result ) {
			// 409 duplicate — the receiver already exists; for DOI we still
			// want the activation mail below, otherwise treat as success.
			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( 409 !== $code ) {
				return $result;
			}
		}

		// DOI activation mail via the configured CleverReach form.
		$doi_form = sanitize_text_field( (string) ( $settings['cleverreach_form_id'] ?? '' ) );
		if ( $doi && '' !== $doi_form ) {
			$activate = wp_remote_post(
				self::API_BASE . '/v3/forms.json/' . rawurlencode( $doi_form ) . '/send/activate',
				[
					'timeout' => 10,
					'headers' => [
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					],
					'body'    => wp_json_encode(
						[
							'email'   => $email,
							// Deliberately empty: no visitor IP/UA is captured.
							'doidata' => [
								'user_ip'    => '',
								'referer'    => '',
								'user_agent' => '',
							],
						]
					),
				]
			);

			$activation_result = $this->evaluate( $activate, 'CleverReach DOI' );
			if ( true !== $activation_result ) {
				return $activation_result;
			}
		}

		return true;
	}

	/**
	 * Fetch (or reuse) an OAuth2 client-credentials token.
	 *
	 * @param array<string, mixed> $settings
	 * @return string|\WP_Error
	 */
	private function token( array $settings ) {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::API_BASE . '/oauth/token.php',
			[
				'timeout' => 10,
				'headers' => [
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth, not obfuscation.
					'Authorization' => 'Basic ' . base64_encode(
						(string) $settings['cleverreach_client_id'] . ':' . (string) $settings['cleverreach_client_secret']
					),
				],
				'body'    => [ 'grant_type' => 'client_credentials' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'transient', 'CleverReach token: ' . $response->get_error_message() );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$token   = is_array( $payload ) ? (string) ( $payload['access_token'] ?? '' ) : '';

		if ( $code < 200 || $code >= 300 || '' === $token ) {
			$kind = $code >= 500 ? 'transient' : 'permanent';
			return new \WP_Error( $kind, sprintf( 'CleverReach token: HTTP %d', $code ) );
		}

		$expires = is_array( $payload ) && isset( $payload['expires_in'] ) ? (int) $payload['expires_in'] : 3600;
		set_transient( self::TOKEN_TRANSIENT, $token, max( 60, $expires - 60 ) );

		return $token;
	}

	/**
	 * Map an HTTP response to the provider result contract.
	 *
	 * @param array|\WP_Error $response wp_remote_* result.
	 * @param string          $context  Label for error messages.
	 * @return true|\WP_Error
	 */
	private function evaluate( $response, string $context ) {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'transient', $context . ': ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$kind = $code >= 500 ? 'transient' : 'permanent';
		return new \WP_Error( $kind, sprintf( '%s: HTTP %d', $context, $code ) );
	}
}
