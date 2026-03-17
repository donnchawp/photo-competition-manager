/**
 * Confirmation dialogs for destructive actions on the competitions page.
 *
 * Intercepts clicks on elements with data-confirm attributes and shows
 * a native confirm() dialog before proceeding.
 *
 * @package PhotoCompetitionManager
 */

document.addEventListener( 'DOMContentLoaded', function () {
	document.addEventListener( 'click', function ( e ) {
		if (
			e.target.classList.contains( 'photo-comp-delete' ) ||
			e.target.classList.contains( 'photo-comp-reset-votes' ) ||
			e.target.classList.contains( 'photo-comp-regenerate-hash' )
		) {
			var confirmMessage = e.target.getAttribute( 'data-confirm' );
			if ( confirmMessage && ! confirm( confirmMessage ) ) {
				e.preventDefault();
				return false;
			}
		}
	} );
} );
