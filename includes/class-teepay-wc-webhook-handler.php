<?php
/**
 * Receives TeePay webhooks.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies a webhook and turns it into an authenticated status read for the matching order.
 *
 * The webhook body is only a hint. It must carry a valid signature from the environment's secret,
 * and the order is then confirmed by reading the payment from the TeePay API (see
 * TeePay_WC_Order_Service::confirm()). Each X-TeePay-Event-ID is processed once per order.
 */
class TeePay_WC_Webhook_Handler {

	const MAX_EVENT_IDS = 20;

	/**
	 * Order service.
	 *
	 * @var TeePay_WC_Order_Service
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param TeePay_WC_Order_Service $service Service.
	 */
	public function __construct( TeePay_WC_Order_Service $service ) {
		$this->service = $service;
	}

	/**
	 * Handle a webhook request.
	 *
	 * @param string   $raw_body Raw request body.
	 * @param array    $headers  Request headers (any case).
	 * @param int|null $now      Unix time (for tests).
	 * @return array{0:int,1:array} HTTP status and JSON body.
	 */
	public function handle( $raw_body, array $headers, $now = null ) {
		$headers     = array_change_key_case( $headers, CASE_LOWER );
		$timestamp   = isset( $headers['x-teepay-timestamp'] ) ? (string) $headers['x-teepay-timestamp'] : '';
		$signature   = isset( $headers['x-teepay-signature'] ) ? (string) $headers['x-teepay-signature'] : '';
		$environment = '';
		foreach ( array( 'live', 'sandbox' ) as $candidate ) {
			$secret = $this->service->webhook_secret( $candidate );
			if ( '' !== $secret && TeePay_WC_Webhook_Verifier::verify( $raw_body, $timestamp, $signature, $secret, $now ) ) {
				$environment = $candidate;
				break;
			}
		}
		if ( '' === $environment ) {
			TeePay_WC_Logger::debug( 'Webhook rejected: signature, timestamp or secret is not valid.' );
			return array( 401, array( 'error' => 'invalid_signature' ) );
		}

		$event = json_decode( (string) $raw_body, true );
		if ( ! is_array( $event ) || ! isset( $event['id'], $event['event'], $event['data'] ) || ! is_string( $event['id'] ) || ! is_string( $event['event'] ) || ! is_array( $event['data'] ) ) {
			return array( 400, array( 'error' => 'invalid_payload' ) );
		}
		$header_id = isset( $headers['x-teepay-event-id'] ) ? (string) $headers['x-teepay-event-id'] : '';
		if ( '' !== $header_id && ! hash_equals( $event['id'], $header_id ) ) {
			return array( 400, array( 'error' => 'event_id_mismatch' ) );
		}
		if ( ! isset( $event['environment'] ) || $event['environment'] !== $environment ) {
			return array( 400, array( 'error' => 'environment_mismatch' ) );
		}
		TeePay_WC_Logger::debug(
			'Webhook received.',
			array(
				'event_id' => $event['id'],
				'event'    => $event['event'],
			)
		);
		if ( 0 !== strpos( $event['event'], 'payment.' ) ) {
			return array( 200, array( 'status' => 'ignored' ) );
		}

		$prefetch = null;
		try {
			list( $order, $prefetch ) = $this->find_order( $event['data'], $environment );
		} catch ( TeePay_WC_Api_Exception $e ) {
			if ( $e->is_account_restricted() ) {
				TeePay_WC_Restriction::record( $environment, $e->get_request_id() );
			}
			TeePay_WC_Logger::error(
				'Webhook: TeePay status read failed.',
				array(
					'event_id'   => $event['id'],
					'code'       => $e->get_error_code(),
					'request_id' => $e->get_request_id(),
				)
			);
			return $e->is_retryable() ? array( 503, array( 'status' => 'retry' ) ) : array( 200, array( 'status' => 'not_confirmed' ) );
		}
		if ( null === $order ) {
			return array( 200, array( 'status' => 'no_matching_order' ) );
		}

		$seen = $order->get_meta( TeePay_WC_Order_Service::META_EVENTS );
		$seen = is_array( $seen ) ? $seen : array();
		if ( in_array( $event['id'], $seen, true ) ) {
			return array( 200, array( 'status' => 'duplicate' ) );
		}

		$state = $this->service->confirm( $order, 'webhook', $prefetch );
		if ( in_array( $state, array( 'retry', 'busy' ), true ) ) {
			return array( 503, array( 'status' => 'retry' ) );
		}
		$seen[] = $event['id'];
		$order->update_meta_data( TeePay_WC_Order_Service::META_EVENTS, array_slice( $seen, -self::MAX_EVENT_IDS ) );
		$order->save();

		return array( 200, array( 'status' => $state ) );
	}

	/**
	 * Find the order a payment event belongs to.
	 *
	 * @param array  $data        Event data.
	 * @param string $environment Verified environment.
	 * @return array{0:WC_Order|null,1:array|null} Order and the session read while finding it.
	 * @throws TeePay_WC_Api_Exception When the session cannot be read.
	 */
	private function find_order( array $data, $environment ) {
		$source = isset( $data['source'] ) && is_array( $data['source'] ) ? $data['source'] : null;

		if ( null !== $source && isset( $source['type'], $source['id'] ) && 'checkout_session' === $source['type'] ) {
			$session_id = (string) $source['id'];
			$client     = $this->service->client( $environment );
			if ( null === $client || ! preg_match( TeePay_WC_Api_Client::UUID_PATTERN, $session_id ) ) {
				return array( null, null );
			}
			// The authenticated read tells us which order the session belongs to.
			$session  = $client->get_checkout_session( $session_id );
			$order_id = TeePay_WC_Order_Service::order_id_from_reference( isset( $session['reference'] ) ? $session['reference'] : '' );
			$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
			if ( ! $order || TeePay_WC_Order_Service::GATEWAY_ID !== $order->get_payment_method() || ! $this->service->order_has_session( $order, $session_id ) ) {
				return array( null, null );
			}
			return array( $order, $session );
		}

		$reference = isset( $data['reference'] ) && is_string( $data['reference'] ) ? $data['reference'] : '';
		if ( null === $source && preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $reference ) ) {
			$orders = wc_get_orders(
				array(
					'limit'          => 1,
					'payment_method' => TeePay_WC_Order_Service::GATEWAY_ID,
					// Works with both HPOS and the posts order store.
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one indexed lookup per webhook.
						array(
							'key'   => TeePay_WC_Order_Service::META_COLLECTION,
							'value' => $reference,
						),
					),
				)
			);
			$order  = is_array( $orders ) && isset( $orders[0] ) ? $orders[0] : null;
			if ( $order && (string) $order->get_meta( TeePay_WC_Order_Service::META_ENVIRONMENT ) === $environment ) {
				return array( $order, null );
			}
		}

		return array( null, null );
	}
}
