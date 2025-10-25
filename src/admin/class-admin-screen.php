<?php
/**
 * Admin interface orchestrator.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;

/**
 * Orchestrate admin menus and delegate to specialized controllers.
 *
 * @since 0.1.0
 */
class Admin_Screen {

	/**
	 * Competitions controller.
	 *
	 * @var Competitions_Controller
	 */
	private $competitions_controller;

	/**
	 * Members controller.
	 *
	 * @var Members_Controller
	 */
	private $members_controller;

	/**
	 * Submissions controller.
	 *
	 * @var Submissions_Controller
	 */
	private $submissions_controller;

	/**
	 * Voting controller.
	 *
	 * @var Voting_Controller
	 */
	private $voting_controller;

	/**
	 * Settings controller.
	 *
	 * @var Settings_Controller
	 */
	private $settings_controller;

	/**
	 * Export screen.
	 *
	 * @var Export_Screen
	 */
	private $export_screen;

	/**
	 * Results controller.
	 *
	 * @var Results_Controller
	 */
	private $results_controller;

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Initialize repositories.
		$competitions = new Competitions_Repository();
		$members      = new Members_Repository();
		$images       = new Images_Repository();
		$votes        = new Votes_Repository();

		// Initialize services.
		$analytics        = new \PhotoCompetitionManager\Service\Results_Analytics( $competitions, $images, $members, $votes );
		$score_calculator = new \PhotoCompetitionManager\Service\Score_Calculator( $images, $votes );
		$email_service    = new \PhotoCompetitionManager\Service\Email_Service();
		$email_job_mgr    = new \PhotoCompetitionManager\Service\Email_Results_Job_Manager( $competitions, $images, $members, $votes, $analytics, $score_calculator, $email_service );

		// Initialize controllers with their dependencies.
		$this->competitions_controller = new Competitions_Controller( $competitions );
		$this->members_controller      = new Members_Controller( $competitions, $members );
		$this->submissions_controller  = new Submissions_Controller( $competitions, $members, $images, $votes );
		$this->voting_controller       = new Voting_Controller( $competitions, $images );
		$this->settings_controller     = new Settings_Controller();
		$this->export_screen           = new Export_Screen();
		$this->results_controller      = new Results_Controller( $competitions, $images, $members, $votes, $analytics, $score_calculator, $email_service, $email_job_mgr );
	}

	/**
	 * Attach admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// Register all controllers.
		$this->competitions_controller->register();
		$this->members_controller->register();
		$this->submissions_controller->register();
		$this->voting_controller->register();
		$this->settings_controller->register();
		$this->results_controller->register();
	}

	/**
	 * Register primary plugin menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Club Competitions', 'photo-competition-manager' ),
			__( 'Competitions', 'photo-competition-manager' ),
			'publish_posts',
			'photo-competition-manager',
			array( $this->competitions_controller, 'render' ),
			'dashicons-camera'
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Members', 'photo-competition-manager' ),
			__( 'Members', 'photo-competition-manager' ),
			'publish_posts',
			'club-competitions-members',
			array( $this->members_controller, 'render' )
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Submissions', 'photo-competition-manager' ),
			__( 'Submissions', 'photo-competition-manager' ),
			'publish_posts',
			'club-competitions-submissions',
			array( $this->submissions_controller, 'render' )
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Voting Controls', 'photo-competition-manager' ),
			__( 'Voting Controls', 'photo-competition-manager' ),
			'publish_posts',
			'club-competitions-voting',
			array( $this->voting_controller, 'render' )
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Results', 'photo-competition-manager' ),
			__( 'Results', 'photo-competition-manager' ),
			'publish_posts',
			'club-competitions-results',
			array( $this->results_controller, 'render' )
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Settings', 'photo-competition-manager' ),
			__( 'Settings', 'photo-competition-manager' ),
			'publish_posts',
			'club-competitions-settings',
			array( $this->settings_controller, 'render' )
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Export', 'photo-competition-manager' ),
			__( 'Export', 'photo-competition-manager' ),
			'publish_posts',
			'club-competitions-export',
			array( $this->export_screen, 'render' )
		);
	}

	/**
	 * Enqueue admin assets for slideshow.
	 *
	 * @param  string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		// Only load on voting controls page.
		if ( 'competitions_page_club-competitions-voting' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'club-competitions-admin-slideshow',
			plugins_url( 'assets/css/admin-slideshow.css', dirname( __DIR__ ) . '/club-competitions.php' ),
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'club-competitions-admin-slideshow',
			plugins_url( 'assets/js/admin-slideshow.js', dirname( __DIR__ ) . '/club-competitions.php' ),
			array( 'jquery' ),
			'1.0.0',
			true
		);

		wp_enqueue_script(
			'club-competitions-qrcode',
			plugins_url( 'assets/js/vendor/qrcode.js', dirname( __DIR__ ) . '/club-competitions.php' ),
			array(),
			'1.0.0',
			true
		);

		wp_enqueue_script(
			'club-competitions-admin-qr',
			plugins_url( 'assets/js/admin-qr.js', dirname( __DIR__ ) . '/club-competitions.php' ),
			array( 'club-competitions-qrcode' ),
			'1.0.0',
			true
		);

		// Pass AJAX URL and nonce to JavaScript.
		wp_localize_script(
			'club-competitions-admin-slideshow',
			'clubCompeteSlideshow',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'photo_comp_admin_slideshow' ),
			)
		);
	}
}
