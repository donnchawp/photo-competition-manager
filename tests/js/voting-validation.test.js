/**
 * Tests for the vote counter and missing-votes message on the voting page.
 */

const { setLocaleData, resetLocaleData } = require( '@wordpress/i18n' );

// Load the script as if the page were still loading, so each test can start it on its own markup.
Object.defineProperty( document, 'readyState', { configurable: true, get: () => 'loading' } );
require( '../../assets/src/js/voting-validation' );

/**
 * Render a voting form with the given number of images and start the script on it.
 *
 * @param {number} images How many images the form has.
 */
function renderForm( images ) {
	const items = Array.from( { length: images }, ( _, i ) => `
		<div class="voting-image-item" data-image-id="${ i + 1 }">
			<input type="radio" name="votes[${ i + 1 }]" value="5" />
		</div>` ).join( '' );
	document.body.innerHTML = `
		<form class="voting-form">
			${ items }
			<div class="voting-submit"><button type="submit">Submit</button></div>
		</form>`;
	document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
}

/**
 * Vote for the first few images.
 *
 * @param {number} count How many images to vote for.
 */
function voteFor( count ) {
	document.querySelectorAll( '.voting-form input[type="radio"]' ).forEach( ( radio, i ) => {
		if ( i < count ) {
			radio.checked = true;
			radio.dispatchEvent( new Event( 'change' ) );
		}
	} );
}

/**
 * Submit the form; the button is disabled until every image has a vote, so send the event directly.
 */
function submit() {
	document.querySelector( '.voting-form' ).dispatchEvent( new Event( 'submit', { bubbles: true, cancelable: true } ) );
}

const counter = () => document.querySelector( '.vote-counter' );
const errorLines = () => Array.from( document.querySelectorAll( '.voting-validation-error p' ), ( p ) => p.textContent );

describe( 'voting validation', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		window.alert = jest.fn();
		Element.prototype.scrollIntoView = jest.fn();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'counts the votes so far', () => {
		renderForm( 3 );
		voteFor( 1 );

		expect( counter().textContent ).toBe( 'You have voted for 1 of 3 images. Please vote for all images before submitting.' );
		expect( counter().querySelector( 'p' ).className ).toBe( 'vote-counter-incomplete' );
	} );

	it( 'says when every image has a vote', () => {
		renderForm( 2 );
		voteFor( 2 );

		expect( counter().textContent ).toBe( '✓ All 2 images have been voted for.' );
		expect( counter().querySelector( 'p' ).className ).toBe( 'vote-counter-complete' );
	} );

	it( 'says how many votes are missing, in the plural', () => {
		renderForm( 3 );
		voteFor( 1 );
		submit();

		expect( errorLines() ).toEqual( [
			'⚠️ Please vote for all images before submitting.',
			'You need to vote for 2 more images out of 3 total. Images missing votes are highlighted with a red border below.',
		] );
		expect( window.alert ).toHaveBeenLastCalledWith( 'Please vote for all 3 images before submitting.\n\nYou still need to vote for 2 more images.' );
	} );

	it( 'says one vote is missing, in the singular', () => {
		renderForm( 3 );
		voteFor( 2 );
		submit();

		expect( errorLines()[ 1 ] ).toBe( 'You need to vote for 1 more image out of 3 total. Images missing votes are highlighted with a red border below.' );
		expect( window.alert ).toHaveBeenLastCalledWith( 'Please vote for all 3 images before submitting.\n\nYou still need to vote for 1 more image.' );
	} );

	describe( 'on a site in another language', () => {
		beforeEach( () => {
			setLocaleData(
				{
					'': { domain: 'photo-competition-manager', plural_forms: 'nplurals=2; plural=n != 1;' },
					'You have voted for %1$d of %2$d image. Please vote for all images before submitting.': [
						'Vótáil tú do %1$d as %2$d íomhá.',
						'Vótáil tú do %1$d as %2$d íomhánna.',
					],
					'Please vote for all images before submitting.': [ '<b>Vótáil</b> do gach íomhá.' ],
					'You need to vote for %1$d more image out of %2$d total. Images missing votes are highlighted with a red border below.': [
						'%1$d íomhá eile, as %2$d.',
						'%1$d íomhánna eile, as %2$d.',
					],
				},
				'photo-competition-manager'
			);
		} );

		afterEach( () => {
			resetLocaleData( undefined, 'photo-competition-manager' );
		} );

		it( 'shows the translated counter', () => {
			renderForm( 3 );

			expect( counter().textContent ).toBe( 'Vótáil tú do 0 as 3 íomhánna.' );
		} );

		it( 'picks the translated singular and plural for the missing votes', () => {
			renderForm( 3 );
			voteFor( 2 );
			submit();
			expect( errorLines()[ 1 ] ).toBe( '1 íomhá eile, as 3.' );

			renderForm( 3 );
			voteFor( 1 );
			submit();
			expect( errorLines()[ 1 ] ).toBe( '2 íomhánna eile, as 3.' );
		} );

		it( 'shows markup in a translation as written', () => {
			renderForm( 2 );
			submit();

			expect( errorLines()[ 0 ] ).toBe( '⚠️ <b>Vótáil</b> do gach íomhá.' );
			expect( document.querySelector( '.voting-validation-error b' ) ).toBeNull();
		} );
	} );
} );
