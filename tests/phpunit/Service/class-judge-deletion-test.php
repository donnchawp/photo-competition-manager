<?php
/**
 * Tests for Judge_Deletion.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competition_Judges_Repository;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Judges_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Ballots;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Service\Judge_Deletion;
use PhotoCompetitionManager\Service\Results_Ranking;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

/**
 * Deleting a judge and the privacy eraser.
 *
 * @covers \PhotoCompetitionManager\Service\Judge_Deletion
 */
class Judge_Deletion_Test extends WP_UnitTestCase {

	/**
	 * @var Judges_Repository
	 */
	private $judges;

	public function set_up(): void {
		parent::set_up();
		$this->judges = new Judges_Repository();
	}

	/**
	 * Run the plugin's judges eraser, as Tools > Erase Personal Data does.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return mixed
	 */
	private function run_eraser( string $email, int $page = 1 ) {
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'photo-competition-manager-judges', $erasers );

		return call_user_func( $erasers['photo-competition-manager-judges']['callback'], $email, $page );
	}

	public function test_delete_judge_keeps_totals(): void {
		$competitions   = new Competitions_Repository();
		$competition_id = (int) $competitions->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'     => 'Judged Comp',
				'slug'      => 'judged-comp',
				'open_date' => '2020-01-01 00:00:00',
				'settings'  => array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
							'quota' => 1,
						),
					),
					'voting'     => array(
						'auth_mode'    => 'judges',
						'score_matrix' => array( 5, 4, 3 ),
					),
				),
			)
		);

		$images = array();
		foreach ( array( 0, 1 ) as $i ) {
			$member_id = Member_Fixtures::insert_with_grade( 'Entrant ' . $i, 'entrant-' . $i . '@example.com', 'beginner' );
			$images[]  = Entry_Fixtures::insert_entry( $competition_id, 'colour', $member_id, array() );
		}
		Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_VOTING );
		$competition = $competitions->find( $competition_id );

		$judge_id = $this->judges->create( 'Jo Judge', 'jo@example.com' );
		$this->assertNotWPError( $judge_id );
		$token = ( new Competition_Judges_Repository() )->assign( $competition_id, $judge_id );
		$this->assertNotWPError( $token );

		$ballots = new Ballots();
		$voter   = $ballots->judge_voter( $competition, $token );
		$this->assertNotWPError( $voter );
		$this->assertTrue(
			$ballots->cast(
				$competition,
				'colour',
				$voter,
				array(
					$images[0] => '5',
					$images[1] => '3',
				)
			)
		);

		$totals = function () use ( $competition_id ) {
			$ranking = new Results_Ranking( new Images_Repository(), new Votes_Repository(), new Members_Repository() );
			$totals  = array();
			foreach ( $ranking->rank_category( $competition_id, 'colour' ) as $group ) {
				foreach ( $group['entries'] as $entry ) {
					$totals[ (int) $entry['image']->id ] = $entry['total_score'];
				}
			}
			ksort( $totals );
			return $totals;
		};

		$before = $totals();
		$this->assertSame( 8, array_sum( $before ) );

		$this->assertTrue( ( new Judge_Deletion() )->delete( $judge_id ) );

		$this->assertNull( $this->judges->find( $judge_id ) );
		$this->assertSame( $before, $totals() );
	}

	public function test_deleting_a_judge_scrubs_the_log_of_their_name_and_email(): void {
		$judge_id = $this->judges->create( 'Jo Judge', 'jo@example.com' );
		$other_id = $this->judges->create( 'Al Judge', 'al@example.com' );
		add_filter( 'pre_wp_mail', '__return_true' );
		$service = new Email_Service();
		foreach ( array( $judge_id, $other_id ) as $id ) {
			$sent = $service->send( 'judge_link', $this->judges->find( $id ), null, array( '{judge_link}' => 'https://example.org/vote/?j=abc', '{judge_name}' => 'x' ) );
			$this->assertSame( 'sent', $sent );
		}
		remove_filter( 'pre_wp_mail', '__return_true' );

		$logs = new Logs_Repository();
		$this->assertStringContainsString( 'jo@example.com', wp_json_encode( $logs->find_about_members( array(), array( 'jo@example.com' ) ) ) );

		$this->assertTrue( ( new Judge_Deletion() )->delete( $judge_id ) );

		$remaining = wp_json_encode( $logs->paginate( 500, 0 ) );
		$this->assertStringNotContainsString( 'jo@example.com', $remaining );
		$this->assertStringNotContainsString( 'Jo Judge', $remaining );
		$this->assertStringContainsString( 'al@example.com', $remaining, "Another judge's log rows stay." );
	}

	public function test_delete_unknown_judge_is_an_error(): void {
		$result = ( new Judge_Deletion() )->delete( 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_judge', $result->get_error_code() );
	}

	public function test_eraser_removes_judge_by_email(): void {
		$first  = $this->judges->create( 'Jo Judge', 'jo@example.com' );
		$second = $this->judges->create( 'Jo Again', 'jo@example.com' );
		$other  = $this->judges->create( 'Al Judge', 'al@example.com' );

		$page_one = $this->run_eraser( 'jo@example.com' );
		$this->assertTrue( $page_one['items_removed'] );
		$this->assertFalse( $page_one['done'] );

		$page_two = $this->run_eraser( 'jo@example.com', 2 );
		$this->assertTrue( $page_two['items_removed'] );
		$this->assertTrue( $page_two['done'] );

		$this->assertNull( $this->judges->find( $first ) );
		$this->assertNull( $this->judges->find( $second ) );
		$this->assertNotNull( $this->judges->find( $other ) );
	}

	public function test_eraser_with_no_judge_is_done(): void {
		$response = $this->run_eraser( 'nobody@example.com' );

		$this->assertFalse( $response['items_removed'] );
		$this->assertTrue( $response['done'] );
	}
}
