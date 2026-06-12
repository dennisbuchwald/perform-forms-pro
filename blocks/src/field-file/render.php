<?php
/**
 * Server-side render for the File Upload Field block (Pro).
 *
 * The input posts into $_FILES['flinkform_files'][<fieldName>] — the Pro
 * Uploads module picks it up via the free core's
 * `flinkform_process_submission` seam. The form element always posts
 * multipart/form-data (core 0.4.0+), so no form-level changes are needed.
 *
 * Note: file inputs cannot be repopulated after a failed validation round
 * trip — the help text reminds the visitor to re-select their file when
 * the form re-renders with errors.
 *
 * @var array<string, mixed> $attributes
 * @var WP_Block             $block
 *
 * @package FlinkformPro
 * @since 0.4.0
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$form_id    = isset( $block->context['flinkform/formId'] ) ? (string) $block->context['flinkform/formId'] : '';
$label      = isset( $attributes['label'] ) && is_string( $attributes['label'] ) ? $attributes['label'] : '';
$required   = ! empty( $attributes['required'] );
$help_text  = isset( $attributes['helpText'] ) && is_string( $attributes['helpText'] ) ? $attributes['helpText'] : '';
$field_name = isset( $attributes['fieldName'] ) && is_string( $attributes['fieldName'] ) ? $attributes['fieldName'] : '';
$max_mb     = isset( $attributes['maxSizeMb'] ) && is_numeric( $attributes['maxSizeMb'] ) ? max( 1, (int) $attributes['maxSizeMb'] ) : 5;

$allowed = isset( $attributes['allowedTypes'] ) && is_array( $attributes['allowedTypes'] )
	? array_values( array_filter( array_map( 'sanitize_key', array_map( 'strval', $attributes['allowedTypes'] ) ) ) )
	: [];

if ( '' === $field_name || '' === $form_id ) {
	return;
}

// HTML accept attribute from the allow-list (".pdf,.jpg,…"). The doc/xls
// presets cover their x-variants too.
$accept_parts = [];
foreach ( $allowed as $ext ) {
	$accept_parts[] = '.' . $ext;
	if ( 'jpg' === $ext ) {
		$accept_parts[] = '.jpeg';
	}
	if ( 'doc' === $ext ) {
		$accept_parts[] = '.docx';
	}
	if ( 'xls' === $ext ) {
		$accept_parts[] = '.xlsx';
	}
}
$accept = implode( ',', $accept_parts );

$error     = \Flinkform\Submissions\Handler::flash_error( $field_name );
$field_uid = 'flinkform-field-' . md5( $form_id . '-' . $field_name );
$hint_id   = $field_uid . '-hint';
$help_id   = $help_text ? $field_uid . '-help' : '';
$error_id  = $error ? $field_uid . '-error' : '';
$described = trim( $hint_id . ' ' . $help_id . ' ' . $error_id );
?>
<div class="flinkform-field flinkform-field--file<?php echo $error ? ' flinkform-field--has-error' : ''; ?><?php echo ! empty( $attributes['fullWidth'] ) ? ' flinkform-field--full-width' : ''; ?>"<?php echo \Flinkform\Conditions\Wrapper::data_attribute( $attributes['conditionalLogic'] ?? [] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- data_attribute() returns an esc_attr()-escaped attribute string. ?> data-flinkform-field-name="<?php echo esc_attr( $field_name ); ?>">
	<label class="flinkform-field__label" for="<?php echo esc_attr( $field_uid ); ?>">
		<?php echo esc_html( $label ); ?>
		<?php if ( $required ) : ?>
			<span class="flinkform-field__required" aria-hidden="true"> *</span>
		<?php endif; ?>
	</label>
	<div class="flinkform-field__dropzone" data-flinkform-dropzone>
		<input
			type="file"
			id="<?php echo esc_attr( $field_uid ); ?>"
			name="flinkform_files[<?php echo esc_attr( $field_name ); ?>]"
			class="flinkform-field__input flinkform-field__input--file"
			<?php echo '' !== $accept ? 'accept="' . esc_attr( $accept ) . '"' : ''; ?>
			<?php echo $required ? 'required aria-required="true"' : ''; ?>
			<?php echo $described ? 'aria-describedby="' . esc_attr( $described ) . '"' : ''; ?>
			<?php echo $error ? 'aria-invalid="true"' : ''; ?>
		/>
		<div class="flinkform-field__dropzone-idle" aria-hidden="true">
			<span class="flinkform-field__dropzone-icon">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false">
					<path d="M21 15v3a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-3" />
					<path d="M12 16V4" />
					<path d="m7 9 5-5 5 5" />
				</svg>
			</span>
			<span class="flinkform-field__dropzone-text">
				<?php
				printf(
					/* translators: %s: "Choose a file" (rendered bold) */
					esc_html__( '%s or drag it here', 'flinkform-pro' ),
					'<strong>' . esc_html__( 'Choose a file', 'flinkform-pro' ) . '</strong>'
				);
				?>
			</span>
		</div>
		<div class="flinkform-field__dropzone-file">
			<span class="flinkform-field__dropzone-file-icon" aria-hidden="true">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false">
					<path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z" />
					<path d="M14 2v5h5" />
				</svg>
			</span>
			<span class="flinkform-field__dropzone-name" data-flinkform-file-name></span>
			<span class="flinkform-field__dropzone-size" data-flinkform-file-size></span>
			<button
				type="button"
				class="flinkform-field__dropzone-remove"
				data-flinkform-file-remove
				aria-label="<?php esc_attr_e( 'Remove selected file', 'flinkform-pro' ); ?>"
			>&times;</button>
		</div>
	</div>
	<p class="flinkform-field__help" id="<?php echo esc_attr( $hint_id ); ?>">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: allowed extensions list, 2: max size in MB */
				__( 'Allowed: %1$s · max. %2$d MB', 'flinkform-pro' ),
				strtoupper( implode( ', ', $allowed ) ),
				$max_mb
			)
		);
		?>
	</p>
	<?php if ( $help_text ) : ?>
		<p class="flinkform-field__help" id="<?php echo esc_attr( $help_id ); ?>">
			<?php echo esc_html( $help_text ); ?>
		</p>
	<?php endif; ?>
	<?php if ( $error ) : ?>
		<p class="flinkform-field__error" id="<?php echo esc_attr( $error_id ); ?>" role="alert">
			<?php echo esc_html( $error ); ?>
		</p>
	<?php endif; ?>
</div>
