<?php
/**
 * Let upload code accept test temp files as HTTP uploads.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

/**
 * Upload code refuses a file PHP didn't receive in an HTTP POST. Tests write
 * plain temp files, so they say when to treat those as uploads.
 */
trait Temp_Uploads {

	/**
	 * Treat temp files as HTTP uploads until the test ends.
	 */
	private function accept_temp_files_as_uploads(): void {
		add_filter( 'photo_competition_manager_is_uploaded_file', '__return_true' );
	}

	/**
	 * Go back to refusing temp files, as production does.
	 */
	private function refuse_temp_files_as_uploads(): void {
		remove_filter( 'photo_competition_manager_is_uploaded_file', '__return_true' );
	}
}
