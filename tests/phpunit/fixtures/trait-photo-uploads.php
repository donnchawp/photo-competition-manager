<?php
/**
 * Uploaded photos for tests that go through the Entries module with real files.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

/**
 * Make JPEGs in the shape of a $_FILES entry, and remove them afterwards.
 */
trait Photo_Uploads {

	/**
	 * Temporary source images, removed by remove_tmp_files().
	 *
	 * @var string[]
	 */
	private $tmp_files = array();

	/**
	 * A solid-colour JPEG in the shape of a $_FILES entry.
	 *
	 * @param int[] $rgb    Fill colour, so each upload is a different picture.
	 * @param int   $width  Width in pixels.
	 * @param int   $height Height in pixels.
	 * @return array<string, mixed>
	 */
	private function photo( array $rgb, int $width = 64, int $height = 48 ): array {
		$image = imagecreatetruecolor( $width, $height );
		imagefill( $image, 0, 0, imagecolorallocate( $image, $rgb[0], $rgb[1], $rgb[2] ) );

		// wp_tempnam() creates an empty .tmp file, and the image editor needs a .jpg extension.
		$tmp_name          = wp_tempnam( 'photo.jpg' );
		$tmp_file          = $tmp_name . '.jpg';
		$this->tmp_files[] = $tmp_name;
		$this->tmp_files[] = $tmp_file;
		imagejpeg( $image, $tmp_file, 90 );

		return $this->upload_array( $tmp_file );
	}

	/**
	 * A file in the shape of a $_FILES entry.
	 *
	 * @param string $tmp_file Path of the uploaded file.
	 * @return array<string, mixed>
	 */
	private function upload_array( string $tmp_file ): array {
		return array(
			'name'     => 'photo.jpg',
			'tmp_name' => $tmp_file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $tmp_file ),
		);
	}

	/**
	 * Remove the temporary source images.
	 *
	 * @return void
	 */
	private function remove_tmp_files(): void {
		foreach ( $this->tmp_files as $tmp_file ) {
			wp_delete_file( $tmp_file );
		}
	}
}
