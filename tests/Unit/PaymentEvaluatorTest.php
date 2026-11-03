<?php
/**
 * Rules that decide whether a TeePay read means "paid".
 *
 * @package TeePay\WooCommerce\Tests
 */

// phpcs:ignoreFile

final class PaymentEvaluatorTest extends TP_TestCase {

	private function expected( array $overrides = array() ) {
		return array_merge(
			array(
				'id'           => TP_Fixtures::SESSION_ID,
				'reference'    => TeePay_WC_Order_Service::order_reference( 7 ),
				'amount_minor' => '500000',
				'currency'     => 'TZS',
				'environment'  => 'live',
			),
			$overrides
		);
	}

	private function state( array $session, array $expected = array() ) {
		return TeePay_WC_Payment_Evaluator::evaluate_session( $session, $this->expected( $expected ) );
	}

	public function test_paid_session_with_matching_amount_and_currency_is_paid() {
		$result = $this->state( TP_Fixtures::paid_session( 7 ) );
		$this->assertSame( 'paid', $result['state'] );
		$this->assertSame( 'TP8K2M4QZ1', $result['payment_reference'] );
	}

	public function test_paid_session_with_a_different_amount_is_a_mismatch() {
		$this->assertSame( 'mismatch', $this->state( TP_Fixtures::paid_session( 7, array( 'amount_minor' => '100' ) ) )['state'] );
		$this->assertSame( 'amount_mismatch', $this->state( TP_Fixtures::paid_session( 7 ), array( 'amount_minor' => '500001' ) )['reason'] );
	}

	public function test_paid_session_in_another_currency_is_a_mismatch() {
		$result = $this->state( TP_Fixtures::paid_session( 7, array( 'currency' => 'KES' ) ) );
		$this->assertSame( 'mismatch', $result['state'] );
		$this->assertSame( 'currency_mismatch', $result['reason'] );
	}

	public function test_paid_without_payment_reference_is_not_trusted() {
		$this->assertSame( 'unknown', $this->state( TP_Fixtures::session( 7, array( 'status' => 'paid' ) ) )['state'] );
	}

	public function test_open_and_processing_are_pending() {
		$this->assertSame( 'pending', $this->state( TP_Fixtures::session( 7 ) )['state'] );
		$this->assertSame( 'pending', $this->state( TP_Fixtures::session( 7, array( 'status' => 'processing' ) ) )['state'] );
	}

	public function test_expired_and_unavailable() {
		$this->assertSame( 'expired', $this->state( TP_Fixtures::session( 7, array( 'status' => 'expired' ) ) )['state'] );
		$this->assertSame( 'unknown', $this->state( TP_Fixtures::session( 7, array( 'status' => 'unavailable' ) ) )['state'] );
	}

	public function test_identity_checks() {
		$this->assertSame( 'session_id_mismatch', $this->state( TP_Fixtures::paid_session( 7, array( 'id' => 'b2f3c9d0-0000-4000-8000-000000000000' ) ) )['reason'] );
		$this->assertSame( 'environment_mismatch', $this->state( TP_Fixtures::paid_session( 7, array( 'environment' => 'sandbox' ) ) )['reason'] );
		$this->assertSame( 'reference_mismatch', $this->state( TP_Fixtures::paid_session( 8 ) )['reason'] );
		$this->assertSame( 'invalid', $this->state( TP_Fixtures::paid_session( 7, array( 'object' => 'collection' ) ) )['state'] );
	}

	public function test_collection_rules() {
		$expected = array( 'reference' => 'TPPUSH0001', 'amount_minor' => '500000', 'currency' => 'TZS' );
		$eval     = function ( array $overrides ) use ( $expected ) {
			return TeePay_WC_Payment_Evaluator::evaluate_collection( TP_Fixtures::collection( $overrides ), $expected );
		};

		$this->assertSame( 'paid', $eval( array( 'status' => 'succeeded' ) )['state'] );
		$this->assertSame( 'paid', $eval( array( 'status' => 'succeeded', 'money' => array( 'amount_minor' => '500000', 'currency' => 'TZS' ) ) )['state'] );
		$this->assertSame( 'mismatch', $eval( array( 'status' => 'succeeded', 'money' => array( 'amount_minor' => 1000, 'currency' => 'TZS' ) ) )['state'] );
		$this->assertSame( 'pending', $eval( array( 'status' => 'processing' ) )['state'] );
		$this->assertSame( 'failed', $eval( array( 'status' => 'failed', 'status_detail' => 'cancelled' ) )['state'] );
		$this->assertSame( 'expired', $eval( array( 'status' => 'failed', 'status_detail' => 'expired' ) )['state'] );
		$this->assertSame( 'reversed', $eval( array( 'status' => 'refunded' ) )['state'] );
		$this->assertSame( 'invalid', $eval( array( 'status' => 'succeeded', 'operation' => 'payout' ) )['state'] );
		$this->assertSame( 'invalid', $eval( array( 'status' => 'succeeded', 'public_reference' => 'OTHER' ) )['state'] );
	}
}
