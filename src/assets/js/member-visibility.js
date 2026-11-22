/**
 * Save a member's past competitions choices on their entries page as each
 * Show / Hide radio changes, without reloading the page.
 *
 * The page works without this: the radios sit in a form with a Save button,
 * which this script hides. If a save fails (a site may block the REST API for
 * visitors), the script steps aside and the Save button comes back, with the
 * choice kept for it. An expired link puts the choice back and says so.
 *
 * @package
 */

( function () {
	const FORM_SELECTOR = '.photo-comp-visibility-form';
	const SAVED_FOR_MS = 3000;
	const JS_CLASS = 'photo-comp-visibility-js';
	const EXPIRED = 'invalid_member_key';

	document.documentElement.classList.add( JS_CLASS );

	// The entry a radio is for: 0 for all images, or the entry's ID.
	function entryOf( name ) {
		const match = /^visibility\[(\d+)\]$/.exec( name );
		return match ? match[ 1 ] : '0';
	}

	function check( fieldset, value ) {
		const radio = fieldset.querySelector( 'input[value="' + value + '"]' );
		if ( radio ) {
			radio.checked = true;
		}
	}

	function showNotice( form, allHidden ) {
		const notice = form.querySelector(
			'.photo-comp-visibility-off-notice'
		);
		if ( notice ) {
			notice.hidden = ! allHidden;
		}
	}

	document.addEventListener( 'change', function ( e ) {
		const radio = e.target;
		const form = radio.closest( FORM_SELECTOR );
		if (
			! form ||
			'radio' !== radio.type ||
			! document.documentElement.classList.contains( JS_CLASS )
		) {
			return;
		}

		const fieldset = radio.closest( 'fieldset' );
		const status = fieldset.querySelector(
			'.photo-comp-visibility-status'
		);
		const previous = 'show' === radio.value ? 'hide' : 'show';
		const isAll = 'visibility_all' === radio.name;
		const body = new URLSearchParams();

		body.append( 'key', form.dataset.key );
		body.append( 'entry', entryOf( radio.name ) );
		body.append( 'shown', 'show' === radio.value ? 'true' : 'false' );
		status.textContent = '';

		fetch( form.dataset.endpoint, {
			method: 'POST',
			credentials: 'omit',
			body,
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					return response
						.json()
						.catch( function () {
							return {};
						} )
						.then( function ( error ) {
							throw new Error( error && error.code );
						} );
				}
				status.textContent = form.dataset.saved;
				setTimeout( function () {
					if ( form.dataset.saved === status.textContent ) {
						status.textContent = '';
					}
				}, SAVED_FOR_MS );
				if ( isAll ) {
					showNotice( form, 'hide' === radio.value );
				}
			} )
			.catch( function ( error ) {
				if ( EXPIRED === error.message ) {
					check( fieldset, previous );
					status.textContent = form.dataset.expired;
					return;
				}
				// Leave the choice for the Save button, and stop saving here.
				document.documentElement.classList.remove( JS_CLASS );
				status.textContent = form.dataset.failed;
			} );
	} );
} )();
