<?php
/**
 * Tests for the Entries module, through its interface with real files.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

class Entries_Test extends WP_UnitTestCase {

	/**
	 * @var Entries
	 */
	private $entries;

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

		$this->entries = new Entries( $this->competitions_repo, $this->images_repo, $this->members_repo );
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

	public function test_a_member_adds_an_entry_while_uploads_are_open(): void {
		$competition_id = $this->create_competition( 'add-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$entry_id = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$entry = $this->images_repo->find( $entry_id );
		$this->assertSame( 'jane-doe-colour.jpg', $entry->filename );
		$this->assertFileExists( $this->entry_path( $entry_id ) );
		$this->assertFileExists( $this->entry_path( $entry_id, true ) );
		$this->assertFileExists( $this->original_path( $entry_id ) );

		// Uninstall finds the plugin's originals by this meta.
		$this->assertSame( 'add-comp', get_post_meta( (int) $entry->original_attachment_id, '_photo_comp_slug', true ) );
	}

	public function test_an_upload_that_isnt_an_image_writes_nothing(): void {
		$competition_id = $this->create_competition( 'invalid-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$tmp_file          = wp_tempnam( 'photo.jpg' );
		$this->tmp_files[] = $tmp_file;
		file_put_contents( $tmp_file, 'not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->assertWPError( $this->entries->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', $this->upload_array( $tmp_file ) ) );

		$this->assertDirectoryDoesNotExist( wp_upload_dir()['basedir'] . '/competitions/invalid-comp' );
	}

	public function test_an_entry_whose_row_wont_save_leaves_no_files(): void {
		$competition_id = $this->create_competition( 'unsaved-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$failing_repo = new class() extends Images_Repository {
			public function create( array $data ) {
				return new \WP_Error( 'db_insert_failed', 'Could not create image record.' );
			}
		};
		$entries      = new Entries( $this->competitions_repo, $failing_repo, $this->members_repo );

		$this->assertWPError( $entries->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', $this->photo( array( 200, 0, 0 ) ) ) );

		$this->assertSame( array(), glob( wp_upload_dir()['basedir'] . '/competitions/unsaved-comp/colour/*.jpg' ) );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'meta_key'    => '_photo_comp_slug', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'  => 'unsaved-comp', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	public function test_a_removal_doesnt_log_an_original_thats_already_gone(): void {
		$competition_id = $this->create_competition( 'gone-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		wp_delete_attachment( (int) $this->images_repo->find( $entry_id )->original_attachment_id, true );

		$this->assertTrue( $this->entries->remove( Actor::member( $member_id ), $competition_id, $entry_id ) );

		$this->assertSame( array(), ( new Logs_Repository() )->find_by_competition( $competition_id, 50, 0, array( 'event_type' => 'entry_file_not_deleted' ) ) );
	}

	public function test_a_member_cant_add_an_entry_once_uploads_close(): void {
		$competition_id = $this->create_competition( 'closed-comp', 1, '2020-02-01 00:00:00' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$result = $this->entries->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', $this->photo( array( 200, 0, 0 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'competition_closed', $result->get_error_code() );
	}

	public function test_an_admin_adds_an_entry_after_uploads_close(): void {
		$competition_id = $this->create_competition( 'closed-comp', 1, '2020-02-01 00:00:00' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$entry_id = $this->add( Actor::admin(), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$this->assertSame( $member_id, (int) $this->images_repo->find( $entry_id )->member_id );
	}

	public function test_a_member_cant_add_an_entry_for_someone_else(): void {
		$competition_id = $this->create_competition( 'add-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$other_id       = $this->create_member( 'John Murphy', 'john@example.com' );

		$result = $this->entries->add( Actor::member( $other_id ), $competition_id, $member_id, 'colour', $this->photo( array( 200, 0, 0 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'not_authorized', $result->get_error_code() );
	}

	public function test_an_admin_is_held_to_the_quota(): void {
		$competition_id = $this->create_competition( 'quota-comp', 1, '2020-02-01 00:00:00' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$this->add( Actor::admin(), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$result = $this->entries->add( Actor::admin(), $competition_id, $member_id, 'colour', $this->photo( array( 0, 200, 0 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'quota_exceeded', $result->get_error_code() );
	}

	public function test_an_inactive_member_cant_be_given_an_entry(): void {
		$competition_id = $this->create_competition( 'add-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com', 0 );

		$result = $this->entries->add( Actor::admin(), $competition_id, $member_id, 'colour', $this->photo( array( 200, 0, 0 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'inactive_member', $result->get_error_code() );
	}

	public function test_an_entry_needs_a_category_the_competition_has(): void {
		$competition_id = $this->create_competition( 'add-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$result = $this->entries->add( Actor::member( $member_id ), $competition_id, $member_id, 'nature', $this->photo( array( 200, 0, 0 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_category', $result->get_error_code() );
	}

	public function test_an_entry_needs_a_competition_and_a_member(): void {
		$competition_id = $this->create_competition( 'add-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$no_competition = $this->entries->add( Actor::admin(), 999999, $member_id, 'colour', $this->photo( array( 200, 0, 0 ) ) );
		$no_member      = $this->entries->add( Actor::admin(), $competition_id, 999999, 'colour', $this->photo( array( 200, 0, 0 ) ) );

		$this->assertSame( 'invalid_competition', $no_competition->get_error_code() );
		$this->assertSame( 'invalid_member', $no_member->get_error_code() );
	}

	public function test_a_member_removes_their_entry_and_its_files(): void {
		$competition_id = $this->create_competition( 'remove-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$files          = $this->entry_files( $entry_id );

		$this->assertTrue( $this->entries->remove( Actor::member( $member_id ), $competition_id, $entry_id ) );

		$this->assertNull( $this->images_repo->find( $entry_id ) );
		foreach ( $files as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
	}

	public function test_a_member_cant_remove_someone_elses_entry(): void {
		$competition_id = $this->create_competition( 'remove-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$other_id       = $this->create_member( 'John Murphy', 'john@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$result = $this->entries->remove( Actor::member( $other_id ), $competition_id, $entry_id );

		$this->assertWPError( $result );
		$this->assertSame( 'not_authorized', $result->get_error_code() );
		$this->assertNotNull( $this->images_repo->find( $entry_id ) );
		$this->assertFileExists( $this->entry_path( $entry_id ) );
	}

	public function test_an_entry_is_only_removed_from_its_own_competition(): void {
		$competition_id = $this->create_competition( 'remove-comp' );
		$other_id       = $this->create_competition( 'other-comp', 1, '2020-02-01 00:00:00' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$result = $this->entries->remove( Actor::admin(), $other_id, $entry_id );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
		$this->assertNotNull( $this->images_repo->find( $entry_id ) );
	}

	public function test_a_member_cant_remove_an_entry_once_uploads_close_but_an_admin_can(): void {
		$competition_id = $this->create_competition( 'closed-comp', 1, '2020-02-01 00:00:00' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::admin(), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$result = $this->entries->remove( Actor::member( $member_id ), $competition_id, $entry_id );

		$this->assertWPError( $result );
		$this->assertSame( 'competition_closed', $result->get_error_code() );

		$this->assertTrue( $this->entries->remove( Actor::admin(), $competition_id, $entry_id ) );
		$this->assertNull( $this->images_repo->find( $entry_id ) );
	}

	public function test_a_removal_whose_row_wont_delete_leaves_the_files_alone(): void {
		$competition_id = $this->create_competition( 'remove-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$failing_repo = new class() extends Images_Repository {
			public function delete( int $id ) {
				return new \WP_Error( 'db_delete_failed', 'Could not delete image record.' );
			}
		};
		$entries      = new Entries( $this->competitions_repo, $failing_repo, $this->members_repo );

		$this->assertWPError( $entries->remove( Actor::member( $member_id ), $competition_id, $entry_id ) );

		foreach ( $this->entry_files( $entry_id ) as $file ) {
			$this->assertFileExists( $file );
		}
	}

	public function test_a_removal_whose_file_wont_delete_still_removes_the_entry(): void {
		$competition_id = $this->create_competition( 'remove-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$image_path     = $this->entry_path( $entry_id );

		add_filter( 'wp_delete_file', array( $this, 'keep_entry_images' ) );
		$result = $this->entries->remove( Actor::member( $member_id ), $competition_id, $entry_id );
		remove_filter( 'wp_delete_file', array( $this, 'keep_entry_images' ) );

		$this->assertTrue( $result );
		$this->assertNull( $this->images_repo->find( $entry_id ) );
		$this->assertFileExists( $image_path );

		$logs = ( new Logs_Repository() )->find_by_competition( $competition_id, 50, 0, array( 'event_type' => 'entry_file_not_deleted' ) );
		$this->assertCount( 1, $logs );
		$this->assertSame( $image_path, json_decode( $logs[0]->metadata, true )['path'] );
	}

	public function test_an_upload_after_a_removal_keeps_the_other_entrys_image(): void {
		$competition_id = $this->create_competition( 'counter-comp', 2 );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$member         = Actor::member( $member_id );

		$entry_a = $this->add( $member, $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$entry_b = $this->add( $member, $competition_id, $member_id, 'colour', array( 0, 200, 0 ) );
		$b_hash  = $this->file_hash( $entry_b );

		$this->assertTrue( $this->entries->remove( $member, $competition_id, $entry_a ) );

		$entry_c = $this->add( $member, $competition_id, $member_id, 'colour', array( 0, 0, 200 ) );

		$this->assertNotSame( $this->images_repo->find( $entry_b )->filename, $this->images_repo->find( $entry_c )->filename );
		$this->assertSame( $b_hash, $this->file_hash( $entry_b ), "The second entry's image was overwritten." );
	}

	public function test_members_with_the_same_name_keep_their_own_images(): void {
		$competition_id = $this->create_competition( 'namesake-comp' );
		$first_member   = $this->create_member( 'John Murphy', 'john@example.com' );
		$second_member  = $this->create_member( 'John Murphy', 'john.murphy@example.com' );

		$first      = $this->add( Actor::member( $first_member ), $competition_id, $first_member, 'colour', array( 200, 0, 0 ) );
		$first_hash = $this->file_hash( $first );
		$second     = $this->add( Actor::member( $second_member ), $competition_id, $second_member, 'colour', array( 0, 200, 0 ) );

		$this->assertSame( $first_hash, $this->file_hash( $first ), "The namesake's upload overwrote the first member's image." );
		$this->assertNotSame( $this->original_path( $first ), $this->original_path( $second ) );
	}

	public function test_competitions_in_the_same_month_keep_their_own_originals(): void {
		// Only one competition can be open, so the closed one gets an admin upload.
		$closed_id = $this->create_competition( 'closed-comp', 1, '2020-02-01 00:00:00' );
		$open_id   = $this->create_competition( 'open-comp' );
		$member_id = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$closed          = $this->add( Actor::admin(), $closed_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$closed_original = $this->original_path( $closed );
		$closed_hash     = md5_file( $closed_original );

		$open = $this->add( Actor::member( $member_id ), $open_id, $member_id, 'colour', array( 0, 200, 0 ) );

		$this->assertNotSame( $closed_original, $this->original_path( $open ) );

		$this->assertTrue( $this->entries->remove( Actor::member( $member_id ), $open_id, $open ) );

		$this->assertFileExists( $closed_original, "Removing one competition's entry deleted the other's original." );
		$this->assertSame( $closed_hash, md5_file( $closed_original ), "The other competition's upload overwrote the original." );
	}

	public function test_a_member_swaps_two_entries_between_full_categories(): void {
		$competition_id = $this->create_competition( 'swap-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$member         = Actor::member( $member_id );
		$colour         = $this->add( $member, $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$mono           = $this->add( $member, $competition_id, $member_id, 'mono', array( 0, 200, 0 ) );
		$colour_hash    = $this->file_hash( $colour );
		$mono_hash      = $this->file_hash( $mono );

		$result = $this->entries->change_categories(
			$member,
			$competition_id,
			$member_id,
			array(
				$colour => 'mono',
				$mono   => 'colour',
			)
		);

		$this->assertTrue( $result );
		$this->assertSame( 'mono', $this->images_repo->find( $colour )->category );
		$this->assertSame( 'colour', $this->images_repo->find( $mono )->category );
		$this->assertSame( $colour_hash, $this->file_hash( $colour ) );
		$this->assertSame( $mono_hash, $this->file_hash( $mono ) );
	}

	public function test_a_member_cant_change_categories_once_uploads_close(): void {
		$competition_id = $this->create_competition( 'closed-comp', 1, '2020-02-01 00:00:00' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::admin(), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$result = $this->entries->change_categories( Actor::member( $member_id ), $competition_id, $member_id, array( $entry_id => 'mono' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'competition_closed', $result->get_error_code() );
		$this->assertSame( 'colour', $this->images_repo->find( $entry_id )->category );
		$this->assertFileExists( $this->entry_path( $entry_id ) );
	}

	public function test_a_set_of_changes_that_overfills_a_category_moves_nothing(): void {
		$competition_id = $this->create_competition( 'quota-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$member         = Actor::member( $member_id );
		$colour         = $this->add( $member, $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$mono           = $this->add( $member, $competition_id, $member_id, 'mono', array( 0, 200, 0 ) );

		$result = $this->entries->change_categories( $member, $competition_id, $member_id, array( $colour => 'mono' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'quota_exceeded', $result->get_error_code() );
		$this->assertSame( 'colour', $this->images_repo->find( $colour )->category );
		$this->assertSame( 'mono', $this->images_repo->find( $mono )->category );
		$this->assertFileExists( $this->entry_path( $colour ) );
	}

	public function test_an_admin_changes_categories_after_uploads_close_until_voting_starts(): void {
		$competition_id = $this->create_competition( 'admin-move-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$colour         = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$mono           = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'mono', array( 0, 200, 0 ) );
		Workflow_Fixtures::close_uploads( $competition_id );

		$swap = array(
			$colour => 'mono',
			$mono   => 'colour',
		);
		$this->assertTrue( $this->entries->change_categories( Actor::admin(), $competition_id, $member_id, $swap ) );
		$this->assertSame( 'mono', $this->images_repo->find( $colour )->category );

		Workflow_Fixtures::set_stage( $competition_id, 'mono', Competition_Workflow::STAGE_VOTING );

		$result = $this->entries->change_categories( Actor::admin(), $competition_id, $member_id, array( $colour => 'colour' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'voting_started', $result->get_error_code() );
		$this->assertSame( 'mono', $this->images_repo->find( $colour )->category );
	}

	public function test_an_entry_with_votes_cant_move_even_after_its_category_is_reset(): void {
		$competition_id = $this->create_competition( 'voted-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		Workflow_Fixtures::close_uploads( $competition_id );

		// What a reset that keeps the votes leaves behind: the stage is Not started, the votes remain.
		( new Votes_Repository() )->create( $competition_id, 'colour', 'A Voter', $entry_id, 5 );

		$result = $this->entries->change_categories( Actor::admin(), $competition_id, $member_id, array( $entry_id => 'mono' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'entry_has_votes', $result->get_error_code() );
		$this->assertSame( 'colour', $this->images_repo->find( $entry_id )->category );
	}

	public function test_an_admin_can_move_at_previewed_but_not_into_a_category_thats_voting(): void {
		$competition_id = $this->create_competition( 'stage-comp', 2 );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$first          = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$second         = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 0, 200, 0 ) );
		Workflow_Fixtures::close_uploads( $competition_id );
		Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_PREVIEWED );

		$this->assertTrue( $this->entries->change_categories( Actor::admin(), $competition_id, $member_id, array( $first => 'mono' ) ) );

		Workflow_Fixtures::set_stage( $competition_id, 'mono', Competition_Workflow::STAGE_VOTING );
		$result = $this->entries->change_categories( Actor::admin(), $competition_id, $member_id, array( $second => 'mono' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'voting_started', $result->get_error_code() );
		$this->assertSame( 'colour', $this->images_repo->find( $second )->category );
	}

	public function test_a_move_that_fails_partway_puts_the_earlier_moves_back(): void {
		$competition_id = $this->create_competition( 'partway-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$member         = Actor::member( $member_id );
		$colour         = $this->add( $member, $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$mono           = $this->add( $member, $competition_id, $member_id, 'mono', array( 0, 200, 0 ) );
		$colour_hash    = $this->file_hash( $colour );
		$mono_hash      = $this->file_hash( $mono );

		// The first move's row updates, the second one's doesn't.
		$failing_repo = new class() extends Images_Repository {
			private $updates = 0;

			public function update_category( int $id, string $category, string $filename ) {
				return 2 === ++$this->updates ? new \WP_Error( 'db_update_failed', 'Could not update image category.' ) : parent::update_category( $id, $category, $filename );
			}
		};
		$entries      = new Entries( $this->competitions_repo, $failing_repo, $this->members_repo );

		$swap = array(
			$colour => 'mono',
			$mono   => 'colour',
		);
		$this->assertWPError( $entries->change_categories( $member, $competition_id, $member_id, $swap ) );

		$this->assertSame( 'colour', $this->images_repo->find( $colour )->category );
		$this->assertSame( 'mono', $this->images_repo->find( $mono )->category );
		$this->assertSame( $colour_hash, $this->file_hash( $colour ), "The first entry's image isn't back where its row says." );
		$this->assertSame( $mono_hash, $this->file_hash( $mono ), "The second entry's image isn't back where its row says." );
	}

	public function test_an_entry_in_a_category_the_competition_no_longer_has_can_move_out_of_it(): void {
		$competition_id = $this->create_competition( 'renamed-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->images_repo->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $member_id,
				'category'       => 'nature',
				'filename'       => 'jane-doe-nature.jpg',
			)
		);

		$this->assertTrue( $this->entries->change_categories( Actor::member( $member_id ), $competition_id, $member_id, array( $entry_id => 'colour' ) ) );
		$this->assertSame( 'colour', $this->images_repo->find( $entry_id )->category );
	}

	public function test_moving_an_entry_to_another_category_keeps_the_image_already_there(): void {
		$competition_id = $this->create_competition( 'move-comp', 2 );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$member         = Actor::member( $member_id );

		// Both are the member's first colour upload, so both are named jane-doe-colour-1.jpg.
		$moved_first = $this->add( $member, $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$this->assertTrue( $this->entries->change_categories( $member, $competition_id, $member_id, array( $moved_first => 'mono' ) ) );
		$first_hash = $this->file_hash( $moved_first );

		$moved_second = $this->add( $member, $competition_id, $member_id, 'colour', array( 0, 200, 0 ) );
		$second_hash  = $this->file_hash( $moved_second );
		$this->assertTrue( $this->entries->change_categories( $member, $competition_id, $member_id, array( $moved_second => 'mono' ) ) );

		$this->assertSame( 'mono', $this->images_repo->find( $moved_second )->category );
		$this->assertSame( $first_hash, $this->file_hash( $moved_first ), 'The move overwrote the image already in the category.' );
		$this->assertSame( $second_hash, $this->file_hash( $moved_second ), "The moved entry doesn't point at its own image." );
		$this->assertNotSame( $this->file_hash( $moved_first, true ), $this->file_hash( $moved_second, true ), 'The move overwrote the thumbnail already in the category.' );
	}

	public function test_a_failed_move_puts_the_image_back_under_its_own_name(): void {
		$competition_id = $this->create_competition( 'rollback-comp', 2 );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$member         = Actor::member( $member_id );

		// Take jane-doe-colour-1.jpg in mono, so the next move into mono is renamed.
		$moved = $this->add( $member, $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$this->assertTrue( $this->entries->change_categories( $member, $competition_id, $member_id, array( $moved => 'mono' ) ) );

		$entry_id = $this->add( $member, $competition_id, $member_id, 'colour', array( 0, 200, 0 ) );
		$hash     = $this->file_hash( $entry_id );

		$failing_repo = new class() extends Images_Repository {
			public function update_category( int $id, string $category, string $filename ) {
				return new \WP_Error( 'db_update_failed', 'Could not update image category.' );
			}
		};
		$entries      = new Entries( $this->competitions_repo, $failing_repo, $this->members_repo );

		$this->assertWPError( $entries->change_categories( $member, $competition_id, $member_id, array( $entry_id => 'mono' ) ) );

		$this->assertSame( 'colour', $this->images_repo->find( $entry_id )->category );
		$this->assertSame( $hash, $this->file_hash( $entry_id ), "The image isn't back at the name its row has." );
	}

	public function test_a_member_cant_move_someone_elses_entry(): void {
		$competition_id = $this->create_competition( 'move-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$other_id       = $this->create_member( 'John Murphy', 'john@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$as_other            = $this->entries->change_categories( Actor::member( $other_id ), $competition_id, $member_id, array( $entry_id => 'mono' ) );
		$as_owner_of_the_set = $this->entries->change_categories( Actor::member( $other_id ), $competition_id, $other_id, array( $entry_id => 'mono' ) );

		$this->assertSame( 'permission_denied', $as_other->get_error_code() );
		// Someone else's entry looks the same as a missing one.
		$this->assertSame( 'submission_not_found', $as_owner_of_the_set->get_error_code() );
		$this->assertSame( 'colour', $this->images_repo->find( $entry_id )->category );
	}

	public function test_a_move_needs_an_entry_in_the_competition_and_a_category_it_has(): void {
		$competition_id = $this->create_competition( 'move-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$member         = Actor::member( $member_id );
		$entry_id       = $this->add( $member, $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$this->assertSame( 'submission_not_found', $this->entries->change_categories( $member, $competition_id, $member_id, array( 999999 => 'mono' ) )->get_error_code() );
		$this->assertSame( 'invalid_competition', $this->entries->change_categories( $member, 999999, $member_id, array( $entry_id => 'mono' ) )->get_error_code() );
		$this->assertSame( 'invalid_category', $this->entries->change_categories( $member, $competition_id, $member_id, array( $entry_id => 'nature' ) )->get_error_code() );
		$this->assertTrue( $this->entries->change_categories( $member, $competition_id, $member_id, array( $entry_id => 'colour' ) ) );
	}

	public function test_quota_status_counts_a_members_entries_in_each_category(): void {
		$competition_id = $this->create_competition( 'quota-comp', 2 );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$status = $this->entries->get_quota_status( $this->competitions_repo->find( $competition_id ), $member_id );

		$this->assertSame( 1, $status['colour']['current'] );
		$this->assertSame( 1, $status['colour']['remaining'] );
		$this->assertSame( 0, $status['mono']['current'] );
		$this->assertSame( 2, $status['mono']['remaining'] );
		$this->assertSame( 1, $this->entries->get_category_count( $competition_id, $member_id, 'colour' ) );
	}

	public function test_a_members_entries_come_with_their_urls(): void {
		$competition_id = $this->create_competition( 'list-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$entries = $this->entries->get_member_entries( $competition_id, $member_id );

		$this->assertCount( 1, $entries );
		$this->assertStringEndsWith( '/competitions/list-comp/colour/jane-doe-colour.jpg', $entries[0]->url );
		$this->assertStringContainsString( '/competitions/list-comp/colour/jane-doe-colour-thumb.jpg', $entries[0]->thumbnail_url );
		$this->assertSame( array(), $this->entries->get_member_entries( 999999, $member_id ) );
	}

	public function test_a_removal_whose_original_wont_delete_still_removes_the_entry(): void {
		$competition_id = $this->create_competition( 'remove-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$original       = $this->original_path( $entry_id );

		add_filter( 'wp_delete_file', array( $this, 'keep_originals' ) );
		$result = $this->entries->remove( Actor::member( $member_id ), $competition_id, $entry_id );
		remove_filter( 'wp_delete_file', array( $this, 'keep_originals' ) );

		$this->assertTrue( $result );
		$this->assertNull( $this->images_repo->find( $entry_id ) );
		$this->assertFileExists( $original );

		$logs = ( new Logs_Repository() )->find_by_competition( $competition_id, 50, 0, array( 'event_type' => 'entry_file_not_deleted' ) );
		$this->assertCount( 1, $logs );
		$this->assertSame( $original, json_decode( $logs[0]->metadata, true )['path'] );

		wp_delete_file( $original );
	}

	public function test_a_large_original_that_wont_delete_is_logged_at_full_size(): void {
		$competition_id = $this->create_competition( 'large-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		// Wider than WordPress's 2560px threshold, so the attachment's file is a -scaled copy.
		$entry_id = $this->entries->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', $this->photo( array( 200, 0, 0 ), 2600, 20 ) );
		$this->assertIsInt( $entry_id );
		$full_size = wp_get_original_image_path( (int) $this->images_repo->find( $entry_id )->original_attachment_id );
		$this->assertNotSame( $full_size, $this->original_path( $entry_id ) );

		add_filter( 'wp_delete_file', array( $this, 'keep_originals' ) );
		$this->assertTrue( $this->entries->remove( Actor::member( $member_id ), $competition_id, $entry_id ) );
		remove_filter( 'wp_delete_file', array( $this, 'keep_originals' ) );

		$logs  = ( new Logs_Repository() )->find_by_competition( $competition_id, 50, 0, array( 'event_type' => 'entry_file_not_deleted' ) );
		$paths = array_map(
			function ( $log ) {
				return json_decode( $log->metadata, true )['path'];
			},
			$logs
		);
		$this->assertContains( $full_size, $paths );

		foreach ( glob( dirname( $full_size ) . '/jane-doe-colour-original*' ) as $leftover ) {
			wp_delete_file( $leftover );
		}
	}

	public function test_an_admin_removes_a_competitions_entries_and_its_folder(): void {
		$competition_id = $this->create_competition( 'gone-comp' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$entry_ids      = array(
			$this->add( Actor::admin(), $competition_id, $jane_id, 'colour', array( 200, 0, 0 ) ),
			$this->add( Actor::admin(), $competition_id, $jane_id, 'mono', array( 90, 90, 90 ) ),
			$this->add( Actor::admin(), $competition_id, $john_id, 'colour', array( 0, 200, 0 ) ),
		);
		$files          = array_merge( ...array_map( array( $this, 'entry_files' ), $entry_ids ) );

		$this->assertTrue( $this->entries->remove_competition_entries( Actor::admin(), $competition_id ) );

		foreach ( $entry_ids as $entry_id ) {
			$this->assertNull( $this->images_repo->find( $entry_id ) );
		}
		foreach ( $files as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
		$this->assertDirectoryDoesNotExist( wp_upload_dir()['basedir'] . '/competitions/gone-comp' );
	}

	public function test_removing_a_competitions_entries_leaves_other_competitions_alone(): void {
		$competition_id = $this->create_competition( 'gone-comp' );
		$other_id       = $this->create_competition( 'kept-comp', 1, '2020-02-01 00:00:00' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$this->add( Actor::admin(), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$kept_id = $this->add( Actor::admin(), $other_id, $member_id, 'colour', array( 0, 0, 200 ) );

		$this->assertTrue( $this->entries->remove_competition_entries( Actor::admin(), $competition_id ) );

		$this->assertNotNull( $this->images_repo->find( $kept_id ) );
		$this->entry_files( $kept_id );
	}

	public function test_removing_a_competitions_entries_stops_at_a_row_that_wont_delete(): void {
		$competition_id = $this->create_competition( 'stuck-comp' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$this->add( Actor::admin(), $competition_id, $jane_id, 'colour', array( 200, 0, 0 ) );
		$stuck_id = $this->add( Actor::admin(), $competition_id, $john_id, 'colour', array( 0, 200, 0 ) );

		$failing_repo        = new class() extends Images_Repository {
			/**
			 * @var int
			 */
			public $stuck_id = 0;

			public function delete( int $id ) {
				return $id === $this->stuck_id ? new \WP_Error( 'db_delete_failed', 'Could not delete image record.' ) : parent::delete( $id );
			}
		};
		$failing_repo->stuck_id = $stuck_id;
		$entries                = new Entries( $this->competitions_repo, $failing_repo, $this->members_repo );

		$result = $entries->remove_competition_entries( Actor::admin(), $competition_id );

		$this->assertWPError( $result );
		$this->assertSame( 'db_delete_failed', $result->get_error_code() );
		$this->assertNotNull( $this->images_repo->find( $stuck_id ) );
		$this->entry_files( $stuck_id );
	}

	public function test_a_competition_without_a_slug_leaves_other_competitions_folders_alone(): void {
		$competition_id = $this->create_competition( '!!!' );
		$this->assertSame( '', $this->competitions_repo->find( $competition_id )->slug );

		$idle = wp_upload_dir()['basedir'] . '/competitions/idle-comp';
		wp_mkdir_p( $idle );
		$this->slugs[] = 'idle-comp';

		$this->assertTrue( $this->entries->remove_competition_entries( Actor::admin(), $competition_id ) );

		$this->assertDirectoryExists( $idle );
	}

	public function test_a_competition_folder_that_cant_be_read_doesnt_fail_the_removal(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root can read a folder with no permissions.' );
		}

		$competition_id = $this->create_competition( 'locked-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::admin(), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$locked         = dirname( $this->entry_path( $entry_id ), 2 ) . '/locked';
		mkdir( $locked );
		chmod( $locked, 0 );

		try {
			$result = $this->entries->remove_competition_entries( Actor::admin(), $competition_id );
		} finally {
			chmod( $locked, 0755 );
		}

		$this->assertTrue( $result );
		$this->assertNull( $this->images_repo->find( $entry_id ) );
		$this->assertDirectoryExists( $locked );
	}

	public function test_a_member_cant_remove_a_competitions_entries(): void {
		$competition_id = $this->create_competition( 'kept-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$result = $this->entries->remove_competition_entries( Actor::member( $member_id ), $competition_id );

		$this->assertWPError( $result );
		$this->assertSame( 'not_authorized', $result->get_error_code() );
		$this->entry_files( $entry_id );
	}

	public function test_an_admin_removes_a_members_entries_in_every_competition(): void {
		$competition_id = $this->create_competition( 'first-comp', 1, '2020-02-01 00:00:00' );
		$other_id       = $this->create_competition( 'second-comp' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$entry_ids      = array(
			$this->add( Actor::admin(), $competition_id, $jane_id, 'colour', array( 200, 0, 0 ) ),
			$this->add( Actor::admin(), $other_id, $jane_id, 'mono', array( 90, 90, 90 ) ),
		);
		$files          = array_merge( ...array_map( array( $this, 'entry_files' ), $entry_ids ) );
		$kept_id        = $this->add( Actor::admin(), $other_id, $john_id, 'colour', array( 0, 200, 0 ) );

		$this->assertTrue( $this->entries->remove_member_entries( Actor::admin(), $jane_id ) );

		foreach ( $entry_ids as $entry_id ) {
			$this->assertNull( $this->images_repo->find( $entry_id ) );
		}
		foreach ( $files as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
		$this->entry_files( $kept_id );
	}

	public function test_a_members_entry_whose_competition_is_gone_is_still_removed(): void {
		$member_id     = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$attachment_id = self::factory()->attachment->create();
		$entry_id      = $this->images_repo->create(
			array(
				'competition_id'         => 999999,
				'member_id'              => $member_id,
				'category'               => 'colour',
				'filename'               => 'jane-doe-colour.jpg',
				'original_attachment_id' => $attachment_id,
			)
		);

		$this->assertTrue( $this->entries->remove_member_entries( Actor::admin(), $member_id ) );

		$this->assertNull( $this->images_repo->find( $entry_id ) );
		$this->assertNull( get_post( $attachment_id ) );
	}

	public function test_a_member_cant_remove_their_own_entries_all_at_once(): void {
		$competition_id = $this->create_competition( 'kept-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$result = $this->entries->remove_member_entries( Actor::member( $member_id ), $member_id );

		$this->assertWPError( $result );
		$this->assertSame( 'not_authorized', $result->get_error_code() );
		$this->entry_files( $entry_id );
	}

	public function test_removing_an_entry_that_doesnt_exist_fails(): void {
		$competition_id = $this->create_competition( 'remove-comp' );

		$result = $this->entries->remove( Actor::admin(), $competition_id, 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_image', $result->get_error_code() );
	}

	public function test_discarding_originals_removes_each_one_and_keeps_the_entries(): void {
		$competition_id = $this->create_competition( 'discard-comp' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$entry_ids      = array(
			$this->add( Actor::admin(), $competition_id, $jane_id, 'colour', array( 200, 0, 0 ) ),
			$this->add( Actor::admin(), $competition_id, $john_id, 'colour', array( 0, 200, 0 ) ),
		);
		$originals      = array_map( array( $this, 'original_path' ), $entry_ids );

		$result = $this->entries->discard_originals( Actor::admin(), $competition_id );

		$this->assertSame(
			array(
				'discarded' => 2,
				'failed'    => 0,
			),
			$result
		);
		foreach ( $entry_ids as $entry_id ) {
			$entry = $this->images_repo->find( $entry_id );
			$this->assertNull( $entry->original_attachment_id );
			$this->assertFileExists( $this->entry_path( $entry_id ) );
			$this->assertFileExists( $this->entry_path( $entry_id, true ) );
		}
		foreach ( $originals as $original ) {
			$this->assertFileDoesNotExist( $original );
		}
	}

	public function test_an_original_that_wont_delete_keeps_its_id_and_is_counted_as_failed(): void {
		$competition_id = $this->create_competition( 'discard-comp' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$kept_id        = $this->add( Actor::admin(), $competition_id, $jane_id, 'colour', array( 200, 0, 0 ) );
		$discarded_id   = $this->add( Actor::admin(), $competition_id, $john_id, 'colour', array( 0, 200, 0 ) );
		$kept           = (int) $this->images_repo->find( $kept_id )->original_attachment_id;
		$refuse         = function ( $check, $post ) use ( $kept ) {
			return $post->ID === $kept ? false : $check;
		};

		add_filter( 'pre_delete_attachment', $refuse, 10, 2 );
		$result = $this->entries->discard_originals( Actor::admin(), $competition_id );
		remove_filter( 'pre_delete_attachment', $refuse, 10 );

		$this->assertSame(
			array(
				'discarded' => 1,
				'failed'    => 1,
			),
			$result
		);
		$this->assertSame( $kept, (int) $this->images_repo->find( $kept_id )->original_attachment_id );
		$this->assertNotNull( get_post( $kept ) );
		$this->assertFileExists( $this->original_path( $kept_id ) );
		$this->assertNull( $this->images_repo->find( $discarded_id )->original_attachment_id );

		$logs = ( new Logs_Repository() )->find_by_competition( $competition_id, 50, 0, array( 'event_type' => 'original_not_discarded' ) );
		$this->assertCount( 1, $logs );
		$this->assertSame( $kept, json_decode( $logs[0]->metadata, true )['attachment_id'] );
	}

	public function test_discarding_the_originals_just_exported_keeps_one_uploaded_since(): void {
		$competition_id = $this->create_competition( 'discard-comp' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$exported_id    = $this->add( Actor::admin(), $competition_id, $jane_id, 'colour', array( 200, 0, 0 ) );
		$exported       = $this->images_repo->get_original_attachment_ids( $competition_id );
		$uploaded_id    = $this->add( Actor::admin(), $competition_id, $john_id, 'colour', array( 0, 200, 0 ) );

		$result = $this->entries->discard_originals( Actor::admin(), $competition_id, $exported );

		$this->assertSame( 1, $result['discarded'] );
		$this->assertNull( $this->images_repo->find( $exported_id )->original_attachment_id );
		$this->assertFileExists( $this->original_path( $uploaded_id ) );
	}

	public function test_an_original_whose_id_wont_clear_is_counted_as_failed(): void {
		$competition_id = $this->create_competition( 'discard-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$this->add( Actor::admin(), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$failing_repo = new class() extends Images_Repository {
			public function clear_original_attachment_id( int $id ) {
				return new \WP_Error( 'db_update_failed', 'Could not clear the original attachment ID.' );
			}
		};
		$entries      = new Entries( $this->competitions_repo, $failing_repo, $this->members_repo );

		$this->assertSame(
			array(
				'discarded' => 0,
				'failed'    => 1,
			),
			$entries->discard_originals( Actor::admin(), $competition_id )
		);
	}

	public function test_an_original_already_gone_from_the_media_library_counts_as_discarded(): void {
		$competition_id = $this->create_competition( 'discard-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::admin(), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		wp_delete_attachment( (int) $this->images_repo->find( $entry_id )->original_attachment_id, true );

		$this->assertSame(
			array(
				'discarded' => 1,
				'failed'    => 0,
			),
			$this->entries->discard_originals( Actor::admin(), $competition_id )
		);
		$this->assertNull( $this->images_repo->find( $entry_id )->original_attachment_id );
	}

	public function test_originals_cant_be_discarded_while_a_category_is_accepting_votes(): void {
		$competition_id = $this->create_competition( 'voting-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::admin(), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$original       = $this->original_path( $entry_id );
		Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_VOTING );

		$result = $this->entries->discard_originals( Actor::admin(), $competition_id );

		$this->assertWPError( $result );
		$this->assertSame( 'voting_open', $result->get_error_code() );
		$this->assertNotNull( $this->images_repo->find( $entry_id )->original_attachment_id );
		$this->assertFileExists( $original );

		( new Competition_Workflow() )->close_voting( $competition_id, 'colour' );

		$this->assertSame( 1, $this->entries->discard_originals( Actor::admin(), $competition_id )['discarded'] );
	}

	public function test_a_member_cant_discard_originals(): void {
		$competition_id = $this->create_competition( 'kept-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$result = $this->entries->discard_originals( Actor::member( $member_id ), $competition_id );

		$this->assertWPError( $result );
		$this->assertSame( 'not_authorized', $result->get_error_code() );
		$this->entry_files( $entry_id );
	}

	/**
	 * Filter for wp_delete_file that refuses to delete originals.
	 *
	 * @param string $file Path about to be deleted.
	 * @return string The path, or '' to skip the delete.
	 */
	public function keep_originals( string $file ): string {
		return false !== strpos( basename( $file ), '-original' ) ? '' : $file;
	}

	/**
	 * Filter for wp_delete_file that refuses to delete entry images, but not thumbnails.
	 *
	 * @param string $file Path about to be deleted.
	 * @return string The path, or '' to skip the delete.
	 */
	public function keep_entry_images( string $file ): string {
		return false !== strpos( $file, '/competitions/' ) && false === strpos( $file, '-thumb.' ) ? '' : $file;
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
	 * @param string $name   Member name.
	 * @param string $email  Member email.
	 * @param int    $active 1 for an active member, 0 for an inactive one.
	 * @return int Member ID.
	 */
	private function create_member( string $name, string $email, int $active = 1 ): int {
		return (int) $this->members_repo->create(
			array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => $active,
			)
		);
	}

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
	 * Add an entry that must succeed, and return its ID.
	 *
	 * @param Actor  $actor          Who is adding it.
	 * @param int    $competition_id Competition ID.
	 * @param int    $member_id      Member ID.
	 * @param string $category       Category slug.
	 * @param int[]  $rgb            Fill colour.
	 * @return int Entry ID.
	 */
	private function add( Actor $actor, int $competition_id, int $member_id, string $category, array $rgb ): int {
		$result = $this->entries->add( $actor, $competition_id, $member_id, $category, $this->photo( $rgb ) );

		$this->assertIsInt( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		return $result;
	}

	/**
	 * Path of the entry's competition image, read through its stored row.
	 *
	 * Entry files live at uploads/competitions/<slug>/<category>/<filename>.
	 *
	 * @param int  $entry_id  Entry ID.
	 * @param bool $thumbnail The thumbnail's path instead.
	 * @return string
	 */
	private function entry_path( int $entry_id, bool $thumbnail = false ): string {
		$entry       = $this->images_repo->find( $entry_id );
		$competition = $this->competitions_repo->find( (int) $entry->competition_id );
		$filename    = $thumbnail ? preg_replace( '/\.jpg$/', '-thumb.jpg', $entry->filename ) : $entry->filename;

		return wp_upload_dir()['basedir'] . '/competitions/' . $competition->slug . '/' . $entry->category . '/' . $filename;
	}

	/**
	 * Paths of the entry's image, thumbnail and original, which must all exist.
	 *
	 * @param int $entry_id Entry ID.
	 * @return string[]
	 */
	private function entry_files( int $entry_id ): array {
		$files = array( $this->entry_path( $entry_id ), $this->entry_path( $entry_id, true ), $this->original_path( $entry_id ) );

		foreach ( $files as $file ) {
			$this->assertFileExists( $file );
		}

		return $files;
	}

	/**
	 * Hash of the entry's competition image file.
	 *
	 * @param int  $entry_id  Entry ID.
	 * @param bool $thumbnail Hash the entry's thumbnail instead.
	 * @return string
	 */
	private function file_hash( int $entry_id, bool $thumbnail = false ): string {
		$path = $this->entry_path( $entry_id, $thumbnail );

		$this->assertFileExists( $path );

		return md5_file( $path );
	}

	/**
	 * Path of the entry's media library original.
	 *
	 * @param int $entry_id Entry ID.
	 * @return string
	 */
	private function original_path( int $entry_id ): string {
		$path = get_attached_file( (int) $this->images_repo->find( $entry_id )->original_attachment_id );

		$this->assertIsString( $path );

		return $path;
	}
}
