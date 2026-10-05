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
use PhotoCompetitionManager\Tests\Photo_Uploads;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use RuntimeException;
use ZipArchive;

/**
 * @covers \PhotoCompetitionManager\Admin\Export_Screen
 */
class Export_Screen_Test extends Admin_Controller_Test_Case {

	use Photo_Uploads;

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

		$this->remove_tmp_files();

		parent::tear_down();
	}

	public function test_exporting_a_large_original_puts_the_full_size_file_in_the_zip(): void {
		$this->competition_id = $this->create_competition();
		$entries              = new Entries();

		// Wider than WordPress's 2560px threshold, so the attachment's file is a -scaled copy.
		$entry_id = $entries->add( Actor::admin(), $this->competition_id, $this->create_member(), 'colour', $this->photo( array( 200, 0, 0 ), 2600, 20 ) );
		$this->assertIsInt( $entry_id );
		$full_size = wp_get_original_image_path( (int) ( new Images_Repository() )->find( $entry_id )->original_attachment_id );

		$zip_path          = ( new Export_Screen() )->build_originals_zip( $this->competition_id, $entries->originals( $this->competition_id ) );
		$this->tmp_files[] = $zip_path;

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path ) );
		$this->assertSame( 1, $zip->numFiles );
		$this->assertSame( basename( $full_size ), $zip->getNameIndex( 0 ) );
		$this->assertSame( filesize( $full_size ), $zip->statIndex( 0 )['size'] );
		$zip->close();
	}

	public function test_originals_with_the_same_name_in_different_months_both_go_in_the_zip(): void {
		$this->competition_id = $this->create_competition();
		$september            = $this->original_in( '2026/09', 'jane-doe-colour-original.jpg' );
		$october              = $this->original_in( '2026/10', 'jane-doe-colour-original.jpg' );

		$zip_path          = ( new Export_Screen() )->build_originals_zip(
			$this->competition_id,
			array(
				11 => $september,
				12 => $october,
			)
		);
		$this->tmp_files[] = $zip_path;

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path ) );
		$this->assertSame( 2, $zip->numFiles );
		$zip->close();
	}

	public function test_an_original_that_cant_be_added_fails_the_zip(): void {
		$this->competition_id = $this->create_competition();
		$missing              = wp_upload_dir()['basedir'] . '/2026/09/gone-original.jpg';

		$result = ( new Export_Screen() )->build_originals_zip( $this->competition_id, array( 11 => $missing ) );

		$this->assertWPError( $result );
		$this->assertSame( 'zip_failed', $result->get_error_code() );
	}

	public function test_an_original_that_cant_be_read_fails_the_zip(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root can read a file with no permissions.' );
		}

		$this->competition_id = $this->create_competition();
		$locked               = $this->original_in( '2026/09', 'locked-original.jpg' );
		chmod( $locked, 0 );

		try {
			$result = ( new Export_Screen() )->build_originals_zip( $this->competition_id, array( 11 => $locked ) );
		} finally {
			chmod( $locked, 0644 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'zip_failed', $result->get_error_code() );
	}

	public function test_a_competition_without_originals_has_nothing_to_export(): void {
		$this->competition_id = $this->create_competition();
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
		$this->competition_id = $this->create_competition();
		$entry_id             = ( new Entries() )->add( Actor::admin(), $this->competition_id, $this->create_member(), 'colour', $this->photo( array( 200, 0, 0 ) ) );
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
	 * A JPEG at the given place under uploads, removed in tear_down.
	 *
	 * @param string $folder   Month folder under uploads, such as 2026/09.
	 * @param string $filename File name.
	 * @return string Path.
	 */
	private function original_in( string $folder, string $filename ): string {
		$directory = wp_upload_dir()['basedir'] . '/' . $folder;
		wp_mkdir_p( $directory );

		$path              = $directory . '/' . $filename;
		$this->tmp_files[] = $path;
		copy( $this->photo( array( 200, 0, 0 ) )['tmp_name'], $path );

		return $path;
	}

	/**
	 * Create a competition with a colour category.
	 *
	 * @return int Competition ID.
	 */
	private function create_competition(): int {
		return (int) ( new Competitions_Repository() )->create(
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
	}

	/**
	 * @return int Member ID.
	 */
	private function create_member(): int {
		return (int) ( new Members_Repository() )->create(
			array(
				'name'  => 'Jane Doe',
				'email' => 'jane@example.com',
				'grade' => 'beginner',
			)
		);
	}
}
