<?php
/**
 * Tests for Score_Calculator.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Score_Calculator;
use WP_UnitTestCase;

class Score_Calculator_Test extends WP_UnitTestCase {

	/**
	 * @var Score_Calculator
	 */
	private $calculator;

	/**
	 * @var Images_Repository
	 */
	private $images_repo;

	/**
	 * @var Votes_Repository
	 */
	private $votes_repo;

	/**
	 * @var Members_Repository
	 */
	private $members_repo;

	/**
	 * @var int
	 */
	private $competition_id;

	/**
	 * @var int
	 */
	private $member_id;

	public function setUp(): void {
		parent::setUp();

		$this->images_repo  = new Images_Repository();
		$this->votes_repo   = new Votes_Repository();
		$this->members_repo = new Members_Repository();
		$this->calculator   = new Score_Calculator( $this->images_repo, $this->votes_repo );

		$competitions_repo = new Competitions_Repository();

		$this->competition_id = $competitions_repo->create(
			array(
				'title'    => 'Test Competition',
				'slug'     => 'test-comp',
				'settings' => wp_json_encode( array( 'categories' => array() ) ),
			)
		);

		$this->member_id = $this->members_repo->create(
			array(
				'name'  => 'Alice',
				'email' => 'alice@example.com',
				'grade' => 'beginner',
			)
		);
	}

	/**
	 * Helper to create an image and return its ID.
	 */
	private function create_image( string $category, ?int $member_id = null ): int {
		$member_id = $member_id ?? $this->member_id;
		return $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $member_id,
				'category'       => $category,
				'filename'       => "test-{$category}-" . wp_rand() . '.jpg',
			)
		);
	}

	// ---------------------------------------------------------------
	// calculate_scores()
	// ---------------------------------------------------------------

	public function test_calculate_scores_returns_zero_counts_when_no_votes(): void {
		$result = $this->calculator->calculate_scores( $this->competition_id );

		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 0, $result['errors'] );
	}

	public function test_calculate_scores_updates_image_score_to_vote_sum(): void {
		$image_id = $this->create_image( 'colour' );

		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter A', $image_id, 8 );
		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter B', $image_id, 6 );

		$result = $this->calculator->calculate_scores( $this->competition_id );

		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 0, $result['errors'] );

		$image = $this->images_repo->find( $image_id );
		$this->assertEquals( 14, $image->score );
	}

	public function test_calculate_scores_filters_by_category(): void {
		$colour_image = $this->create_image( 'colour' );
		$bw_image     = $this->create_image( 'black-white' );

		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter A', $colour_image, 9 );
		$this->votes_repo->create( $this->competition_id, 'black-white', 'Voter A', $bw_image, 7 );

		$result = $this->calculator->calculate_scores( $this->competition_id, 'colour' );

		$this->assertSame( 1, $result['updated'] );

		// BW image score should not have been updated.
		$bw = $this->images_repo->find( $bw_image );
		$this->assertEmpty( $bw->score );
	}

	// ---------------------------------------------------------------
	// get_results()
	// ---------------------------------------------------------------

	public function test_get_results_returns_empty_array_when_no_images(): void {
		$results = $this->calculator->get_results( $this->competition_id );

		$this->assertSame( array(), $results );
	}

	public function test_get_results_sorts_by_total_score_descending(): void {
		$image_a = $this->create_image( 'colour' );
		$image_b = $this->create_image( 'colour' );

		// Image A gets lower total score.
		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter A', $image_a, 3 );

		// Image B gets higher total score.
		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter A', $image_b, 9 );

		$results    = $this->calculator->get_results( $this->competition_id );
		$result_ids = array_map( fn( $r ) => (int) $r->id, $results );

		$this->assertSame( array( $image_b, $image_a ), $result_ids );
	}

	public function test_get_results_enriches_images_with_score_data(): void {
		$image_id = $this->create_image( 'colour' );

		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter A', $image_id, 8 );
		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter B', $image_id, 6 );

		$results = $this->calculator->get_results( $this->competition_id );

		$this->assertCount( 1, $results );
		$this->assertEquals( 14, $results[0]->total_score );
		$this->assertEquals( 7.0, $results[0]->average_score );
		$this->assertEquals( 2, $results[0]->vote_count );
	}

	public function test_get_results_uses_cached_score_when_no_votes(): void {
		$image_id = $this->create_image( 'colour' );

		// Manually set a cached score on the image.
		$this->images_repo->update_score( $image_id, 5 );

		$results = $this->calculator->get_results( $this->competition_id );

		$this->assertCount( 1, $results );
		$this->assertEquals( 5, $results[0]->total_score );
		$this->assertEquals( 0.0, $results[0]->average_score );
		$this->assertEquals( 0, $results[0]->vote_count );
	}

	public function test_get_results_filters_by_category(): void {
		$this->create_image( 'colour' );
		$this->create_image( 'black-white' );

		$results = $this->calculator->get_results( $this->competition_id, 'colour' );

		$this->assertCount( 1, $results );
		$this->assertEquals( 'colour', $results[0]->category );
	}

	// ---------------------------------------------------------------
	// get_leaderboard_by_grade()
	// ---------------------------------------------------------------

	public function test_get_leaderboard_by_grade_groups_by_member_grade(): void {
		$member_b = $this->members_repo->create(
			array(
				'name'  => 'Bob',
				'email' => 'bob@example.com',
				'grade' => 'advanced',
			)
		);

		$image_a = $this->create_image( 'colour', $this->member_id );
		$image_b = $this->create_image( 'colour', $member_b );

		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter', $image_a, 8 );
		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter', $image_b, 9 );

		$members = array(
			$this->member_id => (object) array( 'grade' => 'beginner' ),
			$member_b        => (object) array( 'grade' => 'advanced' ),
		);

		$leaderboard = $this->calculator->get_leaderboard_by_grade( $this->competition_id, 'colour', $members );

		$this->assertArrayHasKey( 'beginner', $leaderboard );
		$this->assertArrayHasKey( 'advanced', $leaderboard );
		$this->assertCount( 1, $leaderboard['beginner'] );
		$this->assertCount( 1, $leaderboard['advanced'] );
	}

	public function test_get_leaderboard_uses_unknown_for_missing_members(): void {
		$image_id = $this->create_image( 'colour' );
		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter', $image_id, 5 );

		// Pass empty members lookup — member_id won't be found.
		$leaderboard = $this->calculator->get_leaderboard_by_grade( $this->competition_id, 'colour', array() );

		$this->assertArrayHasKey( 'Unknown', $leaderboard );
		$this->assertCount( 1, $leaderboard['Unknown'] );
	}

	// ---------------------------------------------------------------
	// get_top_winners()
	// ---------------------------------------------------------------

	public function test_get_top_winners_returns_top_n_for_grade(): void {
		$images = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$img_id   = $this->create_image( 'colour' );
			$images[] = $img_id;
			$this->votes_repo->create( $this->competition_id, 'colour', 'Voter', $img_id, $i + 1 );
		}

		$members = array(
			$this->member_id => (object) array( 'grade' => 'beginner' ),
		);

		$winners = $this->calculator->get_top_winners( $this->competition_id, 'colour', 'beginner', $members, 3 );

		$this->assertCount( 3, $winners );
		// Highest scores first.
		$this->assertEquals( 5, $winners[0]->total_score );
		$this->assertEquals( 4, $winners[1]->total_score );
		$this->assertEquals( 3, $winners[2]->total_score );
	}

	public function test_get_top_winners_excludes_other_grades(): void {
		$member_b = $this->members_repo->create(
			array(
				'name'  => 'Bob',
				'email' => 'bob@example.com',
				'grade' => 'advanced',
			)
		);

		$image_a = $this->create_image( 'colour', $this->member_id );
		$image_b = $this->create_image( 'colour', $member_b );

		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter', $image_a, 5 );
		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter', $image_b, 9 );

		$members = array(
			$this->member_id => (object) array( 'grade' => 'beginner' ),
			$member_b        => (object) array( 'grade' => 'advanced' ),
		);

		$winners = $this->calculator->get_top_winners( $this->competition_id, 'colour', 'beginner', $members, 3 );

		$this->assertCount( 1, $winners );
		$this->assertEquals( $image_a, (int) $winners[0]->id );
	}

	public function test_get_top_winners_returns_empty_when_no_matches(): void {
		$image_id = $this->create_image( 'colour' );
		$this->votes_repo->create( $this->competition_id, 'colour', 'Voter', $image_id, 8 );

		$members = array(
			$this->member_id => (object) array( 'grade' => 'beginner' ),
		);

		$winners = $this->calculator->get_top_winners( $this->competition_id, 'colour', 'advanced', $members, 3 );

		$this->assertSame( array(), $winners );
	}
}
