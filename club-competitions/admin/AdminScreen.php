<?php
/**
 * Admin interface hooks.
 *
 * @package ClubCompetitions\Admin
 */

namespace ClubCompetitions\Admin;

use ClubCompetitions\Repository\CompetitionsRepository;
use ClubCompetitions\Repository\MembersRepository;
use ClubCompetitions\Support\CompetitionSettings;

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
	public function __construct( ?CompetitionsRepository $competitions = null, ?MembersRepository $members = null ) {
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

		add_submenu_page(
			'club-competitions',
			__( 'Settings', 'club-competitions' ),
			__( 'Settings', 'club-competitions' ),
			'manage_options',
			'club-competitions-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render admin dashboard overview.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		if ( isset( $_GET['action'], $_GET['competition'] ) && 'edit' === $_GET['action'] ) {
			$this->render_edit_screen( absint( $_GET['competition'] ) );
			return;
		}

		settings_errors( 'club_competitions' );

		$view         = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'active';
		$competitions = $this->competitions->all( 10, 'archived' === $view, 'archived' === $view );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Club Competitions Dashboard', 'club-competitions' ) . '</h1>';

		$this->render_create_form();

		$this->render_competition_table( $competitions, $view );
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

		settings_errors( 'club_competitions_members' );

		$member_action = isset( $_GET['member_action'] ) ? sanitize_text_field( wp_unslash( $_GET['member_action'] ) ) : '';
		$member_id     = isset( $_GET['member'] ) ? absint( $_GET['member'] ) : 0;
		$current       = null;

		if ( 'edit' === $member_action && $member_id ) {
			$current = $this->members->find( $member_id );
		}

		$members = $this->members->all( false );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Members', 'club-competitions' ) . '</h1>';

		if ( 'edit' === $member_action ) {
			$this->render_member_edit_form( $current );
		} else {
			$this->render_member_create_form();
		}

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
		echo '<th>' . esc_html__( 'Actions', 'club-competitions' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $members as $member ) {
			$edit_link = esc_url(
				add_query_arg(
					array(
						'page'          => 'club-competitions-members',
						'member_action' => 'edit',
						'member'        => (int) $member->id,
					),
					admin_url( 'admin.php' )
				)
			);

			echo '<tr>';
			echo '<td>' . esc_html( $member->name ) . '</td>';
			echo '<td>' . esc_html( $member->email ) . '</td>';
			echo '<td>' . esc_html( $member->grade ) . '</td>';
			echo '<td>' . esc_html( $member->active ? __( 'Active', 'club-competitions' ) : __( 'Inactive', 'club-competitions' ) ) . '</td>';
			echo '<td>' . esc_html( $member->created_at ) . '</td>';
			echo '<td><a href="' . $edit_link . '">' . esc_html__( 'Edit', 'club-competitions' ) . '</a></td>';
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

		$action = '';

		if ( isset( $_POST['club_competitions_action'] ) ) {
			$action = sanitize_text_field( wp_unslash( $_POST['club_competitions_action'] ) );
		} elseif ( isset( $_GET['action'] ) ) {
			$action = sanitize_text_field( wp_unslash( $_GET['action'] ) );
		}

		if ( '' === $action ) {
			return;
		}

		if ( 'create_competition' === $action ) {
			check_admin_referer( 'club_competitions_create', 'club_competitions_nonce' );

		$data = array(
			'title'       => wp_unslash( $_POST['competition_title'] ?? '' ),
			'slug'        => wp_unslash( $_POST['competition_slug'] ?? '' ),
			'status'      => wp_unslash( $_POST['competition_status'] ?? 'draft' ),
			'open_date'   => $this->parse_date_input( $_POST['competition_open_date'] ?? '' ),
			'close_date'  => $this->parse_date_input( $_POST['competition_close_date'] ?? '' ),
			'voting_open' => $this->parse_date_input( $_POST['competition_voting_open'] ?? '' ),
			'settings'    => $this->get_global_settings(),
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

			wp_safe_redirect( $this->dashboard_url() );
			exit;
		}

		if ( 'update_competition' === $action ) {
			$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

			check_admin_referer( 'club_competitions_update_' . $competition_id, 'club_competitions_nonce' );

		$data = array(
			'title'       => wp_unslash( $_POST['competition_title'] ?? '' ),
			'slug'        => wp_unslash( $_POST['competition_slug'] ?? '' ),
			'status'      => wp_unslash( $_POST['competition_status'] ?? 'draft' ),
			'open_date'   => $this->parse_date_input( $_POST['competition_open_date'] ?? '' ),
			'close_date'  => $this->parse_date_input( $_POST['competition_close_date'] ?? '' ),
			'voting_open' => $this->parse_date_input( $_POST['competition_voting_open'] ?? '' ),
		);

			$result = $this->competitions->update( $competition_id, $data );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);

				wp_safe_redirect(
					add_query_arg(
						array(
							'page'        => 'club-competitions',
							'action'      => 'edit',
							'competition' => $competition_id,
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			add_settings_error(
				'club_competitions',
				'updated',
				__( 'Competition updated successfully.', 'club-competitions' ),
				'updated'
			);

			wp_safe_redirect( $this->dashboard_url() );
			exit;
		}

		if ( in_array( $action, array( 'archive', 'restore' ), true ) && isset( $_GET['competition'] ) ) {
			$competition_id = absint( $_GET['competition'] );
			$nonce_action   = 'archive' === $action ? 'club_competitions_archive_' : 'club_competitions_restore_';

			check_admin_referer( $nonce_action . $competition_id );

			$result = 'archive' === $action
				? $this->competitions->archive( $competition_id )
				: $this->competitions->restore( $competition_id );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				$message = 'archive' === $action
					? __( 'Competition archived.', 'club-competitions' )
					: __( 'Competition restored.', 'club-competitions' );

				add_settings_error(
					'club_competitions',
					'archive' === $action ? 'archived' : 'restored',
					$message,
					'updated'
				);
			}

			$redirect = 'restore' === $action
				? add_query_arg(
					array(
						'page' => 'club-competitions',
						'view' => 'archived',
					),
					admin_url( 'admin.php' )
				)
				: $this->dashboard_url();

			wp_safe_redirect( $redirect );
			exit;
		}

		if ( 'create_member' === $action ) {
			check_admin_referer( 'club_competitions_member_create', 'club_competitions_member_nonce' );

			$data = array(
				'name'   => wp_unslash( $_POST['member_name'] ?? '' ),
				'email'  => wp_unslash( $_POST['member_email'] ?? '' ),
				'grade'  => wp_unslash( $_POST['member_grade'] ?? '' ),
				'active' => isset( $_POST['member_active'] ) ? 1 : 0,
			);

			$result = $this->members->create( $data );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions_members',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'club_competitions_members',
					'member_created',
					__( 'Member created successfully.', 'club-competitions' ),
					'updated'
				);
			}

			wp_safe_redirect( $this->members_url() );
			exit;
		}

		if ( 'update_member' === $action ) {
			$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;

			check_admin_referer( 'club_competitions_member_update_' . $member_id, 'club_competitions_member_nonce' );

			$data = array(
				'name'   => wp_unslash( $_POST['member_name'] ?? '' ),
				'email'  => wp_unslash( $_POST['member_email'] ?? '' ),
				'grade'  => wp_unslash( $_POST['member_grade'] ?? '' ),
				'active' => isset( $_POST['member_active'] ) ? 1 : 0,
			);

			$result = $this->members->update( $member_id, $data );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions_members',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);

				wp_safe_redirect(
					add_query_arg(
						array(
							'page'          => 'club-competitions-members',
							'member_action' => 'edit',
							'member'        => $member_id,
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			add_settings_error(
				'club_competitions_members',
				'member_updated',
				__( 'Member updated successfully.', 'club-competitions' ),
				'updated'
			);

			wp_safe_redirect( $this->members_url() );
			exit;
		}

		if ( 'update_global_settings' === $action ) {
			check_admin_referer( 'club_competitions_global_settings', 'club_competitions_nonce' );

			$categories = isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ? $_POST['categories'] : array();
			$grades     = isset( $_POST['grades'] ) && is_array( $_POST['grades'] ) ? $_POST['grades'] : array();

			$sanitized_categories = array();
			foreach ( $categories as $category ) {
				if ( ! isset( $category['label'], $category['slug'], $category['quota'] ) ) {
					continue;
				}

				$sanitized_categories[] = array(
					'label' => sanitize_text_field( wp_unslash( $category['label'] ) ),
					'slug'  => sanitize_title( wp_unslash( $category['slug'] ) ),
					'quota' => absint( $category['quota'] ),
				);
			}

			$sanitized_grades = array();
			foreach ( $grades as $grade ) {
				if ( ! isset( $grade['label'], $grade['slug'] ) ) {
					continue;
				}

				$sanitized_grades[] = array(
					'label' => sanitize_text_field( wp_unslash( $grade['label'] ) ),
					'slug'  => sanitize_title( wp_unslash( $grade['slug'] ) ),
				);
			}

			$score_matrix_raw = isset( $_POST['score_matrix'] ) ? sanitize_text_field( wp_unslash( $_POST['score_matrix'] ) ) : '';
			$score_matrix     = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $score_matrix_raw ) ), 'is_numeric' ) );

			if ( empty( $score_matrix ) ) {
				$score_matrix = array( 9, 8, 7, 6, 5 );
			}

			$settings = array(
				'categories'      => $sanitized_categories,
				'grades'          => $sanitized_grades,
				'upload'          => array(
					'max_file_size_mb' => isset( $_POST['max_file_size_mb'] ) ? absint( $_POST['max_file_size_mb'] ) : 5,
					'max_width'        => isset( $_POST['max_width'] ) ? absint( $_POST['max_width'] ) : 1920,
					'max_height'       => isset( $_POST['max_height'] ) ? absint( $_POST['max_height'] ) : 1920,
					'allowed_formats'  => array( 'jpg', 'jpeg' ),
				),
				'voting'          => array(
					'score_matrix' => $score_matrix,
					'auto_open'    => isset( $_POST['auto_open_voting'] ),
				),
				'slideshow'       => array(
					'duration_seconds' => 10,
				),
				'email_reminders' => array(
					'enabled'                => true,
					'days_before_open'       => 7,
					'days_before_close'      => 1,
					'include_qr_code_voting' => true,
				),
			);

			$validation = CompetitionSettings::validate( $settings );

			if ( is_wp_error( $validation ) ) {
				add_settings_error(
					'club_competitions_settings',
					$validation->get_error_code(),
					$validation->get_error_message(),
					'error'
				);
			} else {
				$this->save_global_settings( $settings );

				add_settings_error(
					'club_competitions_settings',
					'settings_saved',
					__( 'Default settings saved successfully.', 'club-competitions' ),
					'updated'
				);
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'club-competitions-settings',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'update_competition_settings' === $action ) {
			$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

			check_admin_referer( 'club_competitions_update_settings_' . $competition_id, 'club_competitions_nonce' );

			$categories = isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ? $_POST['categories'] : array();
			$grades     = isset( $_POST['grades'] ) && is_array( $_POST['grades'] ) ? $_POST['grades'] : array();

			$sanitized_categories = array();
			foreach ( $categories as $category ) {
				if ( ! isset( $category['label'], $category['slug'], $category['quota'] ) ) {
					continue;
				}

				$sanitized_categories[] = array(
					'label' => sanitize_text_field( wp_unslash( $category['label'] ) ),
					'slug'  => sanitize_title( wp_unslash( $category['slug'] ) ),
					'quota' => absint( $category['quota'] ),
				);
			}

			$sanitized_grades = array();
			foreach ( $grades as $grade ) {
				if ( ! isset( $grade['label'], $grade['slug'] ) ) {
					continue;
				}

				$sanitized_grades[] = array(
					'label' => sanitize_text_field( wp_unslash( $grade['label'] ) ),
					'slug'  => sanitize_title( wp_unslash( $grade['slug'] ) ),
				);
			}

			$score_matrix_raw = isset( $_POST['score_matrix'] ) ? sanitize_text_field( wp_unslash( $_POST['score_matrix'] ) ) : '';
			$score_matrix     = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $score_matrix_raw ) ), 'is_numeric' ) );

			if ( empty( $score_matrix ) ) {
				$score_matrix = array( 9, 8, 7, 6, 5 );
			}

			$settings = array(
				'categories'      => $sanitized_categories,
				'grades'          => $sanitized_grades,
				'upload'          => array(
					'max_file_size_mb' => isset( $_POST['max_file_size_mb'] ) ? absint( $_POST['max_file_size_mb'] ) : 5,
					'max_width'        => isset( $_POST['max_width'] ) ? absint( $_POST['max_width'] ) : 1920,
					'max_height'       => isset( $_POST['max_height'] ) ? absint( $_POST['max_height'] ) : 1920,
					'allowed_formats'  => array( 'jpg', 'jpeg' ),
				),
				'voting'          => array(
					'score_matrix' => $score_matrix,
					'auto_open'    => isset( $_POST['auto_open_voting'] ),
				),
				'slideshow'       => array(
					'duration_seconds' => 10,
				),
				'email_reminders' => array(
					'enabled'                => true,
					'days_before_open'       => 7,
					'days_before_close'      => 1,
					'include_qr_code_voting' => true,
				),
			);

			$validation = CompetitionSettings::validate( $settings );

			if ( is_wp_error( $validation ) ) {
				add_settings_error(
					'club_competitions',
					$validation->get_error_code(),
					$validation->get_error_message(),
					'error'
				);

				wp_safe_redirect(
					add_query_arg(
						array(
							'page'        => 'club-competitions',
							'action'      => 'edit',
							'competition' => $competition_id,
							'tab'         => 'settings',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$result = $this->competitions->update(
				$competition_id,
				array(
					'settings' => $settings,
				)
			);

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
					'settings_updated',
					__( 'Competition settings updated successfully.', 'club-competitions' ),
					'updated'
				);
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'        => 'club-competitions',
						'action'      => 'edit',
						'competition' => $competition_id,
						'tab'         => 'settings',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Render the edit competition screen.
	 *
	 * @param int $competition_id Competition ID.
	 * @return void
	 */
	private function render_edit_screen( int $competition_id ): void {
		settings_errors( 'club_competitions' );

		$competition = $this->competitions->find( $competition_id );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Edit Competition', 'club-competitions' ) . '</h1>';

		if ( ! $competition ) {
			echo '<p>' . esc_html__( 'Competition not found. Return to the list and try again.', 'club-competitions' ) . '</p>';
			printf(
				'<a class="button" href="%s">%s</a>',
				esc_url( $this->dashboard_url() ),
				esc_html__( 'Back to competitions', 'club-competitions' )
			);
			echo '</div>';
			return;
		}

		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

		$this->render_competition_tabs( $competition_id, $current_tab );

		if ( 'settings' === $current_tab ) {
			$this->render_competition_settings_form( $competition );
		} else {
			$this->render_competition_general_form( $competition );
		}

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( $this->dashboard_url() ),
			esc_html__( 'Back to competitions', 'club-competitions' )
		);

		echo '</div>';
	}

	/**
	 * Render tabs for competition edit screen.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $current_tab    Current active tab.
	 * @return void
	 */
	private function render_competition_tabs( int $competition_id, string $current_tab ): void {
		$tabs = array(
			'general'  => __( 'General', 'club-competitions' ),
			'settings' => __( 'Settings', 'club-competitions' ),
		);

		echo '<h2 class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page'        => 'club-competitions',
					'action'      => 'edit',
					'competition' => $competition_id,
					'tab'         => $slug,
				),
				admin_url( 'admin.php' )
			);

			$active_class = $slug === $current_tab ? 'nav-tab-active' : '';

			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( $url ),
				esc_attr( $active_class ),
				esc_html( $label )
			);
		}

		echo '</h2>';
	}

	/**
	 * Render general competition form.
	 *
	 * @param object $competition Competition data.
	 * @return void
	 */
	private function render_competition_general_form( object $competition ): void {
		echo '<form method="post" class="card" style="max-width: 720px; padding: 16px;">';
		wp_nonce_field( 'club_competitions_update_' . (int) $competition->id, 'club_competitions_nonce' );
		echo '<input type="hidden" name="club_competitions_action" value="update_competition" />';
		echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition->id ) . '" />';

		echo '<p>';
		echo '<label for="competition_title">' . esc_html__( 'Title', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="competition_title" name="competition_title" class="regular-text" required value="' . esc_attr( $competition->title ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_slug">' . esc_html__( 'Slug', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="competition_slug" name="competition_slug" class="regular-text" value="' . esc_attr( $competition->slug ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_status">' . esc_html__( 'Status', 'club-competitions' ) . '</label><br />';
		echo '<select id="competition_status" name="competition_status">';

		$statuses = array(
			'draft'     => __( 'Draft', 'club-competitions' ),
			'scheduled' => __( 'Scheduled', 'club-competitions' ),
			'active'    => __( 'Active', 'club-competitions' ),
			'closed'    => __( 'Closed', 'club-competitions' ),
		);

		foreach ( $statuses as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $competition->status, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		echo '</p>';

		echo '<p>';
		$label_format = $this->get_ui_date_label();

		echo '<p>';
		echo '<label for="competition_open_date">' . esc_html__( 'Open Date', 'club-competitions' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_open_date" name="competition_open_date" value="' . esc_attr( $this->format_date_for_input( $competition->open_date ) ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_close_date">' . esc_html__( 'Close Date', 'club-competitions' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_close_date" name="competition_close_date" value="' . esc_attr( $this->format_date_for_input( $competition->close_date ) ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_voting_open">' . esc_html__( 'Voting Opens', 'club-competitions' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_voting_open" name="competition_voting_open" value="' . esc_attr( $this->format_date_for_input( $competition->voting_open ) ) . '" />';
		echo '</p>';

		submit_button( __( 'Update Competition', 'club-competitions' ) );

		echo '</form>';
	}

	/**
	 * Render competition settings form.
	 *
	 * @param object $competition Competition data.
	 * @return void
	 */
	private function render_competition_settings_form( object $competition ): void {
		$settings   = CompetitionSettings::parse( $competition->settings );
		$categories = CompetitionSettings::get_categories( $settings );
		$grades     = CompetitionSettings::get_grades( $settings );
		$upload     = CompetitionSettings::get_upload_constraints( $settings );
		$voting     = CompetitionSettings::get_voting_config( $settings );

		echo '<form method="post" class="card" style="max-width: 720px; padding: 16px;">';
		wp_nonce_field( 'club_competitions_update_settings_' . (int) $competition->id, 'club_competitions_nonce' );
		echo '<input type="hidden" name="club_competitions_action" value="update_competition_settings" />';
		echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition->id ) . '" />';

		echo '<h3>' . esc_html__( 'Categories', 'club-competitions' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Define competition categories and upload quotas. Members can upload up to the specified number of images per category.', 'club-competitions' ) . '</p>';

		echo '<div id="categories-container">';
		foreach ( $categories as $index => $category ) {
			$this->render_category_field( $index, $category );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-category" class="button">' . esc_html__( 'Add Category', 'club-competitions' ) . '</button>';
		echo '</p>';

		echo '<h3>' . esc_html__( 'Grades', 'club-competitions' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Define member grade levels for results grouping.', 'club-competitions' ) . '</p>';

		echo '<div id="grades-container">';
		foreach ( $grades as $index => $grade ) {
			$this->render_grade_field( $index, $grade );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-grade" class="button">' . esc_html__( 'Add Grade', 'club-competitions' ) . '</button>';
		echo '</p>';

		echo '<h3>' . esc_html__( 'Upload Constraints', 'club-competitions' ) . '</h3>';

		echo '<p>';
		echo '<label for="max_file_size_mb">' . esc_html__( 'Max File Size (MB)', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" id="max_file_size_mb" name="max_file_size_mb" min="1" max="50" value="' . esc_attr( $upload['max_file_size_mb'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_width">' . esc_html__( 'Max Width (pixels)', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" id="max_width" name="max_width" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_width'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_height">' . esc_html__( 'Max Height (pixels)', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" id="max_height" name="max_height" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_height'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<h3>' . esc_html__( 'Voting Configuration', 'club-competitions' ) . '</h3>';

		echo '<p>';
		echo '<label for="score_matrix">' . esc_html__( 'Score Matrix (comma-separated)', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="score_matrix" name="score_matrix" value="' . esc_attr( implode( ', ', $voting['score_matrix'] ) ) . '" class="regular-text" />';
		echo '<span class="description">' . esc_html__( 'E.g., 9, 8, 7, 6, 5', 'club-competitions' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="auto_open_voting" value="1"' . checked( $voting['auto_open'], true, false ) . ' /> ';
		echo esc_html__( 'Automatically open voting at scheduled time', 'club-competitions' );
		echo '</label>';
		echo '</p>';

		submit_button( __( 'Save Settings', 'club-competitions' ) );

		echo '</form>';

		$this->render_settings_javascript();
	}

	/**
	 * Render category field row.
	 *
	 * @param int   $index    Category index.
	 * @param array $category Category data.
	 * @return void
	 */
	private function render_category_field( int $index, array $category ): void {
		echo '<div class="category-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Label', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" name="categories[' . esc_attr( $index ) . '][label]" value="' . esc_attr( $category['label'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Slug', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" name="categories[' . esc_attr( $index ) . '][slug]" value="' . esc_attr( $category['slug'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Upload Quota', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" name="categories[' . esc_attr( $index ) . '][quota]" value="' . esc_attr( $category['quota'] ) . '" min="1" max="10" class="small-text" required />';
		echo '</p>';

		echo '<button type="button" class="button remove-category" style="color: #b32d2e;">' . esc_html__( 'Remove', 'club-competitions' ) . '</button>';

		echo '</div>';
	}

	/**
	 * Render grade field row.
	 *
	 * @param int   $index Grade index.
	 * @param array $grade Grade data.
	 * @return void
	 */
	private function render_grade_field( int $index, array $grade ): void {
		echo '<div class="grade-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Label', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" name="grades[' . esc_attr( $index ) . '][label]" value="' . esc_attr( $grade['label'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Slug', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" name="grades[' . esc_attr( $index ) . '][slug]" value="' . esc_attr( $grade['slug'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<button type="button" class="button remove-grade" style="color: #b32d2e;">' . esc_html__( 'Remove', 'club-competitions' ) . '</button>';

		echo '</div>';
	}

	/**
	 * Render JavaScript for dynamic settings fields.
	 *
	 * @return void
	 */
	private function render_settings_javascript(): void {
		?>
		<script>
		(function() {
			let categoryIndex = document.querySelectorAll('.category-row').length;
			let gradeIndex = document.querySelectorAll('.grade-row').length;

			document.getElementById('add-category')?.addEventListener('click', function() {
				const container = document.getElementById('categories-container');
				const row = document.createElement('div');
				row.className = 'category-row';
				row.style.cssText = 'margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;';
				row.innerHTML = `
					<p style="margin: 5px 0;">
						<label><?php echo esc_js( __( 'Label', 'club-competitions' ) ); ?></label><br />
						<input type="text" name="categories[${categoryIndex}][label]" class="regular-text" required />
					</p>
					<p style="margin: 5px 0;">
						<label><?php echo esc_js( __( 'Slug', 'club-competitions' ) ); ?></label><br />
						<input type="text" name="categories[${categoryIndex}][slug]" class="regular-text" required />
					</p>
					<p style="margin: 5px 0;">
						<label><?php echo esc_js( __( 'Upload Quota', 'club-competitions' ) ); ?></label><br />
						<input type="number" name="categories[${categoryIndex}][quota]" value="1" min="1" max="10" class="small-text" required />
					</p>
					<button type="button" class="button remove-category" style="color: #b32d2e;"><?php echo esc_js( __( 'Remove', 'club-competitions' ) ); ?></button>
				`;
				container.appendChild(row);
				categoryIndex++;
			});

			document.getElementById('add-grade')?.addEventListener('click', function() {
				const container = document.getElementById('grades-container');
				const row = document.createElement('div');
				row.className = 'grade-row';
				row.style.cssText = 'margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;';
				row.innerHTML = `
					<p style="margin: 5px 0;">
						<label><?php echo esc_js( __( 'Label', 'club-competitions' ) ); ?></label><br />
						<input type="text" name="grades[${gradeIndex}][label]" class="regular-text" required />
					</p>
					<p style="margin: 5px 0;">
						<label><?php echo esc_js( __( 'Slug', 'club-competitions' ) ); ?></label><br />
						<input type="text" name="grades[${gradeIndex}][slug]" class="regular-text" required />
					</p>
					<button type="button" class="button remove-grade" style="color: #b32d2e;"><?php echo esc_js( __( 'Remove', 'club-competitions' ) ); ?></button>
				`;
				container.appendChild(row);
				gradeIndex++;
			});

			document.addEventListener('click', function(e) {
				if (e.target.classList.contains('remove-category')) {
					e.target.closest('.category-row').remove();
				}
				if (e.target.classList.contains('remove-grade')) {
					e.target.closest('.grade-row').remove();
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render competitions list table.
	 *
	 * @param array<int, object> $competitions Competitions.
	 * @param string             $view         Current view.
	 * @return void
	 */
	private function render_competition_table( array $competitions, string $view ): void {
		echo '<h2 class="screen-reader-text">' . esc_html__( 'Competition List', 'club-competitions' ) . '</h2>';

		$total_active   = $this->competitions->count( false );
		$total_archived = $this->competitions->count( true );

		echo '<ul class="subsubsub">';
		$views = array(
			'active'   => array(
				'label' => __( 'Active', 'club-competitions' ),
				'count' => $total_active,
			),
			'archived' => array(
				'label' => __( 'Archived', 'club-competitions' ),
				'count' => max( 0, $total_archived ),
			),
		);

		$index = 0;
		foreach ( $views as $slug => $data ) {
			$url = esc_url(
				add_query_arg(
					array(
						'page' => 'club-competitions',
						'view' => $slug,
					),
					admin_url( 'admin.php' )
				)
			);

			echo '<li><a href="' . $url . '"' . ( $slug === $view ? ' class="current"' : '' ) . '>' . esc_html( $data['label'] ) . ' <span class="count">(' . esc_html( (string) $data['count'] ) . ')</span></a>';
			if ( ++$index < count( $views ) ) {
				echo ' | ';
			}
			echo '</li>';
		}
		echo '</ul>';

		if ( empty( $competitions ) ) {
			echo '<p>' . esc_html__( 'No competitions found for this view.', 'club-competitions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Opens', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Closes', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Last Updated', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'club-competitions' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $competitions as $competition ) {
			$is_archived = ! empty( $competition->deleted_at );

			$edit_link = esc_url(
				add_query_arg(
					array(
						'page'        => 'club-competitions',
						'action'      => 'edit',
						'competition' => (int) $competition->id,
					),
					admin_url( 'admin.php' )
				)
			);

			echo '<tr>';
			echo '<td>' . esc_html( $competition->title ) . '</td>';
			echo '<td>' . esc_html( $competition->status ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $competition->open_date ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $competition->close_date ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $competition->updated_at ?: $competition->created_at ) ) . '</td>';

			$actions = array(
				sprintf( '<a href="%s">%s</a>', $edit_link, esc_html__( 'Edit', 'club-competitions' ) ),
			);

			if ( $is_archived ) {
				$restore_url = esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'page'        => 'club-competitions',
								'action'      => 'restore',
								'competition' => (int) $competition->id,
							),
							admin_url( 'admin.php' )
						),
						'club_competitions_restore_' . (int) $competition->id
					)
				);

				$actions[] = sprintf( '<a href="%s">%s</a>', $restore_url, esc_html__( 'Restore', 'club-competitions' ) );
			} else {
				$archive_url = esc_url(
					wp_nonce_url(
						add_query_arg(
							array(
								'page'        => 'club-competitions',
								'action'      => 'archive',
								'competition' => (int) $competition->id,
							),
							admin_url( 'admin.php' )
						),
						'club_competitions_archive_' . (int) $competition->id
					)
				);

				$actions[] = sprintf( '<a href="%s" class="submitdelete">%s</a>', $archive_url, esc_html__( 'Archive', 'club-competitions' ) );
			}

			echo '<td>' . implode( ' | ', $actions ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}
	/**
	 * Format stored datetime for date input.
	 *
	 * @param string|null $datetime Stored datetime.
	 * @return string
	 */
	private function format_date_for_input( ?string $datetime ): string {
		if ( empty( $datetime ) ) {
			return '';
		}

		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Format short date display.
	 *
	 * @param string|null $datetime Value.
	 * @return string
	 */
	private function format_date_for_display( ?string $datetime ): string {
		if ( empty( $datetime ) ) {
			return '';
		}

		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return '';
		}

		return wp_date( $this->get_display_date_format(), $timestamp );
	}

	/**
	 * Format datetime for display using site locale.
	 *
	 * @param string|null $datetime Datetime value.
	 * @return string
	 */
	private function format_datetime( ?string $datetime ): string {
		if ( empty( $datetime ) ) {
			return '—';
		}

		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return '—';
		}

		$date_format = $this->get_display_date_format();
		$time_format = get_option( 'time_format' );
		$format      = $date_format . ( ! empty( $time_format ) ? ' ' . $time_format : '' );

		return wp_date( $format, $timestamp );
	}

	/**
	 * Determine the date format to display, accounting for locale defaults.
	 *
	 * @return string
	 */
	private function get_display_date_format(): string {
		$locale = get_locale();

		if ( in_array( $locale, array( 'en_GB', 'en_AU', 'en_NZ', 'en_IE', 'en_ZA' ), true ) ) {
			return 'd/m/Y';
		}

		$format = get_option( 'date_format' );

		if ( empty( $format ) ) {
			$format = 'F j, Y';
		}

		return $format;
	}

	/**
	 * UI label format (human readable).
	 *
	 * @return string
	 */
	private function get_ui_date_label(): string {
		$locale = get_locale();

		if ( in_array( $locale, array( 'en_GB', 'en_AU', 'en_NZ', 'en_IE', 'en_ZA' ), true ) ) {
			return 'dd/mm/yyyy';
		}

		return 'yyyy-mm-dd';
	}

	/**
	 * Parse user input to normalized Y-m-d format.
	 *
	 * @param string $raw Raw input.
	 * @return string|null
	 */
	private function parse_date_input( string $raw ): ?string {
		$raw = trim( wp_unslash( $raw ) );

		if ( '' === $raw ) {
			return null;
		}

		$tz = wp_timezone();

		$dt = \DateTime::createFromFormat( 'Y-m-d', $raw, $tz );

		if ( $dt instanceof \DateTimeInterface ) {
			return $dt->format( 'Y-m-d' );
		}

		$format = $this->get_display_date_format();
		$dt     = \DateTime::createFromFormat( $format, $raw, $tz );

		if ( $dt instanceof \DateTimeInterface ) {
			return gmdate( 'Y-m-d', $dt->getTimestamp() );
		}

		$timestamp = strtotime( $raw );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Render the create competition form.
	 *
	 * @return void
	 */
	private function render_create_form(): void {
		$date_placeholder = $this->get_display_date_format();

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
		$label_format = $this->get_ui_date_label();

		echo '<label for="competition_open_date">' . esc_html__( 'Open Date', 'club-competitions' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_open_date" name="competition_open_date" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_close_date">' . esc_html__( 'Close Date', 'club-competitions' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_close_date" name="competition_close_date" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_voting_open">' . esc_html__( 'Voting Opens', 'club-competitions' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_voting_open" name="competition_voting_open" />';
		echo '</p>';

		submit_button( __( 'Create Competition', 'club-competitions' ) );

		echo '</form>';
	}

	/**
	 * Render create member form.
	 *
	 * @return void
	 */
	private function render_member_create_form(): void {
		echo '<form method="post" class="card" style="max-width: 520px; margin-bottom: 24px; padding: 16px;">';
		echo '<h2>' . esc_html__( 'Add Member', 'club-competitions' ) . '</h2>';

		wp_nonce_field( 'club_competitions_member_create', 'club_competitions_member_nonce' );

		echo '<input type="hidden" name="club_competitions_action" value="create_member" />';

		echo '<p>';
		echo '<label for="member_name">' . esc_html__( 'Name', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="member_name" name="member_name" class="regular-text" required />';
		echo '</p>';

		echo '<p>';
		echo '<label for="member_email">' . esc_html__( 'Email', 'club-competitions' ) . '</label><br />';
		echo '<input type="email" id="member_email" name="member_email" class="regular-text" required />';
		echo '</p>';

		echo '<p>';
		echo '<label for="member_grade">' . esc_html__( 'Grade', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="member_grade" name="member_grade" class="regular-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="member_active" value="1" checked /> ';
		echo esc_html__( 'Active', 'club-competitions' );
		echo '</label>';
		echo '</p>';

		submit_button( __( 'Add Member', 'club-competitions' ) );

		echo '</form>';
	}

	/**
	 * Render edit member form.
	 *
	 * @param object|null $member Member row.
	 * @return void
	 */
	private function render_member_edit_form( $member ): void {
		if ( ! $member ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Member not found. Return to the list to continue.', 'club-competitions' ) . '</p></div>';
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( $this->members_url() ),
				esc_html__( 'Back to members', 'club-competitions' )
			);
			return;
		}

		echo '<form method="post" class="card" style="max-width: 520px; margin-bottom: 24px; padding: 16px;">';
		echo '<h2>' . esc_html__( 'Edit Member', 'club-competitions' ) . '</h2>';

		wp_nonce_field( 'club_competitions_member_update_' . (int) $member->id, 'club_competitions_member_nonce' );

		echo '<input type="hidden" name="club_competitions_action" value="update_member" />';
		echo '<input type="hidden" name="member_id" value="' . esc_attr( $member->id ) . '" />';

		echo '<p>';
		echo '<label for="member_name">' . esc_html__( 'Name', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="member_name" name="member_name" class="regular-text" required value="' . esc_attr( $member->name ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="member_email">' . esc_html__( 'Email', 'club-competitions' ) . '</label><br />';
		echo '<input type="email" id="member_email" name="member_email" class="regular-text" required value="' . esc_attr( $member->email ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="member_grade">' . esc_html__( 'Grade', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="member_grade" name="member_grade" class="regular-text" value="' . esc_attr( $member->grade ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="member_active" value="1"' . checked( (bool) $member->active, true, false ) . ' /> ';
		echo esc_html__( 'Active', 'club-competitions' );
		echo '</label>';
		echo '</p>';

		submit_button( __( 'Update Member', 'club-competitions' ) );

		echo '</form>';

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( $this->members_url() ),
			esc_html__( 'Back to members', 'club-competitions' )
		);
	}

	/**
	 * Dashboard URL.
	 *
	 * @return string
	 */
	private function dashboard_url(): string {
		return add_query_arg(
			array(
				'page' => 'club-competitions',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Members page URL.
	 *
	 * @return string
	 */
	private function members_url(): string {
		return add_query_arg(
			array(
				'page' => 'club-competitions-members',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render global settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'club-competitions' ) );
		}

		settings_errors( 'club_competitions_settings' );

		$settings   = $this->get_global_settings();
		$categories = CompetitionSettings::get_categories( $settings );
		$grades     = CompetitionSettings::get_grades( $settings );
		$upload     = CompetitionSettings::get_upload_constraints( $settings );
		$voting     = CompetitionSettings::get_voting_config( $settings );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Default Competition Settings', 'club-competitions' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'These settings will be used as defaults when creating new competitions. Individual competitions can override these settings.', 'club-competitions' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="card" style="max-width: 720px; padding: 16px; margin-top: 20px;">';
		wp_nonce_field( 'club_competitions_global_settings', 'club_competitions_nonce' );
		echo '<input type="hidden" name="club_competitions_action" value="update_global_settings" />';
		echo '<input type="hidden" name="page" value="club-competitions-settings" />';

		echo '<h2>' . esc_html__( 'Categories', 'club-competitions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Define default categories and upload quotas.', 'club-competitions' ) . '</p>';

		echo '<div id="categories-container">';
		foreach ( $categories as $index => $category ) {
			$this->render_category_field( $index, $category );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-category" class="button">' . esc_html__( 'Add Category', 'club-competitions' ) . '</button>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Grades', 'club-competitions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Define default member grade levels.', 'club-competitions' ) . '</p>';

		echo '<div id="grades-container">';
		foreach ( $grades as $index => $grade ) {
			$this->render_grade_field( $index, $grade );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-grade" class="button">' . esc_html__( 'Add Grade', 'club-competitions' ) . '</button>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Upload Constraints', 'club-competitions' ) . '</h2>';

		echo '<p>';
		echo '<label for="max_file_size_mb">' . esc_html__( 'Max File Size (MB)', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" id="max_file_size_mb" name="max_file_size_mb" min="1" max="50" value="' . esc_attr( $upload['max_file_size_mb'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_width">' . esc_html__( 'Max Width (pixels)', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" id="max_width" name="max_width" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_width'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_height">' . esc_html__( 'Max Height (pixels)', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" id="max_height" name="max_height" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_height'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Voting Configuration', 'club-competitions' ) . '</h2>';

		echo '<p>';
		echo '<label for="score_matrix">' . esc_html__( 'Score Matrix (comma-separated)', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="score_matrix" name="score_matrix" value="' . esc_attr( implode( ', ', $voting['score_matrix'] ) ) . '" class="regular-text" />';
		echo '<span class="description">' . esc_html__( 'E.g., 9, 8, 7, 6, 5', 'club-competitions' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="auto_open_voting" value="1"' . checked( $voting['auto_open'], true, false ) . ' /> ';
		echo esc_html__( 'Automatically open voting at scheduled time', 'club-competitions' );
		echo '</label>';
		echo '</p>';

		submit_button( __( 'Save Default Settings', 'club-competitions' ) );

		echo '</form>';
		echo '</div>';

		$this->render_settings_javascript();
	}

	/**
	 * Get global default settings.
	 *
	 * @return array<string, mixed>
	 */
	private function get_global_settings(): array {
		$saved = get_option( 'club_competitions_default_settings', '' );
		return CompetitionSettings::parse( $saved );
	}

	/**
	 * Save global default settings.
	 *
	 * @param array<string, mixed> $settings Settings to save.
	 * @return void
	 */
	private function save_global_settings( array $settings ): void {
		update_option( 'club_competitions_default_settings', CompetitionSettings::encode( $settings ) );
	}
}
