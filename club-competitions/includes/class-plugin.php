<?php
/**
 * Core plugin orchestrator.
 *
 * @package ClubCompetitions
 */

namespace ClubCompetitions;

use ClubCompetitions\Admin\Admin_Screen;
use ClubCompetitions\Frontend\Frontend;
use ClubCompetitions\Repository\Competitions_Repository;
use ClubCompetitions\Repository\Images_Repository;
use ClubCompetitions\Repository\Members_Repository;

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
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

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
		$this->competitions = new Competitions_Repository();
		$this->members      = new Members_Repository();
		$this->images       = new Images_Repository();
		$this->admin        = new Admin_Screen( $this->competitions, $this->members, $this->images );
		$this->frontend     = new Frontend();

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
