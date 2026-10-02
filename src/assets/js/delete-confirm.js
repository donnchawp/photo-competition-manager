/**
 * Two-tap confirmation for deleting submissions on the upload page.
 *
 * The first tap arms the button and changes its label; a second tap within
 * a few seconds submits the form. This avoids window.confirm(), which some
 * in-app browsers (email and chat app WebViews) suppress by returning false
 * without showing a dialog, so the button silently does nothing.
 *
 * @package PhotoCompetitionManager
 */

( function () {
	var ARM_TIMEOUT_MS = 4000;
	var BUTTON_SELECTOR = '.photo-comp-delete-button';

	// Only one button is armed (or busy) at a time.
	var armed = null;
	var armedLabel = '';
	var timer;

	function disarm() {
		if ( ! armed ) {
			return;
		}
		clearTimeout( timer );
		armed.disabled = false;
		armed.classList.remove( 'is-armed' );
		armed.textContent = armedLabel;
		armed = null;
	}

	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( BUTTON_SELECTOR );
		if ( ! button || button === armed ) {
			return;
		}

		e.preventDefault();
		disarm();

		armed = button;
		armedLabel = button.textContent;
		button.textContent = button.dataset.confirmLabel;
		button.classList.add( 'is-armed' );
		timer = setTimeout( disarm, ARM_TIMEOUT_MS );
	} );

	document.addEventListener( 'submit', function ( e ) {
		if ( ! armed || ! e.target.contains( armed ) ) {
			return;
		}

		// Show progress and block repeat taps while the request is in flight.
		clearTimeout( timer );
		armed.disabled = true;
		armed.textContent = armed.dataset.busyLabel;
	} );

	// Going back to a page restored from the back/forward cache would
	// otherwise leave the button disabled on "Deleting…".
	window.addEventListener( 'pageshow', function ( e ) {
		if ( e.persisted ) {
			disarm();
		}
	} );
} )();
