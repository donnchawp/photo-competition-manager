<?php
/**
 * Basic plugin bootstrap test.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Plugin;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Service\Log_Retention;
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

		$this->assertCount( 8, $schema );
		$this->assertStringContainsString( 'photocomp_members', $schema[0] );
		$this->assertStringContainsString( 'photocomp_competitions', $schema[1] );
		$this->assertStringContainsString( 'photocomp_images', $schema[2] );
		$this->assertStringContainsString( 'photocomp_votes', $schema[3] );
		$this->assertStringContainsString( 'photocomp_upload_tokens', $schema[4] );
		$this->assertStringContainsString( 'photocomp_voting_tokens', $schema[5] );
		$this->assertStringContainsString( 'photocomp_logs', $schema[6] );
		$this->assertStringContainsString( 'photocomp_recorded_results', $schema[7] );
	}

	/**
	 * Bootstrapping schedules the daily log trim, and the event trims old rows.
	 *
	 * @return void
	 */
	public function test_bootstrap_schedules_the_daily_log_trim(): void {
		wp_unschedule_hook( Log_Retention::HOOK );
		update_option( Log_Retention::OPTION, 1 );
		$logs = new Logs_Repository();
		$logs->create(
			array(
				'event_type'     => 'email_sent',
				'event_category' => 'email',
				'created_at'     => '2015-01-01 00:00:00',
			)
		);

		$plugin = new Plugin();
		$plugin->register();
		$plugin->bootstrap();

		$this->assertSame( 'daily', wp_get_schedule( Log_Retention::HOOK ) );

		do_action( Log_Retention::HOOK );

		$this->assertSame( 0, $logs->count( array( 'date_to' => '2015-01-01 00:00:00' ) ) );
	}
}
