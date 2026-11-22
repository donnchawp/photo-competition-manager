/**
 * Save a member's past competitions choices on their entries page as each
 * Show / Hide radio changes, without reloading the page.
 *
 * The page works without this: the radios sit in a form with a Save button,
 * which this script hides. A choice that fails to save is put back.
 *
 * @package
 */

( function () {
	const FORM_SELECTOR = '.photo-comp-visibility-form';
	const SAVED_FOR_MS = 3000;

	document.documentElement.classList.add( 'photo-comp-visibility-js' );

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
		if ( ! form || 'radio' !== radio.type ) {
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
					throw new Error( 'Not saved' );
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
			.catch( function () {
				check( fieldset, previous );
				status.textContent = form.dataset.failed;
			} );
	} );
} )();
