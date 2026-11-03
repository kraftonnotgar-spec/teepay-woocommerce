<?php
/**
 * Debug logging through WC_Logger.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes redacted entries to WooCommerce > Status > Logs (source "teepay").
 *
 * Debug and info entries are written only when debug logging is enabled; errors are always written.
 * Keys, secrets, signatures and phone numbers are removed first.
 */
class TeePay_WC_Logger {

	const SOURCE = 'teepay';

	/**
	 * Whether debug logging is on.
	 *
	 * @var bool
	 */
	private static $enabled = false;

	/**
	 * Enable or disable debug logging.
	 *
	 * @param bool $enabled Enabled.
	 * @return void
	 */
	public static function set_enabled( $enabled ) {
		self::$enabled = (bool) $enabled;
	}

	/**
	 * Debug entry.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function debug( $message, array $context = array() ) {
		if ( self::$enabled ) {
			self::write( 'debug', $message, $context );
		}
	}

	/**
	 * Error entry, written even when debug logging is off.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	public static function error( $message, array $context = array() ) {
		self::write( 'error', $message, $context );
	}

	/**
	 * Write an entry.
	 *
	 * @param string $level   Level.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @return void
	 */
	private static function write( $level, $message, array $context ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$line = TeePay_WC_Redactor::redact( (string) $message );
		if ( array() !== $context ) {
			$encoded = wp_json_encode( TeePay_WC_Redactor::redact( $context ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$line   .= ' ' . TeePay_WC_Redactor::redact( (string) $encoded );
		}
		wc_get_logger()->log( $level, $line, array( 'source' => self::SOURCE ) );
	}
}
