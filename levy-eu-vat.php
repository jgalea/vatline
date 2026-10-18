<?php
/**
 * Plugin Name:       Levy – EU VAT for WooCommerce
 * Description:       Decides, applies and records EU VAT treatment on every WooCommerce order.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Jean Galea
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       levy-eu-vat
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'LEVY_EU_VAT_VERSION', '0.1.0' );
define( 'LEVY_EU_VAT_FILE', __FILE__ );
define( 'LEVY_EU_VAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'LEVY_EU_VAT_URL', plugin_dir_url( __FILE__ ) );

if ( ! file_exists( LEVY_EU_VAT_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Levy - EU VAT is missing its autoloader. Run "composer install" in the plugin folder.', 'levy-eu-vat' ) . '</p></div>';
		}
	);
	return;
}

require_once LEVY_EU_VAT_DIR . 'vendor/autoload.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', LEVY_EU_VAT_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', LEVY_EU_VAT_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( \Levy\EuVat\Ledger\Schema::class, 'install' ) );

add_action( 'plugins_loaded', array( \Levy\EuVat\Plugin::class, 'boot' ), 20 );
