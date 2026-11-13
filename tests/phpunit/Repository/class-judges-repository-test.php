<?php
/**
 * Tests for Judges_Repository.
 *
 * @package PhotoCompetitionManager\Tests\Repository
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Repository\Competition_Judges_Repository;
use PhotoCompetitionManager\Repository\Judges_Repository;
use WP_UnitTestCase;

class Judges_Repository_Test extends WP_UnitTestCase {

	/**
	 * Repository instance.
	 *
	 * @var Judges_Repository
	 */
	private $repository;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->repository = new Judges_Repository();
	}

	public function test_create_and_find_judge() {
		$id = $this->repository->create( 'Jane Judge', 'jane@example.com' );

		$this->assertIsInt( $id );
		$judge = $this->repository->find( $id );
		$this->assertSame( 'Jane Judge', $judge->name );
		$this->assertSame( 'jane@example.com', $judge->email );
	}

	public function test_create_rejects_bad_email() {
		$result = $this->repository->create( 'Jane Judge', 'not-an-email' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_email', $result->get_error_code() );
	}

	public function test_create_rejects_missing_name() {
		$result = $this->repository->create( '  ', 'jane@example.com' );

		$this->assertWPError( $result );
		$this->assertSame( 'missing_name', $result->get_error_code() );
	}

	public function test_update_changes_name_and_email_and_validates() {
		$id = $this->repository->create( 'Jane Judge', 'jane@example.com' );

		$this->assertTrue( $this->repository->update( $id, 'Janet Judge', 'janet@example.com' ) );
		$judge = $this->repository->find( $id );
		$this->assertSame( 'Janet Judge', $judge->name );
		$this->assertSame( 'janet@example.com', $judge->email );

		$this->assertWPError( $this->repository->update( $id, 'Janet', 'bad' ) );
	}

	public function test_all_is_ordered_by_name_and_find_by_email_matches() {
		$this->repository->create( 'Zed', 'zed@example.com' );
		$this->repository->create( 'Amy', 'amy@example.com' );

		$names = wp_list_pluck( $this->repository->all(), 'name' );
		$this->assertSame( array( 'Amy', 'Zed' ), $names );

		$found = $this->repository->find_by_email( 'amy@example.com' );
		$this->assertCount( 1, $found );
		$this->assertSame( 'Amy', $found[0]->name );
	}

	public function test_find_returns_null_for_unknown_judge() {
		$this->assertNull( $this->repository->find( 999999 ) );
	}

	public function test_delete_judge_removes_assignments_keeps_votes() {
		global $wpdb;

		$judge_id = $this->repository->create( 'Jane Judge', 'jane@example.com' );
		$assigns  = new Competition_Judges_Repository();
		$token    = $assigns->assign( 5, $judge_id );

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

		$this->assertTrue( $this->repository->delete( $judge_id ) );

		$this->assertNull( $this->repository->find( $judge_id ) );
		$this->assertNull( $assigns->find_by_token( $token ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$votes = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE judge_id = %d', $wpdb->prefix . 'photocomp_votes', $judge_id ) );
		$this->assertSame( 1, (int) $votes );
	}
}
