<?php
/**
 * PHPUnit bootstrap: loads the plugin classes against simple WordPress/WooCommerce stubs.
 *
 * @package TeePay\WooCommerce\Tests
 */

// phpcs:ignoreFile

define( 'ABSPATH', __DIR__ . '/' );
define( 'TEEPAY_WC_VERSION', '1.0.0' );
define( 'TEEPAY_WC_FILE', dirname( __DIR__ ) . '/teepay-for-woocommerce.php' );
define( 'TEEPAY_WC_DIR', dirname( __DIR__ ) . '/' );
define( 'TEEPAY_WC_URL', 'https://shop.example.tz/wp-content/plugins/teepay-for-woocommerce/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

require __DIR__ . '/stubs/wordpress.php';

$includes = dirname( __DIR__ ) . '/includes/';
foreach ( array( 'api-exception', 'api-client', 'money', 'phone', 'redactor', 'webhook-verifier', 'payment-evaluator', 'logger', 'restriction', 'order-service', 'webhook-handler', 'plugin', 'gateway' ) as $name ) {
	require_once $includes . 'class-teepay-wc-' . $name . '.php';
}

require __DIR__ . '/stubs/fakes.php';
require __DIR__ . '/TestCase.php';
