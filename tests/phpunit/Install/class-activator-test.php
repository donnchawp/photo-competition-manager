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
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Tests\Legacy_Tables;
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

	public function test_upgrade_to_6_adds_the_unique_key_to_voting_tokens_without_it(): void {
		$this->shadow_v5_voting_tokens_table();
		update_option( 'photo_comp_db_version', 5 );

		Activator::maybe_upgrade();

		$added = preg_grep( '/^ALTER TABLE \S*photocomp_voting_tokens ADD UNIQUE KEY `?member_competition_category`?/i', $this->ddl );
		$this->assertCount( 1, $added );
		// The swallowed ALTER means the key never appears, so the upgrade
		// stops there and runs again on the next request.
		$this->assertSame( 5, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_upgrade_to_6_leaves_voting_tokens_with_the_unique_key_alone(): void {
		$this->record_ddl();
		update_option( 'photo_comp_db_version', 5 );

		Activator::maybe_upgrade();

		$this->assertSame( array(), $this->ddl );
		$this->assertSame( 6, (int) get_option( 'photo_comp_db_version' ) );
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
	 * Hide the voting tokens table behind one shaped as version 5 left it on
	 * old sites, without the unique key, so duplicates can be stored.
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
