<?php
/**
 * Basic plugin bootstrap test.
 *
 * @package ClubCompetitions\Tests
 */

namespace ClubCompetitions\Tests;

use ClubCompetitions\Install\Activator;
use ClubCompetitions\Plugin;
use WP_UnitTestCase;

class PluginTest extends WP_UnitTestCase {

	/**
	 * Plugin bootstraps controllers.
	 *
	 * @return void
	 */
	public function test_plugin_registers_hooks(): void {
		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
	}

	/**
	 * Activation schema includes expected tables.
	 *
	 * @return void
	 */
	public function test_activation_schema_contains_expected_tables(): void {
		$schema = Activator::get_schema( $GLOBALS['wpdb'] );

		$this->assertCount( 5, $schema );
		$this->assertStringContainsString( 'clubcompete_members', $schema[0] );
		$this->assertStringContainsString( 'clubcompete_competitions', $schema[1] );
		$this->assertStringContainsString( 'clubcompete_images', $schema[2] );
		$this->assertStringContainsString( 'clubcompete_votes', $schema[3] );
		$this->assertStringContainsString( 'clubcompete_upload_tokens', $schema[4] );
	}
}
