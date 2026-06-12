<?php
/**
 * Uploads module wiring (Flinkform Pro).
 *
 * Registers the File Upload field block and docks the upload processing
 * onto the free core's field-type seams. Also owns the file-deletion
 * cascade: when submissions are deleted (admin bulk action, retention
 * purge or a GDPR erasure request), the files they reference are removed
 * from disk too.
 *
 * @package FlinkformPro
 * @since 0.4.0
 */

declare( strict_types = 1 );

namespace FlinkformPro\Uploads;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the file-upload field into the free core.
 */
final class Module {

	/**
	 * File paths collected on the pre-delete hook, keyed by submission id.
	 * Consumed by the post-delete hook once the rows are actually gone.
	 *
	 * @var array<int, array<int, string>>
	 */
	private array $pending_file_cleanup = [];

	/**
	 * Register the WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Block registration — points the free core's Registry at this
		// add-on's compiled block directory.
		add_filter(
			'flinkform_block_dirs',
			static function ( array $dirs ): array {
				$dirs['field-file'] = FLINKFORM_PRO_DIR . 'blocks/build/field-file';
				return $dirs;
			}
		);

		// Field-type registration: the Locator treats the block as a
		// value-bearing field from here on.
		add_filter(
			'flinkform_field_blocks',
			static function ( array $map ): array {
				$map['flinkform/field-file'] = 'file';
				return $map;
			}
		);

		// Carry the author's allow-list + size cap into the field definition.
		add_filter(
			'flinkform_field_extras',
			static function ( array $extras, string $type, string $block_name, array $attrs ): array {
				if ( 'file' !== $type ) {
					return $extras;
				}
				return [
					'allowedTypes'  => isset( $attrs['allowedTypes'] ) && is_array( $attrs['allowedTypes'] ) ? $attrs['allowedTypes'] : [ 'pdf', 'jpg', 'png' ],
					'maxSizeMb'     => isset( $attrs['maxSizeMb'] ) && is_numeric( $attrs['maxSizeMb'] ) ? (int) $attrs['maxSizeMb'] : 5,
					// Default true — unset attribute means "attach".
					'attachToEmail' => ! isset( $attrs['attachToEmail'] ) || false !== $attrs['attachToEmail'],
				];
			},
			10,
			4
		);

		// Sentinel substitution: a file field's $_POST value is always empty
		// (the file travels in $_FILES), so the core's required-check would
		// reject every submission. Returning the PENDING sentinel when a
		// file actually arrived makes the required gate behave correctly;
		// process() later swaps the sentinel for the stored file URL.
		add_filter(
			'flinkform_sanitise_field',
			static function ( $sanitised, string $type, $raw, array $field ) {
				if ( 'file' !== $type ) {
					return $sanitised;
				}
				$name = (string) ( $field['name'] ?? '' );
				return ( '' !== $name && Uploader::has_incoming_file( $name ) ) ? Uploader::PENDING : '';
			},
			10,
			4
		);

		// The actual upload work (validate, move, protect, swap sentinel).
		add_filter(
			'flinkform_process_submission',
			static function ( array $result, array $definition ): array {
				return ( new Uploader() )->process( $result, $definition );
			},
			10,
			2
		);

		// Attach uploaded files to the admin notification email (per-field
		// opt-out via the attachToEmail attribute, 8 MB total cap so the
		// mail itself doesn't bounce at common provider limits).
		add_filter( 'flinkform_email_notification', [ $this, 'attach_files_to_email' ], 10, 4 );

		// Deletion cascade: resolve file paths while the rows still exist…
		add_action( 'flinkform_submissions_before_delete', [ $this, 'collect_files_for_deletion' ] );
		// …and unlink them once the rows are gone.
		add_action( 'flinkform_submissions_deleted', [ $this, 'delete_collected_files' ] );
	}

	/**
	 * flinkform_email_notification filter — append stored upload paths to
	 * the admin notification's attachments.
	 *
	 * Only the admin branch gets attachments: the submitter confirmation
	 * deliberately stays lightweight (the submitter has the file anyway).
	 *
	 * @param array<string, mixed> $email    { to, subject, body, headers, attachments }.
	 * @param array<string, mixed> $context  Merge-tag context (fields, field_defs, …).
	 * @param array<string, mixed> $form_def Located form definition.
	 * @param string               $type     'admin' | 'submitter'.
	 * @return array<string, mixed>
	 */
	public function attach_files_to_email( $email, $context, $form_def, $type ) {
		if ( ! is_array( $email ) || 'admin' !== $type || ! is_array( $context ) ) {
			return $email;
		}

		$fields = isset( $context['field_defs'] ) && is_array( $context['field_defs'] ) ? $context['field_defs'] : [];
		$values = isset( $context['fields'] ) && is_array( $context['fields'] ) ? $context['fields'] : [];

		$attachments = isset( $email['attachments'] ) && is_array( $email['attachments'] ) ? $email['attachments'] : [];
		$budget      = 8 * MB_IN_BYTES; // Total attachment budget per mail.

		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || ( $field['type'] ?? '' ) !== 'file' ) {
				continue;
			}
			if ( isset( $field['attachToEmail'] ) && false === $field['attachToEmail'] ) {
				continue;
			}

			$url  = (string) ( $values[ $field['name'] ?? '' ] ?? '' );
			$path = '' !== $url ? Uploader::url_to_path( $url ) : '';
			if ( '' === $path ) {
				continue;
			}

			$size = (int) filesize( $path );
			if ( $size <= 0 || $size > $budget ) {
				continue; // Too large for mail — the link in the body still works.
			}

			$attachments[] = $path;
			$budget       -= $size;
		}

		$email['attachments'] = $attachments;
		return $email;
	}

	/**
	 * Pre-delete: read each submission's payload and remember the paths of
	 * every file-field value.
	 *
	 * @param array<int, int> $submission_ids
	 * @return void
	 */
	public function collect_files_for_deletion( $submission_ids ): void {
		if ( ! is_array( $submission_ids ) || ! class_exists( '\\Flinkform\\Submissions\\Repository' ) ) {
			return;
		}

		$repo = new \Flinkform\Submissions\Repository();

		foreach ( $submission_ids as $id ) {
			$id  = (int) $id;
			$row = $id > 0 ? $repo->find( $id ) : null;
			if ( ! is_array( $row ) ) {
				continue;
			}

			$data   = isset( $row['data'] ) && is_array( $row['data'] ) ? $row['data'] : json_decode( (string) ( $row['data'] ?? '' ), true );
			$fields = is_array( $data ) && isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : [];

			$paths = [];
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || ( $field['type'] ?? '' ) !== 'file' ) {
					continue;
				}
				$path = Uploader::url_to_path( (string) ( $field['value'] ?? '' ) );
				if ( '' !== $path ) {
					$paths[] = $path;
				}
			}

			if ( ! empty( $paths ) ) {
				$this->pending_file_cleanup[ $id ] = $paths;
			}
		}
	}

	/**
	 * Post-delete: unlink the files collected for the ids that were
	 * actually removed.
	 *
	 * @param array<int, int> $submission_ids
	 * @return void
	 */
	public function delete_collected_files( $submission_ids ): void {
		if ( ! is_array( $submission_ids ) ) {
			return;
		}

		foreach ( $submission_ids as $id ) {
			$id = (int) $id;
			if ( empty( $this->pending_file_cleanup[ $id ] ) ) {
				continue;
			}
			foreach ( $this->pending_file_cleanup[ $id ] as $path ) {
				if ( file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}
			unset( $this->pending_file_cleanup[ $id ] );
		}
	}
}
