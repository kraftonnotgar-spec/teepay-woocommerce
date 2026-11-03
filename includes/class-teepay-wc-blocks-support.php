<?php
/**
 * Cart and Checkout blocks integration.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Registers TeePay with the Checkout block. The script is plain JavaScript, so no build step is
 * needed when the plugin is installed.
 */
final class TeePay_WC_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method name; matches the gateway ID.
	 *
	 * @var string
	 */
	protected $name = 'teepay';

	/**
	 * Load settings.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_teepay_settings', array() );
	}

	/**
	 * Whether the method is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = TeePay_WC_Plugin::gateway();
		return null !== $gateway && $gateway->is_available();
	}

	/**
	 * Script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$asset = include TEEPAY_WC_DIR . 'assets/js/blocks/teepay-blocks.asset.php';
		wp_register_script(
			'teepay-wc-blocks',
			TEEPAY_WC_URL . 'assets/js/blocks/teepay-blocks.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		// Strings are translated in PHP and passed in get_payment_method_data().

		return array( 'teepay-wc-blocks' );
	}

	/**
	 * Data passed to the script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$sandbox     = 'yes' === $this->get_setting( 'testmode' );
		$description = (string) $this->get_setting( 'description' );
		if ( $sandbox ) {
			$description = trim( $description . ' ' . __( 'SANDBOX MODE: no money will move.', 'teepay-for-woocommerce' ) );
		}

		return array(
			'title'       => (string) $this->get_setting( 'title' ),
			'description' => $description,
			'icon'        => TEEPAY_WC_URL . 'assets/images/teepay-mark.svg',
			'mode'        => 'push' === $this->get_setting( 'payment_mode' ) ? 'push' : 'hosted',
			'supports'    => array( 'products' ),
			'i18n'        => array(
				'phoneLabel'   => __( 'Mobile money number', 'teepay-for-woocommerce' ),
				'phoneHelp'    => __( 'We will send a payment prompt to this number. Approve it with your PIN.', 'teepay-for-woocommerce' ),
				'phoneInvalid' => __( 'Enter a valid Tanzanian mobile money number, for example 0712 345 678.', 'teepay-for-woocommerce' ),
			),
		);
	}
}
