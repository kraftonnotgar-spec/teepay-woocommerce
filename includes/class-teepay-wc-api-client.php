<?php
/**
 * Dependency-free TeePay API client.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Small client for the public TeePay API (https://api.teepay.me).
 *
 * The transport is injectable. It is a callable:
 * function ( string $method, string $url, array $headers, ?string $body ): array
 * returning array( 'status' => int, 'headers' => array, 'body' => string ) and throwing
 * TeePay_WC_Api_Exception with status 0 when the request could not be sent.
 * By default the WordPress HTTP API is used.
 */
class TeePay_WC_Api_Client {

	const API_VERSION      = '2026-10-18';
	const DEFAULT_BASE_URL = 'https://api.teepay.me';
	const UUID_PATTERN     = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

	/**
	 * Secret API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Base URL without trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Transport callable.
	 *
	 * @var callable
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param string        $api_key   A tp_test_ or tp_live_ key.
	 * @param callable|null $transport Optional transport.
	 * @param string        $base_url  API base URL.
	 * @throws InvalidArgumentException When the key is malformed.
	 */
	public function __construct( $api_key, $transport = null, $base_url = self::DEFAULT_BASE_URL ) {
		$api_key = trim( (string) $api_key );
		if ( ! self::is_valid_key( $api_key ) ) {
			throw new InvalidArgumentException( 'A TeePay API key starting with tp_test_ or tp_live_ is required.' );
		}
		$this->api_key   = $api_key;
		$this->base_url  = rtrim( (string) $base_url, '/' );
		$this->transport = null !== $transport ? $transport : array( __CLASS__, 'wp_transport' );
	}

	/**
	 * Whether a string looks like a TeePay secret key.
	 *
	 * @param string $key Candidate.
	 * @return bool
	 */
	public static function is_valid_key( $key ) {
		return is_string( $key ) && (bool) preg_match( '/^tp_(test|live)_[A-Za-z0-9._-]{8,}$/', $key );
	}

	/**
	 * Environment of a key: "live" for tp_live_, otherwise "sandbox".
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function environment_of( $key ) {
		return 0 === strpos( (string) $key, 'tp_live_' ) ? 'live' : 'sandbox';
	}

	/**
	 * This client's environment.
	 *
	 * @return string
	 */
	public function environment() {
		return self::environment_of( $this->api_key );
	}

	/**
	 * GET /v1/whoami.
	 *
	 * @return array
	 */
	public function whoami() {
		return $this->request( 'GET', '/v1/whoami' );
	}

	/**
	 * GET /v1/capabilities.
	 *
	 * @return array
	 */
	public function capabilities() {
		return $this->request( 'GET', '/v1/capabilities' );
	}

	/**
	 * POST /v1/checkout-sessions.
	 *
	 * @param array  $input           title, amount_minor, currency; optional description, expires_in,
	 *                                payment_profile_id, reference, metadata.
	 * @param string $idempotency_key 8-120 of [A-Za-z0-9_-].
	 * @return array Checkout session.
	 * @throws InvalidArgumentException When the key is malformed.
	 */
	public function create_checkout_session( array $input, $idempotency_key ) {
		if ( ! is_string( $idempotency_key ) || ! preg_match( '/^[A-Za-z0-9_-]{8,120}$/', $idempotency_key ) ) {
			throw new InvalidArgumentException( 'Idempotency-Key must be 8-120 letters, digits, "_" or "-".' );
		}

		return $this->request( 'POST', '/v1/checkout-sessions', self::checkout_session_body( $input ), $idempotency_key );
	}

	/**
	 * GET /v1/checkout-sessions/{id}.
	 *
	 * @param string $id Session UUID.
	 * @return array
	 * @throws InvalidArgumentException When the ID is not a UUID.
	 */
	public function get_checkout_session( $id ) {
		if ( ! is_string( $id ) || ! preg_match( self::UUID_PATTERN, $id ) ) {
			throw new InvalidArgumentException( 'Checkout session ID must be a UUID.' );
		}

		return $this->request( 'GET', '/v1/checkout-sessions/' . rawurlencode( $id ) );
	}

	/**
	 * POST /v1/collections (mobile money push).
	 *
	 * @param array  $input           money{amount_minor,currency}, customer{country,phone,name?,email?}, description?.
	 * @param string $idempotency_key 8-128 of [A-Za-z0-9_-].
	 * @return array Collection (202).
	 * @throws InvalidArgumentException When input is invalid.
	 */
	public function create_collection( array $input, $idempotency_key ) {
		if ( ! is_string( $idempotency_key ) || ! preg_match( '/^[A-Za-z0-9_-]{8,128}$/', $idempotency_key ) ) {
			throw new InvalidArgumentException( 'Idempotency-Key must be 8-128 letters, digits, "_" or "-".' );
		}

		return $this->request( 'POST', '/v1/collections', self::collection_body( $input ), $idempotency_key );
	}

