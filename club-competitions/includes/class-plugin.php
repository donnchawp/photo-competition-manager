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
	}
}
