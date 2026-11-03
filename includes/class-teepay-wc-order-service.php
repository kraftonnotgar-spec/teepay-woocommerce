<?php
/**
 * Starts TeePay payments for orders and confirms them.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order-level payment logic shared by checkout, the return URL, webhooks and reconciliation.
 *
 * An order is marked paid only by confirm(), which reads the payment from the TeePay API with the
 * merchant's secret key and applies TeePay_WC_Payment_Evaluator's rules. Browser redirects and
 * query strings are never trusted.
 */
class TeePay_WC_Order_Service {

	const GATEWAY_ID = 'teepay';

	const META_MODE         = '_teepay_mode';
	const META_ENVIRONMENT  = '_teepay_environment';
	const META_REFERENCE    = '_teepay_reference';
	const META_SESSIONS     = '_teepay_sessions';
	const META_COLLECTION   = '_teepay_collection_reference';
	const META_AMOUNT       = '_teepay_amount_minor';
	const META_CURRENCY     = '_teepay_currency';
	const META_ATTEMPT      = '_teepay_attempt';
	const META_PAYMENT_REF  = '_teepay_payment_reference';
	const META_EVENTS       = '_teepay_event_ids';
	const META_MISMATCH     = '_teepay_mismatch';
	const META_LAST_CHECK   = '_teepay_last_check';
	const META_PHONE_MASKED = '_teepay_phone_masked';

	const SESSION_TTL  = 86400;
	const MAX_SESSIONS = 5;

	/**
	 * Gateway settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Client factory: function ( string $api_key ): TeePay_WC_Api_Client.
	 *
	 * @var callable|null
	 */
	private $client_factory;

	/**
	 * Constructor.
	 *
	 * @param array         $settings       Gateway settings.
	 * @param callable|null $client_factory Optional client factory for tests.
	 */
	public function __construct( array $settings, $client_factory = null ) {
		$this->settings       = $settings;
		$this->client_factory = $client_factory;
	}

	/**
	 * Environment selected in the settings.
	 *
	 * @return string live or sandbox.
	 */
	public function active_environment() {
		return ( isset( $this->settings['testmode'] ) && 'yes' === $this->settings['testmode'] ) ? 'sandbox' : 'live';
	}

	/**
	 * Configured payment mode.
	 *
	 * @return string hosted or push.
	 */
	public function payment_mode() {
		return ( isset( $this->settings['payment_mode'] ) && 'push' === $this->settings['payment_mode'] ) ? 'push' : 'hosted';
	}

	/**
	 * API key for an environment.
	 *
	 * @param string $environment live or sandbox.
	 * @return string
	 */
	public function api_key( $environment ) {
		$field = 'live' === $environment ? 'live_api_key' : 'test_api_key';
		$key   = isset( $this->settings[ $field ] ) ? trim( (string) $this->settings[ $field ] ) : '';

		return ( TeePay_WC_Api_Client::is_valid_key( $key ) && TeePay_WC_Api_Client::environment_of( $key ) === $environment ) ? $key : '';
	}

	/**
	 * Webhook signing secret for an environment.
	 *
	 * @param string $environment live or sandbox.
	 * @return string
	 */
	public function webhook_secret( $environment ) {
		$field = 'live' === $environment ? 'live_webhook_secret' : 'test_webhook_secret';

		return isset( $this->settings[ $field ] ) ? trim( (string) $this->settings[ $field ] ) : '';
	}

	/**
	 * API client for an environment, or null when no key is configured.
	 *
	 * @param string $environment live or sandbox.
	 * @return TeePay_WC_Api_Client|null
	 */
	public function client( $environment ) {
		$key = $this->api_key( $environment );
		if ( '' === $key ) {
			return null;
		}

		if ( null !== $this->client_factory ) {
			return call_user_func( $this->client_factory, $key );
		}

		/**
		 * Filters the HTTP transport used for TeePay API calls (null uses the WordPress HTTP API).
		 *
		 * @param callable|null $transport Transport callable.
		 */
		return new TeePay_WC_Api_Client( $key, apply_filters( 'teepay_wc_api_transport', null ) );
	}

