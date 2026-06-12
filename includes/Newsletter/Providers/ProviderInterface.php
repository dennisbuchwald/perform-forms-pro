<?php
/**
 * Newsletter provider contract (Flinkform Pro).
 *
 * @package FlinkformPro
 * @since 0.4.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Newsletter\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * One implementation per newsletter/CRM service.
 */
interface ProviderInterface {

	/**
	 * Stable identifier persisted in the form's newsletter config.
	 *
	 * @return string
	 */
	public static function key(): string;

	/**
	 * Human-readable name for settings + editor UI.
	 *
	 * @return string
	 */
	public static function label(): string;

	/**
	 * Whether the global settings contain usable credentials.
	 *
	 * @param array<string, mixed> $settings Decrypted global settings.
	 * @return bool
	 */
	public static function is_configured( array $settings ): bool;

	/**
	 * Subscribe one contact.
	 *
	 * @param string               $email      Subscriber email (validated upstream).
	 * @param array<string, string> $attributes Optional first_name / last_name.
	 * @param array<string, mixed> $config     Per-form config (list_id, double_opt_in).
	 * @param array<string, mixed> $settings   Decrypted global settings.
	 * @return true|\WP_Error True on success; WP_Error with code 'transient'
	 *                        when a retry might succeed (timeout, 5xx) or
	 *                        'permanent' when it will not (bad key, 4xx).
	 */
	public function subscribe( string $email, array $attributes, array $config, array $settings );
}
