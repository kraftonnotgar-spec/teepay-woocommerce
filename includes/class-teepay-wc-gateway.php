<?php
/**
 * WooCommerce payment gateway.
 *
 * @package TeePay\WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * TeePay mobile money gateway: hosted checkout (default) or a mobile money prompt.
 */
class TeePay_WC_Gateway extends WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = TeePay_WC_Order_Service::GATEWAY_ID;
		$this->method_title       = __( 'TeePay', 'teepay-for-woocommerce' );
		$this->method_description = __( 'Accept M-Pesa, Airtel Money, Mixx by Yas and HaloPesa in Tanzania. Customers pay on the TeePay hosted checkout, or approve a prompt sent to their phone.', 'teepay-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = apply_filters( 'teepay_wc_icon', TEEPAY_WC_URL . 'assets/images/teepay-mark.svg' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );
		$this->has_fields  = 'push' === $this->get_option( 'payment_mode' ) || 'yes' === $this->get_option( 'testmode' );

		TeePay_WC_Logger::set_enabled( 'yes' === $this->get_option( 'debug' ) );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
	}

	/**
	 * Order service using this gateway's settings.
	 *
	 * @return TeePay_WC_Order_Service
	 */
	public function service() {
		return new TeePay_WC_Order_Service( $this->settings );
	}

	/**
	 * Settings fields.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'             => array(
				'title'   => __( 'Enable/Disable', 'teepay-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable TeePay mobile money', 'teepay-for-woocommerce' ),
				'default' => 'no',
			),
			'title'               => array(
				'title'       => __( 'Title', 'teepay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'The payment method name customers see at checkout.', 'teepay-for-woocommerce' ),
				'default'     => __( 'Mobile money', 'teepay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'         => array(
				'title'       => __( 'Description', 'teepay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Shown below the payment method at checkout.', 'teepay-for-woocommerce' ),
				'default'     => __( 'Pay with M-Pesa, Airtel Money, Mixx by Yas or HaloPesa.', 'teepay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'payment_mode'        => array(
				'title'       => __( 'Payment flow', 'teepay-for-woocommerce' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'default'     => 'hosted',
				'options'     => array(
					'hosted' => __( 'TeePay hosted checkout (recommended)', 'teepay-for-woocommerce' ),
					'push'   => __( 'Mobile money prompt on this site', 'teepay-for-woocommerce' ),
				),
				'description' => __( 'Hosted checkout sends customers to a TeePay page to pay. The prompt flow asks for a Tanzanian mobile number at checkout and sends the payment prompt directly; your TeePay account must have mobile money push enabled.', 'teepay-for-woocommerce' ),
			),
			'api_details'         => array(
				'title'       => __( 'API keys and webhooks', 'teepay-for-woocommerce' ),
				'type'        => 'title',
				'description' => $this->endpoints_description(),
			),
			'testmode'            => array(
				'title'       => __( 'Sandbox mode', 'teepay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Use the sandbox key (tp_test_)', 'teepay-for-woocommerce' ),
				'default'     => 'no',
				'description' => __( 'Sandbox keys check your connection and settings. TeePay moves money in live mode only, so test a real payment with a small live amount.', 'teepay-for-woocommerce' ),
			),
			'live_api_key'        => array(
				'title'       => __( 'Live secret key', 'teepay-for-woocommerce' ),
				'type'        => 'teepay_secret',
				'description' => __( 'Starts with tp_live_. Needs the collection:create and collection:read scopes.', 'teepay-for-woocommerce' ),
				'default'     => '',
				'prefix'      => 'tp_live_',
			),
			'live_webhook_secret' => array(
				'title'       => __( 'Live webhook signing secret', 'teepay-for-woocommerce' ),
				'type'        => 'teepay_secret',
				'description' => __( 'From Developers > Webhooks in the TeePay dashboard (whsec_...).', 'teepay-for-woocommerce' ),
				'default'     => '',
				'prefix'      => '',
			),
			'test_api_key'        => array(
				'title'       => __( 'Sandbox secret key', 'teepay-for-woocommerce' ),
				'type'        => 'teepay_secret',
				'description' => __( 'Starts with tp_test_.', 'teepay-for-woocommerce' ),
				'default'     => '',
				'prefix'      => 'tp_test_',
			),
			'test_webhook_secret' => array(
				'title'       => __( 'Sandbox webhook signing secret', 'teepay-for-woocommerce' ),
				'type'        => 'teepay_secret',
				'description' => __( 'The signing secret for the sandbox environment.', 'teepay-for-woocommerce' ),
				'default'     => '',
				'prefix'      => '',
			),
			'payment_profile_id'  => array(
				'title'       => __( 'Payment profile ID (optional)', 'teepay-for-woocommerce' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => '00000000-0000-0000-0000-000000000000',
				'description' => __( 'The TeePay payment profile whose branding, return URL and webhook URL this store uses. Leave empty to use your default profile.', 'teepay-for-woocommerce' ),
			),
			'debug'               => array(
				'title'       => __( 'Debug log', 'teepay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log TeePay events', 'teepay-for-woocommerce' ),
				'default'     => 'no',
				'description' => sprintf(
					/* translators: %s: log source name */
					__( 'Writes to WooCommerce > Status > Logs (source "%s"). Keys, secrets and phone numbers are removed. Errors are always logged.', 'teepay-for-woocommerce' ),
					TeePay_WC_Logger::SOURCE
				),
			),
		);
	}

	/**
	 * Webhook and return URLs for the settings screen.
	 *
	 * @return string
	 */
	private function endpoints_description() {
		return sprintf(
			/* translators: 1: webhook URL, 2: return URL */
			__( 'In the TeePay dashboard, open Payment profiles and set the profile this store uses: Webhook URL %1$s and Return URL %2$s. Then create the webhook signing secret under Developers > Webhooks and paste it below.', 'teepay-for-woocommerce' ),
			'<code>' . esc_html( TeePay_WC_Plugin::webhook_url() ) . '</code>',
			'<code>' . esc_html( TeePay_WC_Plugin::return_url() ) . '</code>'
		);
	}

	/**
	 * Render a secret field. The saved value is never printed back to the page.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_teepay_secret_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$saved     = '' !== (string) $this->get_option( $key );
		$data      = wp_parse_args(
			$data,
			array(
				'title'       => '',
				'description' => '',
			)
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<input class="input-text regular-input" type="password" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="" autocomplete="new-password" spellcheck="false"
						placeholder="<?php echo esc_attr( $saved ? __( 'Saved. Leave blank to keep it.', 'teepay-for-woocommerce' ) : __( 'Not set', 'teepay-for-woocommerce' ) ); ?>" />
					<?php if ( $saved ) : ?>
						<br /><label for="<?php echo esc_attr( $field_key . '_clear' ); ?>"><input type="checkbox" name="<?php echo esc_attr( $field_key . '_clear' ); ?>" id="<?php echo esc_attr( $field_key . '_clear' ); ?>" value="1" /> <?php esc_html_e( 'Remove the saved value', 'teepay-for-woocommerce' ); ?></label>
					<?php endif; ?>
					<?php echo $this->get_description_html( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by WooCommerce. ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Validate a secret field: blank keeps the saved value; the clear box removes it.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string
	 */
	public function validate_teepay_secret_field( $key, $value ) {
		$post_data = $this->get_post_data();
		$clear_key = $this->get_field_key( $key ) . '_clear';
		if ( ! empty( $post_data[ $clear_key ] ) ) {
			return '';
		}
		$value = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
		if ( '' === $value ) {
			return (string) $this->get_option( $key );
		}
		$prefix = isset( $this->form_fields[ $key ]['prefix'] ) ? (string) $this->form_fields[ $key ]['prefix'] : '';
		$valid  = '' !== $prefix
			? ( TeePay_WC_Api_Client::is_valid_key( $value ) && 0 === strpos( $value, $prefix ) )
			: (bool) preg_match( '/^[A-Za-z0-9_.-]{16,256}$/', $value );
		if ( ! $valid ) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: field name */
					__( '%s was not saved because it is not in the expected format.', 'teepay-for-woocommerce' ),
					$this->form_fields[ $key ]['title']
				)
			);
			return (string) $this->get_option( $key );
		}

		return $value;
	}

	/**
	 * Validate the payment profile ID.
	 *
	 * @param string      $key   Field key.
	 * @param string|null $value Posted value.
	 * @return string
	 */
	public function validate_payment_profile_id_field( $key, $value ) {
		$value = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
		if ( '' !== $value && ! preg_match( TeePay_WC_Api_Client::UUID_PATTERN, $value ) ) {
			WC_Admin_Settings::add_error( __( 'The payment profile ID must be a UUID from the TeePay dashboard.', 'teepay-for-woocommerce' ) );
			return (string) $this->get_option( $key );
		}

		return strtolower( $value );
	}

	/**
	 * Save settings, then check the active key against TeePay.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();
		$this->init_settings();
		TeePay_WC_Logger::set_enabled( 'yes' === $this->get_option( 'debug' ) );
		$result = TeePay_WC_Plugin::check_connection( $this->settings );
		if ( '' !== $result['message'] ) {
			if ( 'ok' === $result['level'] ) {
				WC_Admin_Settings::add_message( $result['message'] );
			} else {
				WC_Admin_Settings::add_error( $result['message'] );
			}
		}

		return $saved;
	}

	/**
	 * Settings screen.
	 *
	 * @return void
	 */
	public function admin_options() {
		if ( 'yes' === $this->get_option( 'testmode' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Sandbox mode is on. Sandbox keys verify your connection only; TeePay moves money in live mode only. Turn sandbox mode off and test with a small live payment before you launch.', 'teepay-for-woocommerce' ) . '</p></div>';
		}
		parent::admin_options();
	}

	/**
	 * Whether the method can be offered at checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}
		$service     = $this->service();
		$environment = $service->active_environment();
		if ( '' === $service->api_key( $environment ) || TeePay_WC_Restriction::is_paused( $environment ) ) {
			return false;
		}

		return in_array( get_woocommerce_currency(), TeePay_WC_Plugin::supported_currencies( $environment ), true );
	}

	/**
	 * Checkout fields.
	 *
	 * @return void
	 */
	public function payment_fields() {
		$description = (string) $this->get_description();
		if ( 'yes' === $this->get_option( 'testmode' ) ) {
			$description .= ' ' . __( 'SANDBOX MODE: no money will move.', 'teepay-for-woocommerce' );
		}
		if ( '' !== trim( $description ) ) {
			echo wp_kses_post( wpautop( wptexturize( trim( $description ) ) ) );
		}
		if ( 'push' !== $this->get_option( 'payment_mode' ) ) {
			return;
		}
		$phone = '';
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$normalized = TeePay_WC_Phone::normalize_tz( WC()->customer->get_billing_phone() );
			$phone      = null === $normalized ? '' : $normalized;
		}
		?>
		<fieldset id="wc-teepay-phone-form" class="wc-payment-form">
			<p class="form-row form-row-wide">
				<label for="teepay_phone"><?php esc_html_e( 'Mobile money number', 'teepay-for-woocommerce' ); ?>&nbsp;<span class="required" aria-hidden="true">*</span></label>
				<input id="teepay_phone" name="teepay_phone" class="input-text" type="tel" inputmode="tel" autocomplete="tel" placeholder="07XX XXX XXX" value="<?php echo esc_attr( $phone ); ?>" required />
				<span class="description"><?php esc_html_e( 'We will send a payment prompt to this number. Approve it with your PIN.', 'teepay-for-woocommerce' ); ?></span>
			</p>
		</fieldset>
		<?php
	}

	/**
	 * Validate the phone number in the prompt flow.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		if ( 'push' !== $this->get_option( 'payment_mode' ) ) {
			return true;
		}
		if ( null === self::posted_phone() ) {
			wc_add_notice( __( 'Enter a valid Tanzanian mobile money number, for example 0712 345 678.', 'teepay-for-woocommerce' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Phone number posted at checkout, normalised, or null.
	 *
	 * The classic checkout nonce is verified by WooCommerce before process_payment(); the Store API
	 * (Checkout block) verifies its own nonce and passes payment data in the same field.
	 *
	 * @return string|null
	 */
	public static function posted_phone() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce checkout.
		$raw = isset( $_POST['teepay_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['teepay_phone'] ) ) : '';

		return TeePay_WC_Phone::normalize_tz( $raw );
	}

	/**
	 * Start the payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order   = wc_get_order( $order_id );
		$service = $this->service();
		if ( ! $order ) {
			wc_add_notice( __( 'We could not find your order. Please try again.', 'teepay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		try {
			if ( 'push' === $service->payment_mode() ) {
				$phone = self::posted_phone();
				if ( null === $phone ) {
					wc_add_notice( __( 'Enter a valid Tanzanian mobile money number, for example 0712 345 678.', 'teepay-for-woocommerce' ), 'error' );
					return array( 'result' => 'failure' );
				}
				$service->start_push( $order, $phone );
				$order->add_order_note(
					sprintf(
						/* translators: %s: masked phone number */
						__( 'TeePay mobile money prompt sent to %s. Waiting for the customer to approve it.', 'teepay-for-woocommerce' ),
						TeePay_WC_Phone::mask( $phone )
					)
				);
				$redirect = $order->get_checkout_order_received_url();
			} else {
				$redirect = $service->start_hosted_checkout( $order );
			}
		} catch ( TeePay_WC_Api_Exception $e ) {
			wc_add_notice( $this->handle_api_error( $e, $order, $service->active_environment() ), 'error' );
			return array( 'result' => 'failure' );
		} catch ( Exception $e ) {
			TeePay_WC_Logger::error(
				'Could not start a TeePay payment.',
				array(
					'order_id' => $order->get_id(),
					'error'    => $e->getMessage(),
				)
			);
			wc_add_notice( __( 'Mobile money payment could not be started. Please try again or choose another payment method.', 'teepay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'teepay_order_id', $order->get_id() );
		}

		return array(
			'result'   => 'success',
			'redirect' => $redirect,
		);
	}

	/**
	 * Record an API error and choose the shopper message.
	 *
	 * @param TeePay_WC_Api_Exception $e           Error.
	 * @param WC_Order                $order       Order.
	 * @param string                  $environment Environment.
	 * @return string Message for the shopper.
	 */
	public function handle_api_error( TeePay_WC_Api_Exception $e, $order, $environment ) {
		TeePay_WC_Logger::error(
			'TeePay rejected the payment request.',
			array(
				'order_id'   => $order->get_id(),
				'status'     => $e->get_http_status(),
				'code'       => $e->get_error_code(),
				'request_id' => $e->get_request_id(),
			)
		);
		$order->add_order_note(
			sprintf(
				/* translators: 1: error code, 2: TeePay request ID */
				__( 'TeePay could not start the payment (%1$s, request ID %2$s).', 'teepay-for-woocommerce' ),
				$e->get_error_code(),
				'' === $e->get_request_id() ? '-' : $e->get_request_id()
			)
		);

		if ( $e->is_account_restricted() ) {
			TeePay_WC_Restriction::record( $environment, $e->get_request_id() );
			return TeePay_WC_Restriction::shopper_message();
		}
		switch ( $e->get_error_code() ) {
			case 'invalid_amount':
			case 'collection_limit_exceeded':
				return __( 'This amount cannot be paid with mobile money. Please choose another payment method.', 'teepay-for-woocommerce' );
			case 'unsupported_currency':
			case 'unsupported_market':
				return __( 'Mobile money is not available for this currency. Please choose another payment method.', 'teepay-for-woocommerce' );
		}
		if ( $e->is_retryable() ) {
			return __( 'We could not reach the payment service. Please try again in a moment.', 'teepay-for-woocommerce' );
		}

		return TeePay_WC_Restriction::shopper_message();
	}

	/**
	 * Order received page: confirm with TeePay and show the payment state.
	 *
	 * @param int $order_id Order ID.
	 * @return void
	 */
	public function thankyou_page( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $this->id !== $order->get_payment_method() ) {
			return;
		}
		$state = $order->is_paid() ? TeePay_WC_Payment_Evaluator::PAID : $this->service()->confirm( $order, 'thankyou' );
		if ( TeePay_WC_Payment_Evaluator::PAID === $state || $order->is_paid() ) {
			echo '<p class="teepay-status teepay-status--paid">' . esc_html__( 'Your mobile money payment was received. Thank you.', 'teepay-for-woocommerce' ) . '</p>';
			return;
		}
		if ( in_array( $state, array( TeePay_WC_Payment_Evaluator::FAILED, TeePay_WC_Payment_Evaluator::EXPIRED ), true ) || $order->has_status( 'failed' ) ) {
			printf(
				'<p class="teepay-status teepay-status--failed">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'Your mobile money payment was not completed.', 'teepay-for-woocommerce' ),
				esc_url( $order->get_checkout_payment_url() ),
				esc_html__( 'Try again', 'teepay-for-woocommerce' )
			);
			return;
		}
		if ( $order->has_status( 'on-hold' ) ) {
			echo '<p class="teepay-status">' . esc_html__( 'We are reviewing your payment and will update your order shortly.', 'teepay-for-woocommerce' ) . '</p>';
			return;
		}

		$masked  = (string) $order->get_meta( TeePay_WC_Order_Service::META_PHONE_MASKED );
		$message = 'push' === $order->get_meta( TeePay_WC_Order_Service::META_MODE ) && '' !== $masked
			? sprintf(
				/* translators: %s: masked phone number */
				__( 'We sent a payment prompt to %s. Enter your mobile money PIN on your phone to approve it. This page updates automatically.', 'teepay-for-woocommerce' ),
				$masked
			)
			: __( 'We are waiting for TeePay to confirm your payment. This page updates automatically.', 'teepay-for-woocommerce' );

		echo '<p class="teepay-status teepay-status--pending" role="status" aria-live="polite" data-teepay-status>' . esc_html( $message ) . '</p>';

		wp_enqueue_script( 'teepay-order-status', TEEPAY_WC_URL . 'assets/js/teepay-order-status.js', array(), TEEPAY_WC_VERSION, true );
		wp_localize_script(
			'teepay-order-status',
			'teepayOrderStatus',
			array(
				'url'      => TeePay_WC_Plugin::status_url(),
				'orderId'  => $order->get_id(),
				'orderKey' => $order->get_order_key(),
				'paid'     => __( 'Payment received. Updating your order...', 'teepay-for-woocommerce' ),
				'failed'   => __( 'Your mobile money payment was not completed.', 'teepay-for-woocommerce' ),
				'timeout'  => __( 'Still waiting for confirmation. We will email you when the payment is confirmed.', 'teepay-for-woocommerce' ),
			)
		);
	}
}
