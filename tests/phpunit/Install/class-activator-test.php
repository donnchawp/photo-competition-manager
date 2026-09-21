<?php
/**
 * Tests for Activator upgrades.
 *
 * @package PhotoCompetitionManager\Tests\Install
 */

namespace PhotoCompetitionManager\Tests\Install;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Repository\Members_Repository;
use WP_UnitTestCase;

class Activator_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		Activator::activate();
	}

	public function test_maybe_upgrade_marks_inactive_members_from_older_version(): void {
		global $wpdb;
		$repository = new Members_Repository( $wpdb );

		$wpdb->insert(
			$repository->table(),
			array(
				'name'   => 'Old Inactive',
				'email'  => 'old@example.com',
				'grade'  => '',
				'active' => 0,
			)
		);
		$id = (int) $wpdb->insert_id;
		delete_option( 'photo_comp_db_version' );

		Activator::maybe_upgrade();

		$this->assertSame( 'deactivated-old@example.com.invalid', $repository->find( $id )->email );
		$this->assertSame( Activator::DB_VERSION, (int) get_option( 'photo_comp_db_version' ) );
	}

	public function test_maybe_upgrade_keeps_old_version_when_marking_fails(): void {
		global $wpdb;
		delete_option( 'photo_comp_db_version' );

		$break_update = function ( $query ) {
			return 0 === strpos( $query, 'UPDATE' ) && false !== strpos( $query, 'photocomp_members' )
				? 'UPDATE photocomp_no_such_table SET email = email'
				: $query;
		};
		add_filter( 'query', $break_update );
		$suppress = $wpdb->suppress_errors( true );

		Activator::maybe_upgrade();

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_update );

		$this->assertFalse( get_option( 'photo_comp_db_version' ) );
	}

	public function test_maybe_upgrade_skips_when_current(): void {
		global $wpdb;
		$repository = new Members_Repository( $wpdb );

		$wpdb->insert(
			$repository->table(),
			array(
				'name'   => 'Left Alone',
				'email'  => 'alone@example.com',
				'grade'  => '',
				'active' => 0,
			)
		);
		$id = (int) $wpdb->insert_id;
		update_option( 'photo_comp_db_version', Activator::DB_VERSION );

		Activator::maybe_upgrade();

		$this->assertSame( 'alone@example.com', $repository->find( $id )->email );
	}
}
