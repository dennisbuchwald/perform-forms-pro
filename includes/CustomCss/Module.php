<?php
/**
 * Custom CSS module (Pro).
 *
 * Re-adds the per-form custom CSS feature that the free core removed for
 * WordPress.org compliance. Three responsibilities:
 *
 *   1. Register the `customCSS` block attribute on `flinkform/form` so
 *      WordPress persists the value (the free core's block.json no longer
 *      declares it).
 *   2. Render the sanitised CSS into a `<style>` tag on the frontend via
 *      the `render_block_flinkform/form` filter.
 *   3. Output a live-preview `<style>` tag in the editor (handled by the
 *      JS panel component in src/custom-css-panel.js — no PHP needed).
 *
 * @package FlinkformPro
 * @since 0.2.10
 */

declare( strict_types = 1 );

namespace FlinkformPro\CustomCss;

defined( 'ABSPATH' ) || exit;

/**
 * Custom CSS module.
 */
final class Module {

	/**
	 * Wire into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'register_block_type_args', [ $this, 'add_attribute' ], 10, 2 );
		add_filter( 'render_block_flinkform/form', [ $this, 'inject_style' ], 10, 2 );
	}

	/**
	 * Dynamically add the `customCSS` attribute to the form-container block.
	 *
	 * @param array<string, mixed> $args      Block type arguments.
	 * @param string               $block_type Block type name.
	 * @return array<string, mixed>
	 */
	public function add_attribute( array $args, string $block_type ): array {
		if ( 'flinkform/form' !== $block_type ) {
			return $args;
		}

		$args['attributes']['customCSS'] = [
			'type'    => 'string',
			'default' => '',
		];

		return $args;
	}

	/**
	 * Prepend a `<style>` tag with the sanitised custom CSS to the block output.
	 *
	 * @param string               $block_content Rendered block HTML.
	 * @param array<string, mixed> $block         Parsed block (includes attributes).
	 * @return string
	 */
	public function inject_style( string $block_content, array $block ): string {
		$css = isset( $block['attrs']['customCSS'] ) && is_string( $block['attrs']['customCSS'] )
			? self::sanitize( $block['attrs']['customCSS'] )
			: '';

		if ( '' === $css ) {
			return $block_content;
		}

		$form_id = isset( $block['attrs']['formId'] ) && is_string( $block['attrs']['formId'] )
			? $block['attrs']['formId']
			: '';

		$style_id = '' !== $form_id
			? ' id="flinkform-custom-css-' . esc_attr( $form_id ) . '"'
			: '';

		return '<style' . $style_id . '>' . $css . '</style>' . $block_content;
	}

	/**
	 * Sanitise a CSS string for safe inline output.
	 *
	 * @param string $css Raw CSS from the block attribute.
	 * @return string
	 */
	private static function sanitize( string $css ): string {
		$css = wp_strip_all_tags( $css );
		$css = (string) preg_replace( '/expression\s*\(/i', '', $css );
		$css = (string) preg_replace( '/behavior\s*:/i', '', $css );
		$css = (string) preg_replace( '/javascript\s*:/i', '', $css );
		return trim( $css );
	}
}
