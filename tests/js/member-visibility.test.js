/**
 * Tests for saving past competitions choices on the member page as they change.
 */

require( '../../src/assets/js/member-visibility' );

function renderForm() {
	document.body.innerHTML = `
		<form class="photo-comp-visibility-form" data-endpoint="https://example.org/wp-json/photo-comp/v1/member-visibility" data-key="7.123.abc" data-saved="Saved" data-failed="Not saved yet. Press Save." data-expired="This link has expired.">
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
			<p class="photo-comp-visibility-save"><button type="submit">Save</button></p>
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
		document.documentElement.classList.add( 'photo-comp-visibility-js' );
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

	it( 'clears "Saved" after a few seconds', async () => {
		jest.useFakeTimers();
		renderForm();
		global.fetch = jest.fn( () => Promise.resolve( { ok: true } ) );

		const fieldset = choose( 'visibility[42]', 'hide' );
		const status = fieldset.querySelector(
			'.photo-comp-visibility-status'
		);
		// Let the fetch promise chain settle without real timers.
		for ( let i = 0; i < 5; i++ ) {
			await Promise.resolve();
		}
		expect( status.textContent ).toBe( 'Saved' );

		jest.advanceTimersByTime( 5000 );
		jest.useRealTimers();

		expect( status.textContent ).toBe( '' );
	} );

	it( 'falls back to the Save button when saving fails, keeping the choice for it', async () => {
		renderForm();
		global.fetch = jest.fn( () =>
			Promise.resolve( {
				ok: false,
				json: () => Promise.resolve( { code: 'rest_not_logged_in' } ),
			} )
		);

		const fieldset = choose( 'visibility[42]', 'hide' );
		await settle();
		choose( 'visibility_all', 'hide' );
		await settle();

		expect(
			document.documentElement.classList.contains(
				'photo-comp-visibility-js'
			)
		).toBe( false );
		expect(
			document.querySelector(
				'input[name="visibility[42]"][value="hide"]'
			).checked
		).toBe( true );
		expect(
			fieldset.querySelector( '.photo-comp-visibility-status' )
				.textContent
		).toBe( 'Not saved yet. Press Save.' );
		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'says when the link has expired and puts the choice back', async () => {
		renderForm();
		global.fetch = jest.fn( () =>
			Promise.resolve( {
				ok: false,
				json: () => Promise.resolve( { code: 'invalid_member_key' } ),
			} )
		);

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
		).toBe( 'This link has expired.' );
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
