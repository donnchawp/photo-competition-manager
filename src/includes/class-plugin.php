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

		$email_jobs = ( new Dependencies() )->email_job_manager;

		$cron_handler = new \PhotoCompetitionManager\Service\Cron_Handler( null, null, $email_jobs );
		$cron_handler->register();

		$this->register_email_job_hooks( $email_jobs );
		$this->register_rest_api();
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
	 * Register email job background processing hooks.
	 *
	 * @param \PhotoCompetitionManager\Service\Email_Job_Manager $job_manager Email job queue.
	 * @return void
	 */
	private function register_email_job_hooks( \PhotoCompetitionManager\Service\Email_Job_Manager $job_manager ): void {
		// Register cron hook for processing batches. The old hook name keeps
		// batches scheduled before the rename running.
		add_action( \PhotoCompetitionManager\Service\Email_Job_Manager::BATCH_HOOK, array( $job_manager, 'process_batch' ), 10, 1 );
		add_action( 'photo_comp_send_results_batch', array( $job_manager, 'process_batch' ), 10, 1 );

		// Register daily cleanup hook.
		add_action( 'photo_comp_cleanup_email_jobs', array( $job_manager, 'cleanup_old_jobs' ) );

		// Schedule daily cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'photo_comp_cleanup_email_jobs' ) ) {
			wp_schedule_event( time(), 'daily', 'photo_comp_cleanup_email_jobs' );
		}
	}
}
