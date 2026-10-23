<?php
/**
 * Admin email: a VAT number was checked again after checkout.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/vatright-revalidated.php.
 *
 * @var WC_Order $order
 * @var string   $kind
 * @var string   $email_heading
 * @var string   $additional_content
 * @var bool     $plain_text
 * @var WC_Email $email
 */

use Vatright\Orders\DecisionStore;
use Vatright\Orders\RevalidatedEmail;

defined( 'ABSPATH' ) || exit;

$vatright_number = (string) $order->get_meta( DecisionStore::NUMBER_META );

do_action( 'woocommerce_email_header', $email_heading, $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook. ?>

<?php if ( RevalidatedEmail::INVALID === $kind ) : ?>
<p>
	<?php
	/* translators: 1: order number, 2: VAT number */
	echo esc_html( sprintf( __( 'VAT was not charged on order %1$s, but the VAT registry now reports the VAT number %2$s as invalid. Check the order and decide whether VAT is due.', 'vatright' ), $order->get_order_number(), $vatright_number ) );
	?>
</p>
<?php else : ?>
<p>
	<?php
	/* translators: 1: order number, 2: VAT number */
	echo esc_html( sprintf( __( 'VAT was charged on order %1$s because the VAT registry was unavailable when it was placed. The registry now confirms the VAT number %2$s is valid, so the customer may be due a refund of the VAT.', 'vatright' ), $order->get_order_number(), $vatright_number ) );
	?>
</p>
<?php endif; ?>

<p>
	<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php esc_html_e( 'Open the order', 'vatright' ); ?></a>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
