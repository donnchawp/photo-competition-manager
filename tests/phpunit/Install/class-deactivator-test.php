<?php
/**
 * Tests for Deactivator.
 *
 * @package PhotoCompetitionManager\Tests\Install
 */

namespace PhotoCompetitionManager\Tests\Install;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Install\Deactivator;
use WP_UnitTestCase;

class Deactivator_Test extends WP_UnitTestCase {

	public function tear_down(): void {
		// Deactivation removes the capability from roles; give it back.
		Activator::activate();
		parent::tear_down();
	}

	public function test_deactivate_unschedules_the_daily_events(): void {
		wp_schedule_event( time(), 'daily', 'photo_comp_trim_logs' );
		wp_schedule_event( time(), 'daily', 'photo_comp_cleanup_email_jobs' );

		Deactivator::deactivate();

		$this->assertFalse( wp_next_scheduled( 'photo_comp_trim_logs' ) );
		$this->assertFalse( wp_next_scheduled( 'photo_comp_cleanup_email_jobs' ) );
	}
}
