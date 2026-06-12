<?php
/**
 * Brevo (ex Sendinblue) newsletter provider.
 *
 * Upserts a contact via POST /v3/contacts with updateEnabled, so repeat
 * submissions never error on "contact already exists". Double opt-in via
 * Brevo's DOI template is not wired in v1 — operators configure DOI inside
 * Brevo (automation on list entry) instead.
 *
 * @package FlinkformPro
 * @since 0.4.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Newsletter\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Brevo REST v3 connector.
 */
final class Brevo implements ProviderInterface {

	public static function key(): string {
		return 'brevo';
	}

	public static function label(): string {
		return 'Brevo';
	}

	public static function is_configured( array $settings ): bool {
		return '' !== (string) ( $settings['brevo_api_key'] ?? '' );
	}

	/**
	 * @inheritDoc
	 */
	public function subscribe( string $email, array $attributes, array $config, array $settings ) {
		$list_id = (int) ( $config['list_id'] ?? 0 );
		if ( $list_id <= 0 ) {
			return new \WP_Error( 'permanent', 'Brevo: missing list id.' );
		}

		$body = [
			'email'         => $email,
			'listIds'       => [ $list_id ],
			'updateEnabled' => true,
		];

		$attrs = [];
		if ( '' !== (string) ( $attributes['first_name'] ?? '' ) ) {
			$attrs['FIRSTNAME'] = (string) $attributes['first_name'];
		}
		if ( '' !== (string) ( $attributes['last_name'] ?? '' ) ) {
			$attrs['LASTNAME'] = (string) $attributes['last_name'];
		}
		if ( ! empty( $attrs ) ) {
			$body['attributes'] = $attrs;
		}

		$response = wp_remote_post(
			'https://api.brevo.com/v3/contacts',
			[
				'timeout' => 10,
				'headers' => [
					'api-key'      => (string) $settings['brevo_api_key'],
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'transient', 'Brevo: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		// "duplicate_parameter" arrives as 400 when updateEnabled didn't
		// apply (e.g. contact exists in another list state) — not a failure
		// worth alerting the operator about.
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 400 === $code && is_array( $payload ) && 'duplicate_parameter' === ( $payload['code'] ?? '' ) ) {
			return true;
		}

		$kind = $code >= 500 ? 'transient' : 'permanent';
		return new \WP_Error( $kind, sprintf( 'Brevo: HTTP %d %s', $code, (string) ( $payload['message'] ?? '' ) ) );
	}
}
