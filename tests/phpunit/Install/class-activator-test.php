<?php
/**
 * Tests for Activator upgrades.
 *
 * @package PhotoCompetitionManager\Tests\Install
 */

namespace PhotoCompetitionManager\Tests\Install;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Recorded_Results_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Legacy_Tables;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

use function PhotoCompetitionManager\Support\utc_time;

class Activator_Test extends WP_UnitTestCase {

	/**
	 * Table hidden behind a temporary one by the test, if any.
	 *
	 * @var string
	 */
	private $shadowed = '';

	/**
	 * Schema queries the upgrade ran, or tried to: ALTER TABLE is swallowed.
	 *
	 * @var array<string>
	 */
	private $ddl = array();

	public function setUp(): void {
		parent::setUp();
		Activator::activate();
	}

	public function tearDown(): void {
		global $wpdb;

		if ( '' !== $this->shadowed ) {
			$wpdb->query( "DROP TEMPORARY TABLE {$this->shadowed}" );
		}

		parent::tearDown();
	}

	public function test_maybe_upgrade_marks_inactive_members_from_older_version(): void {
		global $wpdb;
		$repository = new Members_Repository( $wpdb );

		$wpdb->insert(
			$repository->table(),
			array(
				'name'   => 'Old Inactive',
				'email'  => 'old@example.com',
				'grade'  => 'beginner',
				'active' => 0,
			)
		);
		$id = (int) $wpdb->insert_id;
		delete_option( 'photo_comp_db_version' );

		Activator::maybe_upgrade();

		$this->assertSame( 'deactivated-old@example.com.invalid', $repository->find( $id )->email );
		$this->assertSame( Activator::DB_VERSION, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_maybe_upgrade_keeps_old_version_when_marking_fails(): void {
		global $wpdb;
		delete_option( 'photo_comp_db_version' );

		$break_update = function ( $query ) {
			return 0 === strpos( $query, 'UPDATE' ) && false !== strpos( $query, 'photocomp_members' )
				? 'UPDATE photocomp_no_such_table SET email = email'
				: $query;
		};
		add_filter( 'query', $break_update );
		$suppress = $wpdb->suppress_errors( true );

		Activator::maybe_upgrade();

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_update );

		$this->assertFalse( get_option( 'photo_comp_db_version' ) );
	}

	public function test_maybe_upgrade_unschedules_email_cron_events(): void {
		// Email jobs are sent from the admin page now, and the competition closed email is gone.
		wp_schedule_event( time(), 'daily', 'photo_competition_daily_cron' );
		wp_schedule_single_event( time() + 60, 'photo_comp_send_email_batch', array( 'email_job_a' ) );
		wp_schedule_single_event( time() + 60, 'photo_comp_send_results_batch', array( 'email_job_b' ) );
		update_option( 'photo_comp_db_version', 1 );

		Activator::maybe_upgrade();

		$this->assertFalse( wp_next_scheduled( 'photo_competition_daily_cron' ) );
		$this->assertFalse( wp_next_scheduled( 'photo_comp_send_email_batch', array( 'email_job_a' ) ) );
		$this->assertFalse( wp_next_scheduled( 'photo_comp_send_results_batch', array( 'email_job_b' ) ) );
		$this->assertSame( Activator::DB_VERSION, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_maybe_upgrade_drops_competition_closed_template(): void {
		update_option(
			'photo_comp_email_templates',
			array(
				'voting_opened'      => array( 'enabled' => true ),
				'competition_closed' => array( 'enabled' => true ),
			)
		);
		update_option( 'photo_comp_db_version', 1 );

		Activator::maybe_upgrade();

		$this->assertSame(
			array( 'voting_opened' => array( 'enabled' => true ) ),
			get_option( 'photo_comp_email_templates' )
		);
	}

	public function test_maybe_upgrade_leaves_missing_templates_option_alone(): void {
		delete_option( 'photo_comp_email_templates' );
		update_option( 'photo_comp_db_version', 1 );

		Activator::maybe_upgrade();

		$this->assertFalse( get_option( 'photo_comp_email_templates' ) );
	}

	public function test_maybe_upgrade_skips_when_current(): void {
		global $wpdb;
		$repository = new Members_Repository( $wpdb );

		$wpdb->insert(
			$repository->table(),
			array(
				'name'   => 'Left Alone',
				'email'  => 'alone@example.com',
				'grade'  => 'beginner',
				'active' => 0,
			)
		);
		$id = (int) $wpdb->insert_id;
		update_option( 'photo_comp_db_version', Activator::DB_VERSION );

		Activator::maybe_upgrade();

		$this->assertSame( 'alone@example.com', $repository->find( $id )->email );
	}

	public function test_upgrade_to_3_moves_the_workflow_out_of_settings(): void {
		$id = $this->insert_v2_competition(
			array(
				'upload'  => array(
					'uploads_closed'   => true,
					'max_file_size_mb' => 7,
				),
				'voting'  => array(
					'password'         => 'swordfish',
					'open_categories'  => array( 'mono' ),
					'category_steps'   => array(
						'colour' => 6,
						'mono'   => 4,
					),
					'voted_categories' => array( 'COMP_colour' ),
				),
				'results' => array( 'results_visible' => false ),
			)
		);
		update_option( 'photo_comp_db_version', 2 );

		Activator::maybe_upgrade();

		$workflow    = new Competition_Workflow();
		$competition = ( new Competitions_Repository() )->find( $id, true );
		$this->assertTrue( $workflow->uploads_closed( $competition ) );
		$this->assertFalse( $workflow->results_published( $competition ) );
		$this->assertSame( Competition_Workflow::STAGE_DONE, $workflow->stage( $competition, 'colour' ) );
		$this->assertSame( Competition_Workflow::STAGE_SLIDESHOW_SHOWN, $workflow->stage( $competition, 'mono' ) );

		$settings = json_decode( $competition->settings, true );
		$this->assertSame(
			array(
				'upload' => array( 'max_file_size_mb' => 7 ),
				'voting' => array( 'password' => 'swordfish' ),
			),
			array_diff_key( $settings, array( 'categories' => true ) ),
			'Only the workflow keys are removed from settings.'
		);
		$this->assertSame( Activator::DB_VERSION, (int) get_option( 'photo_comp_db_version' ) );
	}

	/**
	 * Old pages reconciled stored steps with the live state on every load:
	 * an open category was at least step 3, and a voted one at least step 5.
	 * The upgrade applies that once.
	 */
	public function test_upgrade_to_3_lets_live_state_win_over_inconsistent_steps(): void {
		$id = $this->insert_v2_competition(
			array(
				'voting'  => array(
					'open_categories'  => array( 'colour' ),
					'category_steps'   => array(
						'colour' => 1,
						'mono'   => 3,
					),
					'voted_categories' => array( 'COMP_mono', '999_colour' ),
				),
				'results' => array( 'results_visible' => true ),
			)
		);
		update_option( 'photo_comp_db_version', 2 );

		Activator::maybe_upgrade();

		$workflow    = new Competition_Workflow();
		$competition = ( new Competitions_Repository() )->find( $id, true );
		$this->assertSame( Competition_Workflow::STAGE_VOTING, $workflow->stage( $competition, 'colour' ), 'Open wins over step 1; another competition\'s voted key is ignored.' );
		$this->assertSame( Competition_Workflow::STAGE_CRITIQUE, $workflow->stage( $competition, 'mono' ), 'Voted wins over step 3.' );
		$this->assertTrue( $workflow->results_published( $competition ) );
	}

	/**
	 * A failed upgrade leaves the version behind, so the step runs again on
	 * the next request. Competitions it already moved must keep their state.
	 */
	public function test_upgrade_to_3_running_again_keeps_moved_state(): void {
		$id = $this->insert_v2_competition(
			array(
				'upload'  => array( 'uploads_closed' => true ),
				'voting'  => array( 'category_steps' => array( 'colour' => 5 ) ),
				'results' => array( 'results_visible' => true ),
			)
		);
		update_option( 'photo_comp_db_version', 2 );
		Activator::maybe_upgrade();

		update_option( 'photo_comp_db_version', 2 );
		Activator::maybe_upgrade();

		$workflow    = new Competition_Workflow();
		$competition = ( new Competitions_Repository() )->find( $id, true );
		$this->assertTrue( $workflow->uploads_closed( $competition ) );
		$this->assertTrue( $workflow->results_published( $competition ) );
		$this->assertSame( Competition_Workflow::STAGE_CRITIQUE, $workflow->stage( $competition, 'colour' ) );
	}

	public function test_upgrade_to_3_moves_archived_competitions_too(): void {
		$id = $this->insert_v2_competition( array( 'upload' => array( 'uploads_closed' => true ) ) );
		( new Competitions_Repository() )->archive( $id );
		update_option( 'photo_comp_db_version', 2 );

		Activator::maybe_upgrade();

		$competition = ( new Competitions_Repository() )->find( $id, true );
		$this->assertTrue( ( new Competition_Workflow() )->uploads_closed( $competition ) );
		$this->assertArrayNotHasKey( 'upload', json_decode( $competition->settings, true ) );
	}

	public function test_upgrade_to_3_removes_workflow_keys_from_the_club_settings(): void {
		update_option(
			'photo_comp_default_settings',
			wp_json_encode(
				array(
					'voting'  => array(
						'open_categories' => array(),
						'auth_mode'       => 'token',
					),
					'results' => array( 'results_visible' => false ),
				)
			)
		);
		update_option( 'photo_comp_db_version', 2 );

		Activator::maybe_upgrade();

		$this->assertSame(
			array( 'voting' => array( 'auth_mode' => 'token' ) ),
			json_decode( get_option( 'photo_comp_default_settings' ), true )
		);
	}

	public function test_upgrade_to_5_names_stored_email_jobs_by_their_kind_of_email(): void {
		$stored = array(
			'a' => array( 'type' => 'upload_link' ),
			'b' => array( 'type' => 'results_share' ),
			'c' => array( 'type' => 'results' ),
			'd' => array(),
			'e' => array( 'type' => 'voting_opened' ),
		);
		foreach ( $stored as $id => $job ) {
			update_option( 'photo_comp_email_job_' . $id, $job + array( 'status' => 'pending' ), false );
		}
		update_option( 'photo_comp_db_version', 4 );

		Activator::maybe_upgrade();

		$types = array();
		foreach ( array_keys( $stored ) as $id ) {
			$job          = get_option( 'photo_comp_email_job_' . $id );
			$types[ $id ] = $job['type'];
			$this->assertSame( 'pending', $job['status'] );
		}
		$this->assertSame(
			array(
				'a' => 'upload_reminder',
				'b' => 'results_published',
				'c' => 'results_detailed',
				'd' => 'results_detailed',
				'e' => 'voting_opened',
			),
			$types
		);
		$this->assertSame( Activator::DB_VERSION, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_email_jobs_are_renamed_even_when_an_earlier_upgrade_step_fails(): void {
		global $wpdb;
		update_option( 'photo_comp_email_job_a', array( 'type' => 'results' ), false );
		delete_option( 'photo_comp_db_version' );

		$break_update = function ( $query ) {
			return 0 === strpos( $query, 'UPDATE' ) && false !== strpos( $query, 'photocomp_members' )
				? 'UPDATE photocomp_no_such_table SET email = email'
				: $query;
		};
		add_filter( 'query', $break_update );
		$suppress = $wpdb->suppress_errors( true );

		Activator::maybe_upgrade();

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_update );

		// The admin pages show every unfinished job, and need its kind of email.
		$this->assertSame( 'results_detailed', get_option( 'photo_comp_email_job_a' )['type'] );
		$this->assertFalse( get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_4_keeps_the_earliest_of_duplicate_votes(): void {
		global $wpdb;
		$this->shadow_v3_votes_table();
		$ann_first = $this->insert_v3_vote( 42, null, 'Ann', 7 );
		$this->insert_v3_vote( 42, null, 'ann', 2 );
		$token_first = $this->insert_v3_vote( 42, 100, null, 9 );
		$this->insert_v3_vote( 42, 100, null, 3 );
		$this->insert_v3_vote( 42, 100, null, 1 );
		$other_token = $this->insert_v3_vote( 42, 101, null, 4 );
		$other_image = $this->insert_v3_vote( 43, 100, null, 5 );
		update_option( 'photo_comp_db_version', 3 );

		Activator::maybe_upgrade();

		$kept = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id', $wpdb->prefix . 'photocomp_votes' ) );
		$this->assertSame( array( $ann_first, $token_first, $other_token, $other_image ), array_map( 'intval', $kept ) );
		// The swallowed ALTER means the keys never appear, so the upgrade
		// stops there and runs again on the next request.
		$this->assertSame( 3, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_4_logs_the_duplicate_votes_it_removes(): void {
		$this->shadow_v3_votes_table();
		$this->insert_v3_vote( 42, 100, null, 9 );
		$this->insert_v3_vote( 42, 100, null, 3 );
		$this->insert_v3_vote( 42, null, 'Ann', 7 );
		$this->insert_v3_vote( 42, null, 'Ann', 2 );
		$this->insert_v3_vote( 50, 200, null, 6, 2 );
		$this->insert_v3_vote( 50, 200, null, 5, 2 );
		$this->insert_v3_vote( 60, 300, null, 4, 3 );
		update_option( 'photo_comp_db_version', 3 );

		Activator::maybe_upgrade();

		$this->assertSame( array( 2, 1, 0 ), array_map( array( $this, 'removed_votes_logged' ), array( 1, 2, 3 ) ) );
	}

	public function test_upgrade_to_4_logs_removed_votes_as_the_system_whoever_loads_the_page(): void {
		$this->shadow_v3_votes_table();
		$this->insert_v3_vote( 42, 100, null, 9 );
		$this->insert_v3_vote( 42, 100, null, 3 );
		update_option( 'photo_comp_db_version', 3 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		Activator::maybe_upgrade();

		$logs = ( new Logs_Repository() )->find_by_competition( 1, 50, 0, array( 'event_type' => 'duplicate_votes_removed' ) );
		$this->assertSame( 'system', $logs[0]->actor_type );
		$this->assertNull( $logs[0]->actor_id );
	}

	public function test_upgrade_to_6_makes_the_plain_voting_tokens_key_unique(): void {
		$this->shadow_v5_voting_tokens_table();
		update_option( 'photo_comp_db_version', 5 );

		Activator::maybe_upgrade();

		// The plain key has the unique key's name, so it goes in the same
		// statement: dbDelta would try to add a second key with that name.
		$replaced = preg_grep( '/^ALTER TABLE `?\w*photocomp_voting_tokens`? DROP INDEX `?member_competition_category`?, ADD UNIQUE KEY `?member_competition_category`? \(`?member_id`?, `?competition_id`?, `?category`?\)$/i', $this->ddl );
		$this->assertCount( 1, $replaced, implode( "\n", $this->ddl ) );
		// The swallowed ALTER means the key never appears, so the upgrade
		// stops there and runs again on the next request.
		$this->assertSame( 5, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_6_adds_the_unique_key_to_voting_tokens_without_the_plain_one(): void {
		$this->shadowed = Legacy_Tables::shadow_v5_voting_tokens( false );
		$this->record_ddl();
		update_option( 'photo_comp_db_version', 5 );

		Activator::maybe_upgrade();

		// Dropping a key that isn't there would fail every time, so the
		// upgrade would never finish.
		$this->assertSame( array(), preg_grep( '/DROP INDEX/i', $this->ddl ), implode( "\n", $this->ddl ) );
		$added = preg_grep( '/^ALTER TABLE `?\w*photocomp_voting_tokens`? ADD UNIQUE KEY `?member_competition_category`? \(`?member_id`?, `?competition_id`?, `?category`?\)$/i', $this->ddl );
		$this->assertCount( 1, $added, implode( "\n", $this->ddl ) );
	}

	public function test_upgrade_to_6_leaves_voting_tokens_with_the_unique_key_alone(): void {
		$this->record_ddl();
		update_option( 'photo_comp_db_version', 5 );

		Activator::maybe_upgrade();

		$this->assertSame( array(), $this->ddl );
		$this->assertSame( Activator::DB_VERSION, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_6_keeps_each_members_ballot_and_deletes_their_other_tokens(): void {
		global $wpdb;
		$this->shadow_v5_voting_tokens_table();
		// The first link went unused and the ballot is on a later one.
		$this->insert_v5_token( 1, 1 );
		$later_ballot = $this->insert_v5_token( 1, 1 );
		$this->insert_vote( $later_ballot, 1, 10 );
		// Two ballots from one member: the second shouldn't exist.
		$first_ballot  = $this->insert_v5_token( 2, 1 );
		$second_ballot = $this->insert_v5_token( 2, 1 );
		$this->insert_vote( $first_ballot, 1, 10 );
		$this->insert_vote( $second_ballot, 1, 10 );
		$this->insert_vote( $second_ballot, 1, 11 );
		// No ballot: the latest link is the one the member has.
		$this->insert_v5_token( 3, 2 );
		$latest = $this->insert_v5_token( 3, 2 );
		$alone  = $this->insert_v5_token( 4, 3 );
		$this->insert_vote( $alone, 3, 30 );
		update_option( 'photo_comp_db_version', 5 );

		Activator::maybe_upgrade();

		$tokens = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id', $this->shadowed ) );
		$this->assertSame( array( $later_ballot, $first_ballot, $latest, $alone ), array_map( 'intval', $tokens ) );
		$voters = $wpdb->get_col( $wpdb->prepare( 'SELECT voting_token_id FROM %i ORDER BY id', $wpdb->prefix . 'photocomp_votes' ) );
		$this->assertSame( array( $later_ballot, $first_ballot, $alone ), array_map( 'intval', $voters ) );
	}

	public function test_upgrade_to_6_logs_the_votes_it_removes_with_duplicate_tokens(): void {
		$this->shadow_v5_voting_tokens_table();
		$this->insert_vote( $this->insert_v5_token( 1, 1 ), 1, 10 );
		$second_ballot = $this->insert_v5_token( 1, 1 );
		$this->insert_vote( $second_ballot, 1, 10 );
		$this->insert_vote( $second_ballot, 1, 11 );
		$this->insert_v5_token( 2, 2 );
		$this->insert_v5_token( 2, 2 );
		$this->insert_vote( $this->insert_v5_token( 3, 3 ), 3, 30 );
		update_option( 'photo_comp_db_version', 5 );

		Activator::maybe_upgrade();

		$this->assertSame( array( 2, 0, 0 ), array_map( array( $this, 'removed_votes_logged' ), array( 1, 2, 3 ) ) );
		$logs = ( new Logs_Repository() )->find_by_competition( 1, 50, 0, array( 'event_type' => 'duplicate_votes_removed' ) );
		$this->assertSame( 'system', $logs[0]->actor_type );
	}

	public function test_upgrade_to_6_keeps_a_token_and_runs_again_when_its_votes_cant_be_deleted(): void {
		global $wpdb;
		$this->shadow_v5_voting_tokens_table();
		$this->insert_vote( $this->insert_v5_token( 1, 1 ), 1, 10 );
		$second_ballot = $this->insert_v5_token( 1, 1 );
		$this->insert_vote( $second_ballot, 1, 10 );
		update_option( 'photo_comp_db_version', 5 );

		$break_delete = function ( $query ) {
			return 0 === strpos( $query, 'DELETE' ) && false !== strpos( $query, 'photocomp_votes' )
				? 'DELETE FROM photocomp_no_such_table'
				: $query;
		};
		add_filter( 'query', $break_delete );
		$suppress = $wpdb->suppress_errors( true );

		Activator::maybe_upgrade();

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_delete );

		// A token deleted without its votes would leave a second ballot
		// counting in the results with nothing to find it by.
		$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->shadowed ) ) );
		$this->assertSame( array(), preg_grep( '/^ALTER TABLE/i', $this->ddl ) );
		$this->assertSame( 5, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_7_records_published_closed_and_archived_competitions(): void {
		$competitions = new Competitions_Repository();
		$published    = $this->competition_with_an_entry( 'Published', -1, 1 );
		Workflow_Fixtures::publish_results( $published );
		$closed   = $this->competition_with_an_entry( 'Closed', -8, -7 );
		$archived = $this->competition_with_an_entry( 'Archived', -10, -9 );
		$competitions->archive( $archived );
		$upcoming = $this->competition_with_an_entry( 'Upcoming', 2, 3 );
		// Before version 7, publishing recorded nothing.
		( new Recorded_Results_Repository() )->delete_by_competition( $published );
		update_option( 'photo_comp_db_version', 6 );

		Activator::maybe_upgrade();

		$record = new Recorded_Results_Repository();
		$this->assertSame(
			array( true, true, true, false ),
			array_map( static fn( int $id ): bool => null !== $record->recorded_at( $id ), array( $published, $closed, $archived, $upcoming ) )
		);
		$this->assertSame( Activator::DB_VERSION, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_7_adds_the_recorded_results_table_before_recording(): void {
		$closed = $this->competition_with_an_entry( 'Closed', -8, -7 );
		update_option( 'photo_comp_db_version', 6 );
		// A 0.3.0 site has no recorded results table. Dropping the real one
		// would end the test's transaction, so every query is pointed at a
		// table that doesn't exist yet. The test suite makes the table it
		// creates temporary.
		$table          = ( new Recorded_Results_Repository() )->table();
		$this->shadowed = $table . '_missing';
		$missing        = function ( $query ) use ( $table ) {
			return preg_replace( '/\b' . $table . '\b/', $this->shadowed, $query );
		};
		add_filter( 'query', $missing );
		$this->record_ddl();

		Activator::maybe_upgrade();

		$recorded_at = ( new Recorded_Results_Repository() )->recorded_at( $closed );
		remove_filter( 'query', $missing );
		$this->assertCount( 1, preg_grep( '/^CREATE TEMPORARY TABLE ' . $this->shadowed . ' /', $this->ddl ), implode( "\n", $this->ddl ) );
		$this->assertNotNull( $recorded_at );
		$this->assertSame( Activator::DB_VERSION, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_7_running_again_changes_nothing(): void {
		$closed = $this->competition_with_an_entry( 'Closed', -8, -7 );
		update_option( 'photo_comp_db_version', 6 );
		Activator::maybe_upgrade();
		$recorded = ( new Recorded_Results_Repository() )->find_by_category( $closed, 'colour' );
		Entry_Fixtures::insert_scored_entry( $closed, 'colour', 'Latecomer', 'beginner', array( 9 ) );

		update_option( 'photo_comp_db_version', 6 );
		Activator::maybe_upgrade();

		$this->assertEquals( $recorded, ( new Recorded_Results_Repository() )->find_by_category( $closed, 'colour' ) );
	}

	public function test_upgrade_to_7_drops_the_unused_score_column(): void {
		$this->shadow_v6_images_table();
		update_option( 'photo_comp_db_version', 6 );

		Activator::maybe_upgrade();

		$this->assertCount( 1, preg_grep( '/^ALTER TABLE `?\w*photocomp_images`? DROP COLUMN `?score`?$/i', $this->ddl ), implode( "\n", $this->ddl ) );
		// The swallowed ALTER leaves the column, so the step runs again on the next request.
		$this->assertSame( 6, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_9_creates_a_missing_judge_table(): void {
		global $wpdb;
		$this->record_ddl();
		$judges = "{$wpdb->prefix}photocomp_judges";
		add_filter(
			'query',
			function ( $query ) use ( $judges ) {
				// Make the judges table look missing to SHOW TABLES.
				return false !== strpos( $query, 'SHOW TABLES LIKE' ) && false !== strpos( $query, $judges ) ? 'SELECT NULL WHERE 1 = 0' : $query;
			},
			20
		);
		update_option( 'photo_comp_db_version', 8 );

		Activator::maybe_upgrade();

		// dbDelta compares each table against its schema, so it describes both judge tables.
		$this->assertCount( 1, preg_grep( '/^DESCRIBE `?\w*photocomp_judges`?;?$/i', $this->ddl ), implode( "\n", $this->ddl ) );
		$this->assertCount( 1, preg_grep( '/^DESCRIBE `?\w*photocomp_competition_judges`?;?$/i', $this->ddl ) );
		// The table still looks missing, so the step runs again on the next request.
		$this->assertSame( 8, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_9_leaves_existing_judge_tables_alone(): void {
		global $wpdb;
		$this->record_ddl();
		update_option( 'photo_comp_db_version', 8 );

		Activator::maybe_upgrade();

		$this->assertSame( array(), preg_grep( '/^(CREATE|ALTER|DESCRIBE)/i', $this->ddl ) );
		$this->assertSame( "{$wpdb->prefix}photocomp_judges", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$wpdb->prefix}photocomp_judges" ) ) );
		$this->assertSame( 10, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_9_adds_the_judge_column_to_an_old_votes_table_and_keeps_its_votes(): void {
		global $wpdb;
		$this->shadow_v8_votes_table();
		$wpdb->insert(
			$this->shadowed,
			array(
				'competition_id'  => 1,
				'category'        => 'colour',
				'voting_token_id' => 1,
				'image_id'        => 1,
				'score'           => 5,
			)
		);
		update_option( 'photo_comp_db_version', 8 );

		Activator::maybe_upgrade();

		$this->assertNotEmpty( $wpdb->get_results( "SHOW COLUMNS FROM {$this->shadowed} LIKE 'judge_id'" ) );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->shadowed}" ) );
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->shadowed} WHERE judge_id IS NULL" ) );
		$this->assertSame( 10, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_10_adds_type_tables_and_columns(): void {
		global $wpdb;
		$this->shadow_v9_competitions_table();
		update_option( 'photo_comp_db_version', 9 );

		Activator::maybe_upgrade();

		foreach ( array( 'type_id', 'entry_mode' ) as $column ) {
			$this->assertNotEmpty( $wpdb->get_results( "SHOW COLUMNS FROM {$this->shadowed} LIKE '{$column}'" ), $column );
		}
		$this->assertNotEmpty( $wpdb->get_results( "SHOW INDEX FROM {$this->shadowed} WHERE Key_name = 'type_id'" ) );
		foreach ( array( 'competition_types', 'member_types', 'entrants' ) as $table ) {
			$this->assertSame( "{$wpdb->prefix}photocomp_{$table}", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$wpdb->prefix}photocomp_{$table}" ) ), $table );
		}
		$this->assertSame( 10, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_creates_one_type_from_club_settings(): void {
		update_option(
			'photo_comp_default_settings',
			wp_json_encode(
				array(
					'categories' => array(
						array(
							'slug'  => 'open',
							'label' => 'Open',
							'quota' => 3,
						),
					),
					'upload'     => array( 'max_file_size_mb' => 7 ),
					'voting'     => array( 'score_matrix' => array( 9, 7, 5 ) ),
				)
			)
		);
		$club = Competition_Settings::global_settings();
		$this->downgrade_to_9();

		Activator::maybe_upgrade();

		$types = $this->types();
		$this->assertCount( 1, $types );
		$this->assertSame( 'Individual', $types[0]->name );
		$this->assertSame( '{}', $types[0]->grade_names );
		$settings = json_decode( $types[0]->settings, true );
		$this->assertSame( Competition_Settings::club_categories(), $settings['categories'] );
		$this->assertSame( 'open', $settings['categories'][0]['slug'] );
		$this->assertSame( $club['voting']['score_matrix'], $settings['voting']['score_matrix'] );
		$this->assertSame( $club['upload'], $settings['upload'] );
		$this->assertSame( 10, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_tags_every_member_and_sets_every_competition(): void {
		global $wpdb;
		$active   = $this->insert_member( 1 );
		$inactive = $this->insert_member( 0 );
		$live     = $this->insert_v2_competition( array() );
		$deleted  = $this->insert_v2_competition( array() );
		$wpdb->update( ( new Competitions_Repository() )->table(), array( 'deleted_at' => utc_time() ), array( 'id' => $deleted ) );
		$this->downgrade_to_9();

		Activator::maybe_upgrade();

		$type_id = (int) $this->types()[0]->id;
		$tagged  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT member_id FROM %i WHERE type_id = %d', $wpdb->prefix . 'photocomp_member_types', $type_id ) ) );
		$this->assertContains( $active, $tagged );
		$this->assertContains( $inactive, $tagged );
		$all_members = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'photocomp_members' ) );
		$this->assertCount( $all_members, $tagged );
		foreach ( array( $live, $deleted ) as $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT type_id, entry_mode FROM %i WHERE id = %d', ( new Competitions_Repository() )->table(), $id ) );
			$this->assertSame( $type_id, (int) $row->type_id );
			$this->assertSame( 'all', $row->entry_mode );
		}
	}

	public function test_upgrade_gives_competitions_without_categories_the_club_list(): void {
		global $wpdb;
		$table = ( new Competitions_Repository() )->table();
		$bare  = $this->insert_v2_competition( array() );
		$own   = $this->insert_v2_competition( array() );
		$wpdb->update( $table, array( 'settings' => null ), array( 'id' => $bare ) );
		$wpdb->update( $table, array( 'settings' => wp_json_encode( array( 'categories' => array( array( 'slug' => 'solo', 'label' => 'Solo', 'quota' => 2 ) ) ) ) ), array( 'id' => $own ) );
		$this->downgrade_to_9();

		Activator::maybe_upgrade();

		$bare_settings = json_decode( $wpdb->get_var( $wpdb->prepare( 'SELECT settings FROM %i WHERE id = %d', $table, $bare ) ), true );
		$own_settings  = json_decode( $wpdb->get_var( $wpdb->prepare( 'SELECT settings FROM %i WHERE id = %d', $table, $own ) ), true );
		$this->assertSame( Competition_Settings::club_categories(), $bare_settings['categories'] );
		$this->assertSame( 'solo', $own_settings['categories'][0]['slug'] );
	}

	public function test_upgrade_to_10_is_idempotent(): void {
		global $wpdb;
		$this->insert_member( 1 );
		$this->downgrade_to_9();
		Activator::maybe_upgrade();
		$tags = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'photocomp_member_types' ) );

		update_option( 'photo_comp_db_version', 9 );
		Activator::maybe_upgrade();

		$this->assertCount( 1, $this->types() );
		$this->assertSame( $tags, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'photocomp_member_types' ) ) );
		$this->assertSame( (int) $this->types()[0]->id, Activator::create_default_type() );
	}

	/**
	 * Insert a member row.
	 *
	 * @param int $active Whether the member is active.
	 * @return int Member ID.
	 */
	private function insert_member( int $active ): int {
		global $wpdb;

		static $count = 0;
		++$count;
		$wpdb->insert(
			$wpdb->prefix . 'photocomp_members',
			array(
				'name'   => 'Member ' . $count,
				'email'  => "member{$count}@example.com",
				'grade'  => 'beginner',
				'active' => $active,
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function test_upgrade_to_10_finishes_a_run_that_died_after_inserting_the_type(): void {
		global $wpdb;
		$this->downgrade_to_9();
		$wpdb->insert(
			$wpdb->prefix . 'photocomp_competition_types',
			array(
				'name'        => 'Individual',
				'settings'    => '{}',
				'grade_names' => '{}',
			)
		);
		$type_id = (int) $wpdb->insert_id;
		$member  = $this->insert_member( 1 );
		$comp    = $this->insert_v2_competition( array() );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET type_id = NULL', ( new Competitions_Repository() )->table() ) );

		Activator::maybe_upgrade();

		$this->assertCount( 1, $this->types() );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE member_id = %d AND type_id = %d', $wpdb->prefix . 'photocomp_member_types', $member, $type_id ) ) );
		$this->assertSame( $type_id, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT type_id FROM %i WHERE id = %d', ( new Competitions_Repository() )->table(), $comp ) ) );
		$this->assertSame( 10, (int) get_option( 'photo_comp_db_version' ) );
	}

	/**
	 * Remove what setUp()'s activation created, and make the site look like version 9.
	 */
	private function downgrade_to_9(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'photocomp_competition_types' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'photocomp_member_types' ) );
		update_option( 'photo_comp_db_version', 9 );
	}

	/**
	 * Every competition type row.
	 *
	 * @return array<object>
	 */
	private function types(): array {
		global $wpdb;

		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id', $wpdb->prefix . 'photocomp_competition_types' ) );
	}

	/**
	 * Hide the competitions table behind a temporary one shaped as version 9
	 * left it, without the type columns. The upgrade's ALTER TABLE runs for
	 * real: it ends the test's transaction, but only the temporary table changes.
	 */
	private function shadow_v9_competitions_table(): void {
		global $wpdb;

		$this->shadowed = $wpdb->prefix . 'photocomp_competitions';
		$wpdb->query(
			"CREATE TEMPORARY TABLE {$this->shadowed} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				title VARCHAR(191) NOT NULL,
				slug VARCHAR(191) NOT NULL,
				open_date DATETIME NULL,
				close_date DATETIME NULL,
				settings LONGTEXT NULL,
				workflow LONGTEXT NULL,
				share_hash VARCHAR(64) NOT NULL DEFAULT '',
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NULL,
				deleted_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY share_hash (share_hash)
			) {$wpdb->get_charset_collate()}"
		);
	}

	public function test_upgrade_to_8_leaves_upload_tokens_with_the_unique_key_alone(): void {
		global $wpdb;
		$this->record_ddl();
		$token = ( new Upload_Token_Repository() )->find_or_create( 1, 1 );
		update_option( 'photo_comp_db_version', 7 );

		Activator::maybe_upgrade();

		$this->assertSame( array(), $this->ddl );
		$this->assertSame( array( $token->id ), $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i', $wpdb->prefix . 'photocomp_upload_tokens' ) ) );
		$this->assertSame( Activator::DB_VERSION, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_8_keeps_each_members_earliest_upload_token(): void {
		global $wpdb;
		$this->shadow_v7_upload_tokens_table();
		$three_earliest = $this->insert_v7_upload_token( 1, 1 );
		$this->insert_v7_upload_token( 1, 1 );
		$other_member = $this->insert_v7_upload_token( 2, 1 );
		$this->insert_v7_upload_token( 1, 1 );
		$other_competition = $this->insert_v7_upload_token( 1, 2 );
		$two_earliest      = $this->insert_v7_upload_token( 3, 2 );
		$this->insert_v7_upload_token( 3, 2 );
		update_option( 'photo_comp_db_version', 7 );

		Activator::maybe_upgrade();

		$this->assertSame(
			array( $three_earliest, $other_member, $other_competition, $two_earliest ),
			array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id', $this->shadowed ) ) )
		);
	}

	public function test_upgrade_to_8_makes_the_plain_upload_tokens_key_unique(): void {
		global $wpdb;
		$this->shadow_v7_upload_tokens_table();
		$tokens = array( $this->insert_v7_upload_token( 1, 1 ), $this->insert_v7_upload_token( 2, 1 ), $this->insert_v7_upload_token( 1, 2 ) );
		update_option( 'photo_comp_db_version', 7 );

		Activator::maybe_upgrade();

		$replaced = preg_grep( '/^ALTER TABLE `?\w*photocomp_upload_tokens`? DROP INDEX `?member_competition`?, ADD UNIQUE KEY `?member_competition`? \(`?member_id`?, `?competition_id`?\)$/i', $this->ddl );
		$this->assertCount( 1, $replaced, implode( "\n", $this->ddl ) );
		$this->assertSame( $tokens, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id', $this->shadowed ) ) ) );
		// The swallowed ALTER means the key never appears, so the upgrade
		// stops there and runs again on the next request.
		$this->assertSame( 7, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_8_adds_the_unique_key_to_upload_tokens_without_the_plain_one(): void {
		$this->shadowed = Legacy_Tables::shadow_v7_upload_tokens( false );
		$this->record_ddl();
		update_option( 'photo_comp_db_version', 7 );

		Activator::maybe_upgrade();

		// Dropping a key that isn't there would fail every time, so the
		// upgrade would never finish.
		$this->assertSame( array(), preg_grep( '/DROP INDEX/i', $this->ddl ), implode( "\n", $this->ddl ) );
		$added = preg_grep( '/^ALTER TABLE `?\w*photocomp_upload_tokens`? ADD UNIQUE KEY `?member_competition`? \(`?member_id`?, `?competition_id`?\)$/i', $this->ddl );
		$this->assertCount( 1, $added, implode( "\n", $this->ddl ) );
	}

	public function test_upgrade_to_8_runs_again_when_a_duplicate_cant_be_deleted(): void {
		global $wpdb;
		$this->shadow_v7_upload_tokens_table();
		$tokens = array( $this->insert_v7_upload_token( 1, 1 ), $this->insert_v7_upload_token( 1, 1 ) );
		update_option( 'photo_comp_db_version', 7 );

		$break_delete = function ( $query ) {
			return 0 === strpos( $query, 'DELETE' ) && false !== strpos( $query, 'photocomp_upload_tokens' )
				? 'DELETE FROM photocomp_no_such_table'
				: $query;
		};
		add_filter( 'query', $break_delete );
		$suppress = $wpdb->suppress_errors( true );

		Activator::maybe_upgrade();

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_delete );

		// The ALTER would fail on the duplicate left behind.
		$this->assertSame( array(), preg_grep( '/^ALTER TABLE/i', $this->ddl ) );
		$this->assertSame( $tokens, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id', $this->shadowed ) ) ) );
		$this->assertSame( 7, (int) get_option( 'photo_comp_db_version' ) );
	}

	/**
	 * Create a competition with one scored colour entry.
	 *
	 * @param string $title     Title.
	 * @param int    $opens_in  Days from now it opens, negative in the past.
	 * @param int    $closes_in Days from now it closes, negative in the past.
	 * @return int Competition ID.
	 */
	private function competition_with_an_entry( string $title, int $opens_in, int $closes_in ): int {
		$id = (int) ( new Competitions_Repository() )->create(
			array(
				'title'      => $title,
				'slug'       => sanitize_title( $title ) . '-' . wp_generate_password( 6, false ),
				'open_date'  => utc_time( $opens_in * DAY_IN_SECONDS ),
				'close_date' => utc_time( $closes_in * DAY_IN_SECONDS ),
			)
		);
		$this->assertGreaterThan( 0, $id );
		Entry_Fixtures::insert_scored_entry( $id, 'colour', $title . ' Entrant', 'beginner', array( 7 ) );

		return $id;
	}

	/**
	 * Hide the images table behind one shaped as version 6 left it, with the
	 * score column nothing read.
	 */
	private function shadow_v6_images_table(): void {
		global $wpdb;

		$this->shadowed = $wpdb->prefix . 'photocomp_images';
		$wpdb->query(
			"CREATE TEMPORARY TABLE {$this->shadowed} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				member_id BIGINT UNSIGNED NOT NULL,
				competition_id BIGINT UNSIGNED NOT NULL,
				category VARCHAR(100) NOT NULL,
				filename VARCHAR(191) NOT NULL,
				random_number BIGINT UNSIGNED NOT NULL,
				score INT NULL,
				original_attachment_id BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME NULL,
				PRIMARY KEY  (id)
			) {$wpdb->get_charset_collate()}"
		);

		$this->record_ddl();
	}

	/**
	 * Insert a voting token into the version 5 voting tokens table.
	 *
	 * @param int $member_id      Member ID.
	 * @param int $competition_id Competition ID.
	 * @return int Token ID.
	 */
	private function insert_v5_token( int $member_id, int $competition_id ): int {
		global $wpdb;

		$wpdb->insert(
			$this->shadowed,
			array(
				'member_id'      => $member_id,
				'competition_id' => $competition_id,
				'category'       => 'colour',
				'token_hash'     => wp_generate_password( 64, false ),
				'expires_at'     => utc_time( HOUR_IN_SECONDS ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a vote cast with a voting token.
	 *
	 * @param int $voting_token_id Token.
	 * @param int $competition_id  Competition ID.
	 * @param int $image_id        Image ID.
	 * @return void
	 */
	private function insert_vote( int $voting_token_id, int $competition_id, int $image_id ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'photocomp_votes',
			array(
				'competition_id'  => $competition_id,
				'category'        => 'colour',
				'voting_token_id' => $voting_token_id,
				'image_id'        => $image_id,
				'score'           => 5,
			)
		);
	}

	/**
	 * Hide the upload tokens table behind one shaped as version 7 left it on
	 * old sites, with a plain key where the unique one goes, so duplicates
	 * can be stored.
	 */
	private function shadow_v7_upload_tokens_table(): void {
		$this->shadowed = Legacy_Tables::shadow_v7_upload_tokens();
		$this->record_ddl();
	}

	/**
	 * Insert an upload token into the version 7 upload tokens table.
	 *
	 * @param int $member_id      Member ID.
	 * @param int $competition_id Competition ID.
	 * @return int Token ID.
	 */
	private function insert_v7_upload_token( int $member_id, int $competition_id ): int {
		global $wpdb;

		$wpdb->insert(
			$this->shadowed,
			array(
				'member_id'      => $member_id,
				'competition_id' => $competition_id,
				'token'          => wp_generate_password( 64, false ),
				'expires_at'     => utc_time( WEEK_IN_SECONDS ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Hide the voting tokens table behind one shaped as version 5 left it on
	 * old sites, with a plain key where the unique one goes, so duplicates
	 * can be stored.
	 */
	private function shadow_v5_voting_tokens_table(): void {
		$this->shadowed = Legacy_Tables::shadow_v5_voting_tokens();
		$this->record_ddl();
	}

	/**
	 * Record the schema queries the upgrade runs into $this->ddl, and
	 * swallow ALTER TABLE: it would end the test's transaction, so nothing
	 * the upgrade does to the schema reaches the database.
	 */
	private function record_ddl(): void {
		add_filter(
			'query',
			function ( $query ) {
				if ( preg_match( '/^\s*(ALTER|CREATE|DROP|DESCRIBE)\s/i', $query ) ) {
					$this->ddl[] = trim( $query );
				}

				return 0 === stripos( ltrim( $query ), 'ALTER TABLE' ) ? 'SELECT 1' : $query;
			}
		);
	}

	/**
	 * How many removed duplicate votes the upgrade logged for a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @return int
	 */
	private function removed_votes_logged( int $competition_id ): int {
		$logs = ( new Logs_Repository() )->find_by_competition( $competition_id, 50, 0, array( 'event_type' => 'duplicate_votes_removed' ) );
		$this->assertLessThanOrEqual( 1, count( $logs ) );

		return $logs ? (int) json_decode( $logs[0]->metadata, true )['removed'] : 0;
	}

	/**
	 * Hide the votes table behind a temporary one shaped as version 8 left
	 * it, without the judge column. The upgrade's ALTER TABLE runs for real:
	 * it ends the test's transaction, but only the temporary table changes.
	 */
	private function shadow_v8_votes_table(): void {
		global $wpdb;

		$this->shadowed = $wpdb->prefix . 'photocomp_votes';
		$wpdb->query(
			"CREATE TEMPORARY TABLE {$this->shadowed} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				competition_id BIGINT UNSIGNED NOT NULL,
				category VARCHAR(100) NOT NULL,
				voter_name VARCHAR(191) NULL,
				voting_token_id BIGINT UNSIGNED NULL,
				image_id BIGINT UNSIGNED NOT NULL,
				score INT NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY image (image_id),
				UNIQUE KEY image_token (image_id, voting_token_id),
				UNIQUE KEY image_voter (image_id, voter_name)
			) {$wpdb->get_charset_collate()}"
		);
	}

	/**
	 * Hide the votes table behind a temporary one shaped as version 3 left
	 * it, without unique keys, so duplicates can be stored. Creating a
	 * temporary table doesn't end the test's transaction.
	 */
	private function shadow_v3_votes_table(): void {
		global $wpdb;

		$this->shadowed = $wpdb->prefix . 'photocomp_votes';
		$wpdb->query(
			"CREATE TEMPORARY TABLE {$this->shadowed} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				competition_id BIGINT UNSIGNED NOT NULL,
				category VARCHAR(100) NOT NULL,
				voter_name VARCHAR(191) NULL,
				voting_token_id BIGINT UNSIGNED NULL,
				image_id BIGINT UNSIGNED NOT NULL,
				score INT NOT NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY image (image_id)
			) {$wpdb->get_charset_collate()}"
		);

		$this->record_ddl();
	}

	/**
	 * Insert a vote into the version 3 votes table.
	 *
	 * @param int         $image_id        Image ID.
	 * @param int|null    $voting_token_id Token, in token mode.
	 * @param string|null $voter_name      Voter name, in password mode.
	 * @param int         $score           Score.
	 * @param int         $competition_id  Competition ID.
	 * @return int Vote ID.
	 */
	private function insert_v3_vote( int $image_id, ?int $voting_token_id, ?string $voter_name, int $score, int $competition_id = 1 ): int {
		global $wpdb;

		$wpdb->insert(
			$this->shadowed,
			array(
				'competition_id'  => $competition_id,
				'category'        => 'colour',
				'voter_name'      => $voter_name,
				'voting_token_id' => $voting_token_id,
				'image_id'        => $image_id,
				'score'           => $score,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Insert a competition as version 2 stored it, with the workflow in its
	 * settings. COMP in a voted_categories key stands for its ID.
	 *
	 * @param array<string, mixed> $settings Settings to store.
	 * @return int Competition ID.
	 */
	private function insert_v2_competition( array $settings ): int {
		global $wpdb;

		$repository = new Competitions_Repository();
		$wpdb->insert(
			$repository->table(),
			array(
				'title'      => 'Old',
				'slug'       => 'old-' . wp_generate_password( 6, false ),
				'open_date'  => utc_time( -DAY_IN_SECONDS ),
				'close_date' => utc_time( DAY_IN_SECONDS ),
				'created_at' => utc_time(),
			)
		);
		$id = (int) $wpdb->insert_id;

		$settings['categories'] = array(
			array(
				'slug'  => 'colour',
				'label' => 'Colour',
				'quota' => 1,
			),
			array(
				'slug'  => 'mono',
				'label' => 'Mono',
				'quota' => 1,
			),
		);
		if ( isset( $settings['voting']['voted_categories'] ) ) {
			$settings['voting']['voted_categories'] = str_replace( 'COMP', (string) $id, $settings['voting']['voted_categories'] );
		}
		$wpdb->update( $repository->table(), array( 'settings' => wp_json_encode( $settings ) ), array( 'id' => $id ) );

		return $id;
	}
}
