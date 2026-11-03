/**
 * TeePay payment method for the WooCommerce Cart and Checkout blocks.
 *
 * Plain ES5 using the globals WooCommerce and WordPress provide, so no build step is needed.
 */
( function ( wc, wp ) {
	'use strict';

	if ( ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings || ! wp || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var decode = wp.htmlEntities ? wp.htmlEntities.decodeEntities : function ( value ) {
		return value;
	};
	var settings = wc.wcSettings.getSetting( 'teepay_data', {} );
	var i18n = settings.i18n || {};
	var title = decode( settings.title || 'Mobile money' );

	/**
	 * Normalise a Tanzanian mobile number to +255XXXXXXXXX, or return null.
	 * The server validates again; this only gives quick feedback.
	 *
	 * @param {string} input Raw input.
	 * @return {string|null} Normalised number.
	 */
	function normalizePhone( input ) {
		var value = String( input || '' ).replace( /[\s().-]+/g, '' );
		if ( value.charAt( 0 ) === '+' ) {
			value = value.slice( 1 );
		} else if ( value.slice( 0, 2 ) === '00' ) {
			value = value.slice( 2 );
		}
		if ( ! /^\d+$/.test( value ) ) {
			return null;
		}
		var national = null;
		if ( value.length === 12 && value.slice( 0, 3 ) === '255' ) {
			national = value.slice( 3 );
		} else if ( value.length === 10 && value.charAt( 0 ) === '0' ) {
			national = value.slice( 1 );
		} else if ( value.length === 9 ) {
			national = value;
		}
		return national && /^[67]\d{8}$/.test( national ) ? '+255' + national : null;
	}

	function Description() {
		return settings.description ? el( 'p', null, decode( settings.description ) ) : null;
	}

	function PhoneContent( props ) {
		var state = useState( '' );
		var phone = state[ 0 ];
		var setPhone = state[ 1 ];
		var registration = props.eventRegistration || {};
		var onPaymentSetup = registration.onPaymentSetup || registration.onPaymentProcessing;
		var responseTypes = props.emitResponse.responseTypes;

		useEffect(
			function () {
				if ( ! onPaymentSetup ) {
					return undefined;
				}
				return onPaymentSetup( function () {
					var normalized = normalizePhone( phone );
					if ( ! normalized ) {
						return { type: responseTypes.ERROR, message: i18n.phoneInvalid };
					}
					return {
						type: responseTypes.SUCCESS,
						meta: { paymentMethodData: { teepay_phone: normalized } },
					};
				} );
			},
			[ onPaymentSetup, phone, responseTypes ]
		);

		return el(
			'div',
			{ className: 'wc-block-components-teepay' },
			el( Description ),
			el(
				'div',
				{ className: 'wc-block-components-text-input is-active' },
				el( 'input', {
					id: 'teepay-phone',
					type: 'tel',
					inputMode: 'tel',
					autoComplete: 'tel',
					placeholder: '07XX XXX XXX',
					value: phone,
					'aria-describedby': 'teepay-phone-help',
					onChange: function ( event ) {
						setPhone( event.target.value );
					},
				} ),
				el( 'label', { htmlFor: 'teepay-phone' }, i18n.phoneLabel )
			),
			el( 'p', { id: 'teepay-phone-help', className: 'wc-block-components-checkout-step__description' }, i18n.phoneHelp )
		);
	}

	function Label( props ) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el( PaymentMethodLabel, { text: title } ) : el( 'span', null, title );
	}

	var content = settings.mode === 'push' ? el( PhoneContent ) : el( Description );

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'teepay',
		label: el( Label ),
		ariaLabel: title,
		content: content,
		edit: el( Description ),
		canMakePayment: function () {
			return true;
		},
		supports: { features: settings.supports || [ 'products' ] },
	} );
} )( window.wc, window.wp );
