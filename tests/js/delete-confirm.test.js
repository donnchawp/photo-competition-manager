/**
 * Tests for the two-tap delete confirmation on the upload page.
 */

require( '../../src/assets/js/delete-confirm' );

function renderForm() {
	document.body.innerHTML = `
		<form method="post" class="photo-comp-delete-form">
			<input type="hidden" name="photo_competition_delete" value="1" />
			<button
				type="submit"
				class="photo-comp-delete-button"
				data-confirm-label="Tap again to delete"
				data-busy-label="Deleting…"
			>Delete</button>
		</form>`;

	const form = document.querySelector( 'form' );
	const button = document.querySelector( 'button' );
	const submissions = [];

	// jsdom cannot navigate; record the submission and stop it here.
	form.addEventListener( 'submit', ( e ) => {
		submissions.push( e );
		e.preventDefault();
	} );

	return { form, button, submissions };
}

describe( 'delete confirmation', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'does not submit on the first tap and asks for a second', () => {
		const { button, submissions } = renderForm();

		button.click();

		expect( submissions ).toHaveLength( 0 );
		expect( button.textContent ).toBe( 'Tap again to delete' );
		expect( button.classList.contains( 'is-armed' ) ).toBe( true );
	} );

	it( 'submits on the second tap and shows progress', () => {
		const { button, submissions } = renderForm();

		button.click();
		button.click();

		expect( submissions ).toHaveLength( 1 );
		expect( button.disabled ).toBe( true );
		expect( button.textContent ).toBe( 'Deleting…' );
	} );

	it( 'disarms after a few seconds without a second tap', () => {
		const { button, submissions } = renderForm();

		button.click();
		jest.advanceTimersByTime( 5000 );

		expect( button.textContent ).toBe( 'Delete' );
		expect( button.classList.contains( 'is-armed' ) ).toBe( false );

		button.click();
		expect( submissions ).toHaveLength( 0 );
	} );

	it( 'restores buttons when the page comes back from the back/forward cache', () => {
		const { button } = renderForm();

		button.click();
		button.click();

		const event = new Event( 'pageshow' );
		event.persisted = true;
		window.dispatchEvent( event );

		expect( button.disabled ).toBe( false );
		expect( button.textContent ).toBe( 'Delete' );
		expect( button.classList.contains( 'is-armed' ) ).toBe( false );
	} );
} );
