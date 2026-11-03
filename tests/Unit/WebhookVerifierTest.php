<?php
/**
 * Webhook signature verification.
 *
 * @package TeePay\WooCommerce\Tests
 */

// phpcs:ignoreFile

final class WebhookVerifierTest extends TP_TestCase {

	const SECRET = 'whsec_docs_example_secret';
	const BODY   = '{"id":"evt_1","event":"payment.completed"}';
	const TS     = 1761645605;

	public function test_matches_an_independently_computed_signature() {
		// Computed with Node.js crypto and Python hmac (the algorithm TeePay's signer uses).
		$expected = 'v1=6cf01c563795205c72212d06c363ceb73c2c249d6dcb4fc11db0a5ca60016abb';
		$this->assertSame( $expected, TeePay_WC_Webhook_Verifier::sign( self::BODY, self::TS, self::SECRET ) );
		$this->assertTrue( TeePay_WC_Webhook_Verifier::verify( self::BODY, (string) self::TS, $expected, self::SECRET, self::TS ) );
	}

	public function test_tampered_body_is_rejected() {
		$signature = TeePay_WC_Webhook_Verifier::sign( self::BODY, self::TS, self::SECRET );
		$this->assertFalse( TeePay_WC_Webhook_Verifier::verify( str_replace( 'completed', 'failed', self::BODY ), (string) self::TS, $signature, self::SECRET, self::TS ) );
	}

	public function test_body_is_compared_byte_for_byte() {
		$signature = TeePay_WC_Webhook_Verifier::sign( self::BODY, self::TS, self::SECRET );
		$this->assertFalse( TeePay_WC_Webhook_Verifier::verify( self::BODY . "\n", (string) self::TS, $signature, self::SECRET, self::TS ) );
	}

	public function test_wrong_secret_is_rejected() {
		$signature = TeePay_WC_Webhook_Verifier::sign( self::BODY, self::TS, 'whsec_other_secret_value' );
		$this->assertFalse( TeePay_WC_Webhook_Verifier::verify( self::BODY, (string) self::TS, $signature, self::SECRET, self::TS ) );
	}

	public function test_timestamp_is_part_of_the_signature() {
		$signature = TeePay_WC_Webhook_Verifier::sign( self::BODY, self::TS, self::SECRET );
		$this->assertFalse( TeePay_WC_Webhook_Verifier::verify( self::BODY, (string) ( self::TS + 10 ), $signature, self::SECRET, self::TS ) );
	}

	public function test_replayed_request_older_than_five_minutes_is_rejected() {
		$signature = TeePay_WC_Webhook_Verifier::sign( self::BODY, self::TS, self::SECRET );
		$this->assertTrue( TeePay_WC_Webhook_Verifier::verify( self::BODY, (string) self::TS, $signature, self::SECRET, self::TS + 300 ) );
		$this->assertFalse( TeePay_WC_Webhook_Verifier::verify( self::BODY, (string) self::TS, $signature, self::SECRET, self::TS + 301 ) );
	}

	public function test_timestamp_far_in_the_future_is_rejected() {
		$signature = TeePay_WC_Webhook_Verifier::sign( self::BODY, self::TS, self::SECRET );
		$this->assertFalse( TeePay_WC_Webhook_Verifier::verify( self::BODY, (string) self::TS, $signature, self::SECRET, self::TS - 301 ) );
	}

	/**
	 * @dataProvider missing_parts
	 */
	public function test_missing_or_malformed_parts_are_rejected( $timestamp, $signature, $secret ) {
		$this->assertFalse( TeePay_WC_Webhook_Verifier::verify( self::BODY, $timestamp, $signature, $secret, self::TS ) );
	}

	public function missing_parts() {
		$signature = TeePay_WC_Webhook_Verifier::sign( self::BODY, self::TS, self::SECRET );
		return array(
			'no timestamp'          => array( '', $signature, self::SECRET ),
			'non-numeric timestamp' => array( '17616456O5', $signature, self::SECRET ),
			'no signature'          => array( (string) self::TS, '', self::SECRET ),
			'bare hex, no v1='      => array( (string) self::TS, substr( $signature, 3 ), self::SECRET ),
			'no secret configured'  => array( (string) self::TS, $signature, '' ),
		);
	}

	public function test_one_valid_signature_in_a_list_is_accepted() {
		$signature = TeePay_WC_Webhook_Verifier::sign( self::BODY, self::TS, self::SECRET );
		$this->assertTrue( TeePay_WC_Webhook_Verifier::verify( self::BODY, (string) self::TS, 'v1=deadbeef, ' . $signature, self::SECRET, self::TS ) );
	}
}
