/**
 * Tests for saving past competitions choices on the member page as they change.
 */

require( '../../src/assets/js/member-visibility' );

function renderForm() {
	document.body.innerHTML = `
		<form class="photo-comp-visibility-form" data-endpoint="https://example.org/wp-json/photo-comp/v1/member-visibility" data-key="7.123.abc" data-saved="Saved" data-failed="Couldn't save">
			<fieldset class="photo-comp-visibility-choice">
				<input type="radio" name="visibility_all" value="show" checked />
				<input type="radio" name="visibility_all" value="hide" />
				<span class="photo-comp-visibility-status"></span>
			</fieldset>
			<p class="photo-comp-visibility-off-notice" hidden>Nothing is public.</p>
			<fieldset class="photo-comp-visibility-choice">
				<input type="radio" name="visibility[42]" value="show" checked />
				<input type="radio" name="visibility[42]" value="hide" />
				<span class="photo-comp-visibility-status"></span>
			</fieldset>
		</form>`;
}

function choose( name, value ) {
	const radio = document.querySelector(
		`input[name="${ name }"][value="${ value }"]`
	);
	radio.checked = true;
	radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	return radio.closest( 'fieldset' );
}

// Let the fetch promise chain settle.
const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

describe( 'member page past competitions choices', () => {
	afterEach( () => {
		delete global.fetch;
	} );

	it( 'saves a single image choice straight away and says so', async () => {
		renderForm();
		global.fetch = jest.fn( () => Promise.resolve( { ok: true } ) );

		const fieldset = choose( 'visibility[42]', 'hide' );
		await settle();

		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
		const [ url, options ] = global.fetch.mock.calls[ 0 ];
		expect( url ).toBe(
			'https://example.org/wp-json/photo-comp/v1/member-visibility'
		);
		expect( Object.fromEntries( options.body ) ).toEqual( {
			key: '7.123.abc',
			entry: '42',
			shown: 'false',
		} );
		expect(
			fieldset.querySelector( '.photo-comp-visibility-status' )
				.textContent
		).toBe( 'Saved' );
	} );

	it( 'puts the choice back when it fails to save', async () => {
		renderForm();
		global.fetch = jest.fn( () => Promise.resolve( { ok: false } ) );

		const fieldset = choose( 'visibility[42]', 'hide' );
		await settle();

		expect(
			document.querySelector(
				'input[name="visibility[42]"][value="show"]'
			).checked
		).toBe( true );
		expect(
			fieldset.querySelector( '.photo-comp-visibility-status' )
				.textContent
		).toBe( "Couldn't save" );
	} );

	it( 'saves hiding all images as entry 0 and says nothing is public', async () => {
		renderForm();
		global.fetch = jest.fn( () => Promise.resolve( { ok: true } ) );

		choose( 'visibility_all', 'hide' );
		await settle();

		expect(
			Object.fromEntries( global.fetch.mock.calls[ 0 ][ 1 ].body )
		).toEqual( { key: '7.123.abc', entry: '0', shown: 'false' } );
		expect(
			document.querySelector( '.photo-comp-visibility-off-notice' ).hidden
		).toBe( false );
	} );
} );
