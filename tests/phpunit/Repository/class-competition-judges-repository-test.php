<?php
/**
 * Tests for Competition_Judges_Repository.
 *
 * @package PhotoCompetitionManager\Tests\Repository
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Repository\Competition_Judges_Repository;
use PhotoCompetitionManager\Repository\Judges_Repository;
use WP_UnitTestCase;

class Competition_Judges_Repository_Test extends WP_UnitTestCase {

	/**
	 * Repository instance.
	 *
	 * @var Competition_Judges_Repository
	 */
	private $repository;

	/**
	 * Judge repository.
	 *
	 * @var Judges_Repository
	 */
	private $judges;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->repository = new Competition_Judges_Repository();
		$this->judges     = new Judges_Repository();
	}

	public function test_assign_returns_stable_token() {
		$judge_id = $this->judges->create( 'Jane Judge', 'jane@example.com' );

		$first  = $this->repository->assign( 5, $judge_id );
		$second = $this->repository->assign( 5, $judge_id );

		$this->assertSame( 64, strlen( $first ) );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->repository->judges_for( 5 ) );
	}

	public function test_assign_gives_each_assignment_its_own_token() {
		$judge_id = $this->judges->create( 'Jane Judge', 'jane@example.com' );

		$this->assertNotSame( $this->repository->assign( 5, $judge_id ), $this->repository->assign( 6, $judge_id ) );
	}

	public function test_assign_rejects_unknown_judge() {
		$this->assertWPError( $this->repository->assign( 5, 999999 ) );
	}

	public function test_find_by_token_returns_assignment() {
		$judge_id = $this->judges->create( 'Jane Judge', 'jane@example.com' );
		$token    = $this->repository->assign( 5, $judge_id );

		$found = $this->repository->find_by_token( $token );

		$this->assertSame( 5, (int) $found->competition_id );
		$this->assertSame( $judge_id, (int) $found->judge_id );
		$this->assertSame( 'Jane Judge', $found->name );
		$this->assertSame( 'jane@example.com', $found->email );
		$this->assertNull( $this->repository->find_by_token( 'nope' ) );
		$this->assertNull( $this->repository->find_by_token( '' ) );
	}

	public function test_unassign_revokes_link_but_keeps_votes() {
		global $wpdb;

		$judge_id = $this->judges->create( 'Jane Judge', 'jane@example.com' );
		$token    = $this->repository->assign( 5, $judge_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'photocomp_votes',
			array(
				'competition_id' => 5,
				'category'       => 'open',
				'judge_id'       => $judge_id,
				'image_id'       => 77,
				'score'          => 8,
			)
		);

		$this->assertTrue( $this->repository->unassign( 5, $judge_id ) );

		$this->assertNull( $this->repository->find_by_token( $token ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$votes = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE judge_id = %d', $wpdb->prefix . 'photocomp_votes', $judge_id ) );
		$this->assertSame( 1, (int) $votes );
	}

	public function test_judges_for_is_ordered_by_name_with_token_and_sent_at() {
		$zed = $this->judges->create( 'Zed', 'zed@example.com' );
		$amy = $this->judges->create( 'Amy', 'amy@example.com' );
		$this->repository->assign( 5, $zed );
		$token = $this->repository->assign( 5, $amy );

		$rows = $this->repository->judges_for( 5 );

		$this->assertSame( array( 'Amy', 'Zed' ), wp_list_pluck( $rows, 'name' ) );
		$this->assertSame( $token, $rows[0]->token );
		$this->assertNull( $rows[0]->sent_at );
	}

	public function test_mark_sent_sets_sent_at() {
		$judge_id = $this->judges->create( 'Jane Judge', 'jane@example.com' );
		$this->repository->assign( 5, $judge_id );

		$this->repository->mark_sent( 5, $judge_id );

		$this->assertNotNull( $this->repository->judges_for( 5 )[0]->sent_at );
	}

	public function test_delete_by_competition_removes_only_that_competitions_assignments() {
		$judge_id = $this->judges->create( 'Jane Judge', 'jane@example.com' );
		$this->repository->assign( 5, $judge_id );
		$other = $this->repository->assign( 6, $judge_id );

		$this->repository->delete_by_competition( 5 );

		$this->assertSame( array(), $this->repository->judges_for( 5 ) );
		$this->assertNotNull( $this->repository->find_by_token( $other ) );
	}
}
