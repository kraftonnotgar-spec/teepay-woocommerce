<?php
/**
 * Gateway checkout, settings and return behaviour.
 *
 * @package TeePay\WooCommerce\Tests
 */

// phpcs:ignoreFile

final class GatewayTest extends TP_TestCase {

	private function gateway( array $overrides = array() ) {
		update_option( 'woocommerce_teepay_settings', TP_Fixtures::settings( $overrides ) );
		return new TeePay_WC_Gateway();
	}

	public function test_process_payment_redirects_to_hosted_checkout() {
		$order = new TP_Order( 1001 );
		$this->api->on( 'POST', '/v1/checkout-sessions', 201, TP_Fixtures::session( 1001 ) );

		$result = $this->gateway()->process_payment( 1001 );

		$this->assertSame( array( 'result' => 'success', 'redirect' => 'https://checkout.teepay.me/checkout/abc' ), $result );
		$this->assertSame( 1001, TP_State::$session['teepay_order_id'] );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_restricted_account_shows_shopper_a_generic_message_and_flags_admin() {
		$order = new TP_Order( 1001 );
		$this->api->on( 'POST', '/v1/checkout-sessions', 403, array( 'error' => array( 'code' => 'account_restricted', 'message' => 'Account restricted.', 'request_id' => 'req_restricted' ) ) );

		$result = $this->gateway()->process_payment( 1001 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertCount( 1, TP_State::$notices );
		$this->assertSame( 'error', TP_State::$notices[0]['type'] );
		$this->assertStringContainsString( 'unavailable', TP_State::$notices[0]['message'] );
		$this->assertStringNotContainsStringIgnoringCase( 'restrict', TP_State::$notices[0]['message'] );

		$state = TeePay_WC_Restriction::get();
		$this->assertSame( 'req_restricted', $state['request_id'] );
		$this->assertTrue( TeePay_WC_Restriction::is_paused( 'live' ) );
		$this->assertStringContainsString( 'restricted your account', TeePay_WC_Plugin::restricted_message( 'req_restricted' ) );
		$this->assertStringContainsString( 'req_restricted', end( $order->notes ) );

		ob_start();
		TeePay_WC_Plugin::admin_notices();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'restricted your account', $html );
		$this->assertStringContainsString( 'req_restricted', $html );

		TP_State::$user_can = false;
		ob_start();
		TeePay_WC_Plugin::admin_notices();
		$this->assertSame( '', ob_get_clean(), 'Only shop managers see the notice.' );
	}

	public function test_gateway_is_hidden_while_restricted_then_offered_again() {
		$gateway = $this->gateway();
		$this->assertTrue( $gateway->is_available() );
		TeePay_WC_Restriction::record( 'live', 'r' );
		$this->assertFalse( $gateway->is_available() );
		TP_State::$options[ TeePay_WC_Restriction::OPTION ]['at'] = time() - TeePay_WC_Restriction::PAUSE - 1;
		$this->assertTrue( $gateway->is_available() );
	}

	public function test_successful_call_clears_the_restriction() {
		TeePay_WC_Restriction::record( 'live', 'r' );
		new TP_Order( 1001 );
		$this->api->on( 'POST', '/v1/checkout-sessions', 201, TP_Fixtures::session( 1001 ) );
		$this->gateway()->process_payment( 1001 );
		$this->assertNull( TeePay_WC_Restriction::get() );
	}

	public function test_other_api_errors_map_to_friendly_messages() {
		new TP_Order( 1001 );
		$this->api->on( 'POST', '/v1/checkout-sessions', 422, array( 'error' => array( 'code' => 'invalid_amount', 'message' => 'x', 'request_id' => 'r' ) ) );
		$this->gateway()->process_payment( 1001 );
		$this->assertStringContainsString( 'cannot be paid with mobile money', TP_State::$notices[0]['message'] );
		$this->assertNull( TeePay_WC_Restriction::get() );
	}

	public function test_network_failure_asks_shopper_to_retry() {
		new TP_Order( 1001 );
		$this->api->on( 'POST', '/v1/checkout-sessions', 0, '' );
		$result = $this->gateway()->process_payment( 1001 );
		$this->assertSame( 'failure', $result['result'] );
		$this->assertStringContainsString( 'try again in a moment', TP_State::$notices[0]['message'] );
	}

	public function test_push_mode_rejects_invalid_phone_without_calling_teepay() {
		new TP_Order( 1001 );
		$_POST['teepay_phone'] = '0222 123 456';
		$gateway = $this->gateway( array( 'payment_mode' => 'push' ) );

		$this->assertFalse( $gateway->validate_fields() );
		$this->assertSame( 'failure', $gateway->process_payment( 1001 )['result'] );
		$this->assertSame( array(), $this->api->requests );
		$this->assertStringContainsString( 'valid Tanzanian mobile money number', TP_State::$notices[0]['message'] );
	}

	public function test_push_mode_sends_prompt_and_waits_on_order_received_page() {
		$order = new TP_Order( 1001 );
		$_POST['teepay_phone'] = '0712 345 678';
		$this->api->on( 'POST', '/v1/collections', 202, TP_Fixtures::collection() );

		$result = $this->gateway( array( 'payment_mode' => 'push' ) )->process_payment( 1001 );

		$this->assertSame( $order->get_checkout_order_received_url(), $result['redirect'] );
		$this->assertSame( '+255712345678', json_decode( $this->api->last()['body'], true )['customer']['phone'] );
		$this->assertFalse( $order->is_paid() );
		$this->assertStringContainsString( '+255712***678', end( $order->notes ) );
	}

	public function test_secret_fields_never_print_the_saved_value() {
		$gateway = $this->gateway();
		$html    = $gateway->generate_teepay_secret_html( 'live_api_key', $gateway->form_fields['live_api_key'] );
		$this->assertStringNotContainsString( TP_Fixtures::LIVE_KEY, $html );
		$this->assertStringNotContainsString( 'secretvalue', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringContainsString( 'value=""', $html );
		$this->assertStringContainsString( 'Leave blank to keep it', $html );
	}

	public function test_secret_validation_blank_keeps_clear_removes_wrong_prefix_rejected() {
		$gateway = $this->gateway();
		$this->assertSame( TP_Fixtures::LIVE_KEY, $gateway->validate_teepay_secret_field( 'live_api_key', '' ) );
		$this->assertSame( TP_Fixtures::LIVE_KEY, $gateway->validate_teepay_secret_field( 'live_api_key', TP_Fixtures::TEST_KEY ) );
		$this->assertCount( 1, TP_State::$admin['errors'] );
		$this->assertSame( 'tp_live_new12345.value', $gateway->validate_teepay_secret_field( 'live_api_key', ' tp_live_new12345.value ' ) );

		$gateway->set_post_data( array( 'woocommerce_teepay_live_api_key_clear' => '1' ) );
		$this->assertSame( '', $gateway->validate_teepay_secret_field( 'live_api_key', '' ) );
	}

	public function test_payment_profile_id_must_be_a_uuid() {
		$gateway = $this->gateway();
		$this->assertSame( '', $gateway->validate_payment_profile_id_field( 'payment_profile_id', 'default' ) );
		$this->assertSame( '3f1e2d3c-4b5a-4c6d-8e7f-9a0b1c2d3e4f', $gateway->validate_payment_profile_id_field( 'payment_profile_id', '3F1E2D3C-4B5A-4C6D-8E7F-9A0B1C2D3E4F' ) );
	}

	public function test_return_url_query_string_cannot_mark_an_order_paid() {
		$order = $this->order_with_session();
		$_GET  = array( 'status' => 'paid', 'order_id' => '1001', 'payment_reference' => 'FAKE', 'amount_minor' => '500000' );
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::session( 1001 ) );

		$url = TeePay_WC_Plugin::return_destination( 1001, $this->service() );

		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 0, $order->payment_complete_calls );
		$this->assertSame( $order->get_checkout_order_received_url(), $url );
		$this->assertSame( 1, $this->api->count( 'GET', '/v1/checkout-sessions/' ) );
	}

