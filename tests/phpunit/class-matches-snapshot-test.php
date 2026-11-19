<?php
/**
 * Tests for the shared golden-file assertion used by the render tests.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

use PHPUnit\Framework\AssertionFailedError;
use WP_UnitTestCase;

/**
 * A missing golden must fail, so a renamed or uncommitted fixture can't pass as a skip.
 */
class Matches_Snapshot_Test extends WP_UnitTestCase {

	use Matches_Snapshot;

	/**
	 * Directory holding this test's goldens.
	 *
	 * @var string
	 */
	private $dir;

	public function set_up(): void {
		parent::set_up();
		$this->dir = get_temp_dir() . 'pcm-snapshot-test-' . wp_generate_password( 8, false );
		putenv( 'UPDATE_SNAPSHOTS' );
	}

	public function tear_down(): void {
		putenv( 'UPDATE_SNAPSHOTS' );
		foreach ( (array) glob( $this->dir . '/*.html' ) as $file ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( is_dir( $this->dir ) ) {
			rmdir( $this->dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		parent::tear_down();
	}

	public function test_a_missing_golden_fails_and_says_how_to_write_it(): void {
		try {
			$this->assert_snapshot( $this->dir, 'missing', '<p>Hello</p>' );
		} catch ( AssertionFailedError $e ) {
			$this->assertStringContainsString( 'UPDATE_SNAPSHOTS=1', $e->getMessage() );
			$this->assertFileDoesNotExist( $this->dir . '/missing.html' );
			return;
		}

		$this->fail( 'A missing golden passed.' );
	}

	public function test_update_mode_writes_the_golden_that_later_runs_compare_against(): void {
		putenv( 'UPDATE_SNAPSHOTS=1' );
		$this->assert_snapshot( $this->dir, 'written', '<p>Hello</p>' );
		putenv( 'UPDATE_SNAPSHOTS' );

		$this->assert_snapshot( $this->dir, 'written', '<p>Hello</p>' );

		$this->expectException( AssertionFailedError::class );
		$this->assert_snapshot( $this->dir, 'written', '<p>Goodbye</p>' );
	}

	public function test_update_mode_rewrites_a_golden_that_drifted(): void {
		putenv( 'UPDATE_SNAPSHOTS=1' );
		$this->assert_snapshot( $this->dir, 'drifted', '<p>Old</p>' );
		$this->assert_snapshot( $this->dir, 'drifted', '<p>New</p>' );
		putenv( 'UPDATE_SNAPSHOTS' );

		$this->assert_snapshot( $this->dir, 'drifted', '<p>New</p>' );
	}
}
