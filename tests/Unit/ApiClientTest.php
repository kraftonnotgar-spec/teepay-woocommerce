<?php
/**
 * API client request shape and error parsing.
 *
 * @package TeePay\WooCommerce\Tests
 */

// phpcs:ignoreFile

final class ApiClientTest extends TP_TestCase {

	private function client( $key = TP_Fixtures::LIVE_KEY ) {
		return new TeePay_WC_Api_Client( $key, $this->api->transport() );
	}

	public function test_create_checkout_session_sends_the_documented_request() {
		$this->api->on( 'POST', '/v1/checkout-sessions', 201, TP_Fixtures::session( 1 ) );

		$session = $this->client()->create_checkout_session(
			array(
				'title'        => 'Order 1001',
				'description'  => '2 x Coffee bag',
				'amount_minor' => '500000',
				'currency'     => 'TZS',
				'expires_in'   => 86400,
				'reference'    => 'order-1001',
				'metadata'     => array( 'cart' => 'c-77' ),
			),
			'order-1001-key'
		);

		$request = $this->api->last();
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( 'https://api.teepay.me/v1/checkout-sessions', $request['url'] );
		$this->assertSame( 'Bearer ' . TP_Fixtures::LIVE_KEY, $request['headers']['Authorization'] );
		$this->assertSame( 'order-1001-key', $request['headers']['Idempotency-Key'] );
		$this->assertSame( 'application/json', $request['headers']['Content-Type'] );
		$this->assertSame( 'application/json', $request['headers']['Accept'] );
		$this->assertSame( '2026-10-18', $request['headers']['TeePay-Version'] );
		$this->assertStringStartsWith( 'teepay-for-woocommerce/', $request['headers']['User-Agent'] );
		$this->assertSame(
			'{"title":"Order 1001","description":"2 x Coffee bag","amount_minor":"500000","currency":"TZS","expires_in":86400,"reference":"order-1001","metadata":{"cart":"c-77"}}',
			$request['body']
		);
		$this->assertSame( 'checkout_session', $session['object'] );
	}

	public function test_amount_is_sent_as_a_string_of_minor_units() {
		$body = TeePay_WC_Api_Client::checkout_session_body( array( 'title' => 'x', 'amount_minor' => 1999, 'currency' => 'TZS' ) );
		$this->assertSame( '1999', $body['amount_minor'] );
	}

	/**
	 * @dataProvider invalid_session_bodies
	 */
	public function test_invalid_session_bodies_are_rejected_before_sending( array $input ) {
		$this->expectException( InvalidArgumentException::class );
		try {
			$this->client()->create_checkout_session( $input, 'order-1001-key' );
		} finally {
			$this->assertSame( array(), $this->api->requests );
		}
	}

	public function invalid_session_bodies() {
		$valid = array( 'title' => 'Order', 'amount_minor' => '500000', 'currency' => 'TZS' );
		return array(
			'unknown field'      => array( $valid + array( 'return_url' => 'https://evil.example' ) ),
			'missing title'      => array( array( 'amount_minor' => '1', 'currency' => 'TZS' ) ),
			'zero amount'        => array( array_merge( $valid, array( 'amount_minor' => '0' ) ) ),
			'decimal amount'     => array( array_merge( $valid, array( 'amount_minor' => '50.00' ) ) ),
			'lowercase currency' => array( array_merge( $valid, array( 'currency' => 'tzs' ) ) ),
			'short expiry'       => array( $valid + array( 'expires_in' => 60 ) ),
			'bad reference'      => array( $valid + array( 'reference' => 'order 1/2' ) ),
			'bad profile'        => array( $valid + array( 'payment_profile_id' => 'default' ) ),
			'too much metadata'  => array( $valid + array( 'metadata' => array_fill_keys( range( 'a', 'k' ), 'v' ) ) ),
		);
	}

	public function test_idempotency_key_is_required_and_validated() {
		$this->expectException( InvalidArgumentException::class );
		$this->client()->create_checkout_session( array( 'title' => 'x', 'amount_minor' => '1', 'currency' => 'TZS' ), 'short' );
	}

