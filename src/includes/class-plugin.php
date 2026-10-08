<?php
/**
 * Core plugin orchestrator.
 *
 * @package PhotoCompetitionManager
 */

namespace PhotoCompetitionManager;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Admin_Screen;
use PhotoCompetitionManager\Frontend\Frontend;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Service\Log_Retention;

/**
 * Class Plugin
 *
 * @package PhotoCompetitionManager
 */
class Plugin {

	/**
	 * Admin controller.
	 *
	 * @var Admin_Screen
	 */
	private $admin;

	/**
	 * Frontend controller.
	 *
	 * @var Frontend
	 */
	private $frontend;

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->admin    = new Admin_Screen();
		$this->frontend = new Frontend();

		add_action( 'plugins_loaded', array( $this, 'bootstrap' ) );
	}

	/**
	 * Bootstrap plugin components.
	 *
	 * @return void
	 */
	public function bootstrap(): void {
		\PhotoCompetitionManager\Install\Activator::maybe_upgrade();

		$this->admin->register();
		$this->frontend->register();

		$deps = new Dependencies();
		$this->register_email_job_hooks( $deps->email_job_manager );
		$this->register_log_trim( $deps->logs );
		$this->register_rest_api();

		add_filter( 'wp_privacy_personal_data_erasers', array( \PhotoCompetitionManager\Service\Member_Deletion::class, 'register_eraser' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( \PhotoCompetitionManager\Service\Member_Export::class, 'register_exporter' ) );
		add_action( 'admin_init', array( \PhotoCompetitionManager\Support\Privacy_Policy::class, 'suggest' ) );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	private function register_rest_api(): void {
		add_action(
			'rest_api_init',
			function () {
				$upload_api = new \PhotoCompetitionManager\API\Upload_API();
				$upload_api->register_routes();
			}
		);
	}

	/**
	 * Register email job cleanup hooks.
	 *
	 * @param \PhotoCompetitionManager\Service\Email_Job_Manager $job_manager Email job queue.
	 * @return void
	 */
	private function register_email_job_hooks( \PhotoCompetitionManager\Service\Email_Job_Manager $job_manager ): void {
		// Register daily cleanup hook.
		add_action( 'photo_comp_cleanup_email_jobs', array( $job_manager, 'cleanup_old_jobs' ) );

		// Schedule daily cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'photo_comp_cleanup_email_jobs' ) ) {
			wp_schedule_event( time(), 'daily', 'photo_comp_cleanup_email_jobs' );
		}
	}

	/**
	 * Register the daily trim of old log rows.
	 *
	 * @since 0.4.0
	 *
	 * @param Logs_Repository $logs Logs repository.
	 * @return void
	 */
	private function register_log_trim( Logs_Repository $logs ): void {
		$retention = new Log_Retention( $logs );
		add_action( Log_Retention::HOOK, array( $retention, 'trim' ), 10, 0 );

		if ( ! wp_next_scheduled( Log_Retention::HOOK ) ) {
			wp_schedule_event( time(), 'daily', Log_Retention::HOOK );
		}
	}
}
