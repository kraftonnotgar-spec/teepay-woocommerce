<?php
/**
 * TeePay API error.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * An error returned by the TeePay API, or a failure to reach it.
 *
 * The API returns {"error":{"code","message","request_id"}}. HTTP status 0 means the request
 * could not be sent (network error) and its outcome is unknown.
 */
class TeePay_WC_Api_Exception extends Exception {

	/**
	 * HTTP status, or 0 for a network error.
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Stable TeePay error code, such as "account_restricted".
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * TeePay request ID to quote to support.
	 *
	 * @var string
	 */
	private $request_id;

	/**
	 * Seconds to wait before retrying, from Retry-After.
	 *
	 * @var int
	 */
	private $retry_after;

	/**
	 * Constructor.
	 *
	 * @param int    $http_status HTTP status or 0.
	 * @param string $error_code  TeePay error code.
	 * @param string $message     Message (never shown to shoppers).
	 * @param string $request_id  TeePay request ID.
	 * @param int    $retry_after Retry-After seconds.
	 */
	public function __construct( $http_status, $error_code, $message, $request_id = '', $retry_after = 0 ) {
		parent::__construct( (string) $message, (int) $http_status );
		$this->http_status = (int) $http_status;
		$this->error_code  = (string) $error_code;
		$this->request_id  = (string) $request_id;
		$this->retry_after = max( 0, (int) $retry_after );
	}

	/**
	 * Build from an HTTP response.
	 *
	 * @param int    $status  HTTP status.
	 * @param string $body    Raw body.
	 * @param array  $headers Lower-cased response headers.
	 * @return TeePay_WC_Api_Exception
	 */
	public static function from_response( $status, $body, array $headers ) {
		$decoded    = json_decode( (string) $body, true );
		$error      = ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) ? $decoded['error'] : array();
		$code       = isset( $error['code'] ) && is_string( $error['code'] ) ? $error['code'] : self::default_code( $status );
		$message    = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : 'TeePay returned HTTP ' . (int) $status . '.';
		$request_id = isset( $error['request_id'] ) && is_string( $error['request_id'] ) ? $error['request_id'] : ( isset( $headers['x-request-id'] ) ? (string) $headers['x-request-id'] : '' );
		$retry      = isset( $headers['retry-after'] ) && ctype_digit( (string) $headers['retry-after'] ) ? (int) $headers['retry-after'] : 0;

		return new self( (int) $status, $code, $message, $request_id, $retry );
	}

	/**
	 * Code to use when the body carries none.
	 *
	 * @param int $status HTTP status.
	 * @return string
	 */
	private static function default_code( $status ) {
		$codes = array(
			401 => 'invalid_credential',
			403 => 'access_denied',
			404 => 'not_found',
			409 => 'idempotency_conflict',
			422 => 'invalid_request',
			429 => 'rate_limited',
			503 => 'unavailable',
		);

		return isset( $codes[ $status ] ) ? $codes[ $status ] : ( $status >= 500 ? 'internal_error' : 'unexpected_response' );
	}

	/**
	 * HTTP status or 0.
	 *
	 * @return int
	 */
	public function get_http_status() {
		return $this->http_status;
	}

	/**
	 * TeePay error code.
	 *
	 * @return string
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * TeePay request ID.
	 *
	 * @return string
	 */
	public function get_request_id() {
		return $this->request_id;
	}

	/**
	 * Retry-After seconds.
	 *
	 * @return int
	 */
	public function get_retry_after() {
		return $this->retry_after;
	}

	/**
	 * Whether TeePay has restricted the merchant account.
	 *
	 * @return bool
	 */
	public function is_account_restricted() {
		return 403 === $this->http_status && 'account_restricted' === $this->error_code;
	}

	/**
	 * Whether the same request may succeed later (network, rate limit, 5xx).
	 *
	 * @return bool
	 */
	public function is_retryable() {
		return 0 === $this->http_status || 429 === $this->http_status || $this->http_status >= 500;
	}
}
