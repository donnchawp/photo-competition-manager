<?php
/**
 * Admin interface orchestrator.
 *
 * @package ClubCompetitions\Admin
 */

namespace ClubCompetitions\Admin;

use ClubCompetitions\Repository\Competitions_Repository;
use ClubCompetitions\Repository\Images_Repository;
use ClubCompetitions\Repository\Members_Repository;
use ClubCompetitions\Repository\Votes_Repository;

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
	 * Constructor.
	 */
	public function __construct() {
		// Initialize repositories.
		$competitions = new Competitions_Repository();
		$members      = new Members_Repository();
		$images       = new Images_Repository();
		$votes        = new Votes_Repository();

		// Initialize controllers with their dependencies.
		$this->competitions_controller = new Competitions_Controller( $competitions );
		$this->members_controller      = new Members_Controller( $competitions, $members );
		$this->submissions_controller  = new Submissions_Controller( $competitions, $members, $images, $votes );
		$this->voting_controller       = new Voting_Controller( $competitions, $images );
		$this->settings_controller     = new Settings_Controller();
		$this->export_screen           = new Export_Screen();
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
	}

	/**
	 * Register primary plugin menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Club Competitions', 'club-competitions' ),
			__( 'Competitions', 'club-competitions' ),
			'publish_posts',
			'club-competitions',
			array( $this->competitions_controller, 'render' ),
			'dashicons-camera'
		);

		add_submenu_page(
			'club-competitions',
			__( 'Members', 'club-competitions' ),
			__( 'Members', 'club-competitions' ),
			'publish_posts',
			'club-competitions-members',
			array( $this->members_controller, 'render' )
		);

		add_submenu_page(
			'club-competitions',
			__( 'Submissions', 'club-competitions' ),
			__( 'Submissions', 'club-competitions' ),
			'publish_posts',
			'club-competitions-submissions',
			array( $this->submissions_controller, 'render' )
		);

		add_submenu_page(
			'club-competitions',
			__( 'Voting Controls', 'club-competitions' ),
			__( 'Voting Controls', 'club-competitions' ),
			'publish_posts',
			'club-competitions-voting',
			array( $this->voting_controller, 'render' )
		);

		add_submenu_page(
			'club-competitions',
			__( 'Settings', 'club-competitions' ),
			__( 'Settings', 'club-competitions' ),
			'publish_posts',
			'club-competitions-settings',
			array( $this->settings_controller, 'render' )
		);

		add_submenu_page(
			'club-competitions',
			__( 'Export', 'club-competitions' ),
			__( 'Export', 'club-competitions' ),
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
				'nonce'   => wp_create_nonce( 'club_compete_admin_slideshow' ),
			)
		);
	}
}
