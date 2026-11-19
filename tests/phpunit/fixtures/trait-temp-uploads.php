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
	 * Temp files written by the test, removed by remove_tmp_files().
	 *
	 * @var string[]
	 */
	private $tmp_files = array();

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

	/**
	 * A CSV file in the shape of a $_FILES entry, removed by remove_tmp_files().
	 *
	 * @param string $content CSV content.
	 * @param string $name    Filename the browser sent.
	 * @return array<string, mixed>
	 */
	private function csv_upload( string $content, string $name = 'members.csv' ): array {
		$tmp               = wp_tempnam( $name );
		$this->tmp_files[] = $tmp;
		file_put_contents( $tmp, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		return array(
			'name'     => $name,
			'type'     => 'text/csv',
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $content ),
		);
	}

	/**
	 * A $_FILES entry for an upload PHP didn't receive, such as a blank file
	 * input (UPLOAD_ERR_NO_FILE) or a file over its size limit.
	 *
	 * @param int    $error An UPLOAD_ERR_* code.
	 * @param string $name  Filename the browser sent, if any.
	 * @return array<string, mixed>
	 */
	private function failed_upload( int $error, string $name = '' ): array {
		return array(
			'name'     => $name,
			'type'     => '',
			'tmp_name' => '',
			'error'    => $error,
			'size'     => 0,
		);
	}

	/**
	 * Remove the temp files this test wrote.
	 */
	private function remove_tmp_files(): void {
		foreach ( $this->tmp_files as $tmp_file ) {
			wp_delete_file( $tmp_file );
		}
	}
}