	public function test_return_confirms_paid_with_an_api_read() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001 ) );
		TeePay_WC_Plugin::return_destination( 1001, $this->service() );
		$this->assertTrue( $order->is_paid() );
	}

	public function test_return_without_session_order_goes_to_the_shop() {
		$this->assertSame( 'https://shop.example.tz/shop/', TeePay_WC_Plugin::return_destination( 0, $this->service() ) );
		$this->assertSame( array(), $this->api->requests );
	}

	public function test_return_after_expiry_sends_customer_back_to_pay() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::session( 1001, array( 'status' => 'expired' ) ) );
		$this->assertSame( $order->get_checkout_payment_url(), TeePay_WC_Plugin::return_destination( 1001, $this->service() ) );
	}

	public function test_connection_check_reports_restriction_and_sandbox_limits() {
		$this->api->on( 'GET', '/v1/capabilities', 403, array( 'error' => array( 'code' => 'account_restricted', 'message' => 'x', 'request_id' => 'req_c' ) ) );
		$result = TeePay_WC_Plugin::check_connection( TP_Fixtures::settings() );
		$this->assertSame( 'error', $result['level'] );
		$this->assertStringContainsString( 'restricted your account', $result['message'] );

		$api = new TP_Api();
		TP_State::$filters = array();
		add_filter( 'teepay_wc_api_transport', function () use ( $api ) { return $api->transport(); } );
		$api->on( 'GET', '/v1/capabilities', 200, array( 'environment' => 'sandbox', 'features' => array( 'checkout_sessions' => false ), 'supported_currencies' => array() ) );
		$result = TeePay_WC_Plugin::check_connection( TP_Fixtures::settings( array( 'testmode' => 'yes' ) ) );
		$this->assertStringContainsString( 'live mode with a small amount', $result['message'] );
	}

	public function test_connection_check_success_caches_currencies() {
		$this->api->on( 'GET', '/v1/capabilities', 200, array( 'environment' => 'live', 'features' => array( 'checkout_sessions' => true ), 'supported_currencies' => array( 'TZS' ) ) );
		$result = TeePay_WC_Plugin::check_connection( TP_Fixtures::settings() );
		$this->assertSame( 'ok', $result['level'] );
		$this->assertSame( array( 'TZS' ), TeePay_WC_Plugin::supported_currencies( 'live' ) );
	}

	public function test_sandbox_mode_is_shown_to_shoppers_and_merchants() {
		$gateway = $this->gateway( array( 'testmode' => 'yes' ) );
		ob_start();
		$gateway->payment_fields();
		$this->assertStringContainsString( 'SANDBOX MODE', ob_get_clean() );
		ob_start();
		$gateway->admin_options();
		$this->assertStringContainsString( 'Sandbox mode is on', ob_get_clean() );
	}

	public function test_refunds_are_not_advertised() {
		$this->assertSame( array( 'products' ), $this->gateway()->supports );
	}
}
