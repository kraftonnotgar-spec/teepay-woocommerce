<?php
/**
 * Redacts secrets and personal data before logging.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Removes API keys, webhook secrets, bearer tokens, signatures and phone numbers from log data.
 */
class TeePay_WC_Redactor {

	/**
	 * Array keys whose values are always removed.
	 *
	 * @var string[]
	 */
	private static $secret_keys = array( 'authorization', 'api_key', 'secret', 'webhook_secret', 'live_api_key', 'test_api_key', 'live_webhook_secret', 'test_webhook_secret', 'x-teepay-signature', 'signature', 'password', 'token', 'checkout_url' );

	/**
	 * Redact a string or a nested array.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$lower = is_string( $key ) ? strtolower( $key ) : '';
				if ( in_array( $lower, self::$secret_keys, true ) ) {
					$out[ $key ] = '[redacted]';
				} elseif ( in_array( $lower, array( 'phone', 'customer_phone' ), true ) && is_string( $item ) ) {
					$out[ $key ] = TeePay_WC_Phone::mask( $item );
				} else {
					$out[ $key ] = self::redact( $item );
				}
			}
			return $out;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$patterns = array(
			'/\btp_(live|test)_[A-Za-z0-9._-]+/' => 'tp_$1_[redacted]',
			'/\bwhsec_[A-Za-z0-9._-]+/'          => 'whsec_[redacted]',
			'/Bearer\s+[A-Za-z0-9._~+\/=-]+/i'   => 'Bearer [redacted]',
			'/\bv1=[0-9a-fA-F]{16,}/'            => 'v1=[redacted]',
			'/\+?255[67]\d{8}\b/'                => '+255[redacted]',
		);

		return preg_replace( array_keys( $patterns ), array_values( $patterns ), $value );
	}
}
