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
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Results_Ranking;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use WP_UnitTestCase;

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
	 * @var Votes_Repository
	 */
	private $votes;

	/**
	 * @var int
	 */
	private $competition_id;

	/**
	 * Voter number for the next vote, so every vote has its own voter.
	 *
	 * @var int
	 */
	private $next_voter = 1;

	public function set_up(): void {
		parent::set_up();

		$this->images  = new Images_Repository();
		$this->votes   = new Votes_Repository();
		$this->ranking = new Results_Ranking( $this->images, $this->votes, new Members_Repository() );

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
	 * @param string   $name   Member name.
	 * @param string   $grade  Grade slug, valid or not.
	 * @param int[]    $scores Votes to give the entry.
	 * @return int Image ID.
	 */
	private function seed_entry( string $name, string $grade, array $scores ): int {
		$member_id = Member_Fixtures::insert_with_grade( $name, sanitize_title( $name ) . '@example.com', $grade );

		return $this->seed_image( $member_id, $scores );
	}

	/**
	 * Seed a colour image for a member ID and score it.
	 *
	 * @param int   $member_id Member ID, which need not exist.
	 * @param int[] $scores    Votes to give the image.
	 * @return int Image ID.
	 */
	private function seed_image( int $member_id, array $scores ): int {
		$image_id = (int) $this->images->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'entry-' . $member_id . '.jpg',
			)
		);

		foreach ( $scores as $score ) {
			$this->votes->create_anonymous( $this->competition_id, 'colour', $this->next_voter++, $image_id, $score );
		}

		return $image_id;
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

	public function test_entry_without_votes_scores_zero_even_with_a_cached_score(): void {
		$image_id = $this->seed_entry( 'Cached', 'beginner', array() );
		$this->images->update_score( $image_id, 40 );
		$this->seed_entry( 'Voted', 'beginner', array( 6 ) );

		$groups = $this->ranking->rank_category( $this->competition_id, 'colour' );

		$this->assertSame(
			array( 'beginner' => array( 'Voted:1:6', 'Cached:2:0' ) ),
			$this->summarize( $groups )
		);
		$this->assertSame( 0, $groups[0]['entries'][1]['vote_count'] );
	}

	public function test_entries_without_a_club_grade_go_in_a_trailing_ungraded_group(): void {
		$this->seed_entry( 'Graded', 'beginner', array( 5 ) );
		$this->seed_entry( 'No Grade', '', array( 9 ) );
		$this->seed_entry( 'Old Grade', 'retired', array( 7 ) );
		$this->seed_image( 999999, array( 7 ) );

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
}
