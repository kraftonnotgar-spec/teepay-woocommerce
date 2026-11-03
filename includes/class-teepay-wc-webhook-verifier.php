<?php
/**
 * Webhook signature verification.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies TeePay webhook signatures.
 *
 * X-TeePay-Signature is "v1=" followed by the hex HMAC-SHA256 of "<X-TeePay-Timestamp>.<raw body>"
 * keyed with the environment's signing secret (whsec_...). Requests signed more than five minutes
 * before or after the current time are rejected, so a captured request cannot be replayed later.
 */
class TeePay_WC_Webhook_Verifier {

	const TOLERANCE = 300;

	/**
	 * Verify a webhook.
	 *
	 * @param string   $raw_body  Raw request body bytes, before JSON parsing.
	 * @param string   $timestamp X-TeePay-Timestamp header.
	 * @param string   $signature X-TeePay-Signature header.
	 * @param string   $secret    Signing secret.
	 * @param int|null $now       Current Unix time (for tests).
	 * @param int      $tolerance Allowed clock difference in seconds.
	 * @return bool
	 */
	public static function verify( $raw_body, $timestamp, $signature, $secret, $now = null, $tolerance = self::TOLERANCE ) {
		$secret    = (string) $secret;
		$timestamp = trim( (string) $timestamp );
		$signature = trim( (string) $signature );
		if ( '' === $secret || '' === $signature || ! preg_match( '/^\d{1,12}$/', $timestamp ) ) {
			return false;
		}
		$now = null === $now ? time() : (int) $now;
		if ( abs( $now - (int) $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . (string) $raw_body, $secret );
		foreach ( preg_split( '/[\s,]+/', $signature ) as $candidate ) {
			if ( '' !== $candidate && hash_equals( $expected, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a signature, as TeePay does.
	 *
	 * @param string $raw_body  Body.
	 * @param int    $timestamp Unix time.
	 * @param string $secret    Secret.
	 * @return string
	 */
	public static function sign( $raw_body, $timestamp, $secret ) {
		return 'v1=' . hash_hmac( 'sha256', (int) $timestamp . '.' . (string) $raw_body, (string) $secret );
	}
}
