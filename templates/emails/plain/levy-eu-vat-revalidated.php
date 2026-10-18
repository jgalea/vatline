<?php
/**
 * Admin email (plain text): a VAT number was checked again after checkout.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/levy-eu-vat-revalidated.php.
 *
 * @var WC_Order $order
 * @var string   $kind
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $plain_text
 * @var WC_Email $email
 */

use Levy\EuVat\Orders\DecisionStore;
use Levy\EuVat\Orders\RevalidatedEmail;

defined( 'ABSPATH' ) || exit;

$levy_eu_vat_number = (string) $order->get_meta( DecisionStore::NUMBER_META );

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

if ( RevalidatedEmail::INVALID === $kind ) {
	/* translators: 1: order number, 2: VAT number */
	echo esc_html( sprintf( __( 'VAT was not charged on order %1$s, but the VAT registry now reports the VAT number %2$s as invalid. Check the order and decide whether VAT is due.', 'levy-eu-vat' ), $order->get_order_number(), $levy_eu_vat_number ) ) . "\n\n";
} else {
	/* translators: 1: order number, 2: VAT number */
	echo esc_html( sprintf( __( 'VAT was charged on order %1$s because the VAT registry was unavailable when it was placed. The registry now confirms the VAT number %2$s is valid, so the customer may be due a refund of the VAT.', 'levy-eu-vat' ), $order->get_order_number(), $levy_eu_vat_number ) ) . "\n\n";
}

echo esc_url_raw( $order->get_edit_order_url() ) . "\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
