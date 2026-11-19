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
		wp_mkdir_p( $this->dir );
		putenv( 'UPDATE_SNAPSHOTS' );
	}

	public function tear_down(): void {
		putenv( 'UPDATE_SNAPSHOTS' );
		$this->rmdir( $this->dir );
		$this->delete_folders( $this->dir );
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

	public function test_update_mode_writes_and_rewrites_the_golden_later_runs_compare_against(): void {
		putenv( 'UPDATE_SNAPSHOTS=1' );
		$this->assert_snapshot( $this->dir, 'drifted', '<p>Old</p>' );
		$this->assert_snapshot( $this->dir, 'drifted', '<p>New</p>' );
		putenv( 'UPDATE_SNAPSHOTS' );

		$this->assert_snapshot( $this->dir, 'drifted', '<p>New</p>' );

		$this->expectException( AssertionFailedError::class );
		$this->assert_snapshot( $this->dir, 'drifted', '<p>Old</p>' );
	}
}
