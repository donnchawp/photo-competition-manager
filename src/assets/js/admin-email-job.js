/**
 * Sends an email job batch by batch while its progress notice is on screen.
 *
 * Each request sends one batch and returns the updated notice, which replaces
 * the old one. Sending stops when the returned notice is no longer running.
 *
 * @package PhotoCompetitionManager
 */

( function () {
	var BATCH_PAUSE_MS = 500;

	function sendNextBatch( notice ) {
		var body = new URLSearchParams( {
			action: notice.dataset.action,
			_ajax_nonce: notice.dataset.nonce,
			job_id: notice.dataset.jobId,
		} );

		fetch( notice.dataset.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body,
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				if ( ! response.success ) {
					throw new Error( ( response.data && response.data.message ) || '' );
				}

				var wrapper = document.createElement( 'div' );
				wrapper.innerHTML = response.data.html;
				var next = wrapper.firstElementChild;
				notice.replaceWith( next );

				if ( next.classList.contains( 'photo-comp-email-job' ) ) {
					setTimeout( function () {
						sendNextBatch( next );
					}, BATCH_PAUSE_MS );
				} else if ( window.jQuery ) {
					// Adds the dismiss button to the finished notice.
					window.jQuery( document ).trigger( 'wp-updates-notice-added' );
				}
			} )
			.catch( function ( error ) {
				showError( notice, error.message );
			} );
	}

	function showError( notice, message ) {
		var error = notice.querySelector( '.photo-comp-email-job-error' );
		if ( message ) {
			error.querySelector( 'span' ).textContent = message;
		}
		error.hidden = false;

		error.querySelector( 'button' ).onclick = function () {
			error.hidden = true;
			sendNextBatch( notice );
		};
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.photo-comp-email-job' ).forEach( sendNextBatch );
	} );
} )();
