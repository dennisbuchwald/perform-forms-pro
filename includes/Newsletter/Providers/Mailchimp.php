<?php
/**
 * Mailchimp newsletter provider.
 *
 * Upserts via PUT /3.0/lists/{list}/members/{subscriber_hash}. With double
 * opt-in enabled, new members are created as 'pending' and Mailchimp sends
 * its confirmation email; existing members keep their status.
 *
 * @package FlinkformPro
 * @since 0.4.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Newsletter\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Mailchimp Marketing API v3 connector.
 */
final class Mailchimp implements ProviderInterface {

	public static function key(): string {
		return 'mailchimp';
	}

	public static function label(): string {
		return 'Mailchimp';
	}

	public static function is_configured( array $settings ): bool {
		$key = (string) ( $settings['mailchimp_api_key'] ?? '' );
		return '' !== $key && false !== strpos( $key, '-' );
	}

	/**
	 * @inheritDoc
	 */
	public function subscribe( string $email, array $attributes, array $config, array $settings ) {
		$api_key = (string) ( $settings['mailchimp_api_key'] ?? '' );
		$list_id = sanitize_text_field( (string) ( $config['list_id'] ?? '' ) );
		if ( '' === $list_id ) {
			return new \WP_Error( 'permanent', 'Mailchimp: missing audience id.' );
		}

		// The datacenter is the suffix after the final dash of the API key.
		$dc = substr( $api_key, (int) strrpos( $api_key, '-' ) + 1 );
		if ( '' === $dc || ! preg_match( '/^[a-z]{2,3}\d+$/', $dc ) ) {
			return new \WP_Error( 'permanent', 'Mailchimp: malformed API key (missing datacenter suffix).' );
		}

		$hash = md5( strtolower( $email ) );
		$url  = sprintf( 'https://%s.api.mailchimp.com/3.0/lists/%s/members/%s', $dc, rawurlencode( $list_id ), $hash );

		$body = [
			'email_address' => $email,
			'status_if_new' => ! empty( $config['double_opt_in'] ) ? 'pending' : 'subscribed',
		];

		$merge = [];
		if ( '' !== (string) ( $attributes['first_name'] ?? '' ) ) {
			$merge['FNAME'] = (string) $attributes['first_name'];
		}
		if ( '' !== (string) ( $attributes['last_name'] ?? '' ) ) {
			$merge['LNAME'] = (string) $attributes['last_name'];
		}
		if ( ! empty( $merge ) ) {
			$body['merge_fields'] = $merge;
		}

		$response = wp_remote_request(
			$url,
			[
				'method'  => 'PUT',
				'timeout' => 10,
				'headers' => [
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth, not obfuscation.
					'Authorization' => 'Basic ' . base64_encode( 'flinkform:' . $api_key ),
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'transient', 'Mailchimp: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$kind    = $code >= 500 ? 'transient' : 'permanent';
		return new \WP_Error( $kind, sprintf( 'Mailchimp: HTTP %d %s', $code, (string) ( $payload['detail'] ?? '' ) ) );
	}
}
