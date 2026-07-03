<?php
/**
 * Basic plugin bootstrap test.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Plugin;
use WP_UnitTestCase;

/**
 * Plugin bootstrap tests.
 */
class Plugin_Test extends WP_UnitTestCase {

	/**
	 * Plugin bootstraps controllers.
	 *
	 * @return void
	 */
	public function test_plugin_registers_hooks(): void {
		$this->assertInstanceOf( Plugin::class, new Plugin() );
	}

	/**
	 * Activation schema includes expected tables.
	 *
	 * @return void
	 */
	public function test_activation_schema_contains_expected_tables(): void {
		$schema = Activator::get_schema( $GLOBALS['wpdb'] );

		$this->assertCount( 7, $schema );
		$this->assertStringContainsString( 'photocomp_members', $schema[0] );
		$this->assertStringContainsString( 'photocomp_competitions', $schema[1] );
		$this->assertStringContainsString( 'photocomp_images', $schema[2] );
		$this->assertStringContainsString( 'photocomp_votes', $schema[3] );
		$this->assertStringContainsString( 'photocomp_upload_tokens', $schema[4] );
		$this->assertStringContainsString( 'photocomp_voting_tokens', $schema[5] );
		$this->assertStringContainsString( 'photocomp_logs', $schema[6] );
	}
}
