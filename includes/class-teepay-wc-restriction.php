<?php
/**
 * Account restriction state.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remembers that TeePay answered 403 account_restricted so merchants see a clear admin notice,
 * and pauses the payment method briefly so shoppers are not offered a method that cannot work.
 */
class TeePay_WC_Restriction {

	const OPTION = 'teepay_wc_account_restricted';

	/**
	 * Seconds the payment method stays hidden from checkout after a restriction response.
	 */
	const PAUSE = 600;

	/**
	 * Record a restriction response.
	 *
	 * @param string $environment live or sandbox.
	 * @param string $request_id  TeePay request ID.
	 * @return void
	 */
	public static function record( $environment, $request_id ) {
		update_option(
			self::OPTION,
			array(
				'environment' => (string) $environment,
				'request_id'  => (string) $request_id,
				'at'          => time(),
			),
			false
		);
	}

	/**
	 * Clear after a successful API call.
	 *
	 * @param string $environment live or sandbox.
	 * @return void
	 */
	public static function clear( $environment ) {
		$state = self::get();
		if ( null !== $state && $state['environment'] === $environment ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Current restriction state, if any.
	 *
	 * @return array|null environment, request_id, at.
	 */
	public static function get() {
		$state = get_option( self::OPTION );
		if ( ! is_array( $state ) || ! isset( $state['environment'], $state['at'] ) ) {
			return null;
		}

		return array(
			'environment' => (string) $state['environment'],
			'request_id'  => isset( $state['request_id'] ) ? (string) $state['request_id'] : '',
			'at'          => (int) $state['at'],
		);
	}

	/**
	 * Whether checkout should currently hide the payment method.
	 *
	 * @param string   $environment Active environment.
	 * @param int|null $now         Unix time.
	 * @return bool
	 */
	public static function is_paused( $environment, $now = null ) {
		$state = self::get();
		$now   = null === $now ? time() : (int) $now;

		return null !== $state && $state['environment'] === $environment && ( $now - $state['at'] ) < self::PAUSE;
	}

	/**
	 * Message shown to shoppers. It never mentions the restriction.
	 *
	 * @return string
	 */
	public static function shopper_message() {
		return __( 'Mobile money payment is unavailable right now. Please choose another payment method or try again later.', 'teepay-for-woocommerce' );
	}
}
