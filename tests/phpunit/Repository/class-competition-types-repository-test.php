<?php
/**
 * Tests for Competition_Types_Repository.
 *
 * @package PhotoCompetitionManager\Tests\Repository
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Repository\Competition_Types_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use WP_UnitTestCase;

class Competition_Types_Repository_Test extends WP_UnitTestCase {

	/**
	 * Repository instance.
	 *
	 * @var Competition_Types_Repository
	 */
	private $repository;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->repository = new Competition_Types_Repository();
		$this->clear_types();
	}

	/**
	 * Remove types the upgrade may have left, inside the test transaction.
	 *
	 * @return void
	 */
	private function clear_types(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}photocomp_competition_types" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}photocomp_member_types" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Settings a type could hold.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		return array(
			'categories' => array(
				array(
					'slug'  => 'open',
					'label' => 'Open',
					'quota' => 2,
				),
			),
			'voting'     => array( 'score_matrix' => array( 10, 8, 6 ) ),
		);
	}

	/**
	 * Insert a bare competition row for a type.
	 *
	 * @param int  $type_id  Type ID.
	 * @param bool $archived Whether the competition is soft-deleted.
	 * @return void
	 */
	private function insert_competition( int $type_id, bool $archived ): void {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'photocomp_competitions',
			array(
				'title'      => 'Typed',
				'slug'       => 'typed-' . ( $archived ? 'archived' : 'live' ),
				'type_id'    => $type_id,
				'deleted_at' => $archived ? current_time( 'mysql', true ) : null,
			)
		);
	}

	public function test_create_and_find_type_round_trips_settings_and_grade_names() {
		$id = $this->repository->create( 'Juniors', $this->settings(), array( 'novice' => 'Beginner' ) );

		$this->assertIsInt( $id );
		$type = $this->repository->find( $id );
		$this->assertSame( 'Juniors', $type->name );
		$this->assertSame( $this->settings(), $type->settings );
		$this->assertSame( array( 'novice' => 'Beginner' ), $type->grade_names );
		$this->assertSame( 1, $this->repository->count() );
		$this->assertSame( array( $id ), array_map( fn( $t ) => (int) $t->id, $this->repository->all() ) );
	}

	public function test_create_rejects_missing_name_and_invalid_settings() {
		$result = $this->repository->create( '  ', $this->settings() );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_name', $result->get_error_code() );

		$bad                           = $this->settings();
		$bad['voting']['score_matrix'] = array();
		$result                        = $this->repository->create( 'Bad', $bad );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_settings', $result->get_error_code() );
	}

	public function test_update_changes_the_type() {
		$id = $this->repository->create( 'Juniors', $this->settings() );

		$this->assertTrue( $this->repository->update( $id, 'Seniors', $this->settings(), array( 'a' => 'B' ) ) );

		$type = $this->repository->find( $id );
		$this->assertSame( 'Seniors', $type->name );
		$this->assertSame( array( 'a' => 'B' ), $type->grade_names );
	}

	public function test_delete_removes_type_and_its_member_tags() {
		global $wpdb;

		$keep = $this->repository->create( 'Keep', $this->settings() );
		$id   = $this->repository->create( 'Drop', $this->settings() );
		$wpdb->insert(
			$wpdb->prefix . 'photocomp_member_types',
			array(
				'member_id' => 1,
				'type_id'   => $id,
			)
		);

		$this->assertTrue( $this->repository->delete( $id ) );

		$this->assertNull( $this->repository->find( $id ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type_id = %d', $wpdb->prefix . 'photocomp_member_types', $id ) ) );
		$this->assertNotNull( $this->repository->find( $keep ) );
	}

	public function test_delete_refuses_type_in_use() {
		$this->repository->create( 'Other', $this->settings() );
		$live     = $this->repository->create( 'Live', $this->settings() );
		$archived = $this->repository->create( 'Archived', $this->settings() );
		$this->insert_competition( $live, false );
		$this->insert_competition( $archived, true );

		foreach ( array( $live, $archived ) as $id ) {
			$result = $this->repository->delete( $id );
			$this->assertWPError( $result );
			$this->assertSame( 'type_in_use', $result->get_error_code() );
			$this->assertNotNull( $this->repository->find( $id ) );
		}
	}

	public function test_delete_refuses_last_type() {
		$id = $this->repository->create( 'Only', $this->settings() );

		$result = $this->repository->delete( $id );

		$this->assertWPError( $result );
		$this->assertSame( 'last_type', $result->get_error_code() );
		$this->assertNotNull( $this->repository->find( $id ) );
	}

	public function test_only_returns_null_with_two_types() {
		$this->assertNull( $this->repository->only() );

		$first = $this->repository->create( 'One', $this->settings() );
		$this->assertSame( $first, (int) $this->repository->only()->id );

		$this->repository->create( 'Two', $this->settings() );
		$this->assertNull( $this->repository->only() );
	}

	public function test_find_returns_null_for_unknown_id() {
		$this->assertNull( $this->repository->find( 999999 ) );
	}

	public function test_reads_the_shape_the_upgrade_writes() {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'photocomp_competition_types',
			array(
				'name'        => 'Individual',
				'settings'    => wp_json_encode( array( 'categories' => $this->settings()['categories'] ) ),
				'grade_names' => '{}',
			)
		);

		$type = $this->repository->find( (int) $wpdb->insert_id );

		$this->assertSame( array(), $type->grade_names );
		$this->assertSame( 'open', $type->settings['categories'][0]['slug'] );
	}
}
