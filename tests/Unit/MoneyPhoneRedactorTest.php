<?php
/**
 * Amount conversion, phone validation and log redaction.
 *
 * @package TeePay\WooCommerce\Tests
 */

// phpcs:ignoreFile

final class MoneyPhoneRedactorTest extends TP_TestCase {

	/**
	 * @dataProvider amounts
	 */
	public function test_to_minor( $amount, $currency, $expected ) {
		$this->assertSame( $expected, TeePay_WC_Money::to_minor( $amount, $currency ) );
	}

	public function amounts() {
		return array(
			'TZS 5,000.00'       => array( '5000.00', 'TZS', '500000' ),
			'TZS integer'        => array( '5000', 'TZS', '500000' ),
			'float precision'    => array( 19.99, 'TZS', '1999' ),
			'float 0.1 + 0.2'    => array( 0.1 + 0.2, 'TZS', '30' ),
			'round half up'      => array( '10.005', 'TZS', '1001' ),
			'round down'         => array( '10.004', 'TZS', '1000' ),
			'carry'              => array( '9.995', 'TZS', '1000' ),
			'UGX has no decimal' => array( '5000', 'UGX', '5000' ),
			'KWD three decimals' => array( '1.5', 'KWD', '1500' ),
		);
	}

	public function test_to_minor_rejects_negative_and_garbage() {
		$this->expectException( InvalidArgumentException::class );
		TeePay_WC_Money::to_minor( '-5', 'TZS' );
	}

	public function test_from_minor_and_normalize() {
		$this->assertSame( '5000.00', TeePay_WC_Money::from_minor( '500000', 'TZS' ) );
		$this->assertSame( '0.05', TeePay_WC_Money::from_minor( 5, 'TZS' ) );
		$this->assertSame( '5000', TeePay_WC_Money::from_minor( '5000', 'UGX' ) );
		$this->assertSame( '500000', TeePay_WC_Money::normalize_minor( 500000 ) );
		$this->assertSame( '500000', TeePay_WC_Money::normalize_minor( '0500000' ) );
		$this->assertSame( '', TeePay_WC_Money::normalize_minor( '5000.00' ) );
		$this->assertSame( '', TeePay_WC_Money::normalize_minor( 5000.0 ) );
		$this->assertSame( '', TeePay_WC_Money::normalize_minor( null ) );
	}

	/**
	 * @dataProvider valid_phones
	 */
	public function test_valid_tanzanian_numbers( $input, $expected ) {
		$this->assertSame( $expected, TeePay_WC_Phone::normalize_tz( $input ) );
	}

	public function valid_phones() {
		return array(
			'local 07'         => array( '0712345678', '+255712345678' ),
			'local 06'         => array( '0687 654 321', '+255687654321' ),
			'international +'  => array( '+255 712 345 678', '+255712345678' ),
			'international 00' => array( '00255712345678', '+255712345678' ),
			'no prefix'        => array( '255-754-123-456', '+255754123456' ),
			'nine digits'      => array( '(712) 345-678', '+255712345678' ),
		);
	}

	/**
	 * @dataProvider invalid_phones
	 */
	public function test_invalid_numbers( $input ) {
		$this->assertNull( TeePay_WC_Phone::normalize_tz( $input ) );
	}

	public function invalid_phones() {
		return array(
			'empty'          => array( '' ),
			'landline 022'   => array( '0222123456' ),
			'kenya'          => array( '+254712345678' ),
			'too short'      => array( '071234567' ),
			'too long'       => array( '07123456789' ),
			'letters'        => array( '07123abc78' ),
			'plus only'      => array( '+' ),
			'starts with 8'  => array( '0812345678' ),
		);
	}

	public function test_phone_mask() {
		$this->assertSame( '+255712***678', TeePay_WC_Phone::mask( '+255712345678' ) );
		$this->assertSame( '***', TeePay_WC_Phone::mask( '123' ) );
	}

	public function test_redactor_removes_secrets_and_phone_numbers() {
		$text = 'key tp_live_ab12.secret and tp_test_cd34.secret secret whsec_abcdef Authorization: Bearer tp_live_x.y sig v1=' . str_repeat( 'a', 64 ) . ' phone +255712345678';
		$out  = TeePay_WC_Redactor::redact( $text );
		$this->assertStringNotContainsString( 'ab12.secret', $out );
		$this->assertStringNotContainsString( 'cd34.secret', $out );
		$this->assertStringNotContainsString( 'whsec_abcdef', $out );
		$this->assertStringNotContainsString( str_repeat( 'a', 64 ), $out );
		$this->assertStringNotContainsString( '712345678', $out );

		$array = TeePay_WC_Redactor::redact( array( 'live_api_key' => 'x', 'nested' => array( 'phone' => '+255712345678', 'Authorization' => 'Bearer z' ), 'ok' => 1 ) );
		$this->assertSame( '[redacted]', $array['live_api_key'] );
		$this->assertSame( '+255712***678', $array['nested']['phone'] );
		$this->assertSame( '[redacted]', $array['nested']['Authorization'] );
		$this->assertSame( 1, $array['ok'] );
	}

	public function test_logger_writes_redacted_entries_and_respects_debug_switch() {
		TeePay_WC_Logger::debug( 'calling with tp_live_ab12.secret', array( 'test_api_key' => TP_Fixtures::TEST_KEY ) );
		$this->assertStringNotContainsString( 'secret', str_replace( array( '[redacted]', 'test_api_key' ), '', $this->all_logs() ) );

		TP_State::$logs = array();
		TeePay_WC_Logger::set_enabled( false );
		TeePay_WC_Logger::debug( 'quiet' );
		TeePay_WC_Logger::error( 'loud' );
		$this->assertSame( array( array( 'error', 'loud' ) ), TP_State::$logs );
	}
}
