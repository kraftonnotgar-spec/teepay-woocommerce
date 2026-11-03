<?php
/**
 * Money conversion.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Converts decimal amounts to TeePay integer minor units with exact string arithmetic.
 */
class TeePay_WC_Money {

	/**
	 * ISO 4217 currencies with no minor unit.
	 *
	 * @var string[]
	 */
	private static $zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/**
	 * ISO 4217 currencies with three decimal places.
	 *
	 * @var string[]
	 */
	private static $three_decimal = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );

	/**
	 * Number of decimal places of a currency.
	 *
	 * @param string $currency ISO 4217 code.
	 * @return int
	 */
	public static function exponent( $currency ) {
		$currency = strtoupper( (string) $currency );
		if ( in_array( $currency, self::$zero_decimal, true ) ) {
			return 0;
		}
		if ( in_array( $currency, self::$three_decimal, true ) ) {
			return 3;
		}

		return 2;
	}

	/**
	 * Decimal amount ("5000.00") to minor units ("500000"), rounding half up.
	 *
	 * @param string|int|float $amount   Amount.
	 * @param string           $currency Currency.
	 * @return string
	 * @throws InvalidArgumentException When the amount is not a non-negative decimal.
	 */
	public static function to_minor( $amount, $currency ) {
		if ( is_float( $amount ) ) {
			$amount = number_format( $amount, 8, '.', '' );
		}
		$value = trim( (string) $amount );
		if ( ! preg_match( '/^(\d+)(?:\.(\d*))?$/', $value, $match ) ) {
			throw new InvalidArgumentException( 'Amount must be a non-negative decimal number.' );
		}
		$exponent = self::exponent( $currency );
		$fraction = str_pad( isset( $match[2] ) ? $match[2] : '', $exponent + 1, '0' );
		$digits   = ltrim( $match[1] . substr( $fraction, 0, $exponent ), '0' );
		if ( '' === $digits ) {
			$digits = '0';
		}
		if ( (int) $fraction[ $exponent ] >= 5 ) {
			$digits = self::increment( $digits );
		}

		return $digits;
	}

	/**
	 * Minor units ("500000") to a decimal string ("5000.00").
	 *
	 * @param string|int $minor    Minor units.
	 * @param string     $currency Currency.
	 * @return string
	 * @throws InvalidArgumentException When not a non-negative integer.
	 */
	public static function from_minor( $minor, $currency ) {
		$value = (string) $minor;
		if ( ! preg_match( '/^\d+$/', $value ) ) {
			throw new InvalidArgumentException( 'Minor units must be a non-negative integer.' );
		}
		$value    = ltrim( $value, '0' );
		$value    = '' === $value ? '0' : $value;
		$exponent = self::exponent( $currency );
		if ( 0 === $exponent ) {
			return $value;
		}
		$value = str_pad( $value, $exponent + 1, '0', STR_PAD_LEFT );

		return substr( $value, 0, -$exponent ) . '.' . substr( $value, -$exponent );
	}

	/**
	 * Normalise minor units from an API response (string or integer) for comparison.
	 *
	 * @param mixed $value Value.
	 * @return string Digits without leading zeros, or '' when invalid.
	 */
	public static function normalize_minor( $value ) {
		if ( is_int( $value ) && $value >= 0 ) {
			return (string) $value;
		}
		if ( is_string( $value ) && preg_match( '/^\d{1,19}$/', $value ) ) {
			$value = ltrim( $value, '0' );
			return '' === $value ? '0' : $value;
		}

		return '';
	}

	/**
	 * Add one to a digit string.
	 *
	 * @param string $digits Digits.
	 * @return string
	 */
	private static function increment( $digits ) {
		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			if ( '9' !== $digits[ $i ] ) {
				$digits[ $i ] = (string) ( (int) $digits[ $i ] + 1 );
				return $digits;
			}
			$digits[ $i ] = '0';
		}

		return '1' . $digits;
	}
}
