<?php
/**
 * Tests for Results_Ranking.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competition_Types_Repository;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Tests\Type_Fixtures;
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
use PhotoCompetitionManager\Tests\Member_Fixtures;
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
				'type_id'    => Type_Fixtures::id(),
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

	/**
	 * Seed a club's panels: each category's images, each with its votes.
	 *
	 * @param string                    $name   Club name.
	 * @param string                    $grade  Grade slug.
	 * @param array<string, int[][]>    $panels Category slug => each image's votes.
	 * @return int The club's member ID.
	 */
	private function seed_club( string $name, string $grade, array $panels ): int {
		$club_id = Member_Fixtures::insert_with_grade( $name, sanitize_title( $name ) . '@example.com', $grade );
		foreach ( $panels as $category => $images ) {
			foreach ( $images as $scores ) {
				Entry_Fixtures::insert_entry( $this->competition_id, $category, $club_id, $scores );
			}
		}

		return $club_id;
	}

	/**
	 * Map club standings to "grade" => array of "Club: colour total/position, black-white total/position = total/position".
	 *
	 * @param array<int, array> $groups Results_Ranking::rank_clubs() groups.
	 * @return array<string, array<int, string>>
	 */
	private function summarize_clubs( array $groups ): array {
		$summary = array();
		foreach ( $groups as $group ) {
			$summary[ $group['slug'] ] = array_map(
				static function ( array $club ): string {
					$panels = array();
					foreach ( $club['panels'] as $category => $panel ) {
						$panels[] = $category . ' ' . ( $panel ? $panel['total'] . '/' . $panel['position'] : '-' );
					}

					return $club['member']->name . ': ' . implode( ', ', $panels ) . ' = ' . ( null === $club['total'] ? '-' : $club['total'] . '/' . $club['position'] );
				},
				$group['clubs']
			);
		}

		return $summary;
	}

	public function test_a_clubs_panel_adds_up_its_images_and_its_total_adds_up_its_panels(): void {
		$this->seed_club(
			'Lee',
			'beginner',
			array(
				'colour'      => array( array( 9, 8 ), array( 7 ) ),
				'black-white' => array( array( 5 ) ),
			)
		);
		$this->seed_club(
			'Bandon',
			'beginner',
			array(
				'colour'      => array( array( 6 ) ),
				'black-white' => array( array( 9 ), array( 9 ) ),
			)
		);

		$this->assertSame(
			array(
				'beginner' => array(
					'Lee: colour 24/1, black-white 5/2 = 29/1',
					'Bandon: colour 6/2, black-white 18/1 = 24/2',
				),
			),
			$this->summarize_clubs( $this->ranking->rank_clubs( $this->competition_id ) )
		);
	}

	public function test_tied_clubs_share_a_place_and_the_next_total_takes_the_next(): void {
		foreach ( array( 'Ann' => 10, 'Bob' => 10, 'Cat' => 4 ) as $name => $score ) {
			$this->seed_club(
				$name,
				'beginner',
				array(
					'colour'      => array( array( $score ) ),
					'black-white' => array( array( $score ) ),
				)
			);
		}

		$this->assertSame(
			array( 'beginner' => array( 'Ann: colour 10/1, black-white 10/1 = 20/1', 'Bob: colour 10/1, black-white 10/1 = 20/1', 'Cat: colour 4/2, black-white 4/2 = 8/2' ) ),
			$this->summarize_clubs( $this->ranking->rank_clubs( $this->competition_id ) )
		);
	}

	public function test_a_club_that_skipped_a_panel_has_no_overall_place_and_comes_last(): void {
		$this->seed_club( 'Skipper', 'beginner', array( 'colour' => array( array( 10, 10, 10 ) ) ) );
		$this->seed_club(
			'Lee',
			'beginner',
			array(
				'colour'      => array( array( 2 ) ),
				'black-white' => array( array( 2 ) ),
			)
		);

		$this->assertSame(
			array( 'beginner' => array( 'Lee: colour 2/2, black-white 2/1 = 4/1', 'Skipper: colour 30/1, black-white - = -' ) ),
			$this->summarize_clubs( $this->ranking->rank_clubs( $this->competition_id ) )
		);
	}

	public function test_a_panel_nobody_in_the_grade_entered_isnt_needed_for_a_place(): void {
		$this->seed_club( 'Lee', 'beginner', array( 'colour' => array( array( 5 ) ) ) );
		$this->seed_club( 'Bandon', 'advanced', array( 'black-white' => array( array( 6 ) ) ) );

		$this->assertSame(
			array(
				'beginner' => array( 'Lee: colour 5/1, black-white - = 5/1' ),
				'advanced' => array( 'Bandon: colour -, black-white 6/1 = 6/1' ),
			),
			$this->summarize_clubs( $this->ranking->rank_clubs( $this->competition_id ) )
		);
	}

	public function test_clubs_are_ranked_within_their_grade_and_ungraded_ones_are_left_out(): void {
		$this->seed_club( 'Shield', 'intermediate', array( 'colour' => array( array( 9 ) ) ) );
		$this->seed_club( 'Cup', 'advanced', array( 'colour' => array( array( 3 ) ) ) );
		$this->seed_club( 'Nowhere', 'no-such-grade', array( 'colour' => array( array( 10 ) ) ) );

		$this->assertSame(
			array(
				'intermediate' => array( 'Shield: colour 9/1, black-white - = 9/1' ),
				'advanced'     => array( 'Cup: colour 3/1, black-white - = 3/1' ),
			),
			$this->summarize_clubs( $this->ranking->rank_clubs( $this->competition_id ) )
		);
	}

	public function test_published_club_standings_dont_change_with_later_votes(): void {
		$this->seed_club( 'Lee', 'beginner', array( 'colour' => array( array( 5 ) ) ) );
		$bandon       = $this->seed_club( 'Bandon', 'beginner', array( 'colour' => array( array( 4 ) ) ) );
		$bandon_image = (int) $this->images->find_entered( $this->competition_id, 'colour', $bandon )[0]->id;
		Workflow_Fixtures::publish_results( $this->competition_id );

		$this->late_vote( $bandon_image, 10 );

		$this->assertSame(
			array( 'beginner' => array( 'Lee: colour 5/1, black-white - = 5/1', 'Bandon: colour 4/2, black-white - = 4/2' ) ),
			$this->summarize_clubs( $this->ranking->rank_clubs( $this->competition_id ) )
		);
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

	/**
	 * Leaving a grade out of a type stops new entries, but entries already in
	 * it still have results, under the grade's name (#247).
	 */
	public function test_entries_in_a_grade_the_type_left_out_still_have_results(): void {
		$this->seed_entry( 'Beg', 'beginner', array( 5 ) );
		$this->seed_entry( 'Adv', 'advanced', array( 9 ) );
		$types = new Competition_Types_Repository();
		$type  = $types->find( Type_Fixtures::id() );
		$colour = array( array( 'label' => 'Colour', 'slug' => 'colour', 'quota' => 2 ) );
		$this->assertTrue( $types->update( (int) $type->id, $type->name, array( 'categories' => $colour ), $type->grade_names, array( 'beginner' ) ) );

		$groups = $this->ranking->rank_category( $this->competition_id, 'colour' );

		$this->assertSame( array( 'beginner' => array( 'Beg:1:5' ), 'advanced' => array( 'Adv:1:9' ) ), $this->summarize( $groups ) );
		$this->assertSame( 'Beginner', $groups[0]['label'] );
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

	public function test_unrenamed_type_output_is_unchanged(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 5 ) );

		$groups = $this->ranking->rank_category( $this->competition_id, 'colour' );

		$this->assertSame( Competition_Settings::grade_labels()['beginner'], $groups[0]['label'] );
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

		$this->late_vote( $bob, 9 );
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

		$this->late_vote( $bob, 9 );

		$this->assertSame(
			array( 'beginner' => array( 'Bob:1:14', 'Ann:2:9' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_hiding_results_keeps_their_record(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		Workflow_Fixtures::publish_results( $this->competition_id );

		$this->assertTrue( ( new Competition_Workflow() )->unpublish_results( $this->competition_id ) );

		$rows = ( new Recorded_Results_Repository() )->find_by_category( $this->competition_id, 'colour' );
		$this->assertSame( array( 9 ), array_map( 'intval', array_column( $rows, 'total_score' ) ) );
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

		$this->close_at( utc_time( DAY_IN_SECONDS ) );
		$this->assertTrue( $workflow->unpublish_results( $this->competition_id ) );
		$this->late_vote( $bob, 9 );
		Workflow_Fixtures::publish_results( $this->competition_id );

		$this->assertSame(
			array( 'beginner' => array( 'Bob:1:14', 'Ann:2:9' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_results_hidden_when_the_competition_closes_are_recorded_afresh(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob = $this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$this->publish_then_hide_an_hour_ago();
		$this->late_vote( $bob, 9 );
		$this->close_competition();

		$groups = $this->ranking->rank_category( $this->competition_id, 'colour' );

		$this->assertSame( array( 'beginner' => array( 'Bob:1:14', 'Ann:2:9' ) ), $this->summarize( $groups ) );
		$this->assertSame( array( true, true ), array_column( $groups[0]['entries'], 'recorded' ) );
	}

	public function test_showing_results_after_close_keeps_the_record_made_at_close(): void {
		$ann = $this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob = $this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$this->publish_then_hide_an_hour_ago();
		$this->late_vote( $bob, 9 );
		$this->close_competition();
		$this->ranking->rank_category( $this->competition_id, 'colour' );
		$this->late_vote( $ann, 9 );

		Workflow_Fixtures::publish_results( $this->competition_id );

		$this->assertSame(
			array( 'beginner' => array( 'Bob:1:14', 'Ann:2:9' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_a_competition_archived_while_open_is_recorded_afresh_once_restored_and_closed(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob          = $this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$competitions = new Competitions_Repository();
		$this->assertTrue( $competitions->archive( $this->competition_id ) );
		$this->ranking->rank_category( $this->competition_id, 'colour' );
		$this->late_vote( $bob, 9 );
		$this->assertSame(
			array( 'beginner' => array( 'Ann:1:9', 'Bob:2:5' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) ),
			'A record made while archived is read while it stays archived.'
		);
		$this->record_made_at( utc_time( -HOUR_IN_SECONDS ) );

		$this->assertTrue( $competitions->restore( $this->competition_id ) );
		$this->close_competition();

		$this->assertSame(
			array( 'beginner' => array( 'Bob:1:14', 'Ann:2:9' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_results_published_when_the_competition_closes_keep_their_record(): void {
		$ann = $this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob = $this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		Workflow_Fixtures::publish_results( $this->competition_id );
		$this->record_made_at( utc_time( -HOUR_IN_SECONDS ) );
		$this->close_competition();
		$this->late_vote( $bob, 9 );

		$this->delete_member_of( $ann );

		$this->assertSame(
			array( 'beginner' => array( '(missing):1:9', 'Bob:2:5' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_archiving_a_closed_competition_keeps_the_record_made_at_close(): void {
		$ann = $this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob = $this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$this->close_at( utc_time( -2 * HOUR_IN_SECONDS ) );
		$this->ranking->rank_category( $this->competition_id, 'colour' );
		$this->record_made_at( utc_time( -HOUR_IN_SECONDS ) );
		$this->assertTrue( ( new Competitions_Repository() )->archive( $this->competition_id ) );
		$this->late_vote( $bob, 9 );

		$this->delete_member_of( $ann );

		$this->assertSame(
			array( 'beginner' => array( '(missing):1:9', 'Bob:2:5' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_a_record_made_before_close_is_replaced_before_an_entry_is_removed(): void {
		$ann = $this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob = $this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$this->publish_then_hide_an_hour_ago();
		$this->late_vote( $bob, 9 );
		$this->close_competition();

		$this->assertTrue( ( new Entries() )->remove( Actor::admin(), $this->competition_id, $ann ) );

		$groups = $this->ranking->rank_category( $this->competition_id, 'colour' );
		$this->assertSame( array( 'beginner' => array( 'Bob:1:14', 'Ann:2:9' ) ), $this->summarize( $groups ) );
		$this->assertNull( $groups[0]['entries'][1]['image'] );
	}

	public function test_a_record_made_before_close_is_replaced_before_a_members_entries_are_removed(): void {
		$ann = $this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob = $this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$this->publish_then_hide_an_hour_ago();
		$this->late_vote( $bob, 9 );
		$this->close_competition();

		$this->delete_member_of( $ann );

		$this->assertSame(
			array( 'beginner' => array( 'Bob:1:14', '(missing):2:9' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	/**
	 * Records made at the close date and just either side of it.
	 *
	 * @return array<string, array{int, string[]}>
	 */
	public function records_around_the_close_date(): array {
		return array(
			'a second before is replaced' => array( -1, array( 'Bob:1:14', 'Ann:2:9' ) ),
			'the same second is kept'     => array( 0, array( 'Ann:1:9', 'Bob:2:5' ) ),
			'a second after is kept'      => array( 1, array( 'Ann:1:9', 'Bob:2:5' ) ),
		);
	}

	/**
	 * A record made before the close date is replaced; one made after is kept.
	 *
	 * @dataProvider records_around_the_close_date
	 *
	 * @param int      $offset   Seconds from the close date the record was made.
	 * @param string[] $expected Summarised ranking.
	 */
	public function test_a_record_counts_only_if_made_once_the_close_date_passed( int $offset, array $expected ): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob   = $this->seed_entry( 'Bob', 'beginner', array( 5 ) );
		$close = utc_time( -HOUR_IN_SECONDS );
		$this->close_at( $close );
		$this->ranking->rank_category( $this->competition_id, 'colour' );
		$this->record_made_at( gmdate( 'Y-m-d H:i:s', strtotime( $close . ' UTC' ) + $offset ) );
		$this->late_vote( $bob, 9 );

		$this->assertSame(
			array( 'beginner' => $expected ),
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

	/**
	 * A member moved up a grade before their competitions were recorded is put
	 * back in the grade they entered in, in every category, and both grades are
	 * ranked again from the recorded scores.
	 */
	public function test_correcting_a_members_grade_moves_their_entries_and_ranks_both_grades_again(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		$bob = $this->member_id_of( $this->seed_entry( 'Bob', 'intermediate', array( 8 ) ) );
		Entry_Fixtures::insert_entry( $this->competition_id, 'black-white', $bob, array( 6 ) );
		$this->seed_entry( 'Cat', 'beginner', array( 5 ) );
		$this->seed_entry( 'Dan', 'intermediate', array( 7 ) );
		$this->close_competition();

		$changes = $this->ranking->correct_grades( $this->competition(), array( $bob => 'beginner' ) );

		$this->assertSame(
			array(
				$bob => array(
					'from' => 'intermediate',
					'to'   => 'beginner',
				),
			),
			$changes
		);
		$this->assertSame(
			array(
				'beginner'     => array( 'Ann:1:9', 'Bob:2:8', 'Cat:3:5' ),
				'intermediate' => array( 'Dan:1:7' ),
			),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
		$this->assertSame(
			array( 'beginner' => array( 'Bob:1:6' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'black-white' ) )
		);
	}

	/**
	 * Until a competition has closed its results can still be recorded again,
	 * which would undo a correction, so the member's own grade is what to change.
	 */
	public function test_a_competition_that_hasnt_closed_has_no_grades_to_correct(): void {
		$bob = $this->member_id_of( $this->seed_entry( 'Bob', 'intermediate', array( 8 ) ) );
		Workflow_Fixtures::publish_results( $this->competition_id );

		$corrected = $this->ranking->correct_grades( $this->competition(), array( $bob => 'beginner' ) );

		$this->assertWPError( $corrected );
		$this->assertSame( 'competition_not_closed', $corrected->get_error_code() );
	}

	public function test_a_grade_that_isnt_the_clubs_is_refused_and_nothing_changes(): void {
		$bob = $this->member_id_of( $this->seed_entry( 'Bob', 'intermediate', array( 8 ) ) );
		$this->close_competition();

		$corrected = $this->ranking->correct_grades( $this->competition(), array( $bob => 'expert' ) );

		$this->assertWPError( $corrected );
		$this->assertSame( 'invalid_grade', $corrected->get_error_code() );
		$this->assertSame(
			array( 'intermediate' => array( 'Bob:1:8' ) ),
			$this->summarize( $this->ranking->rank_category( $this->competition_id, 'colour' ) )
		);
	}

	/**
	 * Saving the form sends every member's grade; only the ones that changed count,
	 * and a grade no longer on the club's list can be left as it is.
	 */
	public function test_unchanged_grades_are_not_corrections(): void {
		$ann = $this->member_id_of( $this->seed_entry( 'Ann', 'beginner', array( 9 ) ) );
		$bob = $this->member_id_of( $this->seed_entry( 'Bob', 'advanced', array( 8 ) ) );
		$this->close_competition();
		$this->ranking->rank_category( $this->competition_id, 'colour' );
		// The club drops Beginner after Ann's entry was recorded in it.
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

		$this->assertSame(
			array(),
			$this->ranking->correct_grades(
				$this->competition(),
				array(
					$ann => 'beginner',
					$bob => 'advanced',
				)
			)
		);
	}

	public function test_results_are_not_published_when_they_cannot_be_recorded(): void {
		$this->seed_entry( 'Ann', 'beginner', array( 9 ) );
		Workflow_Fixtures::close_uploads( $this->competition_id );
		$workflow = new Competition_Workflow( null, null, null, null, $this->failing_ranking() );

		$this->assertWPError( $workflow->publish_results( $this->competition_id ) );

		$competition = ( new Competitions_Repository() )->find( $this->competition_id );
		$this->assertFalse( $workflow->results_published( $competition ) );
		$this->assertNull( ( new Recorded_Results_Repository() )->recorded_at( $this->competition_id ) );
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
		$this->assertNull( ( new Recorded_Results_Repository() )->recorded_at( $this->competition_id ) );
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
	 * Publish the results, then hide them, an hour ago.
	 */
	private function publish_then_hide_an_hour_ago(): void {
		Workflow_Fixtures::publish_results( $this->competition_id );
		$this->assertTrue( ( new Competition_Workflow() )->unpublish_results( $this->competition_id ) );
		$this->record_made_at( utc_time( -HOUR_IN_SECONDS ) );
	}

	/**
	 * The competition's row, as it is now.
	 *
	 * @return object
	 */
	private function competition(): object {
		return ( new Competitions_Repository() )->find( $this->competition_id );
	}

	/**
	 * Close the competition now, as the Competitions screen does.
	 */
	private function close_competition(): void {
		$this->assertTrue( ( new Competition_Workflow() )->close_competition( $this->competition_id ) );
	}

	/**
	 * Say the competition's record was made at a given time, such as before
	 * something the test does next within the same second.
	 *
	 * @param string $utc UTC datetime.
	 */
	private function record_made_at( string $utc ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET created_at = %s WHERE competition_id = %d',
				( new Recorded_Results_Repository() )->table(),
				$utc,
				$this->competition_id
			)
		);
	}

	/**
	 * Set the competition's close date.
	 *
	 * @param string $utc UTC datetime.
	 */
	private function close_at( string $utc ): void {
		$this->assertTrue( ( new Competitions_Repository() )->update( $this->competition_id, array( 'close_date' => $utc ) ) );
	}

	/**
	 * Add a vote for an entry.
	 *
	 * @param int $image_id Image ID.
	 * @param int $score    Score.
	 */
	private function late_vote( int $image_id, int $score ): void {
		( new Votes_Repository() )->create( $this->competition_id, 'colour', 'Late Voter', $image_id, $score );
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
