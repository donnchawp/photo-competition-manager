<?php
/**
 * Tests for the Export screen's originals ZIP.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Export_Screen;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use RuntimeException;
use ZipArchive;

/**
 * @covers \PhotoCompetitionManager\Admin\Export_Screen
 */
class Export_Screen_Test extends Admin_Controller_Test_Case {

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

	public function tear_down(): void {
		if ( $this->competition_id ) {
			( new Entries() )->remove_competition_entries( Actor::admin(), $this->competition_id );
		}

		foreach ( $this->tmp_files as $tmp_file ) {
			wp_delete_file( $tmp_file );
		}

		parent::tear_down();
	}

	public function test_exporting_a_large_original_puts_the_full_size_file_in_the_zip(): void {
		$member_id = $this->create_competition_and_member();

		// Wider than WordPress's 2560px threshold, so the attachment's file is a -scaled copy.
		$entry_id = ( new Entries() )->add( Actor::admin(), $this->competition_id, $member_id, 'colour', $this->photo( 2600, 20 ) );
		$this->assertIsInt( $entry_id );
		$full_size = wp_get_original_image_path( (int) ( new Images_Repository() )->find( $entry_id )->original_attachment_id );

		$zip_path          = ( new Export_Screen() )->build_originals_zip( $this->competition_id, ( new Images_Repository() )->get_original_attachment_ids( $this->competition_id ) );
		$this->tmp_files[] = $zip_path;

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path ) );
		$this->assertSame( 1, $zip->numFiles );
		$this->assertSame( basename( $full_size ), $zip->getNameIndex( 0 ) );
		$this->assertSame( filesize( $full_size ), $zip->statIndex( 0 )['size'] );
		$zip->close();
	}

	public function test_a_competition_without_originals_has_nothing_to_export(): void {
		$this->create_competition_and_member();
		$this->set_request(
			array(
				'action'         => 'export_originals',
				'competition_id' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_export_originals', 'photo_competition_export_nonce' );

		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'No original images found for this competition.' );
		( new Export_Screen() )->handle_actions();
	}

	public function test_export_and_delete_is_refused_before_the_zip_while_a_category_is_accepting_votes(): void {
		$member_id = $this->create_competition_and_member();
		$entry_id  = ( new Entries() )->add( Actor::admin(), $this->competition_id, $member_id, 'colour', $this->photo( 64, 48 ) );
		$this->assertIsInt( $entry_id );
		$original = get_attached_file( (int) ( new Images_Repository() )->find( $entry_id )->original_attachment_id );
		Workflow_Fixtures::set_stage( $this->competition_id, 'colour', Competition_Workflow::STAGE_VOTING );

		// Building the ZIP is too late: the download would start and the refusal couldn't be shown.
		$no_zip = function () {
			throw new RuntimeException( 'The ZIP was built before the refusal.' );
		};
		add_filter( 'upload_dir', $no_zip );

		$this->set_request(
			array(
				'action'              => 'export_originals',
				'competition_id'      => $this->competition_id,
				'delete_after_export' => '1',
			)
		);
		$this->set_nonce( 'photo_competition_export_originals', 'photo_competition_export_nonce' );

		try {
			( new Export_Screen() )->handle_actions();
			$this->fail( 'Expected the export to be refused.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'accepting votes', $e->getMessage() );
		} finally {
			remove_filter( 'upload_dir', $no_zip );
		}

		$this->assertFileExists( $original );
	}

	/**
	 * Create a competition with a colour category, and a member, and return the member's ID.
	 *
	 * @return int Member ID.
	 */
	private function create_competition_and_member(): int {
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

		return (int) ( new Members_Repository() )->create(
			array(
				'name'  => 'Jane Doe',
				'email' => 'jane@example.com',
				'grade' => 'beginner',
			)
		);
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
