<?php

namespace Vatline\Admin;

use Vatline\Checkout\SupplyType;
use Vatline\Domain\Line;

class ProductSupplyField {

	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
	}

	public function render(): void {
		woocommerce_wp_select(
			array(
				'id'          => SupplyType::META,
				'label'       => __( 'EU VAT supply type', 'vatline' ),
				'description' => __( 'How VAT place of supply is decided. Physical products default to goods, virtual products to digital service.', 'vatline' ),
				'desc_tip'    => true,
				'options'     => array(
					''            => __( 'Automatic', 'vatline' ),
					Line::GOODS   => __( 'Goods', 'vatline' ),
					Line::DIGITAL => __( 'Digital service', 'vatline' ),
					Line::SERVICE => __( 'Other service', 'vatline' ),
				),
			)
		);
	}

	/**
	 * @param \WC_Product $product
	 */
	public function save( $product ): void {
		$type = isset( $_POST[ SupplyType::META ] ) ? sanitize_key( wp_unslash( $_POST[ SupplyType::META ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product nonce.
		if ( in_array( $type, SupplyType::TYPES, true ) ) {
			$product->update_meta_data( SupplyType::META, $type );
		} else {
			$product->delete_meta_data( SupplyType::META );
		}
	}
}