	public function test_get_checkout_session_is_an_authenticated_get() {
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::session( 1 ) );
		$this->client()->get_checkout_session( TP_Fixtures::SESSION_ID );
		$request = $this->api->last();
		$this->assertSame( 'GET', $request['method'] );
		$this->assertSame( 'https://api.teepay.me' . $this->session_path(), $request['url'] );
		$this->assertSame( 'Bearer ' . TP_Fixtures::LIVE_KEY, $request['headers']['Authorization'] );
		$this->assertNull( $request['body'] );
		$this->assertArrayNotHasKey( 'Content-Type', $request['headers'] );
	}

	public function test_session_id_must_be_a_uuid_so_paths_cannot_be_injected() {
		$this->expectException( InvalidArgumentException::class );
		$this->client()->get_checkout_session( '../whoami' );
	}

	public function test_collection_reference_is_validated() {
		$this->expectException( InvalidArgumentException::class );
		$this->client()->get_collection( 'TP1/../../v1/whoami' );
	}

	public function test_create_collection_sends_the_documented_request() {
		$this->api->on( 'POST', '/v1/collections', 202, TP_Fixtures::collection() );
		$this->client()->create_collection(
			array(
				'money'       => array( 'amount_minor' => '500000', 'currency' => 'TZS' ),
				'customer'    => array( 'country' => 'TZ', 'phone' => '+255712345678', 'name' => 'Asha Juma', 'email' => 'asha@example.com' ),
				'description' => 'Order 1001',
			),
			'order-1001-push'
		);
		$request = $this->api->last();
		$this->assertSame( 'https://api.teepay.me/v1/collections', $request['url'] );
		$this->assertSame( 'order-1001-push', $request['headers']['Idempotency-Key'] );
		$this->assertSame(
			'{"money":{"amount_minor":"500000","currency":"TZS"},"customer":{"country":"TZ","phone":"+255712345678","name":"Asha Juma","email":"asha@example.com"},"description":"Order 1001"}',
			$request['body']
		);
	}

	public function test_keys_must_have_a_teepay_prefix() {
		$this->expectException( InvalidArgumentException::class );
		new TeePay_WC_Api_Client( 'sk_live_123456789' );
	}

	public function test_environment_follows_the_key_prefix() {
		$this->assertSame( 'live', $this->client()->environment() );
		$this->assertSame( 'sandbox', $this->client( TP_Fixtures::TEST_KEY )->environment() );
	}

	public function test_account_restricted_error_is_recognised() {
		$this->api->on( 'POST', '/v1/checkout-sessions', 403, array( 'error' => array( 'code' => 'account_restricted', 'message' => 'This account is restricted.', 'request_id' => 'req_42' ) ) );
		try {
			$this->client()->create_checkout_session( array( 'title' => 'x', 'amount_minor' => '1', 'currency' => 'TZS' ), 'order-1001-key' );
			$this->fail( 'Expected an exception.' );
		} catch ( TeePay_WC_Api_Exception $e ) {
			$this->assertTrue( $e->is_account_restricted() );
			$this->assertFalse( $e->is_retryable() );
			$this->assertSame( 'req_42', $e->get_request_id() );
			$this->assertSame( 403, $e->get_http_status() );
		}
	}

	public function test_other_403_codes_are_not_treated_as_restriction() {
		$this->api->on( 'GET', '/v1/capabilities', 403, array( 'error' => array( 'code' => 'scope_denied', 'message' => 'x', 'request_id' => 'r' ) ) );
		try {
			$this->client()->capabilities();
			$this->fail( 'Expected an exception.' );
		} catch ( TeePay_WC_Api_Exception $e ) {
			$this->assertFalse( $e->is_account_restricted() );
			$this->assertSame( 'scope_denied', $e->get_error_code() );
		}
	}

	public function test_unavailable_is_retryable_and_reads_retry_after() {
		$this->api->on( 'GET', '/v1/whoami', 503, array( 'error' => array( 'code' => 'unavailable', 'message' => 'x', 'request_id' => 'r1' ) ), array( 'Retry-After' => '5' ) );
		try {
			$this->client()->whoami();
			$this->fail( 'Expected an exception.' );
		} catch ( TeePay_WC_Api_Exception $e ) {
			$this->assertTrue( $e->is_retryable() );
			$this->assertSame( 5, $e->get_retry_after() );
		}
	}

	public function test_error_without_json_body_gets_a_code_from_the_status() {
		$this->api->on( 'GET', '/v1/whoami', 429, 'Too Many Requests' );
		try {
			$this->client()->whoami();
			$this->fail( 'Expected an exception.' );
		} catch ( TeePay_WC_Api_Exception $e ) {
			$this->assertSame( 'rate_limited', $e->get_error_code() );
			$this->assertTrue( $e->is_retryable() );
		}
	}

	public function test_success_that_is_not_json_is_an_error() {
		$this->api->on( 'GET', '/v1/whoami', 200, '<html>proxy</html>' );
		$this->expectException( TeePay_WC_Api_Exception::class );
		$this->client()->whoami();
	}
}