	/**
	 * Short tag that identifies this store, so references from another store on the same TeePay
	 * account are never matched to this store's orders.
	 *
	 * @return string
	 */
	public static function site_tag() {
		return substr( hash( 'sha256', (string) home_url( '/' ) ), 0, 8 );
	}

	/**
	 * Reference sent to TeePay for an order.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	public static function order_reference( $order_id ) {
		return 'wc-' . self::site_tag() . '-' . (int) $order_id;
	}

	/**
	 * Order ID from a reference created by this store, or 0.
	 *
	 * @param string $reference Reference.
	 * @return int
	 */
	public static function order_id_from_reference( $reference ) {
		if ( is_string( $reference ) && preg_match( '/^wc-([0-9a-f]{8})-([1-9][0-9]{0,18})$/', $reference, $match ) && hash_equals( self::site_tag(), $match[1] ) ) {
			return (int) $match[2];
		}

		return 0;
	}

	/**
	 * Create (or reuse) a hosted checkout session for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string URL to send the customer to.
	 * @throws TeePay_WC_Api_Exception On API errors.
	 * @throws RuntimeException When no key is configured or TeePay returns no checkout URL.
	 */
	public function start_hosted_checkout( $order ) {
		$environment = $this->active_environment();
		$client      = $this->require_client( $environment );
		$currency    = (string) $order->get_currency();
		$amount      = TeePay_WC_Money::to_minor( (string) $order->get_total(), $currency );
		$reference   = self::order_reference( $order->get_id() );
		$body        = array(
			'title'        => $this->checkout_title( $order ),
			'amount_minor' => $amount,
			'currency'     => $currency,
			'expires_in'   => self::SESSION_TTL,
			'reference'    => $reference,
			'metadata'     => array(
				'wc_order_id' => (string) $order->get_id(),
				'source'      => 'woocommerce',
				'site'        => self::site_tag(),
			),
		);
		$description = $this->checkout_description( $order );
		if ( '' !== $description ) {
			$body['description'] = $description;
		}
		$profile = isset( $this->settings['payment_profile_id'] ) ? trim( (string) $this->settings['payment_profile_id'] ) : '';
		if ( '' !== $profile ) {
			$body['payment_profile_id'] = $profile;
		}

		$this->prepare_order( $order, 'hosted', $environment, $reference, $amount, $currency );

		for ( $try = 0; $try < 2; $try++ ) {
			$key = $this->idempotency_key( $order, $amount, $currency, 's' );
			try {
				$session = $client->create_checkout_session( $body, $key );
			} catch ( TeePay_WC_Api_Exception $e ) {
				if ( 'idempotency_conflict' === $e->get_error_code() && 0 === $try ) {
					$this->next_attempt( $order );
					continue;
				}
				throw $e;
			}
			TeePay_WC_Restriction::clear( $environment );
			$this->remember_session( $order, $session, $amount, $currency );
			TeePay_WC_Logger::debug(
				'Checkout session ready.',
				array(
					'order_id'   => $order->get_id(),
					'session_id' => isset( $session['id'] ) ? $session['id'] : '',
					'status'     => isset( $session['status'] ) ? $session['status'] : '',
				)
			);

			$status = isset( $session['status'] ) ? (string) $session['status'] : '';
			if ( 'expired' === $status ) {
				$this->next_attempt( $order );
				continue;
			}
			if ( 'paid' === $status ) {
				$this->confirm( $order, 'checkout', $session );
				return $order->get_checkout_order_received_url();
			}
			$url = isset( $session['checkout_url'] ) && is_string( $session['checkout_url'] ) ? $session['checkout_url'] : '';
			if ( 0 !== strpos( $url, 'https://' ) ) {
				throw new RuntimeException( 'TeePay returned no checkout URL.' );
			}
			return $url;
		}

		throw new RuntimeException( 'TeePay could not open a new checkout session for this order.' );
	}

