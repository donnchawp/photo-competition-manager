<?php
/**
 * @package ClubCompetitions\Tests\Repository
 */

namespace ClubCompetitions\Tests\Repository;

use ClubCompetitions\Install\Activator;
use ClubCompetitions\Repository\CompetitionsRepository;
use WP_UnitTestCase;

class CompetitionsRepositoryTest extends WP_UnitTestCase {

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
		$repository = new CompetitionsRepository( $GLOBALS['wpdb'] );

		$this->assertSame(
			$GLOBALS['wpdb']->prefix . 'clubcompete_competitions',
			$repository->table()
		);
	}

	/**
	 * Repository creates competitions and normalizes slug.
	 *
	 * @return void
	 */
	public function test_create_persists_competition(): void {
		$repository = new CompetitionsRepository( $GLOBALS['wpdb'] );

		$result = $repository->create(
			array(
				'title'     => 'October 2024 Competition',
				'open_date' => '2024-10-01',
			)
		);

		$this->assertIsInt( $result );

		$row = $GLOBALS['wpdb']->get_row(
			$GLOBALS['wpdb']->prepare(
				"SELECT * FROM {$repository->table()} WHERE id = %d",
				$result
			)
		);

		$this->assertSame( 'october-2024-competition', $row->slug );
		$this->assertSame( 'draft', $row->status );
	}

	/**
	 * Duplicate slugs return an error.
	 *
	 * @return void
	 */
	public function test_duplicate_slug_returns_error(): void {
		$repository = new CompetitionsRepository( $GLOBALS['wpdb'] );

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
}
