<?php
/**
 * Members controller for admin interface.
 *
 * @package ClubCompetitions\Admin
 */

namespace ClubCompetitions\Admin;

use ClubCompetitions\Admin\Traits\Date_Formatting;
use ClubCompetitions\Admin\Traits\Form_Rendering;
use ClubCompetitions\Repository\Competitions_Repository;
use ClubCompetitions\Repository\Members_Repository;
use ClubCompetitions\Repository\Upload_Token_Repository;
use ClubCompetitions\Support\Competition_Settings;

/**
 * Manage members page.
 *
 * @since 0.1.0
 */
class Members_Controller {

	use Date_Formatting;
	use Form_Rendering;

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
	 * Constructor.
	 *
	 * @param Competitions_Repository $competitions Competitions repository.
	 * @param Members_Repository      $members      Members repository.
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Members_Repository $members
	) {
		$this->competitions = $competitions;
		$this->members      = $members;
	}

	/**
	 * Register hooks for this controller.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Handle admin post actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			return;
		}

		$action = '';

		if ( isset( $_POST['club_competitions_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['club_competitions_action'] ) );
		} elseif ( isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; mutations require explicit nonce checks below.
			$action = sanitize_key( wp_unslash( $_GET['action'] ) );
		}

		// Per-member "Send Upload Email".
		if ( 'send_member_upload_email' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			$member_id = isset( $_GET['member'] ) ? absint( wp_unslash( $_GET['member'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

			if ( ! $member_id || ! $competition_id ) {
				add_settings_error(
					'club_competitions_members',
					'invalid_params',
					__( 'Invalid member or competition.', 'club-competitions' ),
					'error'
				);
				wp_safe_redirect( $this->members_url() );
				exit;
			}

			check_admin_referer( 'club_competitions_send_member_email_' . $member_id . '_' . $competition_id );

			$competition = $this->competitions->find( $competition_id );
			$member      = $this->members->find( $member_id );

			if ( ! $competition || 'active' !== $competition->status ) {
				add_settings_error(
					'club_competitions_members',
					'invalid_competition',
					__( 'Competition must be active to send upload emails.', 'club-competitions' ),
					'error'
				);
				wp_safe_redirect( $this->members_url() );
				exit;
			}

			if ( ! $member || empty( $member->email ) || ! $member->active ) {
				add_settings_error(
					'club_competitions_members',
					'invalid_member',
					__( 'Member must be active and have an email address.', 'club-competitions' ),
					'error'
				);
				wp_safe_redirect( $this->members_url() );
				exit;
			}

			// Resolve upload page URL from competition settings or shortcode detection, fallback to home.
			$settings        = Competition_Settings::parse( $competition->settings );
			$urls            = $settings['urls'] ?? array();
			$upload_page_url = $urls['upload_page'] ?? '';

			if ( empty( $upload_page_url ) && function_exists( 'get_pages' ) ) {
				$pages = get_pages( array( 'number' => 100 ) );
				if ( is_array( $pages ) ) {
					foreach ( $pages as $page ) {
						if ( ! empty( $page->post_content ) && function_exists( 'has_shortcode' ) && has_shortcode( $page->post_content, 'competition_upload' ) ) {
							$upload_page_url = get_permalink( $page->ID );
							break;
						}
					}
				}
			}

			if ( empty( $upload_page_url ) ) {
				$upload_page_url = home_url( '/' );
			}

			$upload_page_url = apply_filters( 'club_compete_upload_page_url', $upload_page_url, $competition );

			$token_repo = new Upload_Token_Repository();
			$result     = $token_repo->send_upload_link_for_member(
				(int) $competition_id,
				(int) $member_id,
				$upload_page_url,
				true // Send email immediately.
			);

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
					'upload_email_sent',
					sprintf(
					/* translators: 1: member name, 2: competition title */
						__( 'Upload email sent to %1$s for "%2$s".', 'club-competitions' ),
						esc_html( $member->name ),
						esc_html( $competition->title )
					),
					'updated'
				);
			}

			wp_safe_redirect( $this->members_url() );
			exit;
		}

		if ( 'create_member' === $action ) {
			check_admin_referer( 'club_competitions_member_create', 'club_competitions_member_nonce' );

			$name_raw  = $this->get_post_string( 'member_name' );
			$email_raw = $this->get_post_string( 'member_email' );
			$grade_raw = $this->get_post_string( 'member_grade' );
			$is_active = isset( $_POST['member_active'] );
			$name      = sanitize_text_field( $name_raw );
			$email     = sanitize_email( $email_raw );
			$grade     = sanitize_text_field( $grade_raw );

			$data = array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => $grade,
				'active' => $is_active ? 1 : 0,
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
			$member_id = absint( $this->get_post_string( 'member_id' ) );

			check_admin_referer( 'club_competitions_member_update_' . $member_id, 'club_competitions_member_nonce' );

			$name_raw  = $this->get_post_string( 'member_name' );
			$email_raw = $this->get_post_string( 'member_email' );
			$grade_raw = $this->get_post_string( 'member_grade' );
			$is_active = isset( $_POST['member_active'] );
			$name      = sanitize_text_field( $name_raw );
			$email     = sanitize_email( $email_raw );
			$grade     = sanitize_text_field( $grade_raw );

			$data = array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => $grade,
				'active' => $is_active ? 1 : 0,
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
	}

	/**
	 * Render members list.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'club-competitions' ) );
		}

		settings_errors( 'club_competitions_members' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view.
		$member_action = isset( $_GET['member_action'] ) ? sanitize_text_field( wp_unslash( $_GET['member_action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view.
		$member_id = isset( $_GET['member'] ) ? absint( wp_unslash( $_GET['member'] ) ) : 0;
		$current   = null;

		if ( 'edit' === $member_action && $member_id ) {
			$current = $this->members->find( $member_id );
		}

		$members = $this->members->all( false );

		// Find currently active competition for per-member email action.
		$active_competition = $this->competitions->find_current_active();

		// Get grade options for label lookups.
		$grade_options = $this->get_grade_options();

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
			$edit_link    = add_query_arg(
				array(
					'page'          => 'club-competitions-members',
					'member_action' => 'edit',
					'member'        => (int) $member->id,
				),
				admin_url( 'admin.php' )
			);
			$status_label = $member->active ? __( 'Active', 'club-competitions' ) : __( 'Inactive', 'club-competitions' );
			$grade_label  = $grade_options[ $member->grade ] ?? $member->grade;

			echo '<tr>';
			echo '<td>' . esc_html( $member->name ) . '</td>';
			echo '<td>' . esc_html( $member->email ) . '</td>';
			echo '<td>' . esc_html( $grade_label ) . '</td>';
			echo '<td>' . esc_html( $status_label ) . '</td>';
			echo '<td>' . esc_html( $member->created_at ) . '</td>';

			$actions = array(
				sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit', 'club-competitions' ) ),
			);

			// Add "Send Upload Email" if we have an active competition and active member with email.
			if ( $active_competition && 'active' === $active_competition->status && $member->active && ! empty( $member->email ) ) {
				$send_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'club-competitions-members',
							'action'      => 'send_member_upload_email',
							'member'      => (int) $member->id,
							'competition' => (int) $active_competition->id,
						),
						admin_url( 'admin.php' )
					),
					'club_competitions_send_member_email_' . (int) $member->id . '_' . (int) $active_competition->id
				);

				$actions[] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( $send_url ),
					esc_html__( 'Send Upload Email', 'club-competitions' )
				);

				// Add upload page link for copying/sharing.
				$upload_url = $this->get_member_upload_url( (int) $member->id, $active_competition );
				if ( ! empty( $upload_url ) ) {
					$actions[] = sprintf(
						'<a href="%s" target="_blank" title="%s">%s</a>',
						esc_url( $upload_url ),
						esc_attr__( 'Copy this link to share with the member', 'club-competitions' ),
						esc_html__( 'Upload Link', 'club-competitions' )
					);
				}
			} else {
				$actions[] = '<span class="button button-small" style="opacity:.5;cursor:not-allowed;" title="' . esc_attr__( 'Requires an active competition and active member with email', 'club-competitions' ) . '">' . esc_html__( 'Send Upload Email', 'club-competitions' ) . '</span>';
			}

			echo '<td>' . wp_kses_post( implode( ' | ', $actions ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
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
		echo '<select id="member_grade" name="member_grade" class="regular-text" required>';
		echo '<option value="">' . esc_html__( 'Select grade', 'club-competitions' ) . '</option>';
		foreach ( $this->get_grade_options() as $grade_slug => $grade_label ) {
			echo '<option value="' . esc_attr( $grade_slug ) . '">' . esc_html( $grade_label ) . '</option>';
		}
		echo '</select>';
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
	 * @param  object|null $member Member row.
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
		echo '<select id="member_grade" name="member_grade" class="regular-text" required>';
		foreach ( $this->get_grade_options() as $grade_slug => $grade_label ) {
			echo '<option value="' . esc_attr( $grade_slug ) . '"' . selected( $member->grade, $grade_slug, false ) . '>' . esc_html( $grade_label ) . '</option>';
		}
		echo '</select>';
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
	 * Retrieve grade options from default settings.
	 *
	 * @return array<string, string>
	 */
	private function get_grade_options(): array {
		$settings = $this->get_global_settings();
		$grades   = Competition_Settings::get_grades( $settings );

		$options = array();
		foreach ( $grades as $grade ) {
			if ( isset( $grade['label'] ) ) {
				$options[ $grade['slug'] ?? sanitize_title( $grade['label'] ) ] = $grade['label'];
			}
		}

		return $options;
	}

	/**
	 * Generate the upload URL for a member for a specific competition.
	 *
	 * Retrieves the upload page URL from competition settings or auto-discovers it,
	 * then generates a tokenized URL that can be shared with the member.
	 *
	 * @param  int    $member_id   Member ID.
	 * @param  object $competition Competition object.
	 * @return string Upload URL with token, or empty string if URL cannot be determined.
	 */
	private function get_member_upload_url( int $member_id, object $competition ): string {
		// Resolve upload page URL from competition settings or shortcode detection, fallback to home.
		$settings        = Competition_Settings::parse( $competition->settings );
		$urls            = $settings['urls'] ?? array();
		$upload_page_url = $urls['upload_page'] ?? '';

		if ( empty( $upload_page_url ) && function_exists( 'get_pages' ) ) {
			$pages = get_pages( array( 'number' => 100 ) );
			if ( is_array( $pages ) ) {
				foreach ( $pages as $page ) {
					if ( ! empty( $page->post_content ) && function_exists( 'has_shortcode' ) && has_shortcode( $page->post_content, 'competition_upload' ) ) {
						$upload_page_url = get_permalink( $page->ID );
						break;
					}
				}
			}
		}

		if ( empty( $upload_page_url ) ) {
			return '';
		}

		$upload_page_url = apply_filters( 'club_compete_upload_page_url', $upload_page_url, $competition );

		// Use the repository to generate the upload URL with a fresh token.
		$token_repo = new Upload_Token_Repository();
		$upload_url = $token_repo->generate_upload_url( (int) $competition->id, $member_id, $upload_page_url );

		// Return empty string if there was an error.
		if ( is_wp_error( $upload_url ) ) {
			return '';
		}

		return $upload_url;
	}

	/**
	 * Get global default settings.
	 *
	 * @return array<string, mixed>
	 */
	private function get_global_settings(): array {
		$saved = get_option( 'club_competitions_default_settings', '' );
		return Competition_Settings::parse( $saved );
	}
}
