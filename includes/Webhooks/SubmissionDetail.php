<?php
/**
 * Webhook deliveries on the submission detail screen (PerForm Pro).
 *
 * The free core's submission detail view fires `perform_submission_detail_after`
 * after the field table; Pro hooks it to render the "Webhook Deliveries" table
 * for that submission (status, response code, retry attempt + a Resend action).
 * The Resend request is handled independently on admin_init — re-checking the
 * submissions capability + the per-row nonce, since the free core no longer
 * routes a `webhook_resend` action.
 *
 * @package PerFormPro
 * @since 0.2.5
 */

declare( strict_types = 1 );

namespace PerFormPro\Webhooks;

use PerForm\Admin\Menu;

defined( 'ABSPATH' ) || exit;

/**
 * Renders + handles webhook deliveries on the submission detail page.
 */
final class SubmissionDetail {

	/**
	 * Register the WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'perform_submission_detail_after', [ $this, 'render_section' ] );
		add_action( 'admin_init', [ $this, 'maybe_handle_resend' ] );
	}

	/**
	 * Render the "Webhook Deliveries" section for a submission.
	 *
	 * @param int $submission_id Submission id passed by the core seam.
	 * @return void
	 */
	public function render_section( $submission_id ): void {
		$submission_id = (int) $submission_id;
		$deliveries    = ( new DeliveryRepository() )->find_for_submission( $submission_id );
		if ( empty( $deliveries ) ) {
			return; // No webhooks configured for this form, or none triggered yet.
		}
		?>
		<h2 style="margin-top:32px;"><?php esc_html_e( 'Webhook Deliveries', 'perform-forms-pro' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Webhook', 'perform-forms-pro' ); ?></th>
					<th><?php esc_html_e( 'Status', 'perform-forms-pro' ); ?></th>
					<th><?php esc_html_e( 'Code', 'perform-forms-pro' ); ?></th>
					<th><?php esc_html_e( 'Attempt', 'perform-forms-pro' ); ?></th>
					<th><?php esc_html_e( 'Updated', 'perform-forms-pro' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'perform-forms-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $deliveries as $delivery ) :
					$label  = (string) ( $delivery['webhook_label'] ?? '' );
					$url    = (string) ( $delivery['webhook_url'] ?? '' );
					$status = (string) ( $delivery['status'] ?? '' );
					$color  = WebhookLogListTable::status_color( $status );
					$resend_nonce = wp_create_nonce( 'perform_webhook_resend_' . (int) $delivery['id'] );
					$resend_url   = add_query_arg(
						[
							'page'           => Menu::PARENT_SLUG,
							'perform_action' => 'webhook_resend',
							'id'             => $submission_id,
							'delivery_id'    => (int) $delivery['id'],
							'_wpnonce'       => $resend_nonce,
						],
						admin_url( 'admin.php' )
					);
					?>
					<tr>
						<td>
							<?php
							if ( '' === $label && '' === $url ) {
								echo '<em>' . esc_html__( '(deleted)', 'perform-forms-pro' ) . '</em>';
							} else {
								echo esc_html( '' !== $label ? $label : $url );
								if ( '' !== $label && '' !== $url ) {
									echo '<br><small style="opacity:0.7">' . esc_html( $url ) . '</small>';
								}
							}
							?>
						</td>
						<td>
							<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:<?php echo esc_attr( $color ); ?>;color:#fff;font-size:11px;text-transform:uppercase;letter-spacing:0.04em;">
								<?php echo esc_html( $status ); ?>
							</span>
						</td>
						<td>
							<?php echo isset( $delivery['response_code'] ) && null !== $delivery['response_code'] ? esc_html( (string) (int) $delivery['response_code'] ) : '—'; ?>
						</td>
						<td><?php echo (int) ( $delivery['attempt'] ?? 0 ); ?></td>
						<td>
							<?php
							$ts = strtotime( ( $delivery['updated_at'] ?? '' ) . ' UTC' );
							echo $ts ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) ) : '—';
							?>
						</td>
						<td>
							<a href="<?php echo esc_url( $resend_url ); ?>" class="button button-small">
								<?php esc_html_e( 'Resend', 'perform-forms-pro' ); ?>
							</a>
						</td>
					</tr>
					<?php if ( ! empty( $delivery['response_body'] ) ) : ?>
						<tr>
							<td colspan="6">
								<details>
									<summary style="cursor:pointer;font-size:12px;opacity:0.75;">
										<?php esc_html_e( 'Response body', 'perform-forms-pro' ); ?>
									</summary>
									<pre style="white-space:pre-wrap;word-break:break-word;font-size:11px;max-height:150px;overflow:auto;margin:4px 0 0;background:#f6f7f7;padding:8px;border-radius:3px;"><?php echo esc_html( (string) $delivery['response_body'] ); ?></pre>
								</details>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Handle the Resend request on admin_init.
	 *
	 * Re-queues a fresh delivery for the same submission + webhook, then kicks a
	 * single-event cron so it fires within a second. The original log row is
	 * left intact as historical record.
	 *
	 * @return void
	 */
	public function maybe_handle_resend(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- routing check only; the nonce is verified below.
		if ( ! isset( $_GET['page'], $_GET['perform_action'] ) ) {
			return;
		}
		$page   = sanitize_key( wp_unslash( $_GET['page'] ) );
		$action = sanitize_key( wp_unslash( $_GET['perform_action'] ) );
		$submission_id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$delivery_id   = isset( $_GET['delivery_id'] ) ? (int) $_GET['delivery_id'] : 0;
		// phpcs:enable

		if ( Menu::PARENT_SLUG !== $page || 'webhook_resend' !== $action ) {
			return;
		}
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}
		if ( 0 === $submission_id || 0 === $delivery_id ) {
			return;
		}
		check_admin_referer( 'perform_webhook_resend_' . $delivery_id );

		$delivery_repo = new DeliveryRepository();
		$original      = $delivery_repo->find( $delivery_id );

		if ( null === $original || (int) $original['submission_id'] !== $submission_id ) {
			$this->redirect_to_detail( $submission_id, __( 'Could not resend — delivery not found.', 'perform-forms-pro' ) );
			return;
		}

		$new_id = $delivery_repo->enqueue( (int) $original['webhook_id'], $submission_id );
		if ( null === $new_id ) {
			$this->redirect_to_detail( $submission_id, __( 'Could not resend — queue insert failed.', 'perform-forms-pro' ) );
			return;
		}

		wp_schedule_single_event( time() + 1, Dispatcher::CRON_HOOK );
		$this->redirect_to_detail( $submission_id, __( 'Webhook delivery re-queued.', 'perform-forms-pro' ) );
	}

	/**
	 * Redirect back to the submission detail view carrying a notice.
	 *
	 * Uses the same `perform_notice` query arg the free core's submission
	 * detail view reads + renders.
	 *
	 * @param int    $submission_id Submission id.
	 * @param string $notice        Human-readable notice text.
	 * @return void
	 */
	private function redirect_to_detail( int $submission_id, string $notice ): void {
		$url = add_query_arg(
			[
				'page'           => Menu::PARENT_SLUG,
				'action'         => 'view',
				'id'             => $submission_id,
				'perform_notice' => rawurlencode( $notice ),
			],
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
