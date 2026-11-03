<?php
/**
 * Starting payments and confirming orders.
 *
 * @package TeePay\WooCommerce\Tests
 */

// phpcs:ignoreFile

final class OrderServiceTest extends TP_TestCase {

	public function test_hosted_checkout_creates_a_session_for_the_order() {
		$order = new TP_Order( 1001, '5000.00' );
		$this->api->on( 'POST', '/v1/checkout-sessions', 201, TP_Fixtures::session( 1001 ) );

		$url = $this->service( array( 'payment_profile_id' => '3f1e2d3c-4b5a-4c6d-8e7f-9a0b1c2d3e4f' ) )->start_hosted_checkout( $order );

		$this->assertSame( 'https://checkout.teepay.me/checkout/abc', $url );
		$request = $this->api->last();
		$body    = json_decode( $request['body'], true );
		$tag     = TeePay_WC_Order_Service::site_tag();
		$this->assertSame(
			array(
				'title'              => 'Order #1001 at Duka & Co',
				'description'        => 'Coffee bag x 2',
				'amount_minor'       => '500000',
				'currency'           => 'TZS',
				'expires_in'         => 86400,
				'payment_profile_id' => '3f1e2d3c-4b5a-4c6d-8e7f-9a0b1c2d3e4f',
				'reference'          => 'wc-' . $tag . '-1001',
				'metadata'           => array( 'wc_order_id' => '1001', 'source' => 'woocommerce', 'site' => $tag ),
			),
			$body
		);
		$this->assertSame( array_keys( $body ), array( 'title', 'description', 'amount_minor', 'currency', 'expires_in', 'payment_profile_id', 'reference', 'metadata' ) );
		$this->assertSame( 'wc-' . $tag . '-1001-500000-TZS-s1', $request['headers']['Idempotency-Key'] );
		$this->assertSame( 'Bearer ' . TP_Fixtures::LIVE_KEY, $request['headers']['Authorization'] );
		$this->assertSame( 'live', $order->get_meta( '_teepay_environment' ) );
		$this->assertSame( TP_Fixtures::SESSION_ID, $order->get_meta( '_teepay_sessions' )[0]['id'] );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_sandbox_mode_uses_the_test_key() {
		$order = new TP_Order( 5 );
		$this->api->on( 'POST', '/v1/checkout-sessions', 201, TP_Fixtures::session( 5, array( 'environment' => 'sandbox' ) ) );
		$this->service( array( 'testmode' => 'yes' ) )->start_hosted_checkout( $order );
		$this->assertSame( 'Bearer ' . TP_Fixtures::TEST_KEY, $this->api->last()['headers']['Authorization'] );
		$this->assertSame( 'sandbox', $order->get_meta( '_teepay_environment' ) );
	}

	public function test_retrying_checkout_reuses_the_same_idempotency_key_and_session() {
		$order = new TP_Order( 1001 );
		$this->api->on( 'POST', '/v1/checkout-sessions', 201, TP_Fixtures::session( 1001 ) );
		$service = $this->service();
		$service->start_hosted_checkout( $order );
		$service->start_hosted_checkout( $order );

		$this->assertSame( $this->api->requests[0]['headers']['Idempotency-Key'], $this->api->requests[1]['headers']['Idempotency-Key'] );
		$this->assertCount( 1, $order->get_meta( '_teepay_sessions' ) );
	}

	public function test_changed_order_total_gets_a_new_key() {
		$order = new TP_Order( 1001 );
		$this->api->on( 'POST', '/v1/checkout-sessions', 201, TP_Fixtures::session( 1001 ) );
		$service = $this->service();
		$service->start_hosted_checkout( $order );
		$order->total = '6000.00';
		$service->start_hosted_checkout( $order );
		$this->assertStringContainsString( '-600000-TZS-s1', $this->api->requests[1]['headers']['Idempotency-Key'] );
	}

	public function test_expired_session_replay_moves_to_a_new_attempt() {
		$order = new TP_Order( 1001 );
		$this->api->on( 'POST', '/v1/checkout-sessions', 201, TP_Fixtures::session( 1001, array( 'status' => 'expired' ) ) )
			->on( 'POST', '/v1/checkout-sessions', 201, TP_Fixtures::session( 1001, array( 'id' => 'a1b2c3d4-0000-4000-8000-000000000001' ) ) );

		$url = $this->service()->start_hosted_checkout( $order );

		$this->assertStringEndsWith( '-s1', $this->api->requests[0]['headers']['Idempotency-Key'] );
		$this->assertStringEndsWith( '-s2', $this->api->requests[1]['headers']['Idempotency-Key'] );
		$this->assertSame( 'https://checkout.teepay.me/checkout/abc', $url );
		$this->assertCount( 2, $order->get_meta( '_teepay_sessions' ) );
	}

	public function test_idempotency_conflict_moves_to_a_new_attempt_once() {
		$order = new TP_Order( 1001 );
		$this->api->on( 'POST', '/v1/checkout-sessions', 409, array( 'error' => array( 'code' => 'idempotency_conflict', 'message' => 'x', 'request_id' => 'r' ) ) )
			->on( 'POST', '/v1/checkout-sessions', 201, TP_Fixtures::session( 1001 ) );
		$this->service()->start_hosted_checkout( $order );
		$this->assertStringEndsWith( '-s2', $this->api->last()['headers']['Idempotency-Key'] );
	}

	public function test_no_key_configured_is_an_error_without_calling_teepay() {
		$this->expectException( RuntimeException::class );
		$this->service( array( 'live_api_key' => '' ) )->start_hosted_checkout( new TP_Order( 1 ) );
	}

	public function test_live_field_holding_a_test_key_is_not_used() {
		$service = $this->service( array( 'live_api_key' => TP_Fixtures::TEST_KEY ) );
		$this->assertSame( '', $service->api_key( 'live' ) );
	}

	public function test_confirm_marks_paid_only_after_an_authenticated_paid_read() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001 ) );

		$this->assertSame( 'paid', $this->service()->confirm( $order, 'return' ) );

		$this->assertSame( 1, $order->payment_complete_calls );
		$this->assertSame( 'TP8K2M4QZ1', $order->transaction_id );
		$this->assertSame( 'TP8K2M4QZ1', $order->get_meta( '_teepay_payment_reference' ) );
		$this->assertSame( 'Bearer ' . TP_Fixtures::LIVE_KEY, $this->api->last()['headers']['Authorization'] );
		$this->assertStringContainsString( 'authenticated status read', implode( ' ', $order->notes ) );
	}

	public function test_open_session_leaves_the_order_pending() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::session( 1001 ) );
		$this->assertSame( 'pending', $this->service()->confirm( $order, 'return' ) );
		$this->assertSame( 0, $order->payment_complete_calls );
		$this->assertSame( 'pending', $order->status );
	}

	public function test_amount_mismatch_holds_the_order_and_never_completes_it() {
		$order = $this->order_with_session( 1001, '5000.00' );
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001, array( 'amount_minor' => '100' ) ) );

		$this->assertSame( 'mismatch', $this->service()->confirm( $order, 'webhook' ) );
		$this->assertSame( 0, $order->payment_complete_calls );
		$this->assertSame( 'on-hold', $order->status );
		$this->assertStringContainsString( 'does not match', end( $order->notes ) );
	}

	public function test_order_total_edited_after_payment_started_is_a_mismatch() {
		$order = $this->order_with_session( 1001, '5000.00' );
		$order->total = '9000.00';
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001 ) );
		$this->assertSame( 'mismatch', $this->service()->confirm( $order, 'webhook' ) );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_currency_mismatch_never_completes() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001, array( 'currency' => 'UGX', 'amount_minor' => '500000' ) ) );
		$this->assertSame( 'mismatch', $this->service()->confirm( $order, 'webhook' ) );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_sandbox_session_cannot_pay_a_live_order() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::paid_session( 1001, array( 'environment' => 'sandbox' ) ) );
		$this->assertSame( 'invalid', $this->service()->confirm( $order, 'webhook' ) );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_expired_session_fails_the_pending_order() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::session( 1001, array( 'status' => 'expired' ) ) );
		$this->assertSame( 'expired', $this->service()->confirm( $order, 'reconcile' ) );
		$this->assertSame( 'failed', $order->status );
	}

	public function test_already_paid_order_is_not_read_or_completed_again() {
		$order = $this->order_with_session();
		$order->status = 'processing';
		$this->assertSame( 'paid', $this->service()->confirm( $order, 'webhook' ) );
		$this->assertSame( array(), $this->api->requests );
		$this->assertSame( 0, $order->payment_complete_calls );
	}

	public function test_concurrent_confirmation_is_locked() {
		$order = $this->order_with_session();
		add_option( 'teepay_wc_lock_1001', time() );
		$this->assertSame( 'busy', $this->service()->confirm( $order, 'webhook' ) );
		$this->assertSame( array(), $this->api->requests );
	}

	public function test_stale_lock_is_taken_over() {
		$order = $this->order_with_session();
		add_option( 'teepay_wc_lock_1001', time() - 120 );
		$this->api->on( 'GET', $this->session_path(), 200, TP_Fixtures::session( 1001 ) );
		$this->assertSame( 'pending', $this->service()->confirm( $order, 'webhook' ) );
		$this->assertFalse( get_option( 'teepay_wc_lock_1001' ) );
	}

	public function test_unavailable_read_asks_for_retry_and_changes_nothing() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 503, array( 'error' => array( 'code' => 'unavailable', 'message' => 'x', 'request_id' => 'r' ) ) );
		$this->assertSame( 'retry', $this->service()->confirm( $order, 'webhook' ) );
		$this->assertSame( 'pending', $order->status );
		$this->assertFalse( get_option( 'teepay_wc_lock_1001' ) );
	}

	public function test_restricted_account_on_read_is_recorded_and_order_untouched() {
		$order = $this->order_with_session();
		$this->api->on( 'GET', $this->session_path(), 403, array( 'error' => array( 'code' => 'account_restricted', 'message' => 'x', 'request_id' => 'req_9' ) ) );
		$this->assertSame( 'error', $this->service()->confirm( $order, 'webhook' ) );
		$this->assertSame( 'pending', $order->status );
		$this->assertSame( 'req_9', TeePay_WC_Restriction::get()['request_id'] );
	}

	public function test_push_sends_the_prompt_with_a_normalised_phone() {
		$order = new TP_Order( 77 );
		$this->api->on( 'POST', '/v1/collections', 202, TP_Fixtures::collection() );
		$this->service( array( 'payment_mode' => 'push' ) )->start_push( $order, '+255712345678' );

		$request = $this->api->last();
		$body    = json_decode( $request['body'], true );
		$this->assertSame( array( 'amount_minor' => '500000', 'currency' => 'TZS' ), $body['money'] );
		$this->assertSame( array( 'country' => 'TZ', 'phone' => '+255712345678', 'name' => 'Asha Juma', 'email' => 'asha@example.com' ), $body['customer'] );
		$this->assertStringEndsWith( '-77-500000-TZS-p1', $request['headers']['Idempotency-Key'] );
		$this->assertSame( 'TPPUSH0001', $order->get_meta( '_teepay_collection_reference' ) );
		$this->assertSame( '+255712***678', $order->get_meta( '_teepay_phone_masked' ) );
	}

	public function test_push_confirm_paid_and_failed() {
		$order = new TP_Order( 77 );
		$this->api->on( 'POST', '/v1/collections', 202, TP_Fixtures::collection() )
			->on( 'GET', '/v1/collections/TPPUSH0001', 200, TP_Fixtures::collection( array( 'status' => 'failed', 'status_detail' => 'cancelled' ) ) );
		$service = $this->service( array( 'payment_mode' => 'push' ) );
		$service->start_push( $order, '+255712345678' );

		$this->assertSame( 'failed', $service->confirm( $order, 'status' ) );
		$this->assertSame( 'failed', $order->status );
		$this->assertSame( 2, (int) $order->get_meta( '_teepay_attempt' ) );

		$order2 = new TP_Order( 78 );
		$api    = new TP_Api();
		$api->on( 'GET', '/v1/collections/TPPUSH0001', 200, TP_Fixtures::collection( array( 'status' => 'succeeded' ) ) );
		$order2->meta = array( '_teepay_mode' => 'push', '_teepay_environment' => 'live', '_teepay_collection_reference' => 'TPPUSH0001' );
		$service2 = new TeePay_WC_Order_Service( TP_Fixtures::settings(), function ( $key ) use ( $api ) {
			return new TeePay_WC_Api_Client( $key, $api->transport() );
		} );
		$this->assertSame( 'paid', $service2->confirm( $order2, 'webhook' ) );
		$this->assertSame( 'TPPUSH0001', $order2->transaction_id );
	}

	public function test_reference_round_trip_and_foreign_site_rejected() {
		$this->assertSame( 1001, TeePay_WC_Order_Service::order_id_from_reference( TeePay_WC_Order_Service::order_reference( 1001 ) ) );
		$this->assertSame( 0, TeePay_WC_Order_Service::order_id_from_reference( 'wc-00000000-1001' ) );
		$this->assertSame( 0, TeePay_WC_Order_Service::order_id_from_reference( 'order-1001' ) );
	}
}
