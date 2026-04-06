<?php
/**
 * Tests for Logs_Repository.
 *
 * @package PhotoCompetitionManager\Tests\Repository
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Repository\Logs_Repository;
use WP_UnitTestCase;

class Logs_Repository_Test extends WP_UnitTestCase {

	/**
	 * @var Logs_Repository
	 */
	private $repo;

	public function setUp(): void {
		parent::setUp();
		Activator::activate();
		$this->repo = new Logs_Repository();
	}

	/**
	 * Helper to insert a log entry.
	 */
	private function create_log( array $overrides = array() ): int {
		$defaults = array(
			'competition_id' => 1,
			'event_type'     => 'test_event',
			'event_category' => 'testing',
			'actor_type'     => 'system',
			'actor_name'     => 'PHPUnit',
			'description'    => 'Test log entry',
		);

		$result = $this->repo->create( array_merge( $defaults, $overrides ) );
		$this->assertNotFalse( $result );

		return $GLOBALS['wpdb']->insert_id;
	}

	// ---------------------------------------------------------------
	// create()
	// ---------------------------------------------------------------

	public function test_create_inserts_log_entry(): void {
		$result = $this->repo->create(
			array(
				'competition_id' => 1,
				'event_type'     => 'vote_received',
				'event_category' => 'voting',
				'actor_type'     => 'member',
				'actor_name'     => 'Alice',
				'description'    => 'Vote submitted',
			)
		);

		$this->assertNotFalse( $result );
	}

	public function test_create_serializes_array_metadata(): void {
		$metadata = array( 'score' => 9, 'image_id' => 42 );

		$this->create_log( array( 'metadata' => $metadata ) );

		$logs = $this->repo->find_by_competition( 1 );
		$this->assertCount( 1, $logs );

		$decoded = json_decode( $logs[0]->metadata, true );
		$this->assertSame( 9, $decoded['score'] );
		$this->assertSame( 42, $decoded['image_id'] );
	}

	// ---------------------------------------------------------------
	// find_by_competition()
	// ---------------------------------------------------------------

	public function test_find_by_competition_returns_matching_logs(): void {
		$this->create_log( array( 'competition_id' => 1 ) );
		$this->create_log( array( 'competition_id' => 1 ) );
		$this->create_log( array( 'competition_id' => 2 ) );

		$logs = $this->repo->find_by_competition( 1 );

		$this->assertCount( 2, $logs );
	}

	public function test_find_by_competition_filters_by_event_category(): void {
		$this->create_log( array( 'event_category' => 'voting' ) );
		$this->create_log( array( 'event_category' => 'upload' ) );

		$logs = $this->repo->find_by_competition( 1, 50, 0, array( 'event_category' => 'voting' ) );

		$this->assertCount( 1, $logs );
		$this->assertSame( 'voting', $logs[0]->event_category );
	}

	public function test_find_by_competition_filters_by_event_type(): void {
		$this->create_log( array( 'event_type' => 'vote_received' ) );
		$this->create_log( array( 'event_type' => 'upload_completed' ) );

		$logs = $this->repo->find_by_competition( 1, 50, 0, array( 'event_type' => 'vote_received' ) );

		$this->assertCount( 1, $logs );
	}

	public function test_find_by_competition_respects_limit_and_offset(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_log();
		}

		$page1 = $this->repo->find_by_competition( 1, 2, 0 );
		$page2 = $this->repo->find_by_competition( 1, 2, 2 );

		$this->assertCount( 2, $page1 );
		$this->assertCount( 2, $page2 );
		$this->assertNotEquals( $page1[0]->id, $page2[0]->id );
	}

	// ---------------------------------------------------------------
	// paginate()
	// ---------------------------------------------------------------

	public function test_paginate_returns_all_logs(): void {
		$this->create_log( array( 'competition_id' => 1 ) );
		$this->create_log( array( 'competition_id' => 2 ) );

		$logs = $this->repo->paginate( 50, 0 );

		$this->assertCount( 2, $logs );
	}

	public function test_paginate_filters_by_competition_id(): void {
		$this->create_log( array( 'competition_id' => 1 ) );
		$this->create_log( array( 'competition_id' => 2 ) );

		$logs = $this->repo->paginate( 50, 0, array( 'competition_id' => 1 ) );

		$this->assertCount( 1, $logs );
	}

	// ---------------------------------------------------------------
	// count()
	// ---------------------------------------------------------------

	public function test_count_returns_total_logs(): void {
		$this->create_log();
		$this->create_log();
		$this->create_log();

		$this->assertSame( 3, $this->repo->count() );
	}

	public function test_count_respects_filters(): void {
		$this->create_log( array( 'event_category' => 'voting' ) );
		$this->create_log( array( 'event_category' => 'voting' ) );
		$this->create_log( array( 'event_category' => 'upload' ) );

		$this->assertSame( 2, $this->repo->count( array( 'event_category' => 'voting' ) ) );
	}

	// ---------------------------------------------------------------
	// delete_older_than()
	// ---------------------------------------------------------------

	public function test_delete_older_than_removes_old_entries(): void {
		$this->create_log( array( 'created_at' => '2020-01-01 00:00:00' ) );
		$this->create_log( array( 'created_at' => '2020-01-01 00:00:00' ) );
		$this->create_log( array( 'created_at' => '2025-01-01 00:00:00' ) );

		$deleted = $this->repo->delete_older_than( '2024-01-01 00:00:00' );

		$this->assertSame( 2, $deleted );
		$this->assertSame( 1, $this->repo->count() );
	}

	// ---------------------------------------------------------------
	// get_event_categories() / get_event_types()
	// ---------------------------------------------------------------

	public function test_get_event_categories_returns_distinct_values(): void {
		$this->create_log( array( 'event_category' => 'voting' ) );
		$this->create_log( array( 'event_category' => 'voting' ) );
		$this->create_log( array( 'event_category' => 'upload' ) );

		$categories = $this->repo->get_event_categories();

		$this->assertCount( 2, $categories );
		$this->assertContains( 'voting', $categories );
		$this->assertContains( 'upload', $categories );
	}

	public function test_get_event_types_returns_distinct_values(): void {
		$this->create_log( array( 'event_type' => 'vote_received' ) );
		$this->create_log( array( 'event_type' => 'vote_received' ) );
		$this->create_log( array( 'event_type' => 'upload_completed' ) );

		$types = $this->repo->get_event_types();

		$this->assertCount( 2, $types );
		$this->assertContains( 'vote_received', $types );
		$this->assertContains( 'upload_completed', $types );
	}

	public function test_get_event_categories_returns_empty_when_no_logs(): void {
		$this->assertSame( array(), $this->repo->get_event_categories() );
	}

	public function test_get_event_types_returns_empty_when_no_logs(): void {
		$this->assertSame( array(), $this->repo->get_event_types() );
	}
}
