<?php
/**
 * @package PhotoCompetitionManager\Tests\Repository
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use WP_UnitTestCase;

class Competitions_Repository_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		Activator::activate();
	}

	/**
	 * Repository returns prefixed table name.
	 *
	 * @return void
	 */
	public function test_table_name_is_prefixed(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$this->assertSame(
			$GLOBALS['wpdb']->prefix . 'photocomp_competitions',
			$repository->table()
		);
	}

	/**
	 * Repository creates competitions and normalizes slug.
	 *
	 * @return void
	 */
	public function test_create_persists_competition(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$result = $repository->create(
			array(
				'title'     => 'October 2024 Competition',
				'open_date' => '2024-10-01',
			)
		);

		$this->assertIsInt( $result );

		$row = $GLOBALS['wpdb']->get_row(
			$GLOBALS['wpdb']->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$repository->table(),
				$result
			)
		);

		$this->assertSame( 'october-2024-competition', $row->slug );
	}

	/**
	 * Duplicate slugs return an error.
	 *
	 * @return void
	 */
	public function test_duplicate_slug_returns_error(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$first = $repository->create(
			array(
				'title' => 'Monthly Challenge',
				'slug'  => 'monthly-challenge',
			)
		);

		$this->assertIsInt( $first );

		$second = $repository->create(
			array(
				'title' => 'Another Challenge',
				'slug'  => 'monthly-challenge',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'duplicate_slug', $second->get_error_code() );
	}

	/**
	 * Update modifies existing rows.
	 *
	 * @return void
	 */
	public function test_update_persists_changes(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition_id = $repository->create(
			array(
				'title' => 'Winter Showcase',
				'status'=> 'draft',
			)
		);

		$result = $repository->update(
			$competition_id,
			array(
				'title'      => 'Winter Showcase Updated',
				'open_date'  => '2024-12-01',
				'close_date' => '2024-12-31',
			)
		);

		$this->assertTrue( $result );

		$row = $repository->find( $competition_id );

		$this->assertSame( 'Winter Showcase Updated', $row->title );
		$this->assertSame( '2024-12-01 00:00:00', $row->open_date );
		$this->assertSame( '2024-12-31 00:00:00', $row->close_date );
	}

	/**
	 * Update with existing slug on same record succeeds.
	 *
	 * @return void
	 */
	public function test_update_allows_same_slug(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition_id = $repository->create(
			array(
				'title' => 'Spring Gala',
				'slug'  => 'spring-gala',
			)
		);

		$result = $repository->update(
			$competition_id,
			array(
				'title' => 'Spring Gala 2025',
				'slug'  => 'spring-gala',
			)
		);

		$this->assertTrue( $result );
		$row = $repository->find( $competition_id );
		$this->assertSame( 'spring-gala', $row->slug );
		$this->assertSame( 'Spring Gala 2025', $row->title );
	}

	/**
	 * Archive hides competitions from default listings.
	 *
	 * @return void
	 */
	public function test_archive_marks_competition_as_deleted(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition_id = $repository->create(
			array(
				'title' => 'Archive Me',
			)
		);

		$this->assertTrue( $repository->archive( $competition_id ) );

		$this->assertCount( 0, $repository->all() );
		$this->assertCount( 1, $repository->all( 10, true, true ) );
		$this->assertSame( 0, $repository->count( false ) );
		$this->assertSame( 1, $repository->count( true ) );

		$archived = $repository->find( $competition_id, true );

		$this->assertNotNull( $archived );
		$this->assertNotEmpty( $archived->deleted_at );
	}

	/**
	 * Restore brings archived items back to active list.
	 *
	 * @return void
	 */
	public function test_restore_reactivates_competition(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition_id = $repository->create(
			array(
				'title' => 'Restore Me',
			)
		);

		$repository->archive( $competition_id );
		$this->assertTrue( $repository->restore( $competition_id ) );

		$active = $repository->find( $competition_id );

		$this->assertNotNull( $active );
		$this->assertNull( $active->deleted_at );
		$this->assertSame( 1, $repository->count( false ) );
		$this->assertSame( 0, $repository->count( true ) );
	}

	/**
	 * Find by slug retrieves competition.
	 *
	 * @return void
	 */
	public function test_find_by_slug_retrieves_competition(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition_id = $repository->create(
			array(
				'title' => 'October 2025',
				'slug'  => 'october-2025',
			)
		);

		$competition = $repository->find_by_slug( 'october-2025' );

		$this->assertNotNull( $competition );
		$this->assertSame( $competition_id, (int) $competition->id );
		$this->assertSame( 'October 2025', $competition->title );
		$this->assertSame( 'october-2025', $competition->slug );
	}

	/**
	 * Find by slug excludes archived competitions by default.
	 *
	 * @return void
	 */
	public function test_find_by_slug_excludes_archived(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition_id = $repository->create(
			array(
				'title' => 'Archived Competition',
				'slug'  => 'archived-comp',
			)
		);

		$repository->archive( $competition_id );

		$competition = $repository->find_by_slug( 'archived-comp' );

		$this->assertNull( $competition );

		$competition_archived = $repository->find_by_slug( 'archived-comp', true );

		$this->assertNotNull( $competition_archived );
		$this->assertSame( $competition_id, (int) $competition_archived->id );
	}

	/**
	 * Find by slug returns null for non-existent slug.
	 *
	 * @return void
	 */
	public function test_find_by_slug_returns_null_for_missing(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition = $repository->find_by_slug( 'does-not-exist' );

		$this->assertNull( $competition );
	}
}
