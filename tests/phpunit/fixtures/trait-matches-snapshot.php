<?php
/**
 * Golden-file assertion for render tests.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

/**
 * Compare rendered HTML with a committed golden file.
 *
 * A missing golden fails. Run with UPDATE_SNAPSHOTS=1 (`make test UPDATE_SNAPSHOTS=1`)
 * to write missing goldens and rewrite ones that drifted; review the diff before committing.
 */
trait Matches_Snapshot {

	/**
	 * Assert HTML matches the golden file for a scenario.
	 *
	 * @param string $dir      Directory holding the goldens.
	 * @param string $scenario Scenario name; the golden is "<scenario>.html".
	 * @param string $html     Rendered, normalised HTML.
	 */
	private function assert_snapshot( string $dir, string $scenario, string $html ): void {
		$file = $dir . '/' . $scenario . '.html';

		if ( '1' === getenv( 'UPDATE_SNAPSHOTS' ) ) {
			if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
				$this->fail( "Could not create snapshot directory {$dir}." );
			}
			if ( false === file_put_contents( $file, $html ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$this->fail( "Could not write snapshot file {$file}." );
			}
			$this->addToAssertionCount( 1 );
			return;
		}

		if ( ! file_exists( $file ) ) {
			$this->fail( "No golden file for scenario {$scenario} ({$file}). Run `make test UPDATE_SNAPSHOTS=1` to write it, then review and commit it." );
		}

		$this->assertSame( file_get_contents( $file ), $html, "Rendered markup drifted for scenario {$scenario}." ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
