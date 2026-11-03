<?php
/**
 * Plugin Name:          TeePay for WooCommerce
 * Plugin URI:           https://teepay.me
 * Description:          Accept M-Pesa, Airtel Money, Mixx by Yas and HaloPesa payments in Tanzania with TeePay hosted checkout or a mobile money prompt.
 * Version:              1.0.0
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               TeePay
 * Author URI:           https://teepay.me
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          teepay-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 7.6
 * WC tested up to:      10.3
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'TEEPAY_WC_VERSION', '1.0.0' );
define( 'TEEPAY_WC_FILE', __FILE__ );
define( 'TEEPAY_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'TEEPAY_WC_URL', plugin_dir_url( __FILE__ ) );

require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-api-exception.php';
require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-api-client.php';
require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-money.php';
require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-phone.php';
require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-redactor.php';
require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-webhook-verifier.php';
require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-payment-evaluator.php';
require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-logger.php';
require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-restriction.php';
require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-order-service.php';
require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-webhook-handler.php';
require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-plugin.php';

register_activation_hook( __FILE__, array( 'TeePay_WC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'TeePay_WC_Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', TEEPAY_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', TEEPAY_WC_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( 'TeePay_WC_Plugin', 'init' ), 11 );
