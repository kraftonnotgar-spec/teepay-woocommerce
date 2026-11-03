<?php
/**
 * Test doubles: an in-memory order and a scripted TeePay API.
 *
 * @package TeePay\WooCommerce\Tests
 */

// phpcs:ignoreFile

final class TP_Item {
	private $name;
	private $qty;
	public function __construct( $name, $qty ) { $this->name = $name; $this->qty = $qty; }
	public function get_name() { return $this->name; }
	public function get_quantity() { return $this->qty; }
}

/**
 * In-memory WC_Order with the methods the plugin uses.
 */
final class TP_Order {
	public $id;
	public $total;
	public $currency;
	public $status = 'pending';
	public $meta = array();
	public $notes = array();
	public $transaction_id = '';
	public $payment_complete_calls = 0;
	public $payment_method = 'teepay';
	public $saves = 0;

	public function __construct( $id, $total = '5000.00', $currency = 'TZS' ) {
		$this->id       = $id;
		$this->total    = $total;
		$this->currency = $currency;
		TP_State::$orders[ $id ] = $this;
	}
	public function get_id() { return $this->id; }
	public function get_order_number() { return (string) $this->id; }
	public function get_total() { return $this->total; }
	public function get_currency() { return $this->currency; }
	public function get_status() { return $this->status; }
	public function has_status( $status ) { return in_array( $this->status, (array) $status, true ); }
	public function get_payment_method() { return $this->payment_method; }
	public function get_order_key() { return 'wc_order_abc123'; }
	public function get_billing_first_name() { return 'Asha'; }
	public function get_billing_last_name() { return 'Juma'; }
	public function get_billing_email() { return 'asha@example.com'; }
	public function get_items() { return array( new TP_Item( 'Coffee bag', 2 ) ); }
	public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
	public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
	public function save() { $this->saves++; return $this->id; }
	public function is_paid() { return in_array( $this->status, array( 'processing', 'completed' ), true ); }
	public function add_order_note( $note ) { $this->notes[] = $note; }
	public function update_status( $status, $note = '' ) { $this->status = $status; if ( '' !== $note ) { $this->notes[] = $note; } }
	public function payment_complete( $transaction_id = '' ) {
		$this->payment_complete_calls++;
		$this->transaction_id = $transaction_id;
		$this->status = 'processing';
		return true;
	}
	public function get_checkout_order_received_url() { return 'https://shop.example.tz/checkout/order-received/' . $this->id . '/?key=wc_order_abc123'; }
	public function get_checkout_payment_url() { return 'https://shop.example.tz/checkout/order-pay/' . $this->id . '/?pay_for_order=true&key=wc_order_abc123'; }
}

/**
 * Scripted TeePay API: routes "METHOD /path" to queued responses and records every request.
 */
final class TP_Api {
	public $requests = array();
	private $routes = array();

	public function on( $method, $path, $status, $body, array $headers = array() ) {
		$this->routes[ $method . ' ' . $path ][] = array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => is_string( $body ) ? $body : json_encode( $body ),
		);
		return $this;
	}

	public function transport() {
		$api = $this;
		return function ( $method, $url, array $headers, $body ) use ( $api ) {
			$path = (string) parse_url( $url, PHP_URL_PATH );
			$api->requests[] = array( 'method' => $method, 'url' => $url, 'path' => $path, 'headers' => $headers, 'body' => $body );
			$key = $method . ' ' . $path;
			if ( empty( $api->routes[ $key ] ) ) {
				throw new RuntimeException( 'Unexpected request: ' . $key );
			}
			$response = count( $api->routes[ $key ] ) > 1 ? array_shift( $api->routes[ $key ] ) : $api->routes[ $key ][0];
			if ( 0 === $response['status'] ) {
				throw new TeePay_WC_Api_Exception( 0, 'network_error', 'timeout' );
			}
			return $response;
		};
	}

	public function count( $method, $path_prefix = '' ) {
		$n = 0;
		foreach ( $this->requests as $request ) {
			if ( $request['method'] === $method && 0 === strpos( $request['path'], $path_prefix ) ) {
				$n++;
			}
		}
		return $n;
	}

	public function last() { return end( $this->requests ); }
}

