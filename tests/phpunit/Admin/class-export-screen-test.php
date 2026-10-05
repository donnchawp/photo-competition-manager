<?php
/**
 * Tests for the Export screen's originals ZIP.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

use PhotoCompetitionManager\Admin\Export_Screen;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Entries;
use WP_UnitTestCase;
use ZipArchive;

/**
 * @covers \PhotoCompetitionManager\Admin\Export_Screen
 */
class Export_Screen_Test extends WP_UnitTestCase {

	/**
	 * Files removed in tearDown.
	 *
	 * @var string[]
	 */
	private $tmp_files = array();

	/**
	 * Competition whose entries are removed in tearDown.
	 *
	 * @var int
	 */
	private $competition_id = 0;

	public function tearDown(): void {
		if ( $this->competition_id ) {
			( new Entries() )->remove_competition_entries( Actor::admin(), $this->competition_id );
		}

		foreach ( $this->tmp_files as $tmp_file ) {
			wp_delete_file( $tmp_file );
		}

		parent::tearDown();
	}

	public function test_exporting_a_large_original_puts_the_full_size_file_in_the_zip(): void {
		$this->competition_id = (int) ( new Competitions_Repository() )->create(
			array(
				'title'     => 'Export Comp',
				'slug'      => 'export-comp',
				'open_date' => '2020-01-01 00:00:00',
				'settings'  => array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
							'quota' => 1,
						),
					),
				),
			)
		);
		$member_id            = (int) ( new Members_Repository() )->create(
			array(
				'name'  => 'Jane Doe',
				'email' => 'jane@example.com',
				'grade' => 'beginner',
			)
		);

		// Wider than WordPress's 2560px threshold, so the attachment's file is a -scaled copy.
		$entry_id = ( new Entries() )->add( Actor::admin(), $this->competition_id, $member_id, 'colour', $this->photo( 2600, 20 ) );
		$this->assertIsInt( $entry_id );
		$full_size = wp_get_original_image_path( (int) ( new Images_Repository() )->find( $entry_id )->original_attachment_id );

		$zip_path          = ( new Export_Screen() )->build_originals_zip( $this->competition_id );
		$this->tmp_files[] = $zip_path;

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path ) );
		$this->assertSame( 1, $zip->numFiles );
		$this->assertSame( basename( $full_size ), $zip->getNameIndex( 0 ) );
		$this->assertSame( filesize( $full_size ), $zip->statIndex( 0 )['size'] );
		$zip->close();
	}

	/**
	 * A solid-colour JPEG in the shape of a $_FILES entry.
	 *
	 * @param int $width  Width in pixels.
	 * @param int $height Height in pixels.
	 * @return array<string, mixed>
	 */
	private function photo( int $width, int $height ): array {
		$image = imagecreatetruecolor( $width, $height );
		imagefill( $image, 0, 0, imagecolorallocate( $image, 200, 0, 0 ) );

		// wp_tempnam() creates an empty .tmp file, and the image editor needs a .jpg extension.
		$tmp_name          = wp_tempnam( 'photo.jpg' );
		$tmp_file          = $tmp_name . '.jpg';
		$this->tmp_files[] = $tmp_name;
		$this->tmp_files[] = $tmp_file;
		imagejpeg( $image, $tmp_file, 90 );

		return array(
			'name'     => 'photo.jpg',
			'tmp_name' => $tmp_file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $tmp_file ),
		);
	}
}
