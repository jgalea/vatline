<?php

namespace Levy\EuVat\Admin;

use Levy\EuVat\Checkout\SupplyType;
use Levy\EuVat\Domain\Line;

class ProductSupplyField {

	public function register(): void {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
	}

	public function render(): void {
		woocommerce_wp_select(
			array(
				'id'          => SupplyType::META,
				'label'       => __( 'EU VAT supply type', 'levy-eu-vat' ),
				'description' => __( 'How VAT place of supply is decided. Physical products default to goods, virtual products to digital service.', 'levy-eu-vat' ),
				'desc_tip'    => true,
				'options'     => array(
					''            => __( 'Automatic', 'levy-eu-vat' ),
					Line::GOODS   => __( 'Goods', 'levy-eu-vat' ),
					Line::DIGITAL => __( 'Digital service', 'levy-eu-vat' ),
					Line::SERVICE => __( 'Other service', 'levy-eu-vat' ),
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
