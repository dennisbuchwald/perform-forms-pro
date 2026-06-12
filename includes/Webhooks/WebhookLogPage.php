<?php
/**
 * Webhook log admin page controller.
 *
 * Mounts the WebhookLogListTable inside the standard Flinkform admin
 * area. Mirrors the FormsPage / SubmissionsPage pattern so the
 * three pages feel native to each other.
 *
 * @package FlinkformPro
 * @since 0.2.5
 */

declare( strict_types = 1 );

namespace FlinkformPro\Webhooks;


defined( 'ABSPATH' ) || exit;

/**
 * Renders the Webhook Log admin page.
 */
final class WebhookLogPage {

	public const SLUG = 'flinkform-webhook-log';

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render(): void {
		$table = new WebhookLogListTable( new DeliveryRepository() );
		$table->prepare_items();

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Webhook Log', 'flinkform-pro' ); ?>
			</h1>
			<p class="description" style="margin-top: 4px;">
				<?php esc_html_e( 'Every outbound webhook delivery dispatched by Flinkform — one row per attempt. Use it to debug receivers, audit retries and verify the queue is draining.', 'flinkform-pro' ); ?>
			</p>

			<hr class="wp-header-end">

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
				<?php
				$table->search_box( __( 'Search webhooks', 'flinkform-pro' ), 'flinkform-webhook-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}
}
