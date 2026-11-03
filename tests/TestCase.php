<?php
/**
 * Base test case.
 *
 * @package TeePay\WooCommerce\Tests
 */

// phpcs:ignoreFile

abstract class TP_TestCase extends \PHPUnit\Framework\TestCase {

	/** @var TP_Api */
	protected $api;

	protected function setUp(): void {
		parent::setUp();
		TP_State::reset();
		TeePay_WC_Logger::set_enabled( true );
		$this->api = new TP_Api();
		$api       = $this->api;
		add_filter(
			'teepay_wc_api_transport',
			function () use ( $api ) {
				return $api->transport();
			}
		);
	}

	protected function service( array $overrides = array() ) {
		return new TeePay_WC_Order_Service( TP_Fixtures::settings( $overrides ) );
	}

	protected function session_path() {
		return '/v1/checkout-sessions/' . TP_Fixtures::SESSION_ID;
	}

	/**
	 * An order that already has a hosted checkout session, as after process_payment().
	 */
	protected function order_with_session( $id = 1001, $total = '5000.00', $environment = 'live' ) {
		$order = new TP_Order( $id, $total );
		$order->meta = array(
			TeePay_WC_Order_Service::META_MODE        => 'hosted',
			TeePay_WC_Order_Service::META_ENVIRONMENT => $environment,
			TeePay_WC_Order_Service::META_REFERENCE   => TeePay_WC_Order_Service::order_reference( $id ),
			TeePay_WC_Order_Service::META_AMOUNT      => TeePay_WC_Money::to_minor( $total, 'TZS' ),
			TeePay_WC_Order_Service::META_CURRENCY    => 'TZS',
			TeePay_WC_Order_Service::META_SESSIONS    => array(
				array( 'id' => TP_Fixtures::SESSION_ID, 'amount_minor' => '500000', 'currency' => 'TZS', 'created' => time() ),
			),
		);
		return $order;
	}

	protected function all_logs() {
		return implode( "\n", array_map( function ( $entry ) { return $entry[1]; }, TP_State::$logs ) );
	}
}