	/**
	 * Send a mobile money prompt for an order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $phone Normalised +255 number.
	 * @return array Collection.
	 * @throws TeePay_WC_Api_Exception On API errors.
	 * @throws RuntimeException When no key is configured.
	 */
	public function start_push( $order, $phone ) {
		$environment = $this->active_environment();
		$client      = $this->require_client( $environment );
		$currency    = (string) $order->get_currency();
		$amount      = TeePay_WC_Money::to_minor( (string) $order->get_total(), $currency );
		$name        = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$body        = array(
			'money'       => array(
				'amount_minor' => $amount,
				'currency'     => $currency,
			),
			'customer'    => array(
				'country' => 'TZ',
				'phone'   => $phone,
				'name'    => $name,
				'email'   => (string) $order->get_billing_email(),
			),
			'description' => $this->checkout_title( $order ),
		);

		$this->prepare_order( $order, 'push', $environment, self::order_reference( $order->get_id() ), $amount, $currency );

		for ( $try = 0; $try < 2; $try++ ) {
			try {
				$collection = $client->create_collection( $body, $this->idempotency_key( $order, $amount, $currency, 'p' ) );
			} catch ( TeePay_WC_Api_Exception $e ) {
				if ( 'idempotency_conflict' === $e->get_error_code() && 0 === $try ) {
					$this->next_attempt( $order );
					continue;
				}
				throw $e;
			}
			TeePay_WC_Restriction::clear( $environment );
			$reference = isset( $collection['public_reference'] ) && is_string( $collection['public_reference'] ) ? $collection['public_reference'] : '';
			if ( ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/', $reference ) ) {
				throw new RuntimeException( 'TeePay returned no payment reference.' );
			}
			$order->update_meta_data( self::META_COLLECTION, $reference );
			$order->update_meta_data( self::META_PHONE_MASKED, TeePay_WC_Phone::mask( $phone ) );
			$order->save();
			TeePay_WC_Logger::debug(
				'Mobile money prompt requested.',
				array(
					'order_id'  => $order->get_id(),
					'reference' => $reference,
					'status'    => isset( $collection['status'] ) ? $collection['status'] : '',
				)
			);
			return $collection;
		}

		throw new RuntimeException( 'TeePay could not send a new prompt for this order.' );
	}

	/**
	 * Confirm an order's payment with an authenticated TeePay status read and apply the result.
	 *
	 * @param WC_Order   $order    Order.
	 * @param string     $via      What triggered the check (webhook, return, thankyou, status, reconcile, checkout).
	 * @param array|null $prefetch A checkout session already read from the API in this request.
	 * @return string One of the TeePay_WC_Payment_Evaluator states, "retry", "busy" or "error".
	 */
	public function confirm( $order, $via, $prefetch = null ) {
		if ( $order->is_paid() ) {
			return TeePay_WC_Payment_Evaluator::PAID;
		}
		$environment = (string) $order->get_meta( self::META_ENVIRONMENT );
		$client      = $this->client( $environment );
		if ( null === $client ) {
			TeePay_WC_Logger::error( 'Cannot confirm order: no API key for its environment.', array( 'order_id' => $order->get_id() ) );
			return 'error';
		}
		if ( ! $this->lock( $order->get_id() ) ) {
			return 'busy';
		}
		try {
			$order->update_meta_data( self::META_LAST_CHECK, time() );
			if ( 'push' === $order->get_meta( self::META_MODE ) ) {
				return $this->confirm_collection( $order, $client, $via );
			}
			return $this->confirm_sessions( $order, $client, $via, $prefetch );
		} catch ( TeePay_WC_Api_Exception $e ) {
			if ( $e->is_account_restricted() ) {
				TeePay_WC_Restriction::record( $environment, $e->get_request_id() );
			}
			TeePay_WC_Logger::error(
				'TeePay status read failed.',
				array(
					'order_id'   => $order->get_id(),
					'via'        => $via,
					'code'       => $e->get_error_code(),
					'status'     => $e->get_http_status(),
					'request_id' => $e->get_request_id(),
				)
			);
			return $e->is_retryable() ? 'retry' : 'error';
		} catch ( InvalidArgumentException $e ) {
			TeePay_WC_Logger::error( 'Stored TeePay reference is invalid.', array( 'order_id' => $order->get_id() ) );
			return 'error';
		} finally {
			$order->save();
			$this->unlock( $order->get_id() );
		}
	}

