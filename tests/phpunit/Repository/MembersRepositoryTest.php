<?php
/**
 * @package ClubCompetitions\Tests\Repository
 */

namespace ClubCompetitions\Tests\Repository;

use ClubCompetitions\Install\Activator;
use ClubCompetitions\Repository\MembersRepository;
use WP_UnitTestCase;

class MembersRepositoryTest extends WP_UnitTestCase {

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
		$repository = new MembersRepository();

		$this->assertSame(
			$GLOBALS['wpdb']->prefix . 'clubcompete_members',
			$repository->table()
		);
	}

	/**
	 * Returns only active members by default.
	 *
	 * @return void
	 */
	public function test_all_returns_active_members(): void {
		$repository = new MembersRepository();

		$GLOBALS['wpdb']->insert(
			$repository->table(),
			array(
				'name'       => 'Alice',
				'email'      => 'alice@example.com',
				'grade'      => 'Beginner',
				'active'     => 1,
				'created_at' => current_time( 'mysql' ),
			)
		);

		$GLOBALS['wpdb']->insert(
			$repository->table(),
			array(
				'name'       => 'Bob',
				'email'      => 'bob@example.com',
				'grade'      => 'Advanced',
				'active'     => 0,
				'created_at' => current_time( 'mysql' ),
			)
		);

		$active_members = $repository->all();

		$this->assertCount( 1, $active_members );
		$this->assertSame( 'Alice', $active_members[0]->name );

		$all_members = $repository->all( false );

		$this->assertCount( 2, $all_members );
	}
}
