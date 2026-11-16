<?php
/**
 * Tests for Entrants_Repository.
 *
 * @package PhotoCompetitionManager\Tests\Repository
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Repository\Entrants_Repository;
use WP_UnitTestCase;

/**
 * @covers \PhotoCompetitionManager\Repository\Entrants_Repository
 */
class Entrants_Repository_Test extends WP_UnitTestCase {

	/**
	 * Repository instance.
	 *
	 * @var Entrants_Repository
	 */
	private $repository;

	public function set_up(): void {
		parent::set_up();
		$this->repository = new Entrants_Repository();
	}

	public function test_add_is_idempotent_and_lists_the_competitions_entrants(): void {
		$this->repository->add( 5, 11 );
		$this->repository->add( 5, 11 );
		$this->repository->add( 5, 12 );
		$this->repository->add( 6, 13 );

		$this->assertSame( array( 11, 12 ), $this->repository->member_ids( 5 ) );
		$this->assertTrue( $this->repository->has( 5, 12 ) );
		$this->assertFalse( $this->repository->has( 5, 13 ) );
	}

	public function test_remove_takes_one_entrant_out(): void {
		$this->repository->add( 5, 11 );
		$this->repository->add( 5, 12 );

		$this->repository->remove( 5, 11 );

		$this->assertSame( array( 12 ), $this->repository->member_ids( 5 ) );
	}

	public function test_delete_by_competition_and_by_member(): void {
		$this->repository->add( 5, 11 );
		$this->repository->add( 5, 12 );
		$this->repository->add( 6, 11 );
		$this->repository->add( 7, 12 );

		$this->repository->delete_by_competition( 5 );
		$this->repository->delete_by_member( 11 );

		$this->assertSame( array(), $this->repository->member_ids( 5 ) );
		$this->assertSame( array(), $this->repository->member_ids( 6 ) );
		$this->assertSame( array( 12 ), $this->repository->member_ids( 7 ) );
	}
}
