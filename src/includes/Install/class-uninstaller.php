<?php
/**
 * Remove the plugin's options, transients and cron events on uninstall.
 *
 * @package PhotoCompetitionManager\Install
 */

namespace PhotoCompetitionManager\Install;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Plugin Uninstaller.
 *
 * Loaded directly by uninstall.php, as the plugin's autoloader isn't
 * running then, so it mustn't depend on other plugin classes.
 *
 * @since 0.3.0
 */
class Uninstaller {

	/**
	 * Delete the plugin's options, transients and cron events.
	 *
	 * @return void
	 */
	public static function delete_data(): void {
		delete_option( 'photo_comp_db_version' );
		delete_option( 'photo_comp_default_settings' );
		delete_option( 'photo_comp_email_templates' );
		delete_option( 'photo_comp_voting_ui_type' );

		// One option per bulk email job, plus a lock left by any request that died mid-batch.
		self::delete_options_starting_with( 'photo_comp_email_job_' );
		self::delete_options_starting_with( 'photo_comp_email_lock_' );

		// Transient names vary by competition and member.
		self::delete_options_starting_with( '_transient_photo_comp_' );
		self::delete_options_starting_with( '_transient_timeout_photo_comp_' );

		// The batch hooks were scheduled with a job ID argument, which
		// wp_clear_scheduled_hook() without args wouldn't match.
		wp_unschedule_hook( 'photo_comp_cleanup_email_jobs' );
		wp_unschedule_hook( 'photo_comp_send_email_batch' );
		wp_unschedule_hook( 'photo_comp_send_results_batch' );
		wp_unschedule_hook( 'photo_competition_daily_cron' );
	}

	/**
	 * Delete every option whose name starts with a prefix.
	 *
	 * @param string $prefix Option name prefix, matched literally.
	 * @return void
	 */
	private static function delete_options_starting_with( string $prefix ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
	}
}