	/**
	 * Whether an order holds a checkout session ID.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $session_id Session ID.
	 * @return bool
	 */
	public function order_has_session( $order, $session_id ) {
		foreach ( $this->sessions( $order ) as $session ) {
			if ( strtolower( $session['id'] ) === strtolower( (string) $session_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checkout sessions recorded on an order, newest first.
	 *
	 * @param WC_Order $order Order.
	 * @return array[]
	 */
	public function sessions( $order ) {
		$stored = $order->get_meta( self::META_SESSIONS );
		$list   = array();
		if ( is_array( $stored ) ) {
			foreach ( $stored as $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) && is_string( $item['id'] ) && preg_match( TeePay_WC_Api_Client::UUID_PATTERN, $item['id'] ) ) {
					$list[] = $item;
				}
			}
		}

		return array_reverse( $list );
	}

	/**
	 * Confirm a hosted checkout order by reading its sessions.
	 *
	 * @param WC_Order             $order    Order.
	 * @param TeePay_WC_Api_Client $client   Client.
	 * @param string               $via      Trigger.
	 * @param array|null           $prefetch Already-read session.
	 * @return string
	 */
	private function confirm_sessions( $order, $client, $via, $prefetch ) {
		$sessions = $this->sessions( $order );
		if ( array() === $sessions ) {
			return TeePay_WC_Payment_Evaluator::UNKNOWN;
		}
		$states = array();
		foreach ( $sessions as $stored ) {
			$session = ( is_array( $prefetch ) && isset( $prefetch['id'] ) && strtolower( (string) $prefetch['id'] ) === strtolower( $stored['id'] ) )
				? $prefetch
				: $client->get_checkout_session( $stored['id'] );
			$result  = TeePay_WC_Payment_Evaluator::evaluate_session(
				$session,
				array(
					'id'           => $stored['id'],
					'reference'    => (string) $order->get_meta( self::META_REFERENCE ),
					'amount_minor' => TeePay_WC_Money::to_minor( (string) $order->get_total(), (string) $order->get_currency() ),
					'currency'     => (string) $order->get_currency(),
					'environment'  => (string) $order->get_meta( self::META_ENVIRONMENT ),
				)
			);
			TeePay_WC_Logger::debug(
				'Checkout session read.',
				array(
					'order_id'   => $order->get_id(),
					'session_id' => $stored['id'],
					'via'        => $via,
					'state'      => $result['state'],
					'reason'     => $result['reason'],
				)
			);
			if ( TeePay_WC_Payment_Evaluator::PAID === $result['state'] ) {
				$this->apply_paid( $order, $result['payment_reference'], $via );
				return $result['state'];
			}
			if ( TeePay_WC_Payment_Evaluator::MISMATCH === $result['state'] ) {
				$this->apply_mismatch( $order, $result );
				return $result['state'];
			}
			$states[] = $result['state'];
		}

		if ( TeePay_WC_Payment_Evaluator::EXPIRED === $states[0] && array( TeePay_WC_Payment_Evaluator::EXPIRED ) === array_values( array_unique( $states ) ) ) {
			$this->apply_failed( $order, __( 'The TeePay checkout expired without a payment.', 'teepay-for-woocommerce' ) );
			return TeePay_WC_Payment_Evaluator::EXPIRED;
		}

		return in_array( TeePay_WC_Payment_Evaluator::PENDING, $states, true ) ? TeePay_WC_Payment_Evaluator::PENDING : $states[0];
	}

	/**
	 * Confirm a mobile money push order by reading its collection.
	 *
	 * @param WC_Order             $order  Order.
	 * @param TeePay_WC_Api_Client $client Client.
	 * @param string               $via    Trigger.
	 * @return string
	 */
	private function confirm_collection( $order, $client, $via ) {
		$reference = (string) $order->get_meta( self::META_COLLECTION );
		if ( '' === $reference ) {
			return TeePay_WC_Payment_Evaluator::UNKNOWN;
		}
		$result = TeePay_WC_Payment_Evaluator::evaluate_collection(
			$client->get_collection( $reference ),
			array(
				'reference'    => $reference,
				'amount_minor' => TeePay_WC_Money::to_minor( (string) $order->get_total(), (string) $order->get_currency() ),
				'currency'     => (string) $order->get_currency(),
			)
		);
		TeePay_WC_Logger::debug(
			'Collection read.',
			array(
				'order_id'  => $order->get_id(),
				'reference' => $reference,
				'via'       => $via,
				'state'     => $result['state'],
				'reason'    => $result['reason'],
			)
		);
		switch ( $result['state'] ) {
			case TeePay_WC_Payment_Evaluator::PAID:
				$this->apply_paid( $order, $result['payment_reference'], $via );
				break;
			case TeePay_WC_Payment_Evaluator::MISMATCH:
				$this->apply_mismatch( $order, $result );
				break;
			case TeePay_WC_Payment_Evaluator::FAILED:
			case TeePay_WC_Payment_Evaluator::EXPIRED:
				$this->next_attempt( $order );
				$this->apply_failed(
					$order,
					'cancelled' === $result['reason']
						? __( 'The customer cancelled the mobile money prompt.', 'teepay-for-woocommerce' )
						: __( 'The mobile money payment did not complete.', 'teepay-for-woocommerce' )
				);
				break;
		}

		return $result['state'];
	}

	/**
	 * Mark an order paid.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $reference TeePay payment reference.
	 * @param string   $via       Trigger.
	 * @return void
	 */
	private function apply_paid( $order, $reference, $via ) {
		if ( $order->is_paid() ) {
			return;
		}
		$order->update_meta_data( self::META_PAYMENT_REF, $reference );
		$order->add_order_note(
			sprintf(
				/* translators: 1: TeePay payment reference, 2: what triggered the check, e.g. webhook */
				__( 'TeePay payment confirmed by an authenticated status read. Payment reference: %1$s (checked after: %2$s).', 'teepay-for-woocommerce' ),
				$reference,
				$via
			)
		);
		$order->payment_complete( $reference );
	}

	/**
	 * Hold an order whose TeePay payment does not match it.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $result Evaluator result.
	 * @return void
	 */
	private function apply_mismatch( $order, array $result ) {
		if ( 'yes' === $order->get_meta( self::META_MISMATCH ) ) {
			return;
		}
		$order->update_meta_data( self::META_MISMATCH, 'yes' );
		TeePay_WC_Logger::error(
			'TeePay payment does not match the order.',
			array(
				'order_id'  => $order->get_id(),
				'reason'    => $result['reason'],
				'reference' => $result['payment_reference'],
			)
		);
		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: 1: TeePay payment reference, 2: reason code */
				__( 'TeePay reports payment %1$s, but its amount or currency does not match this order (%2$s). The order was not marked paid. Reconcile it in the TeePay dashboard before shipping.', 'teepay-for-woocommerce' ),
				$result['payment_reference'],
				$result['reason']
			)
		);
	}