/**
 * Builders for API payloads.
 */
final class TP_Fixtures {
	const SESSION_ID = '7c0e5d2a-1b3f-4c8e-9a6d-2f4b8e1c0d9a';
	const LIVE_KEY   = 'tp_live_ab12cd34.secretvalue000000000000';
	const TEST_KEY   = 'tp_test_ab12cd34.secretvalue000000000000';
	const LIVE_HOOK  = 'whsec_live_0123456789abcdef0123456789';
	const TEST_HOOK  = 'whsec_test_0123456789abcdef0123456789';

	public static function settings( array $overrides = array() ) {
		return array_merge(
			array(
				'enabled'             => 'yes',
				'title'               => 'Mobile money',
				'description'         => 'Pay with mobile money.',
				'payment_mode'        => 'hosted',
				'testmode'            => 'no',
				'live_api_key'        => self::LIVE_KEY,
				'test_api_key'        => self::TEST_KEY,
				'live_webhook_secret' => self::LIVE_HOOK,
				'test_webhook_secret' => self::TEST_HOOK,
				'payment_profile_id'  => '',
				'debug'               => 'no',
			),
			$overrides
		);
	}

	public static function session( $order_id, array $overrides = array() ) {
		return array_merge(
			array(
				'id'                 => self::SESSION_ID,
				'object'             => 'checkout_session',
				'status'             => 'open',
				'title'              => 'Order #' . $order_id,
				'description'        => '',
				'currency'           => 'TZS',
				'amount_minor'       => '500000',
				'checkout_url'       => 'https://checkout.teepay.me/checkout/abc',
				'payment_profile_id' => null,
				'reference'          => TeePay_WC_Order_Service::order_reference( $order_id ),
				'metadata'           => array(),
				'payment'            => null,
				'environment'        => 'live',
				'created_at'         => '2026-11-03T10:00:00Z',
				'expires_at'         => '2026-11-04T10:00:00Z',
			),
			$overrides
		);
	}

	public static function paid_session( $order_id, array $overrides = array() ) {
		return self::session( $order_id, array_merge( array( 'status' => 'paid', 'payment' => array( 'reference' => 'TP8K2M4QZ1' ) ), $overrides ) );
	}

	public static function collection( array $overrides = array() ) {
		return array_merge(
			array(
				'id'               => 'pay_123',
				'public_reference' => 'TPPUSH0001',
				'operation'        => 'charge',
				'status'           => 'pending',
				'money'            => array( 'amount_minor' => 500000, 'currency' => 'TZS' ),
				'created_at'       => '2026-11-03T10:00:00Z',
				'updated_at'       => '2026-11-03T10:00:00Z',
			),
			$overrides
		);
	}

	public static function event( array $data, $event = 'payment.completed', $environment = 'live', $id = '0f8d7c2e-5b1a-5e3f-9c4d-7a6b2e1f0c9d' ) {
		return json_encode(
			array(
				'id'          => $id,
				'event'       => $event,
				'environment' => $environment,
				'created_at'  => '2026-11-03T10:00:05.123456Z',
				'data'        => array_merge(
					array(
						'reference'      => 'TP8K2M4QZ1',
						'transaction_id' => 'pay_6b1f',
						'status'         => 'completed',
						'occurred_at'    => '2026-11-03T10:00:03Z',
						'money'          => array( 'currency' => 'TZS', 'amount_minor' => '500000' ),
						'source'         => array( 'type' => 'checkout_session', 'id' => self::SESSION_ID ),
					),
					$data
				),
			)
		);
	}

	public static function signed_headers( $body, $secret, $timestamp = null, $event_id = '0f8d7c2e-5b1a-5e3f-9c4d-7a6b2e1f0c9d' ) {
		$timestamp = null === $timestamp ? time() : $timestamp;
		return array(
			'X-TeePay-Event-ID'  => $event_id,
			'X-TeePay-Timestamp' => (string) $timestamp,
			'X-TeePay-Signature' => 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret ),
		);
	}
}
