/**
 * Order received page: polls the store (not TeePay) until the payment is confirmed.
 * The store confirms with an authenticated TeePay status read; this script only displays it.
 */
( function () {
	'use strict';

	var config = window.teepayOrderStatus;
	var box = document.querySelector( '[data-teepay-status]' );
	if ( ! config || ! box || ! window.fetch ) {
		return;
	}

	var attempts = 0;
	var maxAttempts = 60;

	function schedule() {
		attempts += 1;
		if ( attempts > maxAttempts ) {
			box.textContent = config.timeout;
			return;
		}
		window.setTimeout( check, attempts < 12 ? 5000 : 10000 );
	}

	function check() {
		var url = config.url + ( config.url.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'order_id=' + encodeURIComponent( config.orderId ) + '&key=' + encodeURIComponent( config.orderKey );

		window.fetch( url, { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( data && data.state === 'paid' ) {
					box.textContent = config.paid;
					window.location.reload();
				} else if ( data && data.state === 'failed' ) {
					box.textContent = config.failed;
					window.location.reload();
				} else {
					schedule();
				}
			} )
			[ 'catch' ]( schedule );
	}

	schedule();
} )();