	/**
	 * Fail a pending order.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $note  Note.
	 * @return void
	 */
	private function apply_failed( $order, $note ) {
		if ( 'pending' === $order->get_status() ) {
			$order->update_status( 'failed', $note );
		}
	}

	/**
	 * Store the payment context on an order before calling TeePay.
	 *
	 * @param WC_Order $order       Order.
	 * @param string   $mode        hosted or push.
	 * @param string   $environment live or sandbox.
	 * @param string   $reference   Reference.
	 * @param string   $amount      Minor units.
	 * @param string   $currency    Currency.
	 * @return void
	 */
	private function prepare_order( $order, $mode, $environment, $reference, $amount, $currency ) {
		if ( (string) $order->get_meta( self::META_ENVIRONMENT ) !== $environment && '' !== (string) $order->get_meta( self::META_ENVIRONMENT ) ) {
			// Switching environment: earlier sessions belong to another key.
			$order->update_meta_data( self::META_SESSIONS, array() );
		}
		$order->update_meta_data( self::META_MODE, $mode );
		$order->update_meta_data( self::META_ENVIRONMENT, $environment );
		$order->update_meta_data( self::META_REFERENCE, $reference );
		$order->update_meta_data( self::META_AMOUNT, $amount );
		$order->update_meta_data( self::META_CURRENCY, $currency );
		$order->save();
	}

