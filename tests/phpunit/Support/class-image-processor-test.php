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
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->processor = new Image_Processor();
	}

	public function test_generate_filename_formats_correctly(): void {
		$result = $this->processor->generate_filename( 'john-doe', 'colour', 1 );

		$this->assertEquals( 'john-doe-colour-1.jpg', $result );
	}

	public function test_generate_filename_sanitizes_input(): void {
		$result = $this->processor->generate_filename( 'John Doe!@#', 'Black & White', 2 );

		$this->assertEquals( 'john-doe-black-white-2.jpg', $result );
	}

	public function test_get_upload_directory_creates_path(): void {
		$result = $this->processor->get_upload_directory( 'summer-2024', 'colour' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'path', $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertStringContainsString( 'competitions/summer-2024/colour', $result['path'] );
		$this->assertStringContainsString( 'competitions/summer-2024/colour', $result['url'] );
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
		$tmp_file = $this->create_test_image();

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

	public function test_get_image_url_returns_correct_url(): void {
		$url = $this->processor->get_image_url( 'summer-2024', 'colour', 'john-doe-colour-1.jpg' );

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'competitions/summer-2024/colour/john-doe-colour-1.jpg', $url );
	}

	public function test_get_thumbnail_url_returns_thumb_path(): void {
		$url = $this->processor->get_thumbnail_url( 'summer-2024', 'colour', 'john-doe-colour-1.jpg' );

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'john-doe-colour-1-thumb.jpg', $url );
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

		$result = $this->processor->process( $file, 'summer-2024', 'colour', 'john-doe', 1, $constraints );

		// Should succeed and return array with filename and attachment_id.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'filename', $result );
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertEquals( 'john-doe-colour-1.jpg', $result['filename'] );
		$this->assertIsInt( $result['attachment_id'] );

		// Verify the processed image exists and is resized.
		$upload_dir = $this->processor->get_upload_directory( 'summer-2024', 'colour' );
		$image_path = trailingslashit( $upload_dir['path'] ) . $result['filename'];

		$this->assertFileExists( $image_path );

		// Check that the image was resized to max dimensions.
		$image_info = getimagesize( $image_path );
		$this->assertLessThanOrEqual( 1920, $image_info[0] ); // width
		$this->assertLessThanOrEqual( 1920, $image_info[1] ); // height

		// Clean up.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		unlink( $tmp_file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
		unlink( $image_path );
	}

	/**
	 * Create a test JPEG image.
	 *
	 * @param int $width  Image width.
	 * @param int $height Image height.
	 * @return string Path to created file.
	 */
	private function create_test_image( int $width = 800, int $height = 600 ): string {
		$image = imagecreatetruecolor( $width, $height );

		// Fill with a color.
		$bg_color = imagecolorallocate( $image, 100, 150, 200 );
		imagefill( $image, 0, 0, $bg_color );

		$tmp_file = tempnam( sys_get_temp_dir(), 'test_image_' ) . '.jpg';
		imagejpeg( $image, $tmp_file, 90 );
		imagedestroy( $image );

		return $tmp_file;
	}
}
