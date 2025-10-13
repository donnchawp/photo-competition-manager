<?php
/**
 * Admin interface hooks.
 *
 * @package ClubCompetitions\Admin
 */

namespace ClubCompetitions\Admin;

use ClubCompetitions\Repository\CompetitionsRepository;
use ClubCompetitions\Repository\MembersRepository;

class AdminScreen {

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
	 * Constructor.
	 *
	 * @param CompetitionsRepository|null $competitions Competition repository.
	 * @param MembersRepository|null      $members      Member repository.
	 */
	public function __construct( CompetitionsRepository $competitions = null, MembersRepository $members = null ) {
		$this->competitions = $competitions ?: new CompetitionsRepository();
		$this->members      = $members ?: new MembersRepository();
	}

	/**
	 * Attach admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
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
			'manage_options',
			'club-competitions',
			array( $this, 'render_dashboard' ),
			'dashicons-camera'
		);

		add_submenu_page(
			'club-competitions',
			__( 'Members', 'club-competitions' ),
			__( 'Members', 'club-competitions' ),
			'manage_options',
			'club-competitions-members',
			array( $this, 'render_members_page' )
		);
	}

	/**
	 * Render admin dashboard overview.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		settings_errors( 'club_competitions' );

		$competitions = $this->competitions->all( 10 );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Club Competitions Dashboard', 'club-competitions' ) . '</h1>';

		$this->render_create_form();

		if ( empty( $competitions ) ) {
			echo '<p>' . esc_html__( 'No competitions recorded yet. Start by creating your first competition.', 'club-competitions' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Opens', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Closes', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Last Updated', 'club-competitions' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $competitions as $competition ) {
			echo '<tr>';
			echo '<td>' . esc_html( $competition->title ) . '</td>';
			echo '<td>' . esc_html( $competition->status ) . '</td>';
			echo '<td>' . esc_html( $competition->open_date ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $competition->close_date ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $competition->updated_at ?: $competition->created_at ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Render members list.
	 *
	 * @return void
	 */
	public function render_members_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'club-competitions' ) );
		}

		$members = $this->members->all( false );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Members', 'club-competitions' ) . '</h1>';

		if ( empty( $members ) ) {
			echo '<p>' . esc_html__( 'No members recorded yet. Import or create members to get started.', 'club-competitions' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Grade', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Joined', 'club-competitions' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $members as $member ) {
			echo '<tr>';
			echo '<td>' . esc_html( $member->name ) . '</td>';
			echo '<td>' . esc_html( $member->email ) . '</td>';
			echo '<td>' . esc_html( $member->grade ) . '</td>';
			echo '<td>' . esc_html( $member->active ? __( 'Active', 'club-competitions' ) : __( 'Inactive', 'club-competitions' ) ) . '</td>';
			echo '<td>' . esc_html( $member->created_at ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Handle admin post actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! isset( $_POST['club_competitions_action'] ) ) {
			return;
		}

		check_admin_referer( 'club_competitions_create', 'club_competitions_nonce' );

		$data = array(
			'title'       => wp_unslash( $_POST['competition_title'] ?? '' ),
			'slug'        => wp_unslash( $_POST['competition_slug'] ?? '' ),
			'status'      => wp_unslash( $_POST['competition_status'] ?? 'draft' ),
			'open_date'   => wp_unslash( $_POST['competition_open_date'] ?? '' ),
			'close_date'  => wp_unslash( $_POST['competition_close_date'] ?? '' ),
			'voting_open' => wp_unslash( $_POST['competition_voting_open'] ?? '' ),
		);

		$result = $this->competitions->create( $data );

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'club_competitions',
				$result->get_error_code(),
				$result->get_error_message(),
				'error'
			);
		} else {
			add_settings_error(
				'club_competitions',
				'created',
				__( 'Competition created successfully.', 'club-competitions' ),
				'updated'
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'club-competitions',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the create competition form.
	 *
	 * @return void
	 */
	private function render_create_form(): void {
		echo '<form method="post" class="card" style="max-width: 720px; margin-bottom: 24px; padding: 16px;">';
		echo '<h2>' . esc_html__( 'Create Competition', 'club-competitions' ) . '</h2>';

		wp_nonce_field( 'club_competitions_create', 'club_competitions_nonce' );
		echo '<input type="hidden" name="club_competitions_action" value="create_competition" />';

		echo '<p>';
		echo '<label for="competition_title">' . esc_html__( 'Title', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="competition_title" name="competition_title" class="regular-text" required />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_slug">' . esc_html__( 'Slug (optional)', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="competition_slug" name="competition_slug" class="regular-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_status">' . esc_html__( 'Status', 'club-competitions' ) . '</label><br />';
		echo '<select id="competition_status" name="competition_status">';
		echo '<option value="draft">' . esc_html__( 'Draft', 'club-competitions' ) . '</option>';
		echo '<option value="scheduled">' . esc_html__( 'Scheduled', 'club-competitions' ) . '</option>';
		echo '<option value="active">' . esc_html__( 'Active', 'club-competitions' ) . '</option>';
		echo '<option value="closed">' . esc_html__( 'Closed', 'club-competitions' ) . '</option>';
		echo '</select>';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_open_date">' . esc_html__( 'Open Date (YYYY-MM-DD)', 'club-competitions' ) . '</label><br />';
		echo '<input type="date" id="competition_open_date" name="competition_open_date" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_close_date">' . esc_html__( 'Close Date (YYYY-MM-DD)', 'club-competitions' ) . '</label><br />';
		echo '<input type="date" id="competition_close_date" name="competition_close_date" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_voting_open">' . esc_html__( 'Voting Opens (YYYY-MM-DD)', 'club-competitions' ) . '</label><br />';
		echo '<input type="date" id="competition_voting_open" name="competition_voting_open" />';
		echo '</p>';

		submit_button( __( 'Create Competition', 'club-competitions' ) );

		echo '</form>';
	}
}
