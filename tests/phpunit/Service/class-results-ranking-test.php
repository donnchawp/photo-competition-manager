<?php
/**
 * Tests for Results_Ranking.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Recorded_Results_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Results_Ranking;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_Error;
use WP_UnitTestCase;

use function PhotoCompetitionManager\Support\utc_time;

class Results_Ranking_Test extends WP_UnitTestCase {

	/**
	 * @var Results_Ranking
	 */
	private $ranking;

	/**
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * @var int
	 */
	private $competition_id;

	public function set_up(): void {
		parent::set_up();

		$this->images  = new Images_Repository();
		$this->ranking = new Results_Ranking( $this->images, new Votes_Repository(), new Members_Repository() );

		$this->competition_id = (int) ( new Competitions_Repository() )->create(
			array(
				'title'    => 'Ranking Comp',
				'slug'     => 'ranking-comp',
				'settings' => array(
					'grades' => array(
						array(
							'slug'  => 'advanced',
							'label' => 'Stale Advanced',
						),
					),
				),
			)
		);
	}

	/**
	 * Seed a colour entry for a member with any grade and score it.
	 *
	 * @param string $name   Member name.
	 * @param string $grade  Grade slug, valid or not.
	 * @param int[]  $scores Votes to give the entry.
	 * @return int Image ID.
	 */
	private function seed_entry( string $name, string $grade, array $scores ): int {
		return Entry_Fixtures::insert_scored_entry( $this->competition_id, 'colour', $name, $grade, $scores );
	}

	/**
	 * Map groups to "slug" => array of "name:position:total_score" strings.
	 *
	 * @param array<int, array> $groups Ranked groups.
	 * @return array<string, array<int, string>>
	 */
	private function summarize( array $groups ): array {
		$summary = array();
		foreach ( $groups as $group ) {
			$summary[ $group['slug'] ] = array_map(
				static function ( array $entry ): string {
					$name = $entry['member'] ? $entry['member']->name : '(missing)';
					return $name . ':' . $entry['position'] . ':' . $entry['total_score'];
				},
				$group['entries']
			);
		}

		return $summary;
	}

	public function test_tied_scores_share_a_position_and_the_next_score_takes_the_next(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9, 8 ) );
		$this->seed_entry( 'Bob', 'beginner', array( 9, 8 ) );
		$this->seed_entry( 'Cat', 'beginner', array( 5, 4 ) );

		$groups = $this->ranking->rank_category( $this->competition_id, 'colour' );

		$this->assertSame(
			array( 'beginner' => array( 'Ann:1:17', 'Bob:1:17', 'Cat:2:9' ) ),
			$this->summarize( $groups )
		);
	}

	public function test_groups_follow_the_club_grade_order(): void {
		$this->seed_entry( 'Adv', 'advanced', array( 9 ) );
		$this->seed_entry( 'Beg', 'beginner', array( 5 ) );
		$this->seed_entry( 'Int', 'intermediate', array( 7 ) );

		$groups = $this->ranking->rank_category( $this->competition_id, 'colour' );

		$this->assertSame( array( 'beginner', 'intermediate', 'advanced' ), array_column( $groups, 'slug' ) );
		$this->assertSame( array( 'Beginner', 'Intermediate', 'Advanced' ), array_column( $groups, 'label' ) );
		$this->assertSame( array( false, false, false ), array_column( $groups, 'ungraded' ) );
	}

	public function test_entry_without_votes_scores_zero(): void {
		$this->seed_entry( 'Unvoted', 'beginner', array() );
		$this->seed_entry( 'Voted', 'beginner', array( 6 ) );

		$groups = $this->ranking->rank_category( $this->competition_id, 'colour' );

		$this->assertSame(
			array( 'beginner' => array( 'Voted:1:6', 'Unvoted:2:0' ) ),
			$this->summarize( $groups )
		);
		$this->assertSame( 0, $groups[0]['entries'][1]['vote_count'] );
	}

	public function test_entries_without_a_club_grade_go_in_a_trailing_ungraded_group(): void {
		$this->seed_entry( 'Graded', 'beginner', array( 5 ) );
		$this->seed_entry( 'No Grade', '', array( 9 ) );
		$this->seed_entry( 'Old Grade', 'retired', array( 7 ) );
		Entry_Fixtures::insert_entry( $this->competition_id, 'colour', 999999, array( 7 ) );

		$groups = $this->ranking->rank_category( $this->competition_id, 'colour' );

		$this->assertSame(
			array(
				'beginner' => array( 'Graded:1:5' ),
				''         => array( 'No Grade:1:9', 'Old Grade:2:7', '(missing):2:7' ),
			),
			$this->summarize( $groups )
		);
		$this->assertTrue( $groups[1]['ungraded'] );
		$this->assertSame( 'Ungraded', $groups[1]['label'] );
	}

	public function test_competitions_own_grade_list_is_ignored(): void {
		update_option(
			'photo_comp_default_settings',
			Competition_Settings::encode(
				array(
					'grades' => array(
						array(
							'slug'  => 'beginner',
							'label' => 'Club Starters',
						),
					),
				)
			)
		);
		$this->seed_entry( 'Starter', 'beginner', array( 5 ) );
		$this->seed_entry( 'Senior', 'advanced', array( 9 ) );

		$groups = $this->ranking->rank_category( $this->competition_id, 'colour' );

		$this->assertSame( array( 'beginner', '' ), array_column( $groups, 'slug' ) );
		$this->assertSame( 'Club Starters', $groups[0]['label'] );
	}

	public function test_category_with_no_entries_has_no_groups(): void {
		$this->seed_entry( 'Colour Only', 'beginner', array( 5 ) );

		$this->assertSame( array(), $this->ranking->rank_category( $this->competition_id, 'mono' ) );
	}

	public function test_published_results_keep_their_order_after_a_member_is_deleted_or_changes_grade(): void {
		$ann = $this->seed_entry( 'Ann', 'beginner', array( 9, 9 ) );
		$bob = $this->seed_entry( 'Bob', 'beginner', array( 7, 7 ) );
		$this->seed_entry( 'Cat', 'beginner', array( 5, 5 ) );
		Workflow_Fixtures::publish_results( $this->competition_id );

		$this->delete_member_of( $ann );
		( new Members_Repository() )->update( $this->member_id_of( $bob ), array( 'grade' => 'intermediate' ) );

		$this->assertSame(
			array( 'beginner' => array( '(missing):1:18', 'Bob:2:14', 'Cat:3:10' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_publishing_again_replaces_the_record(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob = $this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		Workflow_Fixtures::publish_results( $this->competition_id );
		$this->assertTrue( ( new Competition_Workflow() )->unpublish_results( $this->competition_id ) );

		( new Votes_Repository() )->create( $this->competition_id, 'colour', 'Late Voter', $bob, 9 );
		Workflow_Fixtures::publish_results( $this->competition_id );

		$this->assertSame(
			array( 'beginner' => array( 'Bob:1:14', 'Ann:2:9' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_hidden_results_are_worked_out_from_the_votes(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob = $this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		Workflow_Fixtures::publish_results( $this->competition_id );
		$this->assertTrue( ( new Competition_Workflow() )->unpublish_results( $this->competition_id ) );

		( new Votes_Repository() )->create( $this->competition_id, 'colour', 'Late Voter', $bob, 9 );

		$this->assertSame(
			array( 'beginner' => array( 'Bob:1:14', 'Ann:2:9' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_entries_say_whether_they_come_from_the_record(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$this->seed_entry( 'Orphan', 'retired', array( 5 ) );

		$live = $this->ranking->rank_category( $this->competition_id, 'colour' );
		Workflow_Fixtures::publish_results( $this->competition_id );
		$recorded = $this->ranking->rank_category( $this->competition_id, 'colour' );

		$this->assertSame( array( false, false ), array_column( array_merge( ...array_column( $live, 'entries' ) ), 'recorded' ) );
		$this->assertSame( array( true, true ), array_column( array_merge( ...array_column( $recorded, 'entries' ) ), 'recorded' ) );
	}

	public function test_a_closed_competition_is_recorded_the_first_time_it_is_ranked(): void {
		$ann = $this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$this->close_competition();
		$this->ranking->rank_category( $this->competition_id, 'colour' );

		$this->delete_member_of( $ann );

		$this->assertSame(
			array( 'beginner' => array( '(missing):1:9', 'Bob:2:5' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_a_closed_competition_is_recorded_before_a_member_is_deleted(): void {
		$ann = $this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$this->close_competition();

		$this->delete_member_of( $ann );

		$this->assertSame(
			array( 'beginner' => array( '(missing):1:9', 'Bob:2:5' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_a_closed_competition_is_recorded_before_an_entry_is_removed(): void {
		$ann = $this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$this->close_competition();

		$this->assertTrue( ( new Entries() )->remove( Actor::admin(), $this->competition_id, $ann ) );

		$groups = $this->ranking->rank_category( $this->competition_id, 'colour' );
		$this->assertSame( array( 'beginner' => array( 'Ann:1:9', 'Bob:2:5' ) ), $this->summarize( $groups ) );
		$this->assertNull( $groups[0]['entries'][0]['image'] );
	}

	public function test_publishing_a_closed_competition_keeps_its_record(): void {
		$ann = $this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$this->close_competition();
		$this->ranking->rank_category( $this->competition_id, 'colour' );
		$this->delete_member_of( $ann );

		Workflow_Fixtures::publish_results( $this->competition_id );

		$this->assertSame(
			array( 'beginner' => array( '(missing):1:9', 'Bob:2:5' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_moving_the_close_date_into_the_future_lets_results_be_published_again(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob = $this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		Workflow_Fixtures::publish_results( $this->competition_id );
		$this->close_competition();
		$workflow = new Competition_Workflow();
		$this->assertWPError( $workflow->unpublish_results( $this->competition_id ) );

		( new Competitions_Repository() )->update( $this->competition_id, array( 'close_date' => utc_time( DAY_IN_SECONDS ) ) );
		$this->assertTrue( $workflow->unpublish_results( $this->competition_id ) );
		( new Votes_Repository() )->create( $this->competition_id, 'colour', 'Late Voter', $bob, 9 );
		Workflow_Fixtures::publish_results( $this->competition_id );

		$this->assertSame(
			array( 'beginner' => array( 'Bob:1:14', 'Ann:2:9' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_recorded_entries_keep_a_grade_no_longer_on_the_club_list(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		Workflow_Fixtures::publish_results( $this->competition_id );
		update_option(
			'photo_comp_default_settings',
			Competition_Settings::encode(
				array(
					'grades' => array(
						array(
							'slug'  => 'advanced',
							'label' => 'Advanced',
						),
					),
				)
			)
		);

		$groups = $this->ranking->rank_category( $this->competition_id, 'colour' );

		$this->assertSame( array( 'beginner' => array( 'Ann:1:9' ) ), $this->summarize( $groups ) );
		$this->assertSame( 'beginner', $groups[0]['label'] );
		$this->assertFalse( $groups[0]['ungraded'] );
	}

	public function test_results_are_not_published_when_they_cannot_be_recorded(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		Workflow_Fixtures::close_uploads( $this->competition_id );
		$workflow = new Competition_Workflow( null, null, null, null, $this->failing_ranking() );

		$this->assertWPError( $workflow->publish_results( $this->competition_id ) );

		$competition = ( new Competitions_Repository() )->find( $this->competition_id );
		$this->assertFalse( $workflow->results_published( $competition ) );
		$this->assertFalse( ( new Recorded_Results_Repository() )->has_record( $this->competition_id ) );
	}

	public function test_nothing_is_removed_from_a_closed_competition_that_cannot_be_recorded(): void {
		$ann = $this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob = $this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$this->close_competition();
		$entries = new Entries( null, null, null, null, null, null, $this->failing_ranking() );

		$this->assertWPError( $entries->remove( Actor::admin(), $this->competition_id, $ann ) );
		$this->assertWPError( $entries->remove_member_entries( Actor::admin(), $this->member_id_of( $bob ) ) );

		$this->assertNotNull( $this->images->find( $ann ) );
		$this->assertNotNull( $this->images->find( $bob ) );
	}

	public function test_a_closed_competition_that_cannot_be_recorded_is_worked_out_from_the_votes(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$this->close_competition();

		$groups = $this->failing_ranking()->rank_category( $this->competition_id, 'colour' );

		$this->assertSame( array( 'beginner' => array( 'Ann:1:9', 'Bob:2:5' ) ), $this->summarize( $groups ) );
		$this->assertFalse( ( new Recorded_Results_Repository() )->has_record( $this->competition_id ) );
	}

	/**
	 * A ranking whose record can't be written, as when the database refuses.
	 *
	 * @return Results_Ranking
	 */
	private function failing_ranking(): Results_Ranking {
		$record = new class() extends Recorded_Results_Repository {
			/**
			 * Refuse to record.
			 *
			 * @param array $rows Rows to record.
			 * @return WP_Error
			 */
			public function insert( array $rows ) {
				return new WP_Error( 'record_failed', 'Could not record the results.' );
			}
		};

		return new Results_Ranking( $this->images, new Votes_Repository(), new Members_Repository(), null, null, $record );
	}

	/**
	 * Close the competition now, as the Competitions screen does.
	 */
	private function close_competition(): void {
		$this->assertTrue( ( new Competition_Workflow() )->close_competition( $this->competition_id ) );
	}

	/**
	 * The member who entered an entry.
	 *
	 * @param int $image_id Image ID.
	 * @return int Member ID.
	 */
	private function member_id_of( int $image_id ): int {
		return (int) $this->images->find( $image_id )->member_id;
	}

	/**
	 * Delete the member who entered an entry, as the Members screen does.
	 *
	 * @param int $image_id Image ID.
	 */
	private function delete_member_of( int $image_id ): void {
		$member_id = $this->member_id_of( $image_id );

		$this->assertTrue( ( new Entries() )->remove_member_entries( Actor::admin(), $member_id ) );
		$this->assertTrue( ( new Members_Repository() )->delete( $member_id ) );
	}
}
