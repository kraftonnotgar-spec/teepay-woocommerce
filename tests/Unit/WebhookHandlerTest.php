<?php
/**
 * Webhook endpoint behaviour.
 *
 * @package TeePay\WooCommerce\Tests
 */

// phpcs:ignoreFile

final class WebhookHandlerTest extends TP_TestCase {

	private function handler( array $overrides = array() ) {
		return new TeePay_WC_Webhook_Handler( $this->service( $overrides ) );
	}

	public function test_verified_completed_webhook_completes_order_after_status_read() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001 ) );
		$body = TP_Fixtures::event( array() );

		list( $status, $response ) = $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ) );

		$this->assertSame( 200, $status );
		$this->assertSame( 'paid', $response['status'] );
		$this->assertSame( 1, $order->payment_complete_calls );
		$this->assertSame( 'TP8K2M4QZ1', $order->transaction_id );
		$this->assertSame( 1, $this->api->count( 'GET', '/v1/checkout-sessions/' ), 'The session read while matching the order is reused.' );
	}

	public function test_lowercase_header_names_are_accepted() {
		$this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001 ) );
		$body    = TP_Fixtures::event( array() );
		$headers = array_change_key_case( TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ), CASE_LOWER );
		$this->assertSame( 200, $this->handler()->handle( $body, $headers )[0] );
	}

	public function test_invalid_signature_is_rejected_without_calling_teepay() {
		$order = $this->order_with_session();
		$body  = TP_Fixtures::event( array() );

		list( $status ) = $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, 'whsec_attacker_guess_000000000' ) );

		$this->assertSame( 401, $status );
		$this->assertSame( array(), $this->api->requests );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_tampered_body_is_rejected() {
		$order   = $this->order_with_session();
		$body    = TP_Fixtures::event( array() );
		$headers = TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK );
		$this->assertSame( 401, $this->handler()->handle( str_replace( '500000', '1', $body ), $headers )[0] );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_replayed_old_webhook_is_rejected() {
		$order = $this->order_with_session();
		$body  = TP_Fixtures::event( array() );
		$sent  = time() - 3600;

		list( $status ) = $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK, $sent ) );

		$this->assertSame( 401, $status );
		$this->assertSame( array(), $this->api->requests );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_same_event_delivered_twice_is_processed_once() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::session( 1001, array( 'status' => 'processing' ) ) );
		$body    = TP_Fixtures::event( array() );
		$headers = TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK );
		$handler = $this->handler();

		$this->assertSame( 'pending', $handler->handle( $body, $headers )[1]['status'] );
		$reads = count( $this->api->requests );
		$this->assertSame( 'duplicate', $handler->handle( $body, $headers )[1]['status'] );
		$this->assertSame( array( '0f8d7c2e-5b1a-5e3f-9c4d-7a6b2e1f0c9d' ), $order->get_meta( '_teepay_event_ids' ) );
		$this->assertSame( $reads + 1, count( $this->api->requests ), 'Only the order lookup read happens for a duplicate.' );
	}

	public function test_redelivered_paid_event_does_not_complete_twice() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001 ) );
		$handler = $this->handler();
		$first   = TP_Fixtures::event( array(), 'payment.completed', 'live', '11111111-1111-4111-8111-111111111111' );
		$second  = TP_Fixtures::event( array(), 'payment.completed', 'live', '22222222-2222-4222-8222-222222222222' );
		$handler->handle( $first, TP_Fixtures::signed_headers( $first, TP_Fixtures::LIVE_HOOK, null, '11111111-1111-4111-8111-111111111111' ) );
		$handler->handle( $second, TP_Fixtures::signed_headers( $second, TP_Fixtures::LIVE_HOOK, null, '22222222-2222-4222-8222-222222222222' ) );
		$this->assertSame( 1, $order->payment_complete_calls );
	}

	public function test_signed_completed_event_is_not_trusted_when_teepay_says_open() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::session( 1001 ) );
		$body = TP_Fixtures::event( array() );
		$this->assertSame( 'pending', $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ) )[1]['status'] );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_webhook_money_is_ignored_in_favour_of_the_api_read() {
		$order = $this->order_with_session( 1001, '5000.00' );
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001, array( 'amount_minor' => '1000' ) ) );
		$body = TP_Fixtures::event( array( 'money' => array( 'currency' => 'TZS', 'amount_minor' => '500000' ) ) );
		$this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ) );
		$this->assertFalse( $order->is_paid() );
		$this->assertSame( 'on-hold', $order->status );
	}

	public function test_session_from_another_store_is_ignored() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001, array( 'reference' => 'wc-ffffffff-1001' ) ) );
		$body = TP_Fixtures::event( array() );
		$this->assertSame( 'no_matching_order', $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ) )[1]['status'] );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_session_not_recorded_on_the_order_is_ignored() {
		$order = $this->order_with_session();
		$order->meta['_teepay_sessions'] = array( array( 'id' => 'b2f3c9d0-0000-4000-8000-000000000000' ) );
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001 ) );
		$body = TP_Fixtures::event( array() );
		$this->assertSame( 'no_matching_order', $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ) )[1]['status'] );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_order_paid_with_another_gateway_is_ignored() {
		$order = $this->order_with_session();
		$order->payment_method = 'cod';
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001 ) );
		$body = TP_Fixtures::event( array() );
		$this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ) );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_sandbox_secret_cannot_deliver_a_live_event() {
		$order = $this->order_with_session();
		$body  = TP_Fixtures::event( array(), 'payment.completed', 'live' );
		$this->assertSame( 400, $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::TEST_HOOK ) )[0] );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_event_id_header_must_match_body() {
		$this->order_with_session();
		$body = TP_Fixtures::event( array() );
		$this->assertSame( 400, $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK, null, 'other' ) )[0] );
	}

	public function test_payout_events_are_acknowledged_and_ignored() {
		$body = TP_Fixtures::event( array(), 'payout.completed' );
		$this->assertSame( array( 200, array( 'status' => 'ignored' ) ), $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ) ) );
		$this->assertSame( array(), $this->api->requests );
	}

	public function test_failed_event_with_open_session_changes_nothing() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::session( 1001 ) );
		$body = TP_Fixtures::event( array( 'status' => 'failed' ), 'payment.failed' );
		$this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ) );
		$this->assertSame( 'pending', $order->status );
	}

	public function test_api_outage_returns_503_so_teepay_retries() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 503, array( 'error' => array( 'code' => 'unavailable', 'message' => 'x', 'request_id' => 'r' ) ) );
		$body = TP_Fixtures::event( array() );
		$this->assertSame( 503, $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ) )[0] );
		$this->assertSame( '', $order->get_meta( '_teepay_event_ids' ) );
	}

	public function test_restricted_account_is_recorded_and_acknowledged() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 403, array( 'error' => array( 'code' => 'account_restricted', 'message' => 'x', 'request_id' => 'req_7' ) ) );
		$body = TP_Fixtures::event( array() );
		list( $status, $response ) = $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ) );
		$this->assertSame( 200, $status );
		$this->assertSame( 'not_confirmed', $response['status'] );
		$this->assertNotNull( TeePay_WC_Restriction::get() );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_push_payment_webhook_matches_by_collection_reference() {
		$order = new TP_Order( 77 );
		$order->meta = array( '_teepay_mode' => 'push', '_teepay_environment' => 'live', '_teepay_collection_reference' => 'TPPUSH0001' );
		$this->api->on( 'GET', '/v1/collections/TPPUSH0001', 200, TP_Fixtures::collection( array( 'status' => 'succeeded' ) ) );
		$body = TP_Fixtures::event( array( 'reference' => 'TPPUSH0001', 'source' => null ) );
		$this->assertSame( 'paid', $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ) )[1]['status'] );
		$this->assertTrue( $order->is_paid() );
	}

	public function test_malformed_json_is_rejected_after_signature() {
		$body = '{not json';
		$this->assertSame( 400, $this->handler()->handle( $body, TP_Fixtures::signed_headers( $body, TP_Fixtures::LIVE_HOOK ) )[0] );
	}
}
