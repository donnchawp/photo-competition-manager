<?php
/**
 * Tests for the files Upload_Handler writes: each entry keeps its own image.
 *
 * Unlike Upload_Handler_Test, these go through the real Image_Processor and
 * write files to the uploads directory.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Upload_Handler;
use PhotoCompetitionManager\Support\Image_Processor;
use WP_UnitTestCase;

class Upload_Handler_Files_Test extends WP_UnitTestCase {

	/**
	 * @var Upload_Handler
	 */
	private $handler;

	/**
	 * @var Competitions_Repository
	 */
	private $competitions_repo;

	/**
	 * @var Images_Repository
	 */
	private $images_repo;

	/**
	 * @var Members_Repository
	 */
	private $members_repo;

	/**
	 * @var Image_Processor
	 */
	private $processor;

	/**
	 * Competition slugs whose upload folders are removed in tearDown.
	 *
	 * @var string[]
	 */
	private $slugs = array();

	/**
	 * Temporary source images removed in tearDown.
	 *
	 * @var string[]
	 */
	private $tmp_files = array();

	public function setUp(): void {
		parent::setUp();

		$this->competitions_repo = new Competitions_Repository();
		$this->images_repo       = new Images_Repository();
		$this->members_repo      = new Members_Repository();
		$this->processor         = new Image_Processor();

		$this->handler = new Upload_Handler(
			$this->competitions_repo,
			$this->images_repo,
			$this->members_repo,
			$this->processor
		);
	}

	public function tearDown(): void {
		// Deletes every file the test added under uploads, leaving the competition folders empty.
		$this->remove_added_uploads();

		$basedir = wp_upload_dir()['basedir'];
		foreach ( $this->slugs as $slug ) {
			$this->delete_folders( trailingslashit( $basedir ) . 'competitions/' . $slug );
		}

		foreach ( $this->tmp_files as $tmp_file ) {
			wp_delete_file( $tmp_file );
		}

		parent::tearDown();
	}

	public function test_an_upload_after_a_delete_keeps_the_other_entrys_image(): void {
		$competition_id = $this->create_competition( 'counter-comp', 2 );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$image_a = $this->upload( $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$image_b = $this->upload( $competition_id, $member_id, 'colour', array( 0, 200, 0 ) );
		$b_hash  = $this->file_hash( $image_b );

		$this->assertTrue( $this->handler->delete_submission( $image_a, $member_id, $competition_id ) );

		$image_c = $this->upload( $competition_id, $member_id, 'colour', array( 0, 0, 200 ) );

		$this->assertNotSame( $this->images_repo->find( $image_b )->filename, $this->images_repo->find( $image_c )->filename );
		$this->assertSame( $b_hash, $this->file_hash( $image_b ), "The second entry's image was overwritten." );
	}

	public function test_members_with_the_same_name_keep_their_own_images(): void {
		$competition_id = $this->create_competition( 'namesake-comp' );
		$first_member   = $this->create_member( 'John Murphy', 'john@example.com' );
		$second_member  = $this->create_member( 'John Murphy', 'john.murphy@example.com' );

		$first      = $this->upload( $competition_id, $first_member, 'colour', array( 200, 0, 0 ) );
		$first_hash = $this->file_hash( $first );
		$second     = $this->upload( $competition_id, $second_member, 'colour', array( 0, 200, 0 ) );

		$this->assertSame( $first_hash, $this->file_hash( $first ), "The namesake's upload overwrote the first member's image." );
		$this->assertNotSame( $this->original_path( $first ), $this->original_path( $second ) );
	}

	public function test_competitions_in_the_same_month_keep_their_own_originals(): void {
		// Only one competition can be open, so the closed one gets an admin upload.
		$closed_id = $this->create_competition( 'closed-comp', 1, '2020-02-01 00:00:00' );
		$open_id   = $this->create_competition( 'open-comp' );
		$member_id = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$closed          = $this->upload( $closed_id, $member_id, 'colour', array( 200, 0, 0 ), false );
		$closed_original = $this->original_path( $closed );
		$closed_hash     = md5_file( $closed_original );

		$open = $this->upload( $open_id, $member_id, 'colour', array( 0, 200, 0 ) );

		$this->assertNotSame( $closed_original, $this->original_path( $open ) );

		$this->assertTrue( $this->handler->delete_submission( $open, $member_id, $open_id ) );

		$this->assertFileExists( $closed_original, "Deleting one competition's entry deleted the other's original." );
		$this->assertSame( $closed_hash, md5_file( $closed_original ), "The other competition's upload overwrote the original." );
	}

	public function test_moving_an_entry_to_another_category_keeps_the_image_already_there(): void {
		$competition_id = $this->create_competition( 'move-comp', 2 );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		// Both are the member's first colour upload, so both are named jane-doe-colour-1.jpg.
		$moved_first = $this->upload( $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$this->assertTrue( $this->handler->update_submission_category( $moved_first, $member_id, $competition_id, 'mono' ) );
		$first_hash = $this->file_hash( $moved_first );

		$moved_second = $this->upload( $competition_id, $member_id, 'colour', array( 0, 200, 0 ) );
		$second_hash  = $this->file_hash( $moved_second );
		$this->assertTrue( $this->handler->update_submission_category( $moved_second, $member_id, $competition_id, 'mono' ) );

		$this->assertSame( $first_hash, $this->file_hash( $moved_first ), 'The move overwrote the image already in the category.' );
		$this->assertSame( $second_hash, $this->file_hash( $moved_second ), "The moved entry doesn't point at its own image." );
		$this->assertNotSame( $this->file_hash( $moved_first, true ), $this->file_hash( $moved_second, true ), 'The move overwrote the thumbnail already in the category.' );
	}

	public function test_a_failed_move_puts_the_image_back_under_its_own_name(): void {
		$competition_id = $this->create_competition( 'rollback-comp', 2 );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		// Take jane-doe-colour-1.jpg in mono, so the next move into mono is renamed.
		$moved = $this->upload( $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$this->assertTrue( $this->handler->update_submission_category( $moved, $member_id, $competition_id, 'mono' ) );

		$entry = $this->upload( $competition_id, $member_id, 'colour', array( 0, 200, 0 ) );
		$hash  = $this->file_hash( $entry );

		$failing_repo = new class() extends Images_Repository {
			public function update_category( int $id, string $category, string $filename ) {
				return new \WP_Error( 'db_update_failed', 'Could not update image category.' );
			}
		};
		$handler      = new Upload_Handler( $this->competitions_repo, $failing_repo, $this->members_repo, $this->processor );

		$this->assertWPError( $handler->update_submission_category( $entry, $member_id, $competition_id, 'mono' ) );

		$this->assertSame( 'colour', $this->images_repo->find( $entry )->category );
		$this->assertSame( $hash, $this->file_hash( $entry ), "The image isn't back at the name its row has." );
	}

	/**
	 * Create a competition with colour and mono categories.
	 *
	 * @param string      $slug       Competition slug.
	 * @param int         $quota      Images allowed per member in each category.
	 * @param string|null $close_date Close date, or null to leave it open.
	 * @return int Competition ID.
	 */
	private function create_competition( string $slug, int $quota = 1, ?string $close_date = null ): int {
		$this->slugs[] = $slug;

		return (int) $this->competitions_repo->create(
			array(
				'title'      => $slug,
				'slug'       => $slug,
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => $close_date,
				'settings'   => array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
							'quota' => $quota,
						),
						array(
							'slug'  => 'mono',
							'label' => 'Mono',
							'quota' => $quota,
						),
					),
				),
			)
		);
	}

	/**
	 * @param string $name  Member name.
	 * @param string $email Member email.
	 * @return int Member ID.
	 */
	private function create_member( string $name, string $email ): int {
		return (int) $this->members_repo->create(
			array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => 1,
			)
		);
	}

	/**
	 * Upload a solid-colour JPEG and return the new entry's ID.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param int    $member_id      Member ID.
	 * @param string $category       Category slug.
	 * @param int[]  $rgb            Fill colour, so each upload is a different picture.
	 * @param bool   $as_member      Upload as the member (open competitions only), or as an admin.
	 * @return int Image ID.
	 */
	private function upload( int $competition_id, int $member_id, string $category, array $rgb, bool $as_member = true ): int {
		$image = imagecreatetruecolor( 64, 48 );
		imagefill( $image, 0, 0, imagecolorallocate( $image, $rgb[0], $rgb[1], $rgb[2] ) );

		// wp_tempnam() creates an empty .tmp file, and the image editor needs a .jpg extension.
		$tmp_name          = wp_tempnam( 'photo.jpg' );
		$tmp_file          = $tmp_name . '.jpg';
		$this->tmp_files[] = $tmp_name;
		$this->tmp_files[] = $tmp_file;
		imagejpeg( $image, $tmp_file, 90 );

		$file   = array(
			'name'     => 'photo.jpg',
			'tmp_name' => $tmp_file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $tmp_file ),
		);
		$result = $as_member
			? $this->handler->handle_upload( $competition_id, $member_id, $category, $file )
			: $this->handler->upload_on_behalf( $competition_id, $member_id, $category, $file );

		$this->assertIsInt( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		return $result;
	}

	/**
	 * Hash of the entry's competition image file, read through its stored row.
	 *
	 * @param int  $image_id  Image ID.
	 * @param bool $thumbnail Hash the entry's thumbnail instead.
	 * @return string
	 */
	private function file_hash( int $image_id, bool $thumbnail = false ): string {
		$image       = $this->images_repo->find( $image_id );
		$competition = $this->competitions_repo->find( (int) $image->competition_id );
		$dir         = $this->processor->get_upload_directory( $competition->slug, $image->category );
		$filename    = $thumbnail ? Image_Processor::get_thumbnail_filename( $image->filename ) : $image->filename;
		$path        = trailingslashit( $dir['path'] ) . $filename;

		$this->assertFileExists( $path );

		return md5_file( $path );
	}

	/**
	 * Path of the entry's media library original.
	 *
	 * @param int $image_id Image ID.
	 * @return string
	 */
	private function original_path( int $image_id ): string {
		$path = get_attached_file( (int) $this->images_repo->find( $image_id )->original_attachment_id );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		return $path;
	}
}
