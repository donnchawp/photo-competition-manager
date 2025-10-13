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
		$repository = new MembersRepository( $GLOBALS['wpdb'] );

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
		$repository = new MembersRepository( $GLOBALS['wpdb'] );

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

	/**
	 * Create persists member data.
	 *
	 * @return void
	 */
	public function test_create_persists_member(): void {
		$repository = new MembersRepository( $GLOBALS['wpdb'] );

		$id = $repository->create(
			array(
				'name'  => 'Charlie Lens',
				'email' => 'charlie@example.com',
				'grade' => 'Intermediate',
			)
		);

		$this->assertIsInt( $id );

		$member = $repository->find( $id );

		$this->assertSame( 'Charlie Lens', $member->name );
		$this->assertSame( 'charlie@example.com', $member->email );
		$this->assertSame( 'Intermediate', $member->grade );
		$this->assertSame( 1, (int) $member->active );
	}

	/**
	 * Duplicate emails are rejected.
	 *
	 * @return void
	 */
	public function test_duplicate_email_returns_error(): void {
		$repository = new MembersRepository( $GLOBALS['wpdb'] );

		$first = $repository->create(
			array(
				'name'  => 'Dana First',
				'email' => 'dana@example.com',
			)
		);

		$this->assertIsInt( $first );

		$second = $repository->create(
			array(
				'name'  => 'Dana Second',
				'email' => 'dana@example.com',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'duplicate_email', $second->get_error_code() );
	}

	/**
	 * Update modifies existing rows.
	 *
	 * @return void
	 */
	public function test_update_modifies_member(): void {
		$repository = new MembersRepository( $GLOBALS['wpdb'] );

		$id = $repository->create(
			array(
				'name'  => 'Evan Artist',
				'email' => 'evan@example.com',
				'grade' => 'Beginner',
			)
		);

		$result = $repository->update(
			$id,
			array(
				'name'   => 'Evan Artist II',
				'email'  => 'evan.ii@example.com',
				'grade'  => 'Advanced',
				'active' => 0,
			)
		);

		$this->assertTrue( $result );

		$member = $repository->find( $id );

		$this->assertSame( 'Evan Artist II', $member->name );
		$this->assertSame( 'evan.ii@example.com', $member->email );
		$this->assertSame( 'Advanced', $member->grade );
		$this->assertSame( 0, (int) $member->active );
	}

	/**
	 * Setting active toggles flag.
	 *
	 * @return void
	 */
	public function test_set_active_updates_flag(): void {
		$repository = new MembersRepository( $GLOBALS['wpdb'] );

		$id = $repository->create(
			array(
				'name'   => 'Fay Toggle',
				'email'  => 'fay@example.com',
				'active' => 1,
			)
		);

		$this->assertTrue( $repository->set_active( $id, false ) );

		$this->assertSame( 0, (int) $repository->find( $id )->active );
	}

	/**
	 * Find many returns keyed set of members.
	 *
	 * @return void
	 */
	public function test_find_many_returns_members(): void {
		$repository = new MembersRepository( $GLOBALS['wpdb'] );

		$alice = $repository->create(
			array(
				'name'  => 'Alice Batch',
				'email' => 'alice.batch@example.com',
			)
		);

		$bob = $repository->create(
			array(
				'name'  => 'Bob Batch',
				'email' => 'bob.batch@example.com',
			)
		);

		$result = $repository->find_many( array( $alice, $bob, 999 ) );

		$this->assertArrayHasKey( $alice, $result );
		$this->assertArrayHasKey( $bob, $result );
		$this->assertSame( 'Alice Batch', $result[ $alice ]->name );
		$this->assertSame( 'Bob Batch', $result[ $bob ]->name );
	}

	/**
	 * Find by email retrieves member.
	 *
	 * @return void
	 */
	public function test_find_by_email_retrieves_member(): void {
		$repository = new MembersRepository( $GLOBALS['wpdb'] );

		$id = $repository->create(
			array(
				'name'  => 'George Finder',
				'email' => 'george@example.com',
				'grade' => 'Intermediate',
			)
		);

		$member = $repository->find_by_email( 'george@example.com' );

		$this->assertNotNull( $member );
		$this->assertSame( $id, (int) $member->id );
		$this->assertSame( 'George Finder', $member->name );
		$this->assertSame( 'george@example.com', $member->email );
	}

	/**
	 * Find by email returns null for non-existent email.
	 *
	 * @return void
	 */
	public function test_find_by_email_returns_null_for_missing(): void {
		$repository = new MembersRepository( $GLOBALS['wpdb'] );

		$member = $repository->find_by_email( 'nonexistent@example.com' );

		$this->assertNull( $member );
	}

	/**
	 * Find by email validates email format.
	 *
	 * @return void
	 */
	public function test_find_by_email_validates_format(): void {
		$repository = new MembersRepository( $GLOBALS['wpdb'] );

		$member = $repository->find_by_email( 'not-an-email' );

		$this->assertNull( $member );
	}
}
