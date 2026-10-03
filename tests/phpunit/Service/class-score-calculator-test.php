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
	private function create_image( string $category ): int {
		return $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
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
}
