<?php
/**
 * Decides what an authenticated TeePay status read means for an order.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure rules that turn a checkout session or collection read into an outcome.
 *
 * An order may only be paid when TeePay itself reports the payment as final (session status
 * "paid" with a payment reference, or collection status "succeeded") AND the amount and
 * currency TeePay reports equal the order's. Everything else is pending, failed or ignored.
 */
class TeePay_WC_Payment_Evaluator {

	const PAID     = 'paid';
	const PENDING  = 'pending';
	const FAILED   = 'failed';
	const EXPIRED  = 'expired';
	const REVERSED = 'reversed';
	const MISMATCH = 'mismatch';
	const INVALID  = 'invalid';
	const UNKNOWN  = 'unknown';

	/**
	 * Evaluate a checkout session read with GET /v1/checkout-sessions/{id}.
	 *
	 * @param array $session  Session from the API.
	 * @param array $expected id, reference, amount_minor, currency, environment.
	 * @return array{state:string, reason:string, payment_reference:string}
	 */
	public static function evaluate_session( array $session, array $expected ) {
		$get = static function ( $key ) use ( $session ) {
			return isset( $session[ $key ] ) && is_string( $session[ $key ] ) ? $session[ $key ] : '';
		};

		if ( 'checkout_session' !== $get( 'object' ) || '' === $get( 'id' ) || strtolower( $get( 'id' ) ) !== strtolower( (string) $expected['id'] ) ) {
			return self::result( self::INVALID, 'session_id_mismatch' );
		}
		if ( $get( 'environment' ) !== (string) $expected['environment'] ) {
			return self::result( self::INVALID, 'environment_mismatch' );
		}
		if ( $get( 'reference' ) !== (string) $expected['reference'] ) {
			return self::result( self::INVALID, 'reference_mismatch' );
		}

		switch ( $get( 'status' ) ) {
			case 'paid':
				$payment   = isset( $session['payment'] ) && is_array( $session['payment'] ) ? $session['payment'] : array();
				$reference = isset( $payment['reference'] ) && is_string( $payment['reference'] ) ? trim( $payment['reference'] ) : '';
				if ( '' === $reference ) {
					return self::result( self::UNKNOWN, 'paid_without_payment_reference' );
				}
				return self::check_money( $get( 'currency' ), isset( $session['amount_minor'] ) ? $session['amount_minor'] : null, $expected, $reference );
			case 'open':
			case 'processing':
				return self::result( self::PENDING, $get( 'status' ) );
			case 'expired':
				return self::result( self::EXPIRED, 'expired' );
			default:
				return self::result( self::UNKNOWN, '' === $get( 'status' ) ? 'missing_status' : $get( 'status' ) );
		}
	}

	/**
	 * Evaluate a collection read with GET /v1/collections/{reference}.
	 *
	 * @param array $collection Transaction from the API.
	 * @param array $expected   reference, amount_minor, currency.
	 * @return array{state:string, reason:string, payment_reference:string}
	 */
	public static function evaluate_collection( array $collection, array $expected ) {
		$reference = isset( $collection['public_reference'] ) && is_string( $collection['public_reference'] ) ? $collection['public_reference'] : '';
		if ( '' === $reference || $reference !== (string) $expected['reference'] ) {
			return self::result( self::INVALID, 'reference_mismatch' );
		}
		if ( ! isset( $collection['operation'] ) || 'charge' !== $collection['operation'] ) {
			return self::result( self::INVALID, 'not_a_charge' );
		}
		$status = isset( $collection['status'] ) && is_string( $collection['status'] ) ? $collection['status'] : '';
		$detail = isset( $collection['status_detail'] ) && is_string( $collection['status_detail'] ) ? $collection['status_detail'] : '';

		switch ( $status ) {
			case 'succeeded':
				$money = isset( $collection['money'] ) && is_array( $collection['money'] ) ? $collection['money'] : array();
				return self::check_money(
					isset( $money['currency'] ) && is_string( $money['currency'] ) ? $money['currency'] : '',
					isset( $money['amount_minor'] ) ? $money['amount_minor'] : null,
					$expected,
					$reference
				);
			case 'created':
			case 'pending':
			case 'processing':
				return self::result( self::PENDING, $status );
			case 'failed':
				return self::result( 'expired' === $detail ? self::EXPIRED : self::FAILED, '' === $detail ? 'failed' : $detail );
			case 'reversed':
			case 'refunded':
				return self::result( self::REVERSED, $status );
			default:
				return self::result( self::UNKNOWN, '' === $status ? 'missing_status' : $status );
		}
	}

	/**
	 * Compare TeePay's amount and currency with the order's.
	 *
	 * @param string $currency  Reported currency.
	 * @param mixed  $amount    Reported amount_minor (string or integer).
	 * @param array  $expected  amount_minor, currency.
	 * @param string $reference Payment reference.
	 * @return array
	 */
	private static function check_money( $currency, $amount, array $expected, $reference ) {
		if ( $currency !== (string) $expected['currency'] ) {
			return self::result( self::MISMATCH, 'currency_mismatch', $reference );
		}
		$reported = TeePay_WC_Money::normalize_minor( $amount );
		if ( '' === $reported || TeePay_WC_Money::normalize_minor( (string) $expected['amount_minor'] ) !== $reported ) {
			return self::result( self::MISMATCH, 'amount_mismatch', $reference );
		}

		return self::result( self::PAID, 'paid', $reference );
	}

	/**
	 * Build a result.
	 *
	 * @param string $state     State.
	 * @param string $reason    Reason.
	 * @param string $reference Payment reference.
	 * @return array
	 */
	private static function result( $state, $reason, $reference = '' ) {
		return array(
			'state'             => $state,
			'reason'            => $reason,
			'payment_reference' => $reference,
		);
	}
}
