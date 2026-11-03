<?php
/**
 * Minimal WordPress and WooCommerce stand-ins for unit tests. Only what the plugin calls.
 *
 * @package TeePay\WooCommerce\Tests
 */

// phpcs:ignoreFile

/**
 * Global test state, reset before each test.
 */
final class TP_State {
	public static $options    = array();
	public static $transients = array();
	public static $notices    = array();
	public static $orders     = array();
	public static $logs       = array();
	public static $filters    = array();
	public static $admin      = array( 'errors' => array(), 'messages' => array() );
	public static $session    = array();
	public static $user_can   = true;

	public static function reset() {
		self::$options    = array();
		self::$transients = array();
		self::$notices    = array();
		self::$orders     = array();
		self::$logs       = array();
		self::$filters    = array();
		self::$admin      = array( 'errors' => array(), 'messages' => array() );
		self::$session    = array();
		self::$user_can   = true;
		$_POST            = array();
		$_GET             = array();
	}
}

function __( $text, $domain = 'default' ) { return $text; }
function esc_html__( $text, $domain = 'default' ) { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html_e( $text, $domain = 'default' ) { echo htmlspecialchars( $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES ); }
function wp_kses_post( $text ) { return (string) $text; }
function wpautop( $text ) { return '<p>' . $text . '</p>'; }
function wptexturize( $text ) { return $text; }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function wp_unslash( $value ) { return is_string( $value ) ? stripslashes( $value ) : $value; }
function absint( $value ) { return abs( (int) $value ); }
function home_url( $path = '' ) { return 'https://shop.example.tz' . $path; }
function admin_url( $path = '' ) { return 'https://shop.example.tz/wp-admin/' . $path; }
function get_bloginfo( $show = '' ) { return 'Duka &amp; Co'; }
function wp_strip_all_tags( $text ) { return trim( strip_tags( (string) $text ) ); }
function wp_json_encode( $data, $options = 0 ) { return json_encode( $data, $options ); }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
function current_user_can( $cap ) { return TP_State::$user_can; }
function is_user_logged_in() { return false; }
function wc_get_page_permalink( $page ) { return 'https://shop.example.tz/' . $page . '/'; }
function get_woocommerce_currency() { return 'TZS'; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=test'; }
function wp_enqueue_script() {}
function wp_localize_script() {}

function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, TP_State::$options ) ) {
		return false;
	}
	TP_State::$options[ $name ] = $value;
	return true;
}
function get_option( $name, $default = false ) { return array_key_exists( $name, TP_State::$options ) ? TP_State::$options[ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { TP_State::$options[ $name ] = $value; return true; }
function delete_option( $name ) { unset( TP_State::$options[ $name ] ); return true; }
function get_transient( $name ) { return isset( TP_State::$transients[ $name ] ) ? TP_State::$transients[ $name ] : false; }
function set_transient( $name, $value, $ttl = 0 ) { TP_State::$transients[ $name ] = $value; return true; }

function add_filter( $hook, $callback ) { TP_State::$filters[ $hook ][] = $callback; }
function add_action( $hook, $callback ) {}
function apply_filters( $hook, $value ) {
	$args = func_get_args();
	foreach ( isset( TP_State::$filters[ $hook ] ) ? TP_State::$filters[ $hook ] : array() as $callback ) {
		$args[1] = call_user_func_array( $callback, array_slice( $args, 1 ) );
	}
	return $args[1];
}

function wc_add_notice( $message, $type = 'success' ) { TP_State::$notices[] = array( 'type' => $type, 'message' => $message ); }
function wc_get_order( $id ) { return isset( TP_State::$orders[ (int) $id ] ) ? TP_State::$orders[ (int) $id ] : false; }
function wc_get_orders( $args ) {
	$found = array();
	foreach ( TP_State::$orders as $order ) {
		if ( isset( $args['payment_method'] ) && $order->get_payment_method() !== $args['payment_method'] ) {
			continue;
		}
		foreach ( isset( $args['meta_query'] ) ? $args['meta_query'] : array() as $clause ) {
			if ( (string) $order->get_meta( $clause['key'] ) !== (string) $clause['value'] ) {
				continue 2;
			}
		}
		$found[] = $order;
	}
	return array_slice( $found, 0, isset( $args['limit'] ) ? $args['limit'] : 10 );
}

final class TP_Logger {
	public function log( $level, $message, $context = array() ) { TP_State::$logs[] = array( $level, $message ); }
}
function wc_get_logger() { return new TP_Logger(); }

final class TP_Session {
	public function get( $key ) { return isset( TP_State::$session[ $key ] ) ? TP_State::$session[ $key ] : null; }
	public function set( $key, $value ) { TP_State::$session[ $key ] = $value; }
}
final class TP_Customer {
	public function get_billing_phone() { return '0712 345 678'; }
}
final class TP_WooCommerce {
	public $session;
	public $customer;
	public function __construct() {
		$this->session  = new TP_Session();
		$this->customer = new TP_Customer();
	}
	public function api_request_url( $name ) { return 'https://shop.example.tz/wc-api/' . $name . '/'; }
	public function payment_gateways() { return null; }
}
function WC() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new TP_WooCommerce();
	}
	return $instance;
}

class WC_Admin_Settings {
	public static function add_error( $text ) { TP_State::$admin['errors'][] = $text; }
	public static function add_message( $text ) { TP_State::$admin['messages'][] = $text; }
}

/**
 * Just enough of WC_Settings_API / WC_Payment_Gateway.
 */
abstract class WC_Payment_Gateway {
	public $id;
	public $title;
	public $description;
	public $enabled = 'no';
	public $method_title;
	public $method_description;
	public $has_fields;
	public $supports = array();
	public $icon;
	public $form_fields = array();
	public $settings = array();
	protected $data = array();

	public function init_settings() {
		$saved = get_option( 'woocommerce_' . $this->id . '_settings', array() );
		$defaults = array();
		foreach ( $this->form_fields as $key => $field ) {
			$defaults[ $key ] = isset( $field['default'] ) ? $field['default'] : '';
		}
		$this->settings = array_merge( $defaults, is_array( $saved ) ? $saved : array() );
	}
	public function get_option( $key, $empty_value = null ) { return isset( $this->settings[ $key ] ) ? $this->settings[ $key ] : $empty_value; }
	public function get_field_key( $key ) { return 'woocommerce_' . $this->id . '_' . $key; }
	public function get_post_data() { return ! empty( $this->data ) ? $this->data : $_POST; }
	public function set_post_data( $data ) { $this->data = $data; }
	public function get_description() { return $this->description; }
	public function get_description_html( $data ) { return empty( $data['description'] ) ? '' : '<p class="description">' . $data['description'] . '</p>'; }
	public function is_available() { return 'yes' === $this->enabled; }
	public function process_admin_options() { return true; }
	public function admin_options() {}
}
