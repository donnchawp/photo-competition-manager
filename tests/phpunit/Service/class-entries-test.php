<?php
/**
 * Tests for the Entries module, through its interface with real files.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Photo_Uploads;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

class Entries_Test extends WP_UnitTestCase {

	use Photo_Uploads;

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

	public function setUp(): void {
		parent::setUp();

		$this->accept_temp_files_as_uploads();

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

		$this->remove_tmp_files();

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

	public function test_a_member_whose_submission_confirmation_is_on_gets_one_for_each_entry(): void {
		update_option( 'photo_comp_email_templates', array( 'submission_confirmed' => array( 'enabled' => true ) ) );
		$mails = array();
		add_filter(
			'wp_mail',
			function ( $atts ) use ( &$mails ) {
				$mails[] = $atts;
				return $atts;
			}
		);
		$competition_id = $this->create_competition( 'confirm-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$this->assertCount( 1, $mails );
		$this->assertSame( 'jane@example.com', $mails[0]['to'] );
		$this->assertStringContainsString( 'Your image has been uploaded to confirm-comp in the Colour category.', $mails[0]['message'] );
		$this->assertStringContainsString( 'You are entering in the Beginner grade.', $mails[0]['message'] );
		$this->assertStringContainsString( 'You have uploaded 1 of 1 images for this category.', $mails[0]['message'] );

		$logs = ( new Logs_Repository() )->find_by_competition( $competition_id, 50, 0, array( 'event_type' => 'submission_confirmed' ) );
		$this->assertCount( 1, $logs );
	}

	public function test_member_grade_tag_shows_label_not_slug(): void {
		update_option( 'photo_comp_email_templates', array( 'submission_confirmed' => array( 'enabled' => true ) ) );
		$mails = array();
		add_filter(
			'wp_mail',
			function ( $atts ) use ( &$mails ) {
				$mails[] = $atts;
				return $atts;
			}
		);
		$competition_id = $this->create_competition( 'label-comp' );
		$type_id        = Type_Fixtures::create( 'Cups', array( 'beginner' => 'Novice Cup' ) );
		// Moved directly, so the competition keeps its own categories.
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'photocomp_competitions', array( 'type_id' => $type_id ), array( 'id' => $competition_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$member_id = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$this->assertStringContainsString( 'You are entering in the Novice Cup grade.', $mails[0]['message'] );
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

	public function test_an_admin_cant_add_an_entry_to_a_category_with_votes(): void {
		$competition_id = $this->create_competition( 'late-entry-comp', 2 );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$voter_id       = $this->create_member( 'John Roe', 'john@example.com' );
		$voted_id       = $this->add( Actor::member( $voter_id ), $competition_id, $voter_id, 'colour', array( 0, 0, 200 ) );
		Workflow_Fixtures::close_uploads( $competition_id );
		( new Votes_Repository() )->create( $competition_id, 'colour', 'A Voter', $voted_id, 5 );

		$result = $this->entries->add( Actor::admin(), $competition_id, $member_id, 'colour', $this->photo( array( 200, 0, 0 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'category_has_votes', $result->get_error_code() );
		$this->assertSame( array(), glob( wp_upload_dir()['basedir'] . '/competitions/late-entry-comp/colour/jane-doe*' ) );
		$this->assertCount( 1, $this->images_repo->find_uploads( $competition_id ) );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => 'attachment',
					'post_status' => 'any',
					'meta_key'    => '_photo_comp_member', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'  => 'jane-doe', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			)
		);
	}

	public function test_an_admin_adds_an_entry_to_a_category_thats_been_previewed_while_another_has_votes(): void {
		$competition_id = $this->create_competition( 'previewed-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$voter_id       = $this->create_member( 'John Roe', 'john@example.com' );
		$voted_id       = $this->add( Actor::member( $voter_id ), $competition_id, $voter_id, 'mono', array( 0, 200, 0 ) );
		Workflow_Fixtures::close_uploads( $competition_id );
		Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_PREVIEWED );
		( new Votes_Repository() )->create( $competition_id, 'mono', 'A Voter', $voted_id, 5 );

		$entry_id = $this->add( Actor::admin(), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$this->assertSame( 'colour', $this->images_repo->find( $entry_id )->category );
	}

	public function test_an_admin_cant_add_an_entry_to_a_category_thats_voting_before_anyone_votes(): void {
		$competition_id = $this->create_competition( 'unvoted-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$voter_id       = $this->create_member( 'John Roe', 'john@example.com' );
		$this->add( Actor::member( $voter_id ), $competition_id, $voter_id, 'colour', array( 0, 0, 200 ) );
		Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_VOTING );

		$result = $this->entries->add( Actor::admin(), $competition_id, $member_id, 'colour', $this->photo( array( 200, 0, 0 ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'voting_started', $result->get_error_code() );
		$this->assertSame( array(), glob( wp_upload_dir()['basedir'] . '/competitions/unvoted-comp/colour/jane-doe*' ) );
		$this->assertCount( 1, $this->images_repo->find_uploads( $competition_id ) );
	}

	public function test_a_category_thats_voting_and_has_votes_says_voting_has_started(): void {
		$competition_id = $this->create_competition( 'voting-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$voter_id       = $this->create_member( 'John Roe', 'john@example.com' );
		$voted_id       = $this->add( Actor::member( $voter_id ), $competition_id, $voter_id, 'colour', array( 0, 0, 200 ) );
		Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_VOTING );
		( new Votes_Repository() )->create( $competition_id, 'colour', 'A Voter', $voted_id, 5 );

		$result = $this->entries->add( Actor::admin(), $competition_id, $member_id, 'colour', $this->photo( array( 200, 0, 0 ) ) );

		$this->assertSame( 'voting_started', $result->get_error_code() );
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

	public function test_an_entry_cant_move_into_a_category_that_kept_its_votes(): void {
		$competition_id = $this->create_competition( 'kept-votes-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$voter_id       = $this->create_member( 'John Roe', 'john@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$voted_id       = $this->add( Actor::member( $voter_id ), $competition_id, $voter_id, 'mono', array( 0, 0, 200 ) );
		Workflow_Fixtures::set_stage( $competition_id, 'mono', Competition_Workflow::STAGE_VOTING );
		( new Votes_Repository() )->create( $competition_id, 'mono', 'A Voter', $voted_id, 5 );
		$this->assertTrue( ( new Competition_Workflow( $this->competitions_repo ) )->reset_category( $competition_id, 'mono', false ) );

		$result = $this->entries->change_categories( Actor::admin(), $competition_id, $member_id, array( $entry_id => 'mono' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'category_has_votes', $result->get_error_code() );
		$this->assertSame( 'colour', $this->images_repo->find( $entry_id )->category );
		$this->assertFileExists( $this->entry_path( $entry_id ) );
	}

	public function test_an_entry_can_move_into_a_category_reset_with_its_votes_cleared(): void {
		$competition_id = $this->create_competition( 'cleared-votes-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$voter_id       = $this->create_member( 'John Roe', 'john@example.com' );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$voted_id       = $this->add( Actor::member( $voter_id ), $competition_id, $voter_id, 'mono', array( 0, 0, 200 ) );
		Workflow_Fixtures::set_stage( $competition_id, 'mono', Competition_Workflow::STAGE_VOTING );
		( new Votes_Repository() )->create( $competition_id, 'mono', 'A Voter', $voted_id, 5 );
		$this->assertTrue( ( new Competition_Workflow( $this->competitions_repo ) )->reset_category( $competition_id, 'mono', true ) );

		$result = $this->entries->change_categories( Actor::admin(), $competition_id, $member_id, array( $entry_id => 'mono' ) );

		$this->assertTrue( $result );
		$this->assertSame( 'mono', $this->images_repo->find( $entry_id )->category );
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
	}

	public function test_a_members_entries_come_with_their_urls(): void {
		$competition_id = $this->create_competition( 'list-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		$entries = $this->entries->get_member_entries( $competition_id, $member_id );

		$this->assertCount( 1, $entries );
		$this->assertSame( $this->entries->urls( $this->competitions_repo->find( $competition_id ), $entries[0] ), $entries[0]->urls );
		$this->assertStringContainsString( '/competitions/list-comp/colour/jane-doe-colour-thumb.jpg?v=', $entries[0]->urls['thumb'] );
		$this->assertSame( array(), $this->entries->get_member_entries( 999999, $member_id ) );
	}

	public function test_an_entrys_urls_change_when_its_replaced_by_one_with_the_same_filename(): void {
		$competition_id = $this->create_competition( 'replace-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$competition    = $this->competitions_repo->find( $competition_id );
		$first_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );

		// Uploaded an hour ago, so the replacement can't share its modified time.
		touch( $this->entry_path( $first_id ), time() - HOUR_IN_SECONDS );
		touch( $this->entry_path( $first_id, true ), time() - HOUR_IN_SECONDS );
		$before = $this->entries->urls( $competition, $this->images_repo->find( $first_id ) );

		$this->entries->remove( Actor::member( $member_id ), $competition_id, $first_id );
		$second_id = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 0, 0, 200 ) );
		$second    = $this->images_repo->find( $second_id );
		$after     = $this->entries->urls( $competition, $second );

		$this->assertSame( 'jane-doe-colour.jpg', $second->filename );
		$this->assertStringContainsString( '/competitions/replace-comp/colour/jane-doe-colour.jpg?v=', $after['full'] );
		$this->assertStringContainsString( '/competitions/replace-comp/colour/jane-doe-colour-thumb.jpg?v=', $after['thumb'] );
		$this->assertNotSame( $before['full'], $after['full'] );
		$this->assertNotSame( $before['thumb'], $after['thumb'] );
	}

	public function test_a_missing_image_or_thumbnail_has_no_url(): void {
		$competition_id = $this->create_competition( 'missing-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$competition    = $this->competitions_repo->find( $competition_id );
		$entry_id       = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'colour', array( 200, 0, 0 ) );
		$entry          = $this->images_repo->find( $entry_id );

		wp_delete_file( $this->entry_path( $entry_id, true ) );
		$urls = $this->entries->urls( $competition, $entry );
		$this->assertNotSame( '', $urls['full'] );
		$this->assertSame( '', $urls['thumb'] );

		wp_delete_file( $this->entry_path( $entry_id ) );
		$this->assertSame(
			array(
				'full'  => '',
				'thumb' => '',
			),
			$this->entries->urls( $competition, $entry )
		);
	}

	public function test_an_entry_without_a_filename_has_no_urls(): void {
		$competition = $this->competitions_repo->find( $this->create_competition( 'nameless-comp' ) );
		wp_mkdir_p( wp_upload_dir()['basedir'] . '/competitions/nameless-comp/colour' );

		$this->assertSame(
			array(
				'full'  => '',
				'thumb' => '',
			),
			$this->entries->urls(
				$competition,
				(object) array(
					'category' => 'colour',
					'filename' => '',
				)
			)
		);
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

	/**
	 * Create an open interclub competition with colour and mono panels.
	 *
	 * @param string $slug               Competition slug.
	 * @param int    $quota              Images in each panel.
	 * @param int    $substitutes        Substitutes allowed beside each panel.
	 * @param int    $photographer_limit Most images per photographer in a panel; 0 for no limit.
	 * @return int Competition ID.
	 */
	private function create_interclub_competition( string $slug, int $quota, int $substitutes = 0, int $photographer_limit = 0 ): int {
		return $this->create_competition(
			$slug,
			$quota,
			null,
			array(
				'enabled'            => true,
				'substitutes'        => $substitutes,
				'photographer_limit' => $photographer_limit,
			)
		);
	}

	/**
	 * Try to add a club's image to a panel.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param int    $club_id        The club's member ID.
	 * @param string $photographer   Photographer's name.
	 * @param bool   $substitute     Whether it's a substitute.
	 * @param string $category       Category slug.
	 * @return int|\WP_Error Entry ID, or why it was refused.
	 */
	private function add_to_panel( int $competition_id, int $club_id, string $photographer, bool $substitute = false, string $category = 'colour' ) {
		static $shade = 0;
		++$shade;

		return $this->entries->add(
			Actor::member( $club_id ),
			$competition_id,
			$club_id,
			$category,
			$this->photo( array( $shade % 256, 100, 50 ) ),
			array(
				'photographer' => $photographer,
				'substitute'   => $substitute,
			)
		);
	}

	public function test_a_club_enters_up_to_its_panel_and_no_more(): void {
		$competition_id = $this->create_interclub_competition( 'panel-comp', 2 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$this->assertIsInt( $this->add_to_panel( $competition_id, $club_id, 'Ann' ) );
		$this->assertIsInt( $this->add_to_panel( $competition_id, $club_id, 'Bob' ) );

		$result = $this->add_to_panel( $competition_id, $club_id, 'Cal' );

		$this->assertWPError( $result );
		$this->assertSame( 'quota_exceeded', $result->get_error_code() );
	}

	public function test_a_club_uploads_up_to_its_substitutes_beside_a_full_panel(): void {
		$competition_id = $this->create_interclub_competition( 'subs-comp', 1, 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$this->assertIsInt( $this->add_to_panel( $competition_id, $club_id, 'Ann' ) );
		$substitute_id = $this->add_to_panel( $competition_id, $club_id, 'Bob', true );
		$this->assertIsInt( $substitute_id );

		$result = $this->add_to_panel( $competition_id, $club_id, 'Cal', true );

		$this->assertWPError( $result );
		$this->assertSame( 'substitutes_full', $result->get_error_code() );
		$substitute = $this->images_repo->find( $substitute_id );
		$this->assertSame( 'substitute', $substitute->entry_status );
		$this->assertSame( 'Bob', $substitute->photographer );
	}

	public function test_a_substitute_is_refused_without_substitutes_in_the_type(): void {
		$competition_id = $this->create_interclub_competition( 'no-subs-comp', 2 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );

		$result = $this->add_to_panel( $competition_id, $club_id, 'Ann', true );

		$this->assertWPError( $result );
		$this->assertSame( 'no_substitutes', $result->get_error_code() );
	}

	public function test_an_individual_competition_takes_no_substitutes(): void {
		$competition_id = $this->create_competition( 'individual-comp', 2 );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$result = $this->add_to_panel( $competition_id, $member_id, 'Jane Doe', true );

		$this->assertWPError( $result );
		$this->assertSame( 'no_substitutes', $result->get_error_code() );
	}

	public function test_a_club_entry_needs_a_photographer(): void {
		$competition_id = $this->create_interclub_competition( 'named-comp', 2 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );

		$result = $this->add_to_panel( $competition_id, $club_id, '   ' );

		$this->assertWPError( $result );
		$this->assertSame( 'photographer_missing', $result->get_error_code() );
	}

	public function test_a_photographer_cant_have_more_images_in_a_panel_than_the_limit(): void {
		$competition_id = $this->create_interclub_competition( 'limit-comp', 5, 0, 2 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$this->assertIsInt( $this->add_to_panel( $competition_id, $club_id, 'Ann Murphy' ) );
		$this->assertIsInt( $this->add_to_panel( $competition_id, $club_id, ' ann murphy ' ) );
		$this->assertIsInt( $this->add_to_panel( $competition_id, $club_id, 'Ann Murphy', false, 'mono' ) );

		$result = $this->add_to_panel( $competition_id, $club_id, 'ANN MURPHY' );

		$this->assertWPError( $result );
		$this->assertSame( 'photographer_limit', $result->get_error_code() );
	}

	public function test_a_photographers_substitutes_dont_count_towards_the_limit(): void {
		$competition_id = $this->create_interclub_competition( 'limit-subs-comp', 5, 2, 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$this->assertIsInt( $this->add_to_panel( $competition_id, $club_id, 'Ann' ) );

		$this->assertIsInt( $this->add_to_panel( $competition_id, $club_id, 'Ann', true ) );
	}

	/**
	 * Each category's numbers, smallest first.
	 *
	 * @param int $competition_id Competition ID.
	 * @return array<string, int[]>
	 */
	private function numbers_by_category( int $competition_id ): array {
		$numbers = array();
		foreach ( $this->images_repo->find_uploads( $competition_id ) as $image ) {
			$numbers[ $image->category ][] = (int) $image->random_number;
		}
		foreach ( $numbers as $category => $list ) {
			sort( $list );
			$numbers[ $category ] = $list;
		}
		ksort( $numbers );

		return $numbers;
	}

	public function test_each_image_in_an_interclub_category_gets_its_own_number(): void {
		$competition_id = $this->create_interclub_competition( 'numbers-comp', 2 );
		$lee_id         = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$bandon_id      = $this->create_member( 'Bandon Camera Club', 'bandon@example.com' );
		$this->add_to_panel( $competition_id, $lee_id, 'Ann' );
		$this->add_to_panel( $competition_id, $lee_id, 'Bob' );
		$this->add_to_panel( $competition_id, $bandon_id, 'Cat' );
		$this->add_to_panel( $competition_id, $lee_id, 'Dan', false, 'mono' );

		$this->assertSame(
			array(
				'colour' => array( 1, 2, 3 ),
				'mono'   => array( 1 ),
			),
			$this->numbers_by_category( $competition_id )
		);
	}

	public function test_interclub_numbers_stay_1_to_n_when_an_image_moves_or_is_removed(): void {
		$competition_id = $this->create_interclub_competition( 'numbers-move-comp', 3 );
		$lee_id         = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		foreach ( array( 'Ann', 'Bob', 'Cat' ) as $photographer ) {
			$this->add_to_panel( $competition_id, $lee_id, $photographer );
		}
		$this->add_to_panel( $competition_id, $lee_id, 'Dan', false, 'mono' );

		// Each time the image numbered 1 leaves, so the others must close the gap.
		$this->assertTrue( $this->entries->change_categories( Actor::member( $lee_id ), $competition_id, $lee_id, array( $this->numbered( $competition_id, 'colour', 1 ) => 'mono' ) ) );
		$this->assertTrue( $this->entries->remove( Actor::member( $lee_id ), $competition_id, $this->numbered( $competition_id, 'colour', 1 ) ) );

		$this->assertSame(
			array(
				'colour' => array( 1 ),
				'mono'   => array( 1, 2 ),
			),
			$this->numbers_by_category( $competition_id )
		);
	}

	/**
	 * The ID of the image with a given number in a category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @param int    $number         Its number.
	 * @return int
	 */
	private function numbered( int $competition_id, string $category, int $number ): int {
		foreach ( $this->images_repo->find_uploads( $competition_id, $category ) as $image ) {
			if ( $number === (int) $image->random_number ) {
				return (int) $image->id;
			}
		}

		$this->fail( "No image numbered {$number} in {$category}." );
	}

	public function test_deleting_a_club_with_several_images_in_a_category_keeps_the_numbers_1_to_n(): void {
		$competition_id = $this->create_interclub_competition( 'numbers-delete-club-comp', 2 );
		$lee_id         = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$bandon_id      = $this->create_member( 'Bandon Camera Club', 'bandon@example.com' );
		// Lee's images below and above one of Bandon's, so removing the first moves the second.
		foreach ( array( array( $lee_id, 1 ), array( $bandon_id, 2 ), array( $lee_id, 3 ), array( $bandon_id, 4 ) ) as list( $member_id, $number ) ) {
			$this->images_repo->update_number( $this->seed_entry( $competition_id, $member_id ), $number );
		}

		$this->assertTrue( $this->entries->remove_member_entries( Actor::admin(), $lee_id ) );

		$this->assertSame( array( 'colour' => array( 1, 2 ) ), $this->numbers_by_category( $competition_id ) );
	}

	public function test_an_interclub_upload_that_fails_to_save_leaves_the_numbers_1_to_n(): void {
		global $wpdb;
		$competition_id = $this->create_interclub_competition( 'numbers-failed-upload-comp', 5 );
		$lee_id         = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$this->add_to_panel( $competition_id, $lee_id, 'Ann' );
		$this->add_to_panel( $competition_id, $lee_id, 'Bob' );
		$break_insert = static function ( $query ) {
			return 0 === strpos( $query, 'INSERT' ) && false !== strpos( $query, 'photocomp_images' )
				? 'INSERT INTO photocomp_no_such_table (id) VALUES (1)'
				: $query;
		};
		add_filter( 'query', $break_insert );
		$suppress = $wpdb->suppress_errors( true );

		// Each failed upload would have opened a slot at random, so try a few times.
		$results = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$results[] = $this->add_to_panel( $competition_id, $lee_id, 'Cat' );
		}

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_insert );
		foreach ( $results as $result ) {
			$this->assertWPError( $result );
		}
		$this->assertSame( array( 'colour' => array( 1, 2 ) ), $this->numbers_by_category( $competition_id ) );
	}

	public function test_numbers_cant_be_regenerated_while_a_category_has_votes(): void {
		$competition_id = $this->create_interclub_competition( 'renumber-votes-comp', 2 );
		$lee_id         = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$entry_id       = $this->seed_entry( $competition_id, $lee_id );
		// A category reset keeping its votes: voting isn't under way, but the votes still know the numbers.
		( new Votes_Repository() )->create( $competition_id, 'colour', 'A Voter', $entry_id, 5 );

		$result = $this->entries->regenerate_numbers( Actor::admin(), $competition_id );

		$this->assertWPError( $result );
		$this->assertSame( 'category_has_votes', $result->get_error_code() );
	}

	public function test_renumbering_an_interclub_competition_gives_every_image_its_own_number(): void {
		$competition_id = $this->create_interclub_competition( 'renumber-comp', 2 );
		$lee_id         = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$bandon_id      = $this->create_member( 'Bandon Camera Club', 'bandon@example.com' );
		$this->seed_entry( $competition_id, $lee_id );
		$this->seed_entry( $competition_id, $lee_id, 'substitute' );
		$this->seed_entry( $competition_id, $bandon_id );

		$this->assertTrue( $this->entries->regenerate_numbers( Actor::admin(), $competition_id ) );

		$numbers = array_map( 'intval', array_column( $this->images_repo->find_uploads( $competition_id ), 'random_number' ) );
		sort( $numbers );
		$this->assertSame( array( 1, 2, 3 ), $numbers );
	}

	public function test_numbers_cant_be_regenerated_once_voting_has_started(): void {
		$competition_id = $this->create_interclub_competition( 'renumber-voting-comp', 2 );
		$lee_id         = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$entry_id       = $this->seed_entry( $competition_id, $lee_id );
		Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_VOTING );

		$result = $this->entries->regenerate_numbers( Actor::admin(), $competition_id );

		$this->assertWPError( $result );
		$this->assertSame( 'voting_started', $result->get_error_code() );
		$this->assertSame( 1, (int) $this->images_repo->find( $entry_id )->random_number );
	}

	public function test_renumbering_an_individual_competition_keeps_one_number_per_member(): void {
		$competition_id = $this->create_competition( 'renumber-members-comp', 2 );
		$ann_id         = $this->create_member( 'Ann', 'ann@example.com' );
		$bob_id         = $this->create_member( 'Bob', 'bob@example.com' );
		$this->seed_entry( $competition_id, $ann_id );
		$this->seed_entry( $competition_id, $ann_id );
		$this->seed_entry( $competition_id, $bob_id );

		$this->assertTrue( $this->entries->regenerate_numbers( Actor::admin(), $competition_id ) );

		$this->assertCount( 1, array_unique( array_column( $this->images_repo->find_uploads( $competition_id, null, $ann_id ), 'random_number' ) ) );
		$this->assertCount( 2, array_unique( array_column( $this->images_repo->find_uploads( $competition_id ), 'random_number' ) ) );
	}

	public function test_a_disqualified_image_frees_its_place_in_the_panel(): void {
		$competition_id = $this->create_interclub_competition( 'dq-comp', 1, 0, 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$this->images_repo->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $club_id,
				'category'       => 'colour',
				'filename'       => 'disqualified.jpg',
				'photographer'   => 'Ann',
				'entry_status'   => 'disqualified',
			)
		);

		$this->assertIsInt( $this->add_to_panel( $competition_id, $club_id, 'Ann' ) );
	}

	public function test_moving_an_image_cant_break_the_photographer_limit(): void {
		$competition_id = $this->create_interclub_competition( 'move-limit-comp', 3, 0, 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$colour         = $this->add_to_panel( $competition_id, $club_id, 'Ann' );
		$this->assertIsInt( $this->add_to_panel( $competition_id, $club_id, 'Ann', false, 'mono' ) );

		$result = $this->entries->change_categories( Actor::member( $club_id ), $competition_id, $club_id, array( $colour => 'mono' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'photographer_limit', $result->get_error_code() );
	}

	public function test_a_disqualified_image_takes_no_place_when_an_entry_moves_in(): void {
		$competition_id = $this->create_competition( 'move-dq-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$mono           = $this->add( Actor::member( $member_id ), $competition_id, $member_id, 'mono', array( 0, 0, 200 ) );
		$this->images_repo->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'disqualified.jpg',
				'entry_status'   => 'disqualified',
			)
		);

		$this->assertTrue( $this->entries->change_categories( Actor::member( $member_id ), $competition_id, $member_id, array( $mono => 'colour' ) ) );
	}

	public function test_quota_status_counts_a_panel_and_its_substitutes_apart(): void {
		$competition_id = $this->create_interclub_competition( 'status-comp', 2, 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$this->assertIsInt( $this->add_to_panel( $competition_id, $club_id, 'Ann' ) );
		$this->images_repo->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $club_id,
				'category'       => 'colour',
				'filename'       => 'disqualified.jpg',
				'entry_status'   => 'disqualified',
			)
		);

		$status = $this->entries->get_quota_status( $this->competitions_repo->find( $competition_id ), $club_id )['colour'];

		$this->assertSame( 1, $status['current'] );
		$this->assertSame( 2, $status['remaining'] );
		$this->assertSame( 1, $status['entered_remaining'] );
		$this->assertSame( 1, $status['substitutes_remaining'] );
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

	public function test_removing_a_competitions_entries_removes_its_substitutes_and_disqualified_images(): void {
		global $wpdb;
		$competition_id = $this->create_competition( 'gone-comp', 2 );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_ids      = array();
		foreach ( array( 'substitute', 'disqualified' ) as $i => $status ) {
			$entry_ids[] = $this->add( Actor::admin(), $competition_id, $member_id, 'colour', array( 200, $i, 0 ) );
			// No public way to set a status yet: the upload form and admin screen come later.
			$wpdb->update( $wpdb->prefix . 'photocomp_images', array( 'entry_status' => $status ), array( 'id' => end( $entry_ids ) ) );
		}

		$this->assertTrue( $this->entries->remove_competition_entries( Actor::admin(), $competition_id ) );

		foreach ( $entry_ids as $entry_id ) {
			$this->assertNull( $this->images_repo->find( $entry_id ) );
		}
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

		// Logged once, by attachment, since deleting its files by hand wouldn't remove it.
		$logs = ( new Logs_Repository() )->find_by_competition( $competition_id );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'original_not_deleted', $logs[0]->event_type );
		$this->assertSame( $kept, json_decode( $logs[0]->metadata, true )['attachment_id'] );
		$this->assertSame( array( $this->original_path( $kept_id ) ), json_decode( $logs[0]->metadata, true )['paths'] );
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
	 * Record an entry straight into the repository, without its files.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param int    $member_id      Member ID.
	 * @param string $entry_status   Entry status.
	 * @param string $photographer   Photographer's name.
	 * @return int Entry ID.
	 */
	private function seed_entry( int $competition_id, int $member_id, string $entry_status = 'entered', string $photographer = 'Ann' ): int {
		static $count = 0;
		++$count;

		return (int) $this->images_repo->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'seeded-' . $count . '.jpg',
				'photographer'   => $photographer,
				'entry_status'   => $entry_status,
			)
		);
	}

	public function test_an_admin_disqualifies_an_entry_and_it_leaves_the_competition(): void {
		$competition_id = $this->create_competition( 'dq-admin-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->seed_entry( $competition_id, $member_id );

		$this->assertTrue( $this->entries->set_entry_status( Actor::admin(), $competition_id, $entry_id, 'disqualified' ) );

		$this->assertSame( array(), $this->images_repo->find_entered( $competition_id ) );
		$this->assertSame( 'disqualified', $this->images_repo->find( $entry_id )->entry_status );
	}

	public function test_an_entry_can_be_disqualified_after_its_category_has_votes(): void {
		$competition_id = $this->create_competition( 'dq-voted-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->seed_entry( $competition_id, $member_id );
		( new Votes_Repository() )->create_anonymous( $competition_id, 'colour', 111, $entry_id, 5 );
		Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_VOTING );

		$this->assertTrue( $this->entries->set_entry_status( Actor::admin(), $competition_id, $entry_id, 'disqualified' ) );
	}

	public function test_no_entry_changes_status_once_results_are_published(): void {
		$competition_id = $this->create_competition( 'dq-published-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->seed_entry( $competition_id, $member_id );
		Workflow_Fixtures::publish_results( $competition_id );

		$result = $this->entries->set_entry_status( Actor::admin(), $competition_id, $entry_id, 'disqualified' );

		$this->assertWPError( $result );
		$this->assertSame( 'results_recorded', $result->get_error_code() );
		$this->assertStringContainsString( 'Hide its results first.', $result->get_error_message() );
		$this->assertSame( 'entered', $this->images_repo->find( $entry_id )->entry_status );
	}

	public function test_a_closed_competitions_refusal_says_to_move_its_close_date(): void {
		$competition_id = $this->create_competition( 'dq-closed-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->seed_entry( $competition_id, $member_id );
		$this->competitions_repo->update(
			$competition_id,
			array(
				'open_date'  => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ),
				'close_date' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);

		$result = $this->entries->set_entry_status( Actor::admin(), $competition_id, $entry_id, 'disqualified' );

		// Its results were never published, so there are none to hide: only a later close date reopens it.
		$this->assertWPError( $result );
		$this->assertSame( 'results_recorded', $result->get_error_code() );
		$this->assertSame( 'This competition has closed, so its entries can\'t change status. To change one, move its close date into the future first.', $result->get_error_message() );
		$this->assertSame( 'entered', $this->images_repo->find( $entry_id )->entry_status );
	}

	public function test_promoting_a_substitute_fills_a_disqualified_entrys_place(): void {
		$competition_id = $this->create_interclub_competition( 'promote-comp', 1, 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$entered        = $this->seed_entry( $competition_id, $club_id );
		$substitute     = $this->seed_entry( $competition_id, $club_id, 'substitute', 'Bob' );

		$this->assertTrue( $this->entries->set_entry_status( Actor::admin(), $competition_id, $entered, 'disqualified' ) );
		$this->assertTrue( $this->entries->set_entry_status( Actor::admin(), $competition_id, $substitute, 'entered' ) );

		$this->assertSame( array( $substitute ), array_map( 'intval', array_column( $this->images_repo->find_entered( $competition_id ), 'id' ) ) );
	}

	public function test_a_substitute_cant_be_promoted_into_a_full_panel(): void {
		$competition_id = $this->create_interclub_competition( 'promote-full-comp', 1, 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$this->seed_entry( $competition_id, $club_id );
		$substitute = $this->seed_entry( $competition_id, $club_id, 'substitute', 'Bob' );

		$result = $this->entries->set_entry_status( Actor::admin(), $competition_id, $substitute, 'entered' );

		$this->assertWPError( $result );
		$this->assertSame( 'quota_exceeded', $result->get_error_code() );
		$this->assertSame( 'substitute', $this->images_repo->find( $substitute )->entry_status );
	}

	public function test_a_substitute_cant_be_promoted_past_the_photographer_limit(): void {
		$competition_id = $this->create_interclub_competition( 'promote-limit-comp', 2, 1, 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$this->seed_entry( $competition_id, $club_id, 'entered', 'Ann' );
		$substitute = $this->seed_entry( $competition_id, $club_id, 'substitute', ' ann ' );

		$result = $this->entries->set_entry_status( Actor::admin(), $competition_id, $substitute, 'entered' );

		$this->assertWPError( $result );
		$this->assertSame( 'photographer_limit', $result->get_error_code() );
	}

	/**
	 * @return array<string, array{0: bool, 1: string}>
	 */
	public function provide_started_categories(): array {
		return array(
			'voting has started'       => array( true, 'voting_started' ),
			'it was reset, with votes' => array( false, 'category_has_votes' ),
		);
	}

	/**
	 * @dataProvider provide_started_categories
	 *
	 * @param bool   $started Whether the category's voting has started, rather than reset with its votes kept.
	 * @param string $code    The refusal.
	 */
	public function test_a_substitute_cant_be_promoted_once_its_category_is_voted_on( bool $started, string $code ): void {
		$competition_id = $this->create_interclub_competition( 'promote-voted-comp', 2, 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$entered        = $this->seed_entry( $competition_id, $club_id );
		$substitute     = $this->seed_entry( $competition_id, $club_id, 'substitute', 'Bob' );
		if ( $started ) {
			Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_VOTING );
		} else {
			( new Votes_Repository() )->create_anonymous( $competition_id, 'colour', 111, $entered, 5 );
		}

		$result = $this->entries->set_entry_status( Actor::admin(), $competition_id, $substitute, 'entered' );

		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function test_a_disqualified_entry_is_reinstated_only_while_its_category_has_room(): void {
		$competition_id = $this->create_competition( 'reinstate-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$first          = $this->seed_entry( $competition_id, $member_id );
		$this->assertTrue( $this->entries->set_entry_status( Actor::admin(), $competition_id, $first, 'disqualified' ) );
		$this->assertTrue( $this->entries->set_entry_status( Actor::admin(), $competition_id, $first, 'entered' ) );
		$this->assertTrue( $this->entries->set_entry_status( Actor::admin(), $competition_id, $first, 'disqualified' ) );
		$this->seed_entry( $competition_id, $member_id );

		$result = $this->entries->set_entry_status( Actor::admin(), $competition_id, $first, 'entered' );

		$this->assertWPError( $result );
		$this->assertSame( 'quota_exceeded', $result->get_error_code() );
	}

	public function test_an_individual_competitions_entry_cant_become_a_substitute(): void {
		$competition_id = $this->create_competition( 'no-sub-comp', 2 );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->seed_entry( $competition_id, $member_id );

		$result = $this->entries->set_entry_status( Actor::admin(), $competition_id, $entry_id, 'substitute' );

		$this->assertWPError( $result );
		$this->assertSame( 'no_substitutes', $result->get_error_code() );
	}

	public function test_only_an_admin_changes_an_entrys_status(): void {
		$competition_id = $this->create_competition( 'member-dq-comp' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->seed_entry( $competition_id, $member_id );

		$result = $this->entries->set_entry_status( Actor::member( $member_id ), $competition_id, $entry_id, 'disqualified' );

		$this->assertWPError( $result );
		$this->assertSame( 'not_authorized', $result->get_error_code() );
		$this->assertSame( 'entered', $this->images_repo->find( $entry_id )->entry_status );
	}

	public function test_an_entry_changes_status_only_in_its_own_competition(): void {
		$competition_id = $this->create_competition( 'own-dq-comp' );
		$other_id       = $this->create_competition( 'other-dq-comp', 1, '2020-06-01 00:00:00' );
		$member_id      = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$entry_id       = $this->seed_entry( $other_id, $member_id );

		$result = $this->entries->set_entry_status( Actor::admin(), $competition_id, $entry_id, 'disqualified' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
		$this->assertSame( 'entered', $this->images_repo->find( $entry_id )->entry_status );
	}

	public function test_an_admin_corrects_or_clears_a_photographers_name_after_results_are_published(): void {
		$competition_id = $this->create_interclub_competition( 'rename-comp', 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$entry_id       = $this->seed_entry( $competition_id, $club_id, 'entered', 'Anne Murphy' );
		Workflow_Fixtures::publish_results( $competition_id );

		$this->assertTrue( $this->entries->set_photographer( Actor::admin(), $competition_id, $entry_id, '  Ann Murphy ' ) );
		$this->assertSame( 'Ann Murphy', $this->images_repo->find( $entry_id )->photographer );

		$this->assertTrue( $this->entries->set_photographer( Actor::admin(), $competition_id, $entry_id, '' ) );
		$this->assertSame( '', $this->images_repo->find( $entry_id )->photographer );
	}

	public function test_entries_without_a_photographer_dont_count_towards_the_limit(): void {
		$competition_id = $this->create_interclub_competition( 'cleared-names-comp', 3, 1, 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$this->seed_entry( $competition_id, $club_id, 'entered', '' );
		$this->seed_entry( $competition_id, $club_id, 'entered', '' );
		$substitute = $this->seed_entry( $competition_id, $club_id, 'substitute', '' );

		$this->assertTrue( $this->entries->set_entry_status( Actor::admin(), $competition_id, $substitute, 'entered' ) );
	}

	public function test_only_an_admin_changes_a_photographers_name(): void {
		$competition_id = $this->create_interclub_competition( 'rename-member-comp', 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$entry_id       = $this->seed_entry( $competition_id, $club_id, 'entered', 'Anne Murphy' );

		$result = $this->entries->set_photographer( Actor::member( $club_id ), $competition_id, $entry_id, 'Someone Else' );

		$this->assertWPError( $result );
		$this->assertSame( 'not_authorized', $result->get_error_code() );
		$this->assertSame( 'Anne Murphy', $this->images_repo->find( $entry_id )->photographer );
	}

	public function test_a_photographers_name_changes_only_in_its_own_competition(): void {
		$competition_id = $this->create_interclub_competition( 'rename-own-comp', 1 );
		$other_id       = $this->create_competition( 'rename-other-comp', 1, '2020-06-01 00:00:00' );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$entry_id       = $this->seed_entry( $other_id, $club_id, 'entered', 'Anne Murphy' );

		$result = $this->entries->set_photographer( Actor::admin(), $competition_id, $entry_id, 'Someone Else' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
		$this->assertSame( 'Anne Murphy', $this->images_repo->find( $entry_id )->photographer );
	}

	public function test_an_entrys_status_options_follow_its_competitions_phase(): void {
		$competition_id = $this->create_interclub_competition( 'options-comp', 2, 1 );
		$club_id        = $this->create_member( 'Lee Camera Club', 'lee@example.com' );
		$entered        = $this->seed_entry( $competition_id, $club_id );
		$substitute     = $this->seed_entry( $competition_id, $club_id, 'substitute' );

		$this->assertSame( array( 'substitute', 'disqualified' ), $this->entries->entry_status_options( $this->competitions_repo->find( $competition_id ), $this->images_repo->find( $entered ) ) );

		Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_DONE );
		$this->assertSame( array( 'disqualified' ), $this->entries->entry_status_options( $this->competitions_repo->find( $competition_id ), $this->images_repo->find( $substitute ) ) );

		Workflow_Fixtures::publish_results( $competition_id );
		$this->assertSame( array(), $this->entries->entry_status_options( $this->competitions_repo->find( $competition_id ), $this->images_repo->find( $entered ) ) );
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
	 * @param array        $interclub  The competition's interclub settings, or none for an individual competition.
	 * @return int Competition ID.
	 */
	private function create_competition( string $slug, int $quota = 1, ?string $close_date = null, array $interclub = array() ): int {
		$this->slugs[] = $slug;

		return (int) $this->competitions_repo->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'      => $slug,
				'slug'       => $slug,
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => $close_date,
				'settings'   => array(
					'interclub'  => $interclub + array(
						'enabled'            => ! empty( $interclub ),
						'substitutes'        => 0,
						'photographer_limit' => 0,
					),
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
