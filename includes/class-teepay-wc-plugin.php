<?php
/**
 * Plugin bootstrap.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the gateway, endpoints, scheduled reconciliation and admin notices.
 */
class TeePay_WC_Plugin {

	const CRON_HOOK       = 'teepay_wc_reconcile';
	const CRON_SCHEDULE   = 'teepay_wc_fifteen_minutes';
	const CAPS_TRANSIENT  = 'teepay_wc_capabilities_';
	const RECHECK_ACTION  = 'teepay_wc_recheck';
	const RECONCILE_LIMIT = 20;

	/**
	 * Hook everything up once WooCommerce is loaded.
	 *
	 * @return void
	 */
	public static function init() {
		load_plugin_textdomain( 'teepay-for-woocommerce', false, dirname( plugin_basename( TEEPAY_WC_FILE ) ) . '/languages' );

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woocommerce_notice' ) );
			return;
		}
		require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-gateway.php';

		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( TEEPAY_WC_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'woocommerce_api_teepay_webhook', array( __CLASS__, 'handle_webhook' ) );
		add_action( 'woocommerce_api_teepay_return', array( __CLASS__, 'handle_return' ) );
		add_action( 'woocommerce_api_teepay_status', array( __CLASS__, 'handle_status' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 15 minutes.
		add_action( self::CRON_HOOK, array( __CLASS__, 'reconcile' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'admin_post_' . self::RECHECK_ACTION, array( __CLASS__, 'handle_recheck' ) );
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_blocks_support' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Add the gateway.
	 *
	 * @param array $gateways Gateways.
	 * @return array
	 */
	public static function register_gateway( $gateways ) {
		$gateways[] = 'TeePay_WC_Gateway';
		return $gateways;
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=teepay' ) ) . '">' . esc_html__( 'Settings', 'teepay-for-woocommerce' ) . '</a>'
		);
		return $links;
	}

	/**
	 * Register the Cart and Checkout blocks integration.
	 *
	 * @return void
	 */
	public static function register_blocks_support() {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}
		require_once TEEPAY_WC_DIR . 'includes/class-teepay-wc-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new TeePay_WC_Blocks_Support() );
			}
		);
	}

	/**
	 * The gateway instance.
	 *
	 * @return TeePay_WC_Gateway|null
	 */
	public static function gateway() {
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			if ( isset( $gateways[ TeePay_WC_Order_Service::GATEWAY_ID ] ) ) {
				return $gateways[ TeePay_WC_Order_Service::GATEWAY_ID ];
			}
		}

		return class_exists( 'TeePay_WC_Gateway' ) ? new TeePay_WC_Gateway() : null;
	}

	/**
	 * Webhook URL to configure on the TeePay payment profile.
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return WC()->api_request_url( 'teepay_webhook' );
	}

	/**
	 * Return URL to configure on the TeePay payment profile.
	 *
	 * @return string
	 */
	public static function return_url() {
		return WC()->api_request_url( 'teepay_return' );
	}

	/**
	 * Status URL polled by the order received page.
	 *
	 * @return string
	 */
	public static function status_url() {
		return WC()->api_request_url( 'teepay_status' );
	}

	/**
	 * Webhook endpoint: /wc-api/teepay_webhook/.
	 *
	 * @return void
	 */
	public static function handle_webhook() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			wp_send_json( array( 'error' => 'method_not_allowed' ), 405 );
		}
		$gateway = self::gateway();
		if ( null === $gateway ) {
			wp_send_json( array( 'error' => 'unavailable' ), 503 );
		}
		// The signature covers the exact bytes, so the body must not be altered before verification.
		$raw     = (string) file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$headers = array();
		foreach ( array( 'X-TeePay-Timestamp', 'X-TeePay-Signature', 'X-TeePay-Event-ID' ) as $name ) {
			$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
			if ( isset( $_SERVER[ $server_key ] ) ) {
				$headers[ $name ] = sanitize_text_field( wp_unslash( $_SERVER[ $server_key ] ) );
			}
		}
		$handler               = new TeePay_WC_Webhook_Handler( $gateway->service() );
		list( $status, $body ) = $handler->handle( $raw, $headers );
		nocache_headers();
		wp_send_json( $body, $status );
	}

	/**
	 * Return endpoint: /wc-api/teepay_return/. TeePay sends customers here from the payment
	 * profile's return URL. Query strings are ignored; the order comes from the shopper's own
	 * WooCommerce session and its payment is read from the TeePay API.
	 *
	 * @return void
	 */
	public static function handle_return() {
		$order_id = ( function_exists( 'WC' ) && WC()->session ) ? absint( WC()->session->get( 'teepay_order_id' ) ) : 0;
		$gateway  = self::gateway();
		wp_safe_redirect( self::return_destination( $order_id, null === $gateway ? null : $gateway->service() ) );
		exit;
	}

	/**
	 * Where to send a returning customer. Only the order ID from the shopper's own session is used;
	 * the order is confirmed with an authenticated TeePay read.
	 *
	 * @param int                          $order_id Order ID from the WooCommerce session.
	 * @param TeePay_WC_Order_Service|null $service  Service.
	 * @return string URL.
	 */
	public static function return_destination( $order_id, $service ) {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order || null === $service || TeePay_WC_Order_Service::GATEWAY_ID !== $order->get_payment_method() ) {
			return wc_get_page_permalink( is_user_logged_in() ? 'myaccount' : 'shop' );
		}
		$state = $service->confirm( $order, 'return' );
		if ( in_array( $state, array( TeePay_WC_Payment_Evaluator::FAILED, TeePay_WC_Payment_Evaluator::EXPIRED ), true ) ) {
			wc_add_notice( __( 'Your mobile money payment was not completed. Please try again.', 'teepay-for-woocommerce' ), 'error' );
			return $order->get_checkout_payment_url();
		}

		return $order->get_checkout_order_received_url();
	}

	/**
	 * Status endpoint polled by the order received page. Requires the order key.
	 *
	 * @return void
	 */
	public static function handle_status() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authorised by the secret order key.
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable
		$order = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order || '' === $key || ! hash_equals( (string) $order->get_order_key(), $key ) || TeePay_WC_Order_Service::GATEWAY_ID !== $order->get_payment_method() ) {
			wp_send_json( array( 'state' => 'not_found' ), 404 );
		}
		nocache_headers();
		if ( $order->is_paid() ) {
			wp_send_json( array( 'state' => 'paid' ) );
		}
		$last = (int) $order->get_meta( TeePay_WC_Order_Service::META_LAST_CHECK );
		if ( time() - $last >= 4 ) {
			$gateway = self::gateway();
			if ( null !== $gateway ) {
				$gateway->service()->confirm( $order, 'status' );
				$order = wc_get_order( $order_id );
			}
		}
		if ( $order->is_paid() ) {
			wp_send_json( array( 'state' => 'paid' ) );
		}
		wp_send_json( array( 'state' => $order->has_status( 'failed' ) ? 'failed' : 'pending' ) );
	}

	/**
	 * Add the 15 minute schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 900,
			'display'  => __( 'Every 15 minutes (TeePay)', 'teepay-for-woocommerce' ),
		);
		return $schedules;
	}

	/**
	 * Confirm recent unpaid TeePay orders whose webhook may not have arrived.
	 *
	 * @return void
	 */
	public static function reconcile() {
		$gateway = self::gateway();
		if ( null === $gateway ) {
			return;
		}
		$service = $gateway->service();
		$orders  = wc_get_orders(
			array(
				'limit'          => self::RECONCILE_LIMIT,
				'payment_method' => TeePay_WC_Order_Service::GATEWAY_ID,
				'status'         => array( 'pending' ),
				'date_created'   => '>' . ( time() - 2 * DAY_IN_SECONDS ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		foreach ( (array) $orders as $order ) {
			if ( '' !== (string) $order->get_meta( TeePay_WC_Order_Service::META_ENVIRONMENT ) ) {
				$service->confirm( $order, 'reconcile' );
			}
		}
	}

	/**
	 * Check a key against GET /v1/capabilities and cache the result.
	 *
	 * @param array $settings Gateway settings.
	 * @return array{level:string,message:string}
	 */
	public static function check_connection( array $settings ) {
		$service     = new TeePay_WC_Order_Service( $settings );
		$environment = $service->active_environment();
		$client      = $service->client( $environment );
		if ( null === $client ) {
			return array(
				'level'   => 'error',
				'message' => 'live' === $environment
					? __( 'Add your live secret key (tp_live_) to accept payments.', 'teepay-for-woocommerce' )
					: __( 'Add your sandbox secret key (tp_test_) or turn sandbox mode off.', 'teepay-for-woocommerce' ),
			);
		}
		try {
			$capabilities = $client->capabilities();
		} catch ( TeePay_WC_Api_Exception $e ) {
			if ( $e->is_account_restricted() ) {
				TeePay_WC_Restriction::record( $environment, $e->get_request_id() );
				return array(
					'level'   => 'error',
					'message' => self::restricted_message( $e->get_request_id() ),
				);
			}
			return array(
				'level'   => 'error',
				'message' => sprintf(
					/* translators: 1: error code, 2: request ID */
					__( 'TeePay did not accept this key (%1$s, request ID %2$s). Check that the key is current and belongs to the selected environment.', 'teepay-for-woocommerce' ),
					$e->get_error_code(),
					'' === $e->get_request_id() ? '-' : $e->get_request_id()
				),
			);
		}
		TeePay_WC_Restriction::clear( $environment );
		set_transient( self::CAPS_TRANSIENT . $environment, $capabilities, HOUR_IN_SECONDS );

		$features = isset( $capabilities['features'] ) && is_array( $capabilities['features'] ) ? $capabilities['features'] : array();
		$feature  = 'push' === $service->payment_mode() ? 'collection_create' : 'checkout_sessions';
		if ( empty( $features[ $feature ] ) ) {
			return array(
				'level'   => 'error',
				'message' => 'sandbox' === $environment
					? __( 'Connected to TeePay sandbox. This key cannot create payments in sandbox; test a real payment in live mode with a small amount.', 'teepay-for-woocommerce' )
					: __( 'Connected to TeePay, but this key cannot create payments for the selected payment flow yet. Check the key scopes and your account approval in the TeePay dashboard.', 'teepay-for-woocommerce' ),
			);
		}

		return array(
			'level'   => 'ok',
			'message' => sprintf(
				/* translators: 1: environment, 2: currency codes */
				__( 'Connected to TeePay (%1$s). Currencies enabled: %2$s.', 'teepay-for-woocommerce' ),
				$environment,
				implode( ', ', self::supported_currencies( $environment ) )
			),
		);
	}

	/**
	 * Currencies the key can collect in. Falls back to TZS when capabilities are not cached.
	 *
	 * @param string $environment Environment.
	 * @return string[]
	 */
	public static function supported_currencies( $environment ) {
		$capabilities = get_transient( self::CAPS_TRANSIENT . $environment );
		if ( is_array( $capabilities ) && isset( $capabilities['supported_currencies'] ) && is_array( $capabilities['supported_currencies'] ) && array() !== $capabilities['supported_currencies'] ) {
			return array_values( array_filter( $capabilities['supported_currencies'], 'is_string' ) );
		}

		return array( 'TZS' );
	}

	/**
	 * Merchant-facing explanation of account_restricted.
	 *
	 * @param string $request_id Request ID.
	 * @return string
	 */
	public static function restricted_message( $request_id ) {
		return sprintf(
			/* translators: %s: TeePay request ID */
			__( 'TeePay has restricted your account, so new mobile money payments cannot be started. Your customers see a general "payment unavailable" message and the payment method is paused at checkout. Check your email or the TeePay dashboard for next steps, or contact TeePay support with request ID %s.', 'teepay-for-woocommerce' ),
			'' === $request_id ? '-' : $request_id
		);
	}

	/**
	 * Admin notices: account restriction and missing setup.
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$state = TeePay_WC_Restriction::get();
		if ( null !== $state ) {
			$recheck = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::RECHECK_ACTION ), self::RECHECK_ACTION );
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p><a class="button" href="%3$s">%4$s</a></p></div>',
				esc_html__( 'TeePay:', 'teepay-for-woocommerce' ),
				esc_html( self::restricted_message( $state['request_id'] ) ),
				esc_url( $recheck ),
				esc_html__( 'Check again', 'teepay-for-woocommerce' )
			);
		}
	}

	/**
	 * "Check again" button on the restriction notice.
	 *
	 * @return void
	 */
	public static function handle_recheck() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'teepay-for-woocommerce' ), 403 );
		}
		check_admin_referer( self::RECHECK_ACTION );
		$gateway = self::gateway();
		if ( null !== $gateway ) {
			self::check_connection( $gateway->settings );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=teepay' ) );
		exit;
	}

	/**
	 * Notice when WooCommerce is missing.
	 *
	 * @return void
	 */
	public static function missing_woocommerce_notice() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'TeePay for WooCommerce requires WooCommerce to be installed and active.', 'teepay-for-woocommerce' ) . '</p></div>';
		}
	}
}
