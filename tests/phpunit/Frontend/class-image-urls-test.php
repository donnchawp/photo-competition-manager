<?php
/**
 * Tests for the Image_Urls trait shared by public shortcodes.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Image_Urls;
use WP_UnitTestCase;

/**
 * Exercises the Image_Urls trait via a lightweight consumer.
 */
class Image_Urls_Test extends WP_UnitTestCase {

	/**
	 * Object consuming the trait under test.
	 *
	 * @var object
	 */
	private $subject;

	/**
	 * Set up a trait consumer that exposes the private helpers.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->subject = new class() {
			use Image_Urls;

			/**
			 * Public wrapper around the private trait method.
			 *
			 * @param object $competition Competition object.
			 * @param object $image       Image record.
			 * @return array{full: string, thumb: string}
			 */
			public function urls( object $competition, object $image ): array {
				return $this->get_image_urls( $competition, $image );
			}
		};
	}

	/**
	 * Empty URLs are returned when the competition slug is missing.
	 */
	public function test_returns_empty_urls_when_slug_missing(): void {
		$result = $this->subject->urls(
			(object) array( 'slug' => '' ),
			(object) array(
				'filename' => 'john-photo.jpg',
				'category' => 'colour',
			)
		);

		$this->assertSame(
			array(
				'full'  => '',
				'thumb' => '',
			),
			$result
		);
	}

	/**
	 * Empty URLs are returned when the image filename is missing.
	 */
	public function test_returns_empty_urls_when_filename_missing(): void {
		$result = $this->subject->urls(
			(object) array( 'slug' => 'summer-2024' ),
			(object) array(
				'filename' => '',
				'category' => 'colour',
			)
		);

		$this->assertSame(
			array(
				'full'  => '',
				'thumb' => '',
			),
			$result
		);
	}

	/**
	 * Empty URLs are returned when the files are absent on disk.
	 */
	public function test_returns_empty_urls_when_files_are_absent_on_disk(): void {
		// Slug and filename present, but no files written to disk.
		$result = $this->subject->urls(
			(object) array( 'slug' => 'ghost-competition' ),
			(object) array(
				'filename' => 'missing-photo.jpg',
				'category' => 'colour',
			)
		);

		$this->assertSame( '', $result['full'] );
		$this->assertSame( '', $result['thumb'] );
	}

	/**
	 * Full and thumbnail URLs are returned when both files exist.
	 */
	public function test_returns_urls_when_files_exist_on_disk(): void {
		$slug     = 'test-comp';
		$category = 'colour';
		$filename = 'john-photo.jpg';
		$thumb    = 'john-photo-thumb.jpg';

		$uploads    = wp_upload_dir();
		$folder     = trailingslashit( $uploads['basedir'] ) . 'competitions/' . $slug . '/' . $category;
		$folder_url = trailingslashit( $uploads['baseurl'] ) . 'competitions/' . $slug . '/' . $category;

		wp_mkdir_p( $folder );
		file_put_contents( trailingslashit( $folder ) . $filename, 'full' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( trailingslashit( $folder ) . $thumb, 'thumb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = $this->subject->urls(
			(object) array( 'slug' => $slug ),
			(object) array(
				'filename' => $filename,
				'category' => $category,
			)
		);

		$this->assertSame( trailingslashit( $folder_url ) . $filename, $result['full'] );
		$this->assertSame( trailingslashit( $folder_url ) . $thumb, $result['thumb'] );

		// Clean up created files and directory.
		wp_delete_file( trailingslashit( $folder ) . $filename );
		wp_delete_file( trailingslashit( $folder ) . $thumb );
	}

	/**
	 * Full URL is omitted when only the thumbnail file exists.
	 */
	public function test_full_url_omitted_when_only_thumbnail_exists(): void {
		$slug     = 'partial-comp';
		$category = 'mono';
		$filename = 'entry.jpg';
		$thumb    = 'entry-thumb.jpg';

		$uploads    = wp_upload_dir();
		$folder     = trailingslashit( $uploads['basedir'] ) . 'competitions/' . $slug . '/' . $category;
		$folder_url = trailingslashit( $uploads['baseurl'] ) . 'competitions/' . $slug . '/' . $category;

		wp_mkdir_p( $folder );
		file_put_contents( trailingslashit( $folder ) . $thumb, 'thumb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = $this->subject->urls(
			(object) array( 'slug' => $slug ),
			(object) array(
				'filename' => $filename,
				'category' => $category,
			)
		);

		$this->assertSame( '', $result['full'] );
		$this->assertSame( trailingslashit( $folder_url ) . $thumb, $result['thumb'] );

		wp_delete_file( trailingslashit( $folder ) . $thumb );
	}
}
