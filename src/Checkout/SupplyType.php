<?php

namespace Vatline\Checkout;

use Vatline\Domain\Line;
use WC_Product;

final class SupplyType {

	const META = '_vatline_supply_type';

	const TYPES = array( Line::GOODS, Line::DIGITAL, Line::SERVICE );

	/**
	 * Variations inherit the parent's choice, since the product screen sets it
	 * once per product.
	 */
	public static function for_product( WC_Product $product ): string {
		$type = (string) $product->get_meta( self::META );
		if ( '' === $type && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$type   = $parent ? (string) $parent->get_meta( self::META ) : '';
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$type = $product->needs_shipping() ? Line::GOODS : Line::DIGITAL;
		}

		$filtered = apply_filters( 'vatline_supply_type', $type, $product );
		return in_array( $filtered, self::TYPES, true ) ? $filtered : $type;
	}
}
