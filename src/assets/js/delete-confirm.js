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

	function disarm( button ) {
		clearTimeout( button.photoCompDisarmTimer );
		button.classList.remove( 'is-armed' );
		if ( button.dataset.originalLabel ) {
			button.textContent = button.dataset.originalLabel;
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( BUTTON_SELECTOR );
		if ( ! button || button.classList.contains( 'is-armed' ) ) {
			return;
		}

		e.preventDefault();

		button.dataset.originalLabel = button.textContent;
		button.textContent = button.dataset.confirmLabel;
		button.classList.add( 'is-armed' );
		button.photoCompDisarmTimer = setTimeout( function () {
			disarm( button );
		}, ARM_TIMEOUT_MS );
	} );

	document.addEventListener( 'submit', function ( e ) {
		var button = e.target.querySelector( BUTTON_SELECTOR );
		if ( ! button ) {
			return;
		}

		// Show progress and block repeat taps while the request is in flight.
		clearTimeout( button.photoCompDisarmTimer );
		button.disabled = true;
		button.textContent = button.dataset.busyLabel;
	} );

	// Going back to a page restored from the back/forward cache would
	// otherwise leave the buttons disabled on "Deleting…".
	window.addEventListener( 'pageshow', function ( e ) {
		if ( ! e.persisted ) {
			return;
		}
		document.querySelectorAll( BUTTON_SELECTOR ).forEach( function ( button ) {
			button.disabled = false;
			disarm( button );
		} );
	} );
} )();
