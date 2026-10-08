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
use PhotoCompetitionManager\Service\Email_Job_Manager;
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
		$this->schedule_daily( Email_Job_Manager::CLEANUP_HOOK, array( $deps->email_job_manager, 'cleanup_old_jobs' ) );
		// 0 args: a bare do_action() passes '', which trim( ?int $now ) would reject with a TypeError.
		$this->schedule_daily( Log_Retention::HOOK, array( new Log_Retention( $deps->logs ), 'trim' ), 0 );
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
	 * Hook a callback to a daily cron event, scheduling the event if needed.
	 *
	 * @since 0.4.0
	 *
	 * @param string   $hook          Cron event name.
	 * @param callable $callback      Runs when the event fires.
	 * @param int      $accepted_args Arguments the callback takes.
	 * @return void
	 */
	private function schedule_daily( string $hook, callable $callback, int $accepted_args = 1 ): void {
		add_action( $hook, $callback, 10, $accepted_args );

		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time(), 'daily', $hook );
		}
	}
}
