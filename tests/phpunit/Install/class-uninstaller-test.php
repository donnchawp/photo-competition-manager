<?php
/**
 * Tests for Uninstaller.
 *
 * @package PhotoCompetitionManager\Tests\Install
 */

namespace PhotoCompetitionManager\Tests\Install;

use PhotoCompetitionManager\Install\Uninstaller;
use WP_UnitTestCase;

class Uninstaller_Test extends WP_UnitTestCase {

	public function test_delete_data_removes_plugin_options(): void {
		update_option( 'photo_comp_db_version', 2 );
		update_option( 'photo_comp_default_settings', array( 'grades' => array() ) );
		update_option( 'photo_comp_email_templates', array( 'voting_opened' => array( 'enabled' => true ) ) );
		update_option( 'photo_comp_voting_ui_type', 'buttons' );

		Uninstaller::delete_data();

		$this->assertFalse( get_option( 'photo_comp_db_version' ) );
		$this->assertFalse( get_option( 'photo_comp_default_settings' ) );
		$this->assertFalse( get_option( 'photo_comp_email_templates' ) );
		$this->assertFalse( get_option( 'photo_comp_voting_ui_type' ) );
	}

	public function test_delete_data_removes_options_added_later(): void {
		// Every plugin option starts with photo_comp_, so new ones go too.
		update_option( 'photo_comp_some_future_setting', 'value' );

		Uninstaller::delete_data();

		$this->assertFalse( get_option( 'photo_comp_some_future_setting' ) );
	}

	public function test_delete_data_removes_email_jobs_and_locks(): void {
		update_option( 'photo_comp_email_job_email_job_a', array( 'status' => 'completed' ), false );
		update_option( 'photo_comp_email_job_email_job_b', array( 'status' => 'processing' ), false );
		update_option( 'photo_comp_email_lock_email_job_b', time(), false );

		Uninstaller::delete_data();

		$this->assertSame( 0, $this->count_options( 'photo_comp_email_' ) );
	}

	public function test_delete_data_leaves_other_options_alone(): void {
		// The LIKE pattern must not treat the underscores in the prefix as wildcards.
		update_option( 'photo_compXemail_jobXkeep', 'keep' );
		update_option( 'blogname', 'Club Site' );

		Uninstaller::delete_data();

		$this->assertSame( 'keep', get_option( 'photo_compXemail_jobXkeep' ) );
		$this->assertSame( 'Club Site', get_option( 'blogname' ) );
	}

	public function test_delete_data_removes_plugin_transients(): void {
		set_transient( 'photo_comp_closed_notif_1', 1, YEAR_IN_SECONDS );
		set_transient( 'photo_comp_admin_upload_1_2_3', true, 300 );

		Uninstaller::delete_data();

		$this->assertFalse( get_transient( 'photo_comp_closed_notif_1' ) );
		$this->assertFalse( get_transient( 'photo_comp_admin_upload_1_2_3' ) );
	}

	public function test_delete_data_unschedules_cron_events(): void {
		wp_schedule_event( time(), 'daily', 'photo_comp_cleanup_email_jobs' );
		wp_schedule_event( time(), 'daily', 'photo_competition_daily_cron' );
		wp_schedule_single_event( time() + 60, 'photo_comp_send_email_batch', array( 'email_job_a' ) );
		wp_schedule_single_event( time() + 60, 'photo_comp_send_results_batch', array( 'email_job_b' ) );

		Uninstaller::delete_data();

		$this->assertFalse( wp_next_scheduled( 'photo_comp_cleanup_email_jobs' ) );
		$this->assertFalse( wp_next_scheduled( 'photo_competition_daily_cron' ) );
		$this->assertFalse( wp_next_scheduled( 'photo_comp_send_email_batch', array( 'email_job_a' ) ) );
		$this->assertFalse( wp_next_scheduled( 'photo_comp_send_results_batch', array( 'email_job_b' ) ) );
	}

	/**
	 * Count rows in wp_options whose name starts with a prefix.
	 *
	 * @param string $prefix Option name prefix.
	 * @return int
	 */
	private function count_options( string $prefix ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( $prefix ) . '%' ) );
	}
}
