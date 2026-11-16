/**
 * Tests for Restore default on the Email Templates screen.
 */

require( '../../src/assets/js/admin-email-templates' );

const DEFAULT_SUBJECT = 'Vote in {competition_title}';
const DEFAULT_BODY = '<p>Hi {member_name},</p>\n\n<p>Here is your link.</p>';

function renderCard() {
	document.body.innerHTML = `
		<form method="post">
			<input type="text" id="template-voting_link-subject" value="My subject" />
			<textarea id="template_voting_link_body">My body</textarea>
			<button
				type="button"
				class="button photo-comp-restore-default"
				data-subject-field="template-voting_link-subject"
				data-body-field="template_voting_link_body"
				data-default-subject="${ DEFAULT_SUBJECT }"
				data-default-body="${ DEFAULT_BODY.replace( /</g, '&lt;' ).replace(
					/>/g,
					'&gt;'
				) }"
			>Restore default</button>
		</form>`;

	const form = document.querySelector( 'form' );
	const submissions = [];
	form.addEventListener( 'submit', ( e ) => {
		submissions.push( e );
		e.preventDefault();
	} );

	return {
		button: document.querySelector( 'button' ),
		subject: document.getElementById( 'template-voting_link-subject' ),
		body: document.getElementById( 'template_voting_link_body' ),
		submissions,
	};
}

describe( 'restore default', () => {
	afterEach( () => {
		delete window.tinymce;
		delete window.wp;
	} );

	it( 'fills the subject and body with the default text without saving', () => {
		const { button, subject, body, submissions } = renderCard();

		button.click();

		expect( subject.value ).toBe( DEFAULT_SUBJECT );
		expect( body.value ).toBe( DEFAULT_BODY );
		expect( submissions ).toHaveLength( 0 );
	} );

	it( 'shows the text tab the body as the editor would, without paragraph tags', () => {
		const { button, body } = renderCard();
		window.wp = {
			editor: {
				removep: ( html ) => html.replace( /<\/?p>/g, '' ),
			},
		};

		button.click();

		expect( body.value ).toBe( 'Hi {member_name},\n\nHere is your link.' );
	} );

	it( 'fills the visual editor when it is showing', () => {
		const { button } = renderCard();
		const editor = {
			isHidden: () => false,
			setContent: jest.fn(),
		};
		window.tinymce = {
			get: ( id ) =>
				'template_voting_link_body' === id ? editor : null,
		};

		button.click();

		expect( editor.setContent ).toHaveBeenCalledWith( DEFAULT_BODY );
	} );
} );
