<?php
/**
 * Core plugin orchestrator.
 *
 * @package ClubCompetitions
 */

namespace ClubCompetitions;

use ClubCompetitions\Admin\AdminScreen;
use ClubCompetitions\Frontend\Frontend;
use ClubCompetitions\Repository\CompetitionsRepository;
use ClubCompetitions\Repository\ImagesRepository;
use ClubCompetitions\Repository\MembersRepository;

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
	 * @var AdminScreen
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
	 * @var CompetitionsRepository
	 */
	private $competitions;

	/**
	 * Members repository.
	 *
	 * @var MembersRepository
	 */
	private $members;

	/**
	 * Images repository.
	 *
	 * @var ImagesRepository
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
		$this->competitions = new CompetitionsRepository();
		$this->members      = new MembersRepository();
		$this->images       = new ImagesRepository();
		$this->admin        = new AdminScreen( $this->competitions, $this->members, $this->images );
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
	}
}