	/**
	 * GET /v1/collections/{reference}.
	 *
	 * @param string $reference Public reference.
	 * @return array
	 * @throws InvalidArgumentException When the reference is malformed.
	 */
	public function get_collection( $reference ) {
		if ( ! is_string( $reference ) || ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $reference ) ) {
			throw new InvalidArgumentException( 'Collection reference is invalid.' );
		}

		return $this->request( 'GET', '/v1/collections/' . rawurlencode( $reference ) );
	}

	/**
	 * Validate a checkout session body. The API rejects unknown fields.
	 *
	 * @param array $input Input.
	 * @return array
	 * @throws InvalidArgumentException When invalid.
	 */
	public static function checkout_session_body( array $input ) {
		$allowed = array( 'title', 'description', 'amount_minor', 'currency', 'expires_in', 'payment_profile_id', 'reference', 'metadata' );
		foreach ( array_keys( $input ) as $field ) {
			if ( ! in_array( $field, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Unknown checkout session field: ' . esc_html( $field ) );
			}
		}
		$title = isset( $input['title'] ) ? (string) $input['title'] : '';
		if ( '' === $title || self::length( $title ) > 100 ) {
			throw new InvalidArgumentException( 'title must be 1-100 characters.' );
		}
		$body = array( 'title' => $title );
		if ( isset( $input['description'] ) && '' !== (string) $input['description'] ) {
			if ( self::length( (string) $input['description'] ) > 200 ) {
				throw new InvalidArgumentException( 'description must be at most 200 characters.' );
			}
			$body['description'] = (string) $input['description'];
		}
		$amount = isset( $input['amount_minor'] ) ? (string) $input['amount_minor'] : '';
		if ( ! preg_match( '/^[1-9][0-9]{0,12}$/', $amount ) ) {
			throw new InvalidArgumentException( 'amount_minor must be a positive integer string of up to 13 digits.' );
		}
		$body['amount_minor'] = $amount;
		$body['currency']     = self::currency( $input );
		if ( isset( $input['expires_in'] ) ) {
			if ( ! is_int( $input['expires_in'] ) || $input['expires_in'] < 3600 || $input['expires_in'] > 604800 ) {
				throw new InvalidArgumentException( 'expires_in must be an integer from 3600 to 604800.' );
			}
			$body['expires_in'] = $input['expires_in'];
		}
		if ( isset( $input['payment_profile_id'] ) && '' !== (string) $input['payment_profile_id'] ) {
			if ( ! preg_match( self::UUID_PATTERN, (string) $input['payment_profile_id'] ) ) {
				throw new InvalidArgumentException( 'payment_profile_id must be a UUID.' );
			}
			$body['payment_profile_id'] = (string) $input['payment_profile_id'];
		}
		if ( isset( $input['reference'] ) ) {
			if ( ! preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/', (string) $input['reference'] ) ) {
				throw new InvalidArgumentException( 'reference must be 1-64 letters, digits or _ . : -' );
			}
			$body['reference'] = (string) $input['reference'];
		}
		if ( ! empty( $input['metadata'] ) ) {
			if ( ! is_array( $input['metadata'] ) || count( $input['metadata'] ) > 10 ) {
				throw new InvalidArgumentException( 'metadata must have at most 10 entries.' );
			}
			$metadata = array();
			foreach ( $input['metadata'] as $key => $value ) {
				if ( ! is_string( $key ) || ! preg_match( '/^[A-Za-z0-9_.-]{1,40}$/', $key ) || ! is_scalar( $value ) || self::length( (string) $value ) > 200 ) {
					throw new InvalidArgumentException( 'metadata must be string pairs: keys 1-40 of [A-Za-z0-9_.-], values up to 200 characters.' );
				}
				$metadata[ $key ] = (string) $value;
			}
			$body['metadata'] = $metadata;
		}

		return $body;
	}

	/**
	 * Validate a collection body.
	 *
	 * @param array $input Input.
	 * @return array
	 * @throws InvalidArgumentException When invalid.
	 */
	public static function collection_body( array $input ) {
		$money    = isset( $input['money'] ) && is_array( $input['money'] ) ? $input['money'] : array();
		$customer = isset( $input['customer'] ) && is_array( $input['customer'] ) ? $input['customer'] : array();
		$amount   = isset( $money['amount_minor'] ) ? (string) $money['amount_minor'] : '';
		if ( ! preg_match( '/^[1-9][0-9]{0,18}$/', $amount ) ) {
			throw new InvalidArgumentException( 'money.amount_minor must be a positive integer string.' );
		}
		$country = isset( $customer['country'] ) ? (string) $customer['country'] : '';
		$phone   = isset( $customer['phone'] ) ? (string) $customer['phone'] : '';
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) || '' === $phone || strlen( $phone ) > 40 ) {
			throw new InvalidArgumentException( 'customer.country and customer.phone are required.' );
		}
		$body = array(
			'money'    => array(
				'amount_minor' => $amount,
				'currency'     => self::currency( $money ),
			),
			'customer' => array(
				'country' => $country,
				'phone'   => $phone,
			),
		);
		if ( isset( $customer['name'] ) && '' !== (string) $customer['name'] ) {
			$body['customer']['name'] = self::truncate( (string) $customer['name'], 160 );
		}
		if ( isset( $customer['email'] ) && '' !== (string) $customer['email'] && strlen( (string) $customer['email'] ) <= 254 ) {
			$body['customer']['email'] = (string) $customer['email'];
		}
		if ( isset( $input['description'] ) && '' !== (string) $input['description'] ) {
			$body['description'] = self::truncate( (string) $input['description'], 240 );
		}

		return $body;
	}

	/**
	 * Send a request and decode the JSON response.
	 *
	 * @param string      $method          HTTP method.
	 * @param string      $path            Path.
	 * @param array|null  $body            JSON body.
	 * @param string|null $idempotency_key Idempotency key.
	 * @return array
	 * @throws TeePay_WC_Api_Exception On API or network errors.
	 * @throws InvalidArgumentException When the body cannot be encoded.
	 */
	private function request( $method, $path, $body = null, $idempotency_key = null ) {
		$headers = array(
			'Authorization'  => 'Bearer ' . $this->api_key,
			'Accept'         => 'application/json',
			'TeePay-Version' => self::API_VERSION,
			'User-Agent'     => 'teepay-for-woocommerce/' . ( defined( 'TEEPAY_WC_VERSION' ) ? TEEPAY_WC_VERSION : 'dev' ),
		);
		$payload = null;
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
			$payload                 = function_exists( 'wp_json_encode' ) ? wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			if ( false === $payload ) {
				throw new InvalidArgumentException( 'Request body could not be encoded as JSON.' );
			}
		}
		if ( null !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$response = call_user_func( $this->transport, $method, $this->base_url . $path, $headers, $payload );
		$status   = isset( $response['status'] ) ? (int) $response['status'] : 0;
		$received = isset( $response['headers'] ) && is_array( $response['headers'] ) ? array_change_key_case( $response['headers'], CASE_LOWER ) : array();
		$raw      = isset( $response['body'] ) ? (string) $response['body'] : '';

		if ( $status < 200 || $status >= 300 ) {
			throw TeePay_WC_Api_Exception::from_response( $status, $raw, $received ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output; the exception is logged.
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new TeePay_WC_Api_Exception( 502, 'invalid_response', 'TeePay returned a response that is not JSON.', isset( $received['x-request-id'] ) ? (string) $received['x-request-id'] : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- not output.
		}

		return $decoded;
	}

	/**
	 * Transport using the WordPress HTTP API with TLS verification.
	 *
	 * @param string      $method  Method.
	 * @param string      $url     URL.
	 * @param array       $headers Headers.
	 * @param string|null $body    Body.
	 * @return array
	 * @throws TeePay_WC_Api_Exception When the request could not be sent.
	 */
	public static function wp_transport( $method, $url, array $headers, $body ) {
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => 30,
			'redirection' => 0,
			'sslverify'   => true,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new TeePay_WC_Api_Exception( 0, 'network_error', 'Could not reach TeePay: ' . esc_html( $response->get_error_message() ) );
		}
		$received = array();
		foreach ( (array) wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$received[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return array(
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => $received,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Currency from input.
	 *
	 * @param array $input Input with "currency".
	 * @return string
	 * @throws InvalidArgumentException When invalid.
	 */
	private static function currency( array $input ) {
		$currency = isset( $input['currency'] ) ? (string) $input['currency'] : '';
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			throw new InvalidArgumentException( 'currency must be an ISO 4217 code such as TZS.' );
		}

		return $currency;
	}

	/**
	 * Multibyte-safe length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private static function length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Multibyte-safe truncate.
	 *
	 * @param string $value Value.
	 * @param int    $max   Max characters.
	 * @return string
	 */
	private static function truncate( $value, $max ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max, 'UTF-8' ) : substr( $value, 0, $max );
	}
}