	/**
	 * Record a checkout session on an order.
	 *
	 * @param WC_Order $order    Order.
	 * @param array    $session  Session.
	 * @param string   $amount   Minor units.
	 * @param string   $currency Currency.
	 * @return void
	 * @throws RuntimeException When the session has no valid ID.
	 */
	private function remember_session( $order, array $session, $amount, $currency ) {
		$id = isset( $session['id'] ) && is_string( $session['id'] ) ? $session['id'] : '';
		if ( ! preg_match( TeePay_WC_Api_Client::UUID_PATTERN, $id ) ) {
			throw new RuntimeException( 'TeePay returned no checkout session ID.' );
		}
		if ( ! $this->order_has_session( $order, $id ) ) {
			$stored   = array_reverse( $this->sessions( $order ) );
			$stored[] = array(
				'id'           => $id,
				'amount_minor' => $amount,
				'currency'     => $currency,
				'created'      => time(),
			);
			$order->update_meta_data( self::META_SESSIONS, array_slice( $stored, -self::MAX_SESSIONS ) );
		}
		$order->save();
	}

	/**
	 * Idempotency key for the order's current attempt. Retrying the same attempt (for example after
	 * a timeout) returns the same session or prompt instead of creating a second one.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $amount   Minor units.
	 * @param string   $currency Currency.
	 * @param string   $kind     "s" for sessions, "p" for prompts.
	 * @return string
	 */
	private function idempotency_key( $order, $amount, $currency, $kind ) {
		$attempt = max( 1, (int) $order->get_meta( self::META_ATTEMPT ) );

		return sprintf( 'wc-%s-%d-%s-%s-%s%d', self::site_tag(), $order->get_id(), $amount, $currency, $kind, $attempt );
	}

	/**
	 * Move to a new attempt, so the next request creates a fresh session or prompt.
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	private function next_attempt( $order ) {
		$order->update_meta_data( self::META_ATTEMPT, max( 1, (int) $order->get_meta( self::META_ATTEMPT ) ) + 1 );
		$order->save();
	}

	/**
	 * Client for an environment or an exception.
	 *
	 * @param string $environment Environment.
	 * @return TeePay_WC_Api_Client
	 * @throws RuntimeException When not configured.
	 */
	private function require_client( $environment ) {
		$client = $this->client( $environment );
		if ( null === $client ) {
			throw new RuntimeException( 'No TeePay API key is configured for ' . esc_html( $environment ) . '.' );
		}

		return $client;
	}

	/**
	 * Title shown on the TeePay checkout.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function checkout_title( $order ) {
		$site  = wp_strip_all_tags( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
		$title = sprintf(
			/* translators: 1: order number, 2: store name */
			__( 'Order #%1$s at %2$s', 'teepay-for-woocommerce' ),
			$order->get_order_number(),
			$site
		);
		$title = trim( preg_replace( '/\s+/', ' ', $title ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 100, 'UTF-8' ) : substr( $title, 0, 100 );
	}

	/**
	 * Short item summary shown on the TeePay checkout.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function checkout_description( $order ) {
		$parts = array();
		foreach ( $order->get_items() as $item ) {
			$parts[] = wp_strip_all_tags( (string) $item->get_name() ) . ' x ' . (int) $item->get_quantity();
		}
		$text = trim( preg_replace( '/\s+/', ' ', implode( ', ', $parts ) ) );
		if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text ) ) > 200 ) {
			$text = ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 197, 'UTF-8' ) : substr( $text, 0, 197 ) ) . '...';
		}

		return $text;
	}

	/**
	 * Take a short per-order lock so a webhook and a return visit cannot complete an order twice.
	 *
	 * @param int $order_id Order ID.
	 * @return bool
	 */
	private function lock( $order_id ) {
		$name = 'teepay_wc_lock_' . (int) $order_id;
		if ( add_option( $name, time(), '', 'no' ) ) {
			return true;
		}
		$since = (int) get_option( $name );
		if ( $since > 0 && time() - $since > 60 ) {
			delete_option( $name );
			return (bool) add_option( $name, time(), '', 'no' );
		}

		return false;
	}

	/**
	 * Release the lock.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	private function unlock( $order_id ) {
		delete_option( 'teepay_wc_lock_' . (int) $order_id );
	}
}
