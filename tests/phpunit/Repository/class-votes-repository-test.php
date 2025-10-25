<?php
/**
 * @package PhotoCompetitionManager\Tests\Repository
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Repository\Votes_Repository;
use WP_UnitTestCase;

class Votes_Repository_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Activator::activate();
	}

	/**
	 * Table name is prefixed.
	 *
	 * @return void
	 */
	public function test_table_name_is_prefixed(): void {
		$repository = new Votes_Repository( $GLOBALS['wpdb'] );

		$this->assertSame(
			$GLOBALS['wpdb']->prefix . 'photocomp_votes',
			$repository->table()
		);
	}

	/**
	 * Create vote persists data.
	 *
	 * @return void
	 */
	public function test_create_persists_vote(): void {
		$repository = new Votes_Repository( $GLOBALS['wpdb'] );

		$id = $repository->create( 1, 'colour', 'John Doe', 42, 9.0 );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$votes = $repository->find_by_competition( 1 );
		$this->assertCount( 1, $votes );
		$this->assertSame( 1, (int) $votes[0]->competition_id );
		$this->assertSame( 'colour', $votes[0]->category );
		$this->assertSame( 'John Doe', $votes[0]->voter_name );
		$this->assertSame( 42, (int) $votes[0]->image_id );
		$this->assertSame( 9.0, (float) $votes[0]->score );
	}

	/**
	 * Create requires voter name.
	 *
	 * @return void
	 */
	public function test_create_requires_voter_name(): void {
		$repository = new Votes_Repository( $GLOBALS['wpdb'] );

		$result = $repository->create( 1, 'colour', '', 42, 9.0 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_voter_name', $result->get_error_code() );
	}

	/**
	 * Create validates score.
	 *
	 * @return void
	 */
	public function test_create_validates_score(): void {
		$repository = new Votes_Repository( $GLOBALS['wpdb'] );

		$result = $repository->create( 1, 'colour', 'John Doe', 42, -1.0 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_score', $result->get_error_code() );
	}

	/**
	 * Find by competition filters correctly.
	 *
	 * @return void
	 */
	public function test_find_by_competition_filters(): void {
		$repository = new Votes_Repository( $GLOBALS['wpdb'] );

		$repository->create( 1, 'colour', 'Alice', 10, 9.0 );
		$repository->create( 1, 'bw', 'Alice', 20, 8.0 );
		$repository->create( 2, 'colour', 'Bob', 30, 7.0 );

		$comp1_all = $repository->find_by_competition( 1 );
		$this->assertCount( 2, $comp1_all );

		$comp1_colour = $repository->find_by_competition( 1, 'colour' );
		$this->assertCount( 1, $comp1_colour );
		$this->assertSame( 'colour', $comp1_colour[0]->category );

		$comp2_all = $repository->find_by_competition( 2 );
		$this->assertCount( 1, $comp2_all );
	}

	/**
	 * Find by image returns votes.
	 *
	 * @return void
	 */
	public function test_find_by_image(): void {
		$repository = new Votes_Repository( $GLOBALS['wpdb'] );

		$repository->create( 1, 'colour', 'Alice', 42, 9.0 );
		$repository->create( 1, 'colour', 'Bob', 42, 8.0 );
		$repository->create( 1, 'colour', 'Charlie', 43, 7.0 );

		$votes = $repository->find_by_image( 42 );
		$this->assertCount( 2, $votes );
	}

	/**
	 * Has voted detects existing vote.
	 *
	 * @return void
	 */
	public function test_has_voted(): void {
		$repository = new Votes_Repository( $GLOBALS['wpdb'] );

		$this->assertFalse( $repository->has_voted( 1, 'colour', 'Alice' ) );

		$repository->create( 1, 'colour', 'Alice', 42, 9.0 );

		$this->assertTrue( $repository->has_voted( 1, 'colour', 'Alice' ) );
		$this->assertFalse( $repository->has_voted( 1, 'bw', 'Alice' ) );
		$this->assertFalse( $repository->has_voted( 1, 'colour', 'Bob' ) );
	}

	/**
	 * Calculate averages computes correctly.
	 *
	 * @return void
	 */
	public function test_calculate_averages(): void {
		$repository = new Votes_Repository( $GLOBALS['wpdb'] );

		$repository->create( 1, 'colour', 'Alice', 42, 9.0 );
		$repository->create( 1, 'colour', 'Bob', 42, 7.0 );
		$repository->create( 1, 'colour', 'Charlie', 43, 10.0 );

		$averages = $repository->calculate_averages( 1, 'colour' );

		$this->assertArrayHasKey( 42, $averages );
		$this->assertArrayHasKey( 43, $averages );

		$this->assertSame( 42, $averages[42]['image_id'] );
		$this->assertSame( 8.0, $averages[42]['average_score'] );
		$this->assertSame( 2, $averages[42]['vote_count'] );

		$this->assertSame( 43, $averages[43]['image_id'] );
		$this->assertSame( 10.0, $averages[43]['average_score'] );
		$this->assertSame( 1, $averages[43]['vote_count'] );
	}

	/**
	 * Delete by competition removes votes.
	 *
	 * @return void
	 */
	public function test_delete_by_competition(): void {
		$repository = new Votes_Repository( $GLOBALS['wpdb'] );

		$repository->create( 1, 'colour', 'Alice', 42, 9.0 );
		$repository->create( 1, 'colour', 'Bob', 43, 8.0 );
		$repository->create( 2, 'colour', 'Charlie', 44, 7.0 );

		$this->assertCount( 2, $repository->find_by_competition( 1 ) );

		$result = $repository->delete_by_competition( 1 );
		$this->assertTrue( $result );

		$this->assertCount( 0, $repository->find_by_competition( 1 ) );
		$this->assertCount( 1, $repository->find_by_competition( 2 ) );
	}

	/**
	 * Get voters returns unique names.
	 *
	 * @return void
	 */
	public function test_get_voters(): void {
		$repository = new Votes_Repository( $GLOBALS['wpdb'] );

		$repository->create( 1, 'colour', 'Alice', 42, 9.0 );
		$repository->create( 1, 'colour', 'Alice', 43, 8.0 );
		$repository->create( 1, 'colour', 'Bob', 44, 7.0 );
		$repository->create( 2, 'colour', 'Charlie', 45, 6.0 );

		$voters = $repository->get_voters( 1 );
		$this->assertCount( 2, $voters );
		$this->assertContains( 'Alice', $voters );
		$this->assertContains( 'Bob', $voters );
		$this->assertNotContains( 'Charlie', $voters );
	}
}
