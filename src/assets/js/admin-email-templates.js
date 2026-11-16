/**
 * Restore default on the Email Templates screen.
 *
 * Fills a card's subject and body with the default text in place, so the
 * admin can read it. Nothing is saved until the form is.
 *
 * @package PhotoCompetitionManager
 */

( function () {
	document.addEventListener( 'click', function ( e ) {
		const button = e.target.closest( '.photo-comp-restore-default' );
		if ( ! button ) {
			return;
		}

		const subject = document.getElementById( button.dataset.subjectField );
		const bodyId = button.dataset.bodyField;
		const body = button.dataset.defaultBody;
		const editor = window.tinymce && window.tinymce.get( bodyId );

		if ( subject ) {
			subject.value = button.dataset.defaultSubject;
		}

		if ( editor && ! editor.isHidden() ) {
			editor.setContent( body );
			return;
		}

		const textarea = document.getElementById( bodyId );
		if ( textarea ) {
			// The text tab shows paragraphs as blank lines.
			const removep =
				window.wp && window.wp.editor && window.wp.editor.removep;
			textarea.value = removep ? removep( body ) : body;
		}
	} );
} )();
