<?php
/**
 * Orders whose VAT number could not be verified.
 *
 * @package BeltoftEuVat
 */

namespace BeltoftEuVat;

defined( 'ABSPATH' ) || exit;

/**
 * An order flagged _beltoft_eu_vat_review = yes was charged VAT because VIES did not answer.
 * It gets an admin column (both order screens), a notice with the evidence on the order
 * screen, a "mark as reviewed" action and one mail to the shop admin.
 */
final class ReviewQueue {

	private const ACTION = 'beltoft_eu_vat_reviewed';
	private const MAILED = '_beltoft_eu_vat_review_mailed';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'notify' ) );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'order_panel' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'mark_reviewed' ) );
	}

	/**
	 * Orders waiting for review.
	 *
	 * @param array $args Extra wc_get_orders arguments.
	 * @return \WC_Order[]
	 */
	public static function pending( array $args = array() ): array {
		$orders = wc_get_orders(
			array_merge(
				array(
					'limit'      => -1,
					'meta_key'   => OrderEvidence::REVIEW, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- A review queue is a meta query by nature.
					'meta_value' => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				),
				$args
			)
		);

		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Mails the shop admin once when an order is flagged.
	 *
	 * @param mixed $order Order.
	 */
	public static function notify( $order ): void {
		if ( ! $order instanceof \WC_Order || 'yes' !== $order->get_meta( OrderEvidence::REVIEW ) || $order->get_meta( self::MAILED ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: order number */
			__( 'VAT number could not be checked for order %s', 'beltoft-eu-vat' ),
			$order->get_order_number()
		);

		$body = sprintf(
			/* translators: 1: order number, 2: VAT number, 3: link to the order */
			__( "VIES did not answer when order %1\$s was placed, so VAT was charged and the order needs review.\n\nVAT number: %2\$s\n\nOpen the order: %3\$s\n\nIf the number is valid, refund the VAT. If it is not, nothing more is needed.", 'beltoft-eu-vat' ),
			$order->get_order_number(),
			(string) $order->get_meta( '_' . Field::KEY ),
			$order->get_edit_order_url()
		);

		wp_mail( Settings::admin_email(), $subject, $body );

		$order->update_meta_data( self::MAILED, 'yes' );
		$order->save();
	}

	/**
	 * Adds the column.
	 *
	 * @param mixed $columns Columns.
	 * @return mixed
	 */
	public static function add_column( $columns ) {
		if ( is_array( $columns ) ) {
			$columns['beltoft_eu_vat'] = __( 'EU VAT check', 'beltoft-eu-vat' );
		}

		return $columns;
	}

	/**
	 * Fills the column.
	 *
	 * @param string          $column      Column.
	 * @param int|\WC_Order   $order_or_id Id on the posts screen, the order under HPOS.
	 */
	public static function render_column( $column, $order_or_id ): void {
		if ( 'beltoft_eu_vat' !== $column ) {
			return;
		}

		$order = $order_or_id instanceof \WC_Order ? $order_or_id : wc_get_order( $order_or_id );

		if ( ! $order ) {
			return;
		}

		if ( 'yes' === $order->get_meta( OrderEvidence::REVIEW ) ) {
			echo '<mark class="order-status status-on-hold"><span>' . esc_html__( 'Needs review', 'beltoft-eu-vat' ) . '</span></mark>';
			return;
		}

		echo esc_html( (string) $order->get_meta( OrderEvidence::STATUS ) );
	}

	/**
	 * The evidence and the review notice on the order screen.
	 *
	 * @param mixed $order Order.
	 */
	public static function order_panel( $order ): void {
		if ( ! $order instanceof \WC_Order || '' === (string) $order->get_meta( OrderEvidence::STATUS ) ) {
			return;
		}

		echo '<div class="beltoft-eu-vat-order"><h3>' . esc_html__( 'EU VAT check', 'beltoft-eu-vat' ) . '</h3>';

		if ( 'yes' === $order->get_meta( OrderEvidence::REVIEW ) ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . self::ACTION . '&order_id=' . (int) $order->get_id() ),
				self::ACTION . (int) $order->get_id()
			);

			echo '<p><strong>' . esc_html__( 'Needs review: VIES did not answer, VAT was charged.', 'beltoft-eu-vat' ) . '</strong> ';
			echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Mark as reviewed', 'beltoft-eu-vat' ) . '</a></p>';
		}

		$rows = array(
			__( 'Status', 'beltoft-eu-vat' )          => $order->get_meta( OrderEvidence::STATUS ),
			__( 'Consultation number', 'beltoft-eu-vat' ) => $order->get_meta( OrderEvidence::REQUEST_ID ),
			__( 'Registered name', 'beltoft-eu-vat' ) => $order->get_meta( OrderEvidence::NAME ),
			__( 'Checked at', 'beltoft-eu-vat' )      => $order->get_meta( OrderEvidence::CHECKED_AT ),
			__( 'Company entered', 'beltoft-eu-vat' ) => $order->get_meta( OrderEvidence::ENTERED_NAME ),
			__( 'Name matches register', 'beltoft-eu-vat' ) => self::name_result( $order ),
		);

		foreach ( $rows as $label => $value ) {
			if ( '' !== (string) $value ) {
				echo '<p><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( (string) $value ) . '</p>';
			}
		}

		echo '</div>';
	}

	/**
	 * The name check result for the order box: "Yes (equal)" or "No (mismatch)", empty when none was made.
	 *
	 * @param \WC_Order $order Order.
	 */
	private static function name_result( \WC_Order $order ): string {
		$match = (string) $order->get_meta( OrderEvidence::NAME_MATCH );

		if ( '' === $match ) {
			return '';
		}

		return sprintf(
			/* translators: 1: Yes or No, 2: reason such as equal, contains or mismatch */
			__( '%1$s (%2$s)', 'beltoft-eu-vat' ),
			'yes' === $match ? __( 'Yes', 'beltoft-eu-vat' ) : __( 'No', 'beltoft-eu-vat' ),
			(string) $order->get_meta( OrderEvidence::NAME_REASON )
		);
	}

	/**
	 * Clears the flag. Needs the capability and the nonce.
	 */
	public static function mark_reviewed(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		check_admin_referer( self::ACTION . $order_id );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'beltoft-eu-vat' ), '', array( 'response' => 403 ) );
		}

		$order = wc_get_order( $order_id );

		if ( $order ) {
			$order->update_meta_data( OrderEvidence::REVIEW, 'reviewed' );
			$order->add_order_note( __( 'EU VAT check marked as reviewed.', 'beltoft-eu-vat' ) );
			$order->save();
		}

		wp_safe_redirect( $order ? $order->get_edit_order_url() : admin_url() );
		exit;
	}
}
