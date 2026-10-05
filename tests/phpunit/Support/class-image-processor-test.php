<?php
/**
 * Tests for Image_Processor.
 *
 * @package PhotoCompetitionManager\Tests\Support
 */

namespace PhotoCompetitionManager\Tests\Support;

use PhotoCompetitionManager\Support\Image_Processor;
use WP_UnitTestCase;

class Image_Processor_Test extends WP_UnitTestCase {

	/**
	 * Image processor instance.
	 *
	 * @var Image_Processor
	 */
	private $processor;

	/**
	 * Folders process() wrote to, removed in tearDown.
	 *
	 * @var string[]
	 */
	private $directories = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->processor = new Image_Processor();
	}

	/**
	 * Delete the originals and thumbnails process() leaves under uploads, so the
	 * next run gets the same filenames instead of suffixed ones.
	 */
	public function tearDown(): void {
		$this->remove_added_uploads();

		foreach ( $this->directories as $directory ) {
			// rmdir() empties the folder, and delete_folders() removes it.
			$this->rmdir( $directory );
			$this->delete_folders( $directory );
		}

		parent::tearDown();
	}

	public function test_validate_rejects_missing_file(): void {
		$file = array(
			'name'     => 'test.jpg',
			'tmp_name' => '',
			'error'    => UPLOAD_ERR_OK,
			'size'     => 1024,
		);

		$result = $this->processor->validate( $file, array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_upload', $result->get_error_code() );
	}

	public function test_validate_rejects_upload_error(): void {
		$file = array(
			'name'     => 'test.jpg',
			'tmp_name' => '/tmp/test.jpg',
			'error'    => UPLOAD_ERR_INI_SIZE,
			'size'     => 1024,
		);

		$result = $this->processor->validate( $file, array() );

		$this->assertWPError( $result );
		$this->assertEquals( 'upload_error', $result->get_error_code() );
	}

	public function test_validate_rejects_oversized_file(): void {
		$tmp_file = $this->create_test_image();

		$file = array(
			'name'     => 'test.jpg',
			'tmp_name' => $tmp_file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 10 * 1024 * 1024, // 10 MB.
		);

		$constraints = array(
			'max_file_size_mb' => 5,
			'max_width'        => 1920,
			'max_height'       => 1920,
			'allowed_formats'  => array( 'jpg', 'jpeg' ),
		);

		$result = $this->processor->validate( $file, $constraints );

		$this->assertWPError( $result );
		$this->assertEquals( 'file_too_large', $result->get_error_code() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		unlink( $tmp_file );
	}

	public function test_validate_rejects_invalid_format(): void {
		$tmp_file = $this->create_test_image( 800, 600, 'png' );

		$file = array(
			'name'     => 'test.png',
			'tmp_name' => $tmp_file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 1024,
		);

		$constraints = array(
			'max_file_size_mb' => 5,
			'max_width'        => 1920,
			'max_height'       => 1920,
			'allowed_formats'  => array( 'jpg', 'jpeg' ),
		);

		$result = $this->processor->validate( $file, $constraints );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_format', $result->get_error_code() );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		unlink( $tmp_file );
	}

	public function test_validate_accepts_oversized_dimensions(): void {
		$tmp_file = $this->create_test_image( 2000, 2000 );

		$file = array(
			'name'     => 'test.jpg',
			'tmp_name' => $tmp_file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 1024,
		);

		$constraints = array(
			'max_file_size_mb' => 5,
			'max_width'        => 1920,
			'max_height'       => 1920,
			'allowed_formats'  => array( 'jpg', 'jpeg' ),
		);

		$result = $this->processor->validate( $file, $constraints );

		// Oversized images are now accepted and will be resized during processing.
		$this->assertTrue( $result );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		unlink( $tmp_file );
	}

	public function test_validate_accepts_valid_image(): void {
		$tmp_file = $this->create_test_image( 1920, 1080 );

		$file = array(
			'name'     => 'test.jpg',
			'tmp_name' => $tmp_file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 1024,
		);

		$constraints = array(
			'max_file_size_mb' => 5,
			'max_width'        => 1920,
			'max_height'       => 1920,
			'allowed_formats'  => array( 'jpg', 'jpeg' ),
		);

		$result = $this->processor->validate( $file, $constraints );

		$this->assertTrue( $result );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		unlink( $tmp_file );
	}

	/**
	 * Thumbnail suffix is inserted before the file extension.
	 */
	public function test_get_thumbnail_filename_appends_suffix_before_extension(): void {
		$this->assertEquals( 'photo-thumb.jpg', Image_Processor::get_thumbnail_filename( 'photo.jpg' ) );
	}

	/**
	 * Generated slideshow filenames derive the expected thumbnail name.
	 */
	public function test_get_thumbnail_filename_handles_generated_names(): void {
		$this->assertEquals(
			'john-doe-colour-1-thumb.jpg',
			Image_Processor::get_thumbnail_filename( 'john-doe-colour-1.jpg' )
		);
	}

	/**
	 * Only the final extension is treated as the extension.
	 */
	public function test_get_thumbnail_filename_handles_multiple_dots(): void {
		$this->assertEquals(
			'my.photo.final-thumb.png',
			Image_Processor::get_thumbnail_filename( 'my.photo.final.png' )
		);
	}

	/**
	 * The extension is lowercased, so uppercase names don't collide with the main image.
	 */
	public function test_get_thumbnail_filename_lowercases_extension(): void {
		$this->assertEquals( 'Photo-thumb.jpg', Image_Processor::get_thumbnail_filename( 'Photo.JPG' ) );
		$this->assertEquals( 'photo-thumb.jpeg', Image_Processor::get_thumbnail_filename( 'photo.jpeg' ) );
	}

	/**
	 * Filenames without an extension still receive the suffix.
	 */
	public function test_get_thumbnail_filename_handles_missing_extension(): void {
		$this->assertEquals( 'photo-thumb', Image_Processor::get_thumbnail_filename( 'photo' ) );
	}

	public function test_a_failed_process_leaves_no_original_behind(): void {
		$tmp_file = $this->create_test_image();
		$file     = array(
			'name'     => 'test.jpg',
			'tmp_name' => $tmp_file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $tmp_file ),
		);

		// A file where the folder should be, so the original saves but the resized image can't.
		$not_a_directory = wp_tempnam( 'not-a-directory' );

		// The image editor warns when the save fails, and the test is about what process() returns.
		set_error_handler( '__return_true' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_error_handler
		$result = $this->processor->process( $file, $not_a_directory, 'john-doe-colour.jpg', 'John Doe', array(), array() );
		restore_error_handler();

		$this->assertWPError( $result );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'title'       => 'John Doe',
				)
			)
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		unlink( $tmp_file );
		wp_delete_file( $not_a_directory );
	}

	public function test_process_resizes_oversized_images(): void {
		$tmp_file = $this->create_test_image( 2000, 2000 );

		$file = array(
			'name'     => 'test.jpg',
			'tmp_name' => $tmp_file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => 1024,
		);

		$constraints = array(
			'max_file_size_mb' => 5,
			'max_width'        => 1920,
			'max_height'       => 1920,
			'allowed_formats'  => array( 'jpg', 'jpeg' ),
		);

		$directory = trailingslashit( get_temp_dir() ) . uniqid( 'image-processor-test-' );
		wp_mkdir_p( $directory );
		$this->directories[] = $directory;

		$result = $this->processor->process( $file, $directory, 'john-doe-colour-1.jpg', 'John Doe', array(), $constraints );

		// Should succeed and return array with filename and attachment_id.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'filename', $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertEquals( 'john-doe-colour-1.jpg', $result['filename'] );
		$this->assertIsInt( $result['attachment_id'] );
		$this->assertFileExists( trailingslashit( $directory ) . 'john-doe-colour-1-thumb.jpg' );

		// Verify the processed image exists and is resized.
		$image_path = trailingslashit( $directory ) . $result['filename'];

		$this->assertFileExists( $image_path );

		// Check that the image was resized to max dimensions.
		$image_info = getimagesize( $image_path );
		$this->assertLessThanOrEqual( 1920, $image_info[0] ); // width
		$this->assertLessThanOrEqual( 1920, $image_info[1] ); // height

		// Clean up.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		unlink( $tmp_file );
	}

	/**
	 * Create a test image file.
	 *
	 * @param int    $width  Image width.
	 * @param int    $height Image height.
	 * @param string $format Image format (jpg, png, gif).
	 * @return string Path to created file.
	 */
	private function create_test_image( int $width = 800, int $height = 600, string $format = 'jpg' ): string {
		$image = imagecreatetruecolor( $width, $height );

		// Fill with a color.
		$bg_color = imagecolorallocate( $image, 100, 150, 200 );
		imagefill( $image, 0, 0, $bg_color );

		$extension = strtolower( $format );
		$tmp_file  = tempnam( sys_get_temp_dir(), 'test_image_' ) . '.' . $extension;

		switch ( $extension ) {
			case 'png':
				imagepng( $image, $tmp_file );
				break;
			case 'gif':
				imagegif( $image, $tmp_file );
				break;
			case 'jpg':
			case 'jpeg':
			default:
				imagejpeg( $image, $tmp_file, 90 );
				break;
		}

		imagedestroy( $image );

		return $tmp_file;
	}
}
