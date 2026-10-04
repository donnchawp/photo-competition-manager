<?php
/**
 * Tests for Activator upgrades.
 *
 * @package PhotoCompetitionManager\Tests\Install
 */

namespace PhotoCompetitionManager\Tests\Install;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use WP_UnitTestCase;

use function PhotoCompetitionManager\Support\utc_time;

class Activator_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Activator::activate();
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
