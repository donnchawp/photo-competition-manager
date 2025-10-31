<?php
/**
 * Core plugin orchestrator.
 *
 * @package PhotoCompetitionManager
 */

namespace PhotoCompetitionManager;

use PhotoCompetitionManager\Admin\Admin_Screen;
use PhotoCompetitionManager\Frontend\Frontend;

/**
 * Class Plugin
 *
 * @package PhotoCompetitionManager
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

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
	 * Access the singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

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
		$this->admin->register();
		$this->frontend->register();

		$cron_handler = new \PhotoCompetitionManager\Service\Cron_Handler();
		$cron_handler->register();

		$this->register_email_job_hooks();
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
	 * @return void
	 */
	private function register_email_job_hooks(): void {
		// Initialize repositories and services.
		$competitions = new \PhotoCompetitionManager\Repository\Competitions_Repository();
		$members      = new \PhotoCompetitionManager\Repository\Members_Repository();
		$images       = new \PhotoCompetitionManager\Repository\Images_Repository();
		$votes        = new \PhotoCompetitionManager\Repository\Votes_Repository();
		$analytics    = new \PhotoCompetitionManager\Service\Results_Analytics( $competitions, $images, $members, $votes );
		$calculator   = new \PhotoCompetitionManager\Service\Score_Calculator( $images, $votes );
		$email        = new \PhotoCompetitionManager\Service\Email_Service();

		$job_manager = new \PhotoCompetitionManager\Service\Email_Results_Job_Manager(
			$competitions,
			$images,
			$members,
			$votes,
			$analytics,
			$calculator,
			$email
		);

		// Register cron hook for processing batches.
		add_action( 'photo_comp_send_results_batch', array( $job_manager, 'process_batch' ), 10, 1 );

		// Register daily cleanup hook.
		add_action( 'photo_comp_cleanup_email_jobs', array( $job_manager, 'cleanup_old_jobs' ) );

		// Schedule daily cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'photo_comp_cleanup_email_jobs' ) ) {
			wp_schedule_event( time(), 'daily', 'photo_comp_cleanup_email_jobs' );
		}
	}
}
