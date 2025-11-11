<?php
/**
 * Admin interface orchestrator.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

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
	 * Setup wizard controller.
	 *
	 * @var Setup_Wizard_Controller
	 */
	private $setup_wizard_controller;

	/**
	 * Email templates controller.
	 *
	 * @var Email_Templates_Controller
	 */
	private $email_templates_controller;

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
		$this->competitions_controller    = new Competitions_Controller( $competitions );
		$this->members_controller         = new Members_Controller( $competitions, $members );
		$this->submissions_controller     = new Submissions_Controller( $competitions, $members, $images, $votes );
		$this->voting_controller          = new Voting_Controller( $competitions, $images );
		$this->settings_controller        = new Settings_Controller( $competitions, $members );
		$this->export_screen              = new Export_Screen();
		$this->results_controller         = new Results_Controller( $competitions, $images, $members, $votes, $analytics, $score_calculator, $email_service, $email_job_mgr );
		$this->setup_wizard_controller    = new Setup_Wizard_Controller();
		$this->email_templates_controller = new Email_Templates_Controller();
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
		$this->setup_wizard_controller->register();
		$this->email_templates_controller->register();
	}

	/**
	 * Register primary plugin menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Photo Competition Manager', 'photo-competition-manager' ),
			__( 'Competitions', 'photo-competition-manager' ),
			'manage_photo_competitions',
			'photo-competition-manager',
			array( $this->competitions_controller, 'render' ),
			'dashicons-camera'
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Setup Wizard', 'photo-competition-manager' ),
			__( 'Setup Wizard', 'photo-competition-manager' ),
			'manage_photo_competitions',
			'photo-competition-manager-setup',
			array( $this->setup_wizard_controller, 'render' )
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Members', 'photo-competition-manager' ),
			__( 'Members', 'photo-competition-manager' ),
			'manage_photo_competitions',
			'photo-competition-manager-members',
			array( $this->members_controller, 'render' )
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Submissions', 'photo-competition-manager' ),
			__( 'Submissions', 'photo-competition-manager' ),
			'manage_photo_competitions',
			'photo-competition-manager-submissions',
			array( $this->submissions_controller, 'render' )
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Voting Controls', 'photo-competition-manager' ),
			__( 'Voting Controls', 'photo-competition-manager' ),
			'manage_photo_competitions',
			'photo-competition-manager-voting',
			array( $this->voting_controller, 'render' )
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Results', 'photo-competition-manager' ),
			__( 'Results', 'photo-competition-manager' ),
			'manage_photo_competitions',
			'photo-competition-manager-results',
			array( $this->results_controller, 'render' )
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Settings', 'photo-competition-manager' ),
			__( 'Settings', 'photo-competition-manager' ),
			'manage_photo_competitions',
			'photo-competition-manager-settings',
			array( $this->settings_controller, 'render' )
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Export', 'photo-competition-manager' ),
			__( 'Export', 'photo-competition-manager' ),
			'manage_photo_competitions',
			'photo-competition-manager-export',
			array( $this->export_screen, 'render' )
		);

		add_submenu_page(
			'photo-competition-manager',
			__( 'Email Templates', 'photo-competition-manager' ),
			__( 'Email Templates', 'photo-competition-manager' ),
			'manage_options',
			'photo-competition-manager-email-templates',
			array( $this->email_templates_controller, 'render' )
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
		if ( 'competitions_page_photo-competition-manager-voting' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'photo-competition-manager-admin-slideshow',
			plugins_url( 'assets/css/admin-slideshow.css', dirname( __DIR__ ) . '/photo-competition-manager.php' ),
			array(),
			PHOTO_COMPETITION_MANAGER_VERSION
		);

		wp_enqueue_script(
			'photo-competition-manager-admin-slideshow',
			plugins_url( 'assets/js/admin-slideshow.js', dirname( __DIR__ ) . '/photo-competition-manager.php' ),
			array( 'jquery' ),
			PHOTO_COMPETITION_MANAGER_VERSION,
			true
		);

		wp_enqueue_script(
			'photo-competition-manager-qrcode',
			plugins_url( 'assets/js/qrcode.js', dirname( __DIR__ ) . '/photo-competition-manager.php' ),
			array(),
			PHOTO_COMPETITION_MANAGER_VERSION,
			true
		);

		wp_enqueue_script(
			'photo-competition-manager-admin-qr',
			plugins_url( 'assets/js/admin-qr.js', dirname( __DIR__ ) . '/photo-competition-manager.php' ),
			array( 'photo-competition-manager-qrcode' ),
			'1.0.0',
			true
		);

		// Pass AJAX URL and nonce to JavaScript.
		wp_localize_script(
			'photo-competition-manager-admin-slideshow',
			'photoCompetitionManagerSlideshow',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'photo_comp_admin_slideshow' ),
			)
		);
	}
}
