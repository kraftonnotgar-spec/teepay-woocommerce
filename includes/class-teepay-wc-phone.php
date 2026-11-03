<?php
/**
 * Tanzanian mobile number validation.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalises Tanzanian mobile money numbers to +255XXXXXXXXX.
 */
class TeePay_WC_Phone {

	/**
	 * Normalise a Tanzanian mobile number.
	 *
	 * Accepts 07XXXXXXXX, 06XXXXXXXX, 7XXXXXXXX, 2557XXXXXXXX, 002557XXXXXXXX and +255 7XX XXX XXX,
	 * with spaces, dots, dashes or brackets. Mobile numbers start with 6 or 7 after the country code.
	 *
	 * @param string $input Raw input.
	 * @return string|null +255XXXXXXXXX, or null when not a Tanzanian mobile number.
	 */
	public static function normalize_tz( $input ) {
		$value = preg_replace( '/[\s().-]+/', '', (string) $input );
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( 0 === strpos( $value, '+' ) ) {
			$value = substr( $value, 1 );
		} elseif ( 0 === strpos( $value, '00' ) ) {
			$value = substr( $value, 2 );
		}
		if ( '' === $value || ! ctype_digit( $value ) ) {
			return null;
		}
		if ( 12 === strlen( $value ) && 0 === strpos( $value, '255' ) ) {
			$national = substr( $value, 3 );
		} elseif ( 10 === strlen( $value ) && '0' === $value[0] ) {
			$national = substr( $value, 1 );
		} elseif ( 9 === strlen( $value ) ) {
			$national = $value;
		} else {
			return null;
		}
		if ( ! preg_match( '/^[67]\d{8}$/', $national ) ) {
			return null;
		}

		return '+255' . $national;
	}

	/**
	 * Mask a phone number for logs and order notes: +255712***678.
	 *
	 * @param string $phone Phone.
	 * @return string
	 */
	public static function mask( $phone ) {
		$phone = (string) $phone;
		if ( strlen( $phone ) < 10 ) {
			return '***';
		}

		return substr( $phone, 0, 7 ) . str_repeat( '*', strlen( $phone ) - 10 ) . substr( $phone, -3 );
	}
}
