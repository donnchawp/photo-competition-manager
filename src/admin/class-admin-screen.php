<?php
/**
 * Admin interface orchestrator.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.


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
	 * Logs controller.
	 *
	 * @var Logs_Controller
	 */
	private $logs_controller;

	/**
	 * Constructor.
	 *
	 * @param Admin_Dependencies|null $deps Optional dependencies container.
	 */
	public function __construct( ?Admin_Dependencies $deps = null ) {
		$deps = $deps ?? new Admin_Dependencies();

		// Initialize controllers with their dependencies.
		$this->competitions_controller    = new Competitions_Controller( $deps->competitions );
		$this->members_controller         = new Members_Controller( $deps->competitions, $deps->members );
		$this->submissions_controller     = new Submissions_Controller( $deps->competitions, $deps->members, $deps->images, $deps->votes );
		$this->voting_controller          = new Voting_Controller( $deps->competitions, $deps->images );
		$this->settings_controller        = new Settings_Controller( $deps->competitions, $deps->members );
		$this->export_screen              = new Export_Screen();
		$this->results_controller         = new Results_Controller(
			$deps->competitions,
			$deps->images,
			$deps->members,
			$deps->votes,
			$deps->analytics,
			$deps->score_calculator,
			$deps->email_service,
			$deps->email_job_manager
		);
		$this->setup_wizard_controller    = new Setup_Wizard_Controller();
		$this->email_templates_controller = new Email_Templates_Controller();
		$this->logs_controller            = new Logs_Controller( $deps->logs, $deps->competitions );
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
		$this->export_screen->register();
		$this->results_controller->register();
		$this->setup_wizard_controller->register();
		$this->email_templates_controller->register();
		$this->logs_controller->register();
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
			__( 'Logs', 'photo-competition-manager' ),
			__( 'Logs', 'photo-competition-manager' ),
			'manage_photo_competitions',
			'photo-competition-manager-logs',
			array( $this->logs_controller, 'render' )
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
			PHOTO_COMPETITION_MANAGER_URL . 'assets/css/admin-slideshow.css',
			array(),
			PHOTO_COMPETITION_MANAGER_VERSION
		);

		wp_enqueue_script(
			'photo-competition-manager-admin-slideshow',
			PHOTO_COMPETITION_MANAGER_URL . 'assets/js/admin-slideshow.js',
			array( 'jquery' ),
			PHOTO_COMPETITION_MANAGER_VERSION,
			true
		);

		wp_enqueue_script(
			'photo-competition-manager-qrcode',
			PHOTO_COMPETITION_MANAGER_URL . 'assets/js/qrcode.js',
			array(),
			PHOTO_COMPETITION_MANAGER_VERSION,
			true
		);

		wp_enqueue_script(
			'photo-competition-manager-admin-qr',
			PHOTO_COMPETITION_MANAGER_URL . 'assets/js/admin-qr.js',
			array( 'photo-competition-manager-qrcode' ),
			PHOTO_COMPETITION_MANAGER_VERSION,
			true
		);

		// Pass AJAX URL and nonce to JavaScript.
		wp_localize_script(
			'photo-competition-manager-admin-slideshow',
			'photoCompetitionManagerSlideshow',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'photo_comp_admin_slideshow' ),
				'stepNonce' => wp_create_nonce( 'photo_comp_voting_step' ),
			)
		);
	}
}
