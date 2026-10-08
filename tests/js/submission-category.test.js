/**
 * Tests for moving entries between categories on the upload page.
 */

const { setLocaleData, resetLocaleData } = require( '@wordpress/i18n' );

require( '../../assets/src/js/submission-category' );

/**
 * Render the member's entries, each with a category drop-down, and start the script on them.
 *
 * @param {string[]} entryCategories The category each entry is in.
 * @param {Object}   categories      The competition's categories, keyed by slug.
 */
function renderPage( entryCategories, categories ) {
	window.photoCompCategoryUpdate = { token: 'abc', apiUrl: 'https://example.com/wp-json/', nonce: 'n', categories };
	const options = Object.keys( categories ).map( ( slug ) => `<option value="${ slug }">${ slug }</option>` ).join( '' );
	const selects = entryCategories.map( ( category, i ) => `
		<select class="submission-category-select" data-submission-id="${ i + 1 }" data-original-category="${ category }">${ options }</select>` ).join( '' );
	document.body.innerHTML = `
		<div id="category-change-status" class="category-change-status" style="display: none;"></div>
		${ selects }
		<button type="button" id="save-category-changes" style="display: none;">
			Save Category Changes
		</button>`;
	document.querySelectorAll( '.submission-category-select' ).forEach( ( select, i ) => {
		select.value = entryCategories[ i ];
	} );
	document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
}

/**
 * Move an entry to another category.
 *
 * @param {number} index    Which entry, counting from 0.
 * @param {string} category The category to move it to.
 */
function move( index, category ) {
	const select = document.querySelectorAll( '.submission-category-select' )[ index ];
	select.value = category;
	select.dispatchEvent( new Event( 'change' ) );
}

const status = () => document.getElementById( 'category-change-status' );
const saveButton = () => document.getElementById( 'save-category-changes' );
const threeCategories = {
	colour: { label: 'Colour', quota: 1 },
	mono: { label: 'Mono', quota: 2 },
	nature: { label: 'Nature', quota: 2 },
};

describe( 'category changes', () => {
	beforeEach( () => {
		window.alert = jest.fn();
	} );

	it( 'says one change is pending, in the singular', () => {
		renderPage( [ 'colour', 'mono' ], threeCategories );
		move( 0, 'nature' );

		expect( status().textContent ).toBe( '1 category change pending. Click "Save Category Changes" to apply.' );
	} );

	it( 'says how many changes are pending, in the plural', () => {
		renderPage( [ 'colour', 'mono' ], threeCategories );
		move( 0, 'nature' );
		move( 1, 'nature' );

		expect( status().textContent ).toBe( '2 category changes pending. Click "Save Category Changes" to apply.' );
	} );

	it( 'lists the categories over quota', () => {
		renderPage( [ 'mono', 'nature' ], threeCategories );
		move( 1, 'colour' );
		move( 0, 'colour' );

		expect( status().querySelector( 'strong' ).textContent ).toBe( 'Warning: Category quotas exceeded' );
		expect( Array.from( status().querySelectorAll( 'li' ), ( li ) => li.textContent ) ).toEqual( [ 'Colour: 2/1 (over quota)' ] );
		expect( status().querySelector( 'p' ).textContent ).toBe( 'Please adjust categories before saving.' );
		expect( saveButton().disabled ).toBe( true );
	} );

	it( 'shows a category label with HTML in it as written', () => {
		renderPage( [ 'mono', 'nature' ], { ...threeCategories, colour: { label: '<b>Colour</b>', quota: 1 } } );
		move( 1, 'colour' );
		move( 0, 'colour' );

		expect( status().querySelector( 'li' ).textContent ).toBe( '<b>Colour</b>: 2/1 (over quota)' );
		expect( status().querySelector( 'b' ) ).toBeNull();
	} );

	describe( 'saving', () => {
		afterEach( () => {
			delete global.fetch;
		} );

		it( 'says the changes were saved and puts the button back', async () => {
			global.fetch = jest.fn().mockResolvedValue( { ok: true, json: async () => ( {} ) } );
			renderPage( [ 'colour', 'mono' ], threeCategories );
			move( 0, 'nature' );

			saveButton().click();
			expect( saveButton().textContent ).toBe( 'Saving...' );
			expect( status().textContent ).toBe( 'Saving changes...' );
			await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

			expect( status().textContent ).toBe( '✓ Successfully updated 1 category assignment.' );
			expect( saveButton().textContent ).toBe( 'Save Category Changes' );
		} );

		it( 'says why nothing changed', async () => {
			global.fetch = jest.fn().mockResolvedValue( { ok: false, json: async () => ( { message: 'Too many.' } ) } );
			renderPage( [ 'colour', 'mono' ], threeCategories );
			move( 0, 'nature' );

			saveButton().click();
			await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

			expect( status().textContent ).toBe( 'No categories were changed: Too many.' );
		} );
	} );

	describe( 'on a site in another language', () => {
		beforeEach( () => {
			setLocaleData(
				{
					'': { domain: 'photo-competition-manager', plural_forms: 'nplurals=2; plural=n != 1;' },
					'%1$d category change pending. Click "%2$s" to apply.': [
						'%1$d athrú amháin. Brúigh "%2$s".',
						'%1$d athruithe. Brúigh "%2$s".',
					],
					'Please adjust categories before saving.': [ '<b>Athraigh</b> na catagóirí.' ],
				},
				'photo-competition-manager'
			);
		} );

		afterEach( () => {
			resetLocaleData( undefined, 'photo-competition-manager' );
		} );

		it( 'picks the translated singular and plural, naming the button as the page shows it', () => {
			renderPage( [ 'colour', 'mono' ], threeCategories );
			move( 0, 'nature' );
			expect( status().textContent ).toBe( '1 athrú amháin. Brúigh "Save Category Changes".' );

			move( 1, 'nature' );
			expect( status().textContent ).toBe( '2 athruithe. Brúigh "Save Category Changes".' );
		} );

		it( 'shows markup in a translation as written', () => {
			renderPage( [ 'mono', 'nature' ], threeCategories );
			move( 1, 'colour' );
			move( 0, 'colour' );

			expect( status().querySelector( 'p' ).textContent ).toBe( '<b>Athraigh</b> na catagóirí.' );
			expect( status().querySelector( 'b' ) ).toBeNull();
		} );
	} );
} );
