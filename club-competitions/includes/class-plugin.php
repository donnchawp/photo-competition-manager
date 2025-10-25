<?php
/**
 * Core plugin orchestrator.
 *
 * @package ClubCompetitions
 */

namespace ClubCompetitions;

use ClubCompetitions\Admin\Admin_Screen;
use ClubCompetitions\Frontend\Frontend;

/**
 * Class Plugin
 *
 * @package ClubCompetitions
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

		$cron_handler = new \ClubCompetitions\Service\Cron_Handler();
		$cron_handler->register();

		$this->register_email_job_hooks();
	}

	/**
	 * Register email job background processing hooks.
	 *
	 * @return void
	 */
	private function register_email_job_hooks(): void {
		// Initialize repositories and services.
		$competitions = new \ClubCompetitions\Repository\Competitions_Repository();
		$members      = new \ClubCompetitions\Repository\Members_Repository();
		$images       = new \ClubCompetitions\Repository\Images_Repository();
		$votes        = new \ClubCompetitions\Repository\Votes_Repository();
		$analytics    = new \ClubCompetitions\Service\Results_Analytics( $competitions, $images, $members, $votes );
		$calculator   = new \ClubCompetitions\Service\Score_Calculator( $images, $votes );
		$email        = new \ClubCompetitions\Service\Email_Service();

		$job_manager = new \ClubCompetitions\Service\Email_Results_Job_Manager(
			$competitions,
			$images,
			$members,
			$votes,
			$analytics,
			$calculator,
			$email
		);

		// Register cron hook for processing batches.
		add_action( 'club_compete_send_results_batch', array( $job_manager, 'process_batch' ), 10, 1 );

		// Register daily cleanup hook.
		add_action( 'club_compete_cleanup_email_jobs', array( $job_manager, 'cleanup_old_jobs' ) );

		// Schedule daily cleanup if not already scheduled.
		if ( ! wp_next_scheduled( 'club_compete_cleanup_email_jobs' ) ) {
			wp_schedule_event( time(), 'daily', 'club_compete_cleanup_email_jobs' );
		}
	}
}
