<?php
/**
 * Competitions controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Date_Formatting;
use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Service\Upload_Link_Service;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Manage competitions dashboard and CRUD operations.
 *
 * @since 0.1.0
 */
class Competitions_Controller {

	use Date_Formatting;
	use Form_Rendering;

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository $competitions Competitions repository.
	 */
	public function __construct( Competitions_Repository $competitions ) {
		$this->competitions = $competitions;
	}

	/**
	 * Register hooks for this controller.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue inline scripts for competitions page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_photo-competition-manager' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'photo-comp-admin-category-grade',
			PHOTO_COMPETITION_MANAGER_URL . 'assets/js/admin-category-grade.js',
			array(),
			PHOTO_COMPETITION_MANAGER_VERSION,
			true
		);

		wp_localize_script(
			'photo-comp-admin-category-grade',
			'photoCompCategoryGrade',
			array(
				'labelText'       => __( 'Label', 'photo-competition-manager' ),
				'slugText'        => __( 'Slug', 'photo-competition-manager' ),
				'uploadQuotaText' => __( 'Upload Quota', 'photo-competition-manager' ),
				'removeText'      => __( 'Remove', 'photo-competition-manager' ),
			)
		);

		wp_enqueue_script(
			'photo-comp-admin-confirm',
			PHOTO_COMPETITION_MANAGER_URL . 'assets/js/admin-confirm.js',
			array(),
			PHOTO_COMPETITION_MANAGER_VERSION,
			true
		);
	}

	/**
	 * Handle admin post actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			return;
		}

		$action = '';

		if ( isset( $_POST['photo_competition_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['photo_competition_action'] ) );
		} elseif ( isset( $_POST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; mutations require explicit nonce checks below.
			$action = sanitize_key( wp_unslash( $_POST['action'] ) );
		} elseif ( isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; mutations require explicit nonce checks below.
			$action = sanitize_key( wp_unslash( $_GET['action'] ) );
		}

		if ( '' === $action ) {
			return;
		}

		if ( 'create_competition' === $action ) {
			check_admin_referer( 'photo_competition_create', 'photo_competition_nonce' );

			$title_raw      = $this->get_post_string( 'competition_title' );
			$slug_raw       = $this->get_post_string( 'competition_slug' );
			$open_date_raw  = $this->get_post_string( 'competition_open_date' );
			$close_date_raw = $this->get_post_string( 'competition_close_date' );

			$title = sanitize_text_field( $title_raw );
			$slug  = sanitize_title( $slug_raw );

			$data = array(
				'title'      => $title,
				'slug'       => $slug,
				'open_date'  => $this->parse_date_input( $open_date_raw ),
				'close_date' => $this->parse_date_input( $close_date_raw ),
				'settings'   => Competition_Settings::global_settings(),
				'share_hash' => Competition_Settings::generate_share_hash(),
			);

			$result = $this->competitions->create( $data );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_manager',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'photo_competition_manager',
					'created',
					__( 'Competition created successfully.', 'photo-competition-manager' ),
					'updated'
				);
			}

			$this->redirect_with_settings_errors( $this->dashboard_url() );
		}

		if ( 'update_competition' === $action ) {
			$competition_id = absint( $this->get_post_string( 'competition_id' ) );

			check_admin_referer( 'photo_competition_update_' . $competition_id, 'photo_competition_nonce' );

			$title_raw      = $this->get_post_string( 'competition_title' );
			$slug_raw       = $this->get_post_string( 'competition_slug' );
			$open_date_raw  = $this->get_post_string( 'competition_open_date' );
			$close_date_raw = $this->get_post_string( 'competition_close_date' );

			$title = sanitize_text_field( $title_raw );
			$slug  = sanitize_title( $slug_raw );

			$data = array(
				'title'      => $title,
				'slug'       => $slug,
				'open_date'  => $this->parse_date_input( $open_date_raw ),
				'close_date' => $this->parse_date_input( $close_date_raw ),
			);

			$result = $this->competitions->update( $competition_id, $data );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_manager',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);

				$this->redirect_with_settings_errors(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'edit',
							'competition' => $competition_id,
						),
						admin_url( 'admin.php' )
					)
				);
			}

			add_settings_error(
				'photo_competition_manager',
				'updated',
				__( 'Competition updated successfully.', 'photo-competition-manager' ),
				'updated'
			);

			$this->redirect_with_settings_errors( $this->dashboard_url() );
		}

		if ( 'generate_results_link' === $action && isset( $_GET['competition'] ) ) {
			$competition_id = absint( wp_unslash( $_GET['competition'] ) );

			check_admin_referer( 'photo_competition_generate_results_link_' . $competition_id );

			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'photo_competition_manager',
					'competition_not_found',
					__( 'Competition not found.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $this->dashboard_url() );
				return;
			}

			$new_hash = Competition_Settings::generate_share_hash();
			$result   = $this->competitions->update_share_hash( $competition_id, $new_hash );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_manager',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				$settings         = Competition_Settings::parse( $competition->settings );
				$results_page_url = $settings['urls']['results_page'] ?? '';
				if ( ! empty( $results_page_url ) ) {
					$share_url = add_query_arg( 'share', $new_hash, $results_page_url );
					add_settings_error(
						'photo_competition_manager',
						'results_link_generated',
						sprintf(
							/* translators: %1$s: share URL href, %2$s: share URL display text */
							__( 'Results share link generated: <a href="%1$s" target="_blank">%2$s</a>', 'photo-competition-manager' ),
							esc_url( $share_url ),
							esc_html( $share_url )
						),
						'updated'
					);
				} else {
					add_settings_error(
						'photo_competition_manager',
						'results_link_generated',
						__( 'Results share hash generated. Configure a results page URL to get a shareable link.', 'photo-competition-manager' ),
						'updated'
					);
				}
			}

			$this->redirect_with_settings_errors( $this->dashboard_url() );
			return;
		}

		if ( in_array( $action, array( 'archive', 'restore', 'send_emails', 'delete', 'reset_votes' ), true ) && isset( $_GET['competition'] ) ) {
			$competition_id = absint( wp_unslash( $_GET['competition'] ) );
			$nonces         = array(
				'send_emails' => 'photo_competition_send_emails_',
				'archive'     => 'photo_competition_archive_',
				'restore'     => 'photo_competition_restore_',
				'delete'      => 'photo_competition_delete_',
				'reset_votes' => 'photo_competition_reset_votes_',
			);
			$nonce_action   = $nonces[ $action ];

			check_admin_referer( $nonce_action . $competition_id );

			if ( 'delete' === $action ) {
				$result = $this->competitions->delete( $competition_id );

				if ( is_wp_error( $result ) ) {
					add_settings_error(
						'photo_competition_manager',
						$result->get_error_code(),
						$result->get_error_message(),
						'error'
					);
				} else {
					add_settings_error(
						'photo_competition_manager',
						'deleted',
						__( 'Competition permanently deleted.', 'photo-competition-manager' ),
						'updated'
					);
				}

				$this->redirect_with_settings_errors( $this->dashboard_url() );
			}

			if ( 'reset_votes' === $action ) {
				// Delete all votes.
				$votes_repo = new \PhotoCompetitionManager\Repository\Votes_Repository();
				$votes_repo->delete_by_competition( $competition_id );

				// Delete all voting tokens.
				$token_repo = new \PhotoCompetitionManager\Repository\Voting_Token_Repository();
				$token_repo->delete_by_competition( $competition_id );

				// Reset voting workflow state.
				$competition = $this->competitions->find( $competition_id );
				if ( $competition ) {
					$settings                               = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings );
					$settings['voting']['category_steps']   = array();
					$settings['voting']['voted_categories'] = array();
					$settings['voting']['open_categories']  = array();
					$this->competitions->update( $competition_id, array( 'settings' => $settings ) );
				}

				add_settings_error(
					'photo_competition_manager',
					'votes_reset',
					__( 'All votes, tokens, and voting progress have been reset for this competition.', 'photo-competition-manager' ),
					'updated'
				);

				$this->redirect_with_settings_errors( $this->dashboard_url() );
			}

			if ( 'send_emails' === $action ) {
				$upload_link_service = new Upload_Link_Service();
				$result              = $upload_link_service->send_reminders( $competition_id );

				if ( is_wp_error( $result ) ) {
					add_settings_error(
						'photo_competition_manager',
						$result->get_error_code(),
						$result->get_error_message(),
						'error'
					);
				} else {
					$sent_count    = is_array( $result ) ? $result['sent_count'] : ( is_int( $result ) ? $result : 0 );
					$skipped_count = is_array( $result ) && isset( $result['skipped_count'] ) ? $result['skipped_count'] : 0;
					$failed_count  = is_array( $result ) && isset( $result['failed_count'] ) ? $result['failed_count'] : 0;
					$total_count   = is_array( $result ) ? $result['total_count'] : $sent_count;
					$errors        = is_array( $result ) && isset( $result['errors'] ) ? $result['errors'] : array();

					$message = sprintf(
						/* translators: 1: Number of emails sent, 2: Total number of members */
						_n(
							'%1$d of %2$d reminder email sent to members.',
							'%1$d of %2$d reminder emails sent to members.',
							$sent_count,
							'photo-competition-manager'
						),
						$sent_count,
						$total_count
					);

					// Add rate limit notice if some emails were skipped.
					if ( $skipped_count > 0 ) {
						$message .= ' ' . sprintf(
							/* translators: %d: Number of members skipped */
							_n(
								'%d member was skipped due to rate limiting (emails are not resent within 5 minutes).',
								'%d members were skipped due to rate limiting (emails are not resent within 5 minutes).',
								$skipped_count,
								'photo-competition-manager'
							),
							$skipped_count
						);
					} else {
						$message .= ' ' . __( 'Note: Emails will not be resent to the same members within 5 minutes.', 'photo-competition-manager' );
					}

					// Add failed count notice if some emails failed.
					if ( $failed_count > 0 ) {
						$message .= ' ' . sprintf(
							/* translators: %d: Number of members that failed */
							_n(
								'%d email failed to send.',
								'%d emails failed to send.',
								$failed_count,
								'photo-competition-manager'
							),
							$failed_count
						);

						// Show detailed errors.
						if ( ! empty( $errors ) ) {
							$message .= ' ' . __( 'Errors:', 'photo-competition-manager' ) . ' ' . implode( '; ', $errors );
						}
					}

					add_settings_error(
						'photo_competition_manager',
						'emails_sent',
						$message,
						$failed_count > 0 ? 'error' : 'updated'
					);
				}

				$this->redirect_with_settings_errors( $this->dashboard_url() );
			}

			$result = 'archive' === $action
			? $this->competitions->archive( $competition_id )
			: $this->competitions->restore( $competition_id );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_manager',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				$message = 'archive' === $action
				? __( 'Competition archived.', 'photo-competition-manager' )
				: __( 'Competition restored.', 'photo-competition-manager' );

				add_settings_error(
					'photo_competition_manager',
					'archive' === $action ? 'archived' : 'restored',
					$message,
					'updated'
				);
			}

			$redirect = 'restore' === $action
			? add_query_arg(
				array(
					'page' => 'photo-competition-manager',
					'view' => 'archived',
				),
				admin_url( 'admin.php' )
			)
			: $this->dashboard_url();

			$this->redirect_with_settings_errors( $redirect );
		}

		// Centralised toggle_uploads handler — all pages route here via ref_page for redirect.
		if ( 'toggle_uploads' === $action && isset( $_GET['competition'] ) ) {
			$competition_id = absint( wp_unslash( $_GET['competition'] ) );

			check_admin_referer( 'photo_competition_toggle_uploads_' . $competition_id );

			$competition = $this->competitions->find( $competition_id );

			if ( $competition ) {
				$settings       = Competition_Settings::parse( $competition->settings );
				$uploads_closed = ! empty( $settings['upload']['uploads_closed'] );

				$settings['upload']['uploads_closed'] = ! $uploads_closed;

				$result = $this->competitions->update( $competition_id, array( 'settings' => $settings ) );

				if ( is_wp_error( $result ) ) {
					add_settings_error(
						'photo_competition_manager',
						$result->get_error_code(),
						$result->get_error_message(),
						'error'
					);
				} else {
					$message = $uploads_closed
						? __( 'Uploads reopened. Members can now upload images.', 'photo-competition-manager' )
						: __( 'Uploads closed. Members can no longer upload images.', 'photo-competition-manager' );
					add_settings_error(
						'photo_competition_manager',
						'uploads_toggled',
						$message,
						'updated'
					);
				}
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of ref_page for redirect routing.
			$ref_page     = isset( $_GET['ref_page'] ) ? sanitize_key( wp_unslash( $_GET['ref_page'] ) ) : '';
			$redirect_map = array(
				'voting'  => add_query_arg( array( 'page' => 'photo-competition-manager-voting' ), admin_url( 'admin.php' ) ),
				'members' => $this->members_url(),
			);
			$redirect_url = $redirect_map[ $ref_page ] ?? $this->dashboard_url();

			$this->redirect_with_settings_errors( $redirect_url );
		}

		if ( 'update_competition_settings' === $action ) {
			$competition_id = absint( $this->get_post_string( 'competition_id' ) );

			check_admin_referer( 'photo_competition_update_settings_' . $competition_id, 'photo_competition_nonce' );

			// Get existing competition to preserve open_categories (controlled via Voting Controls page).
			$existing_competition     = $this->competitions->find( $competition_id );
			$existing_settings        = $existing_competition ? Competition_Settings::parse( $existing_competition->settings ) : array();
			$existing_open_categories = $existing_settings['voting']['open_categories'] ?? array();

			$categories = $this->get_post_array( 'categories' );
			$grades     = $this->get_post_array( 'grades' );

			$sanitized_categories = array();
			foreach ( $categories as $category ) {
				if ( ! isset( $category['label'], $category['slug'], $category['quota'] ) ) {
					continue;
				}

				$sanitized_categories[] = array(
					'label' => sanitize_text_field( $category['label'] ),
					'slug'  => sanitize_title( $category['slug'] ),
					'quota' => absint( $category['quota'] ),
				);
			}

			$sanitized_grades = array();
			foreach ( $grades as $grade ) {
				if ( ! isset( $grade['label'] ) ) {
					continue;
				}

				$sanitized_grades[] = array(
					'label' => sanitize_text_field( $grade['label'] ),
					'slug'  => sanitize_title( $grade['label'] ),
				);
			}

			$score_matrix_raw = sanitize_text_field( $this->get_post_string( 'score_matrix' ) );
			$score_matrix     = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $score_matrix_raw ) ), 'is_numeric' ) );

			if ( empty( $score_matrix ) ) {
				$score_matrix = array( 9, 8, 7, 6, 5 );
			}

			$auth_mode_input = sanitize_text_field( $this->get_post_string( 'voting_auth_mode', 'password' ) );
			if ( ! in_array( $auth_mode_input, array( 'password', 'token' ), true ) ) {
				$auth_mode_input = 'password';
			}

			$voting_password       = sanitize_text_field( $this->get_post_string( 'voting_password' ) );
			$voting_password_clear = isset( $_POST['voting_password_clear'] ) && '1' === $_POST['voting_password_clear'];
			$click_image_to_zoom   = isset( $_POST['click_image_to_zoom'] ) && '1' === $_POST['click_image_to_zoom'];
			$voting_ui_type        = sanitize_text_field( $this->get_post_string( 'voting_ui_type', 'buttons' ) );
			if ( ! in_array( $voting_ui_type, array( 'buttons', 'dropdown' ), true ) ) {
				$voting_ui_type = 'buttons';
			}

			$upload_page_url = sanitize_url( $this->get_post_string( 'upload_page_url', '' ) );
			$voting_page_url = sanitize_url( $this->get_post_string( 'voting_page_url', '' ) );

			$progress_meter_type_input = sanitize_text_field( $this->get_post_string( 'progress_meter_type', 'bar' ) );
			if ( ! in_array( $progress_meter_type_input, array( 'bar', 'line', 'dots', 'radial' ), true ) ) {
				$progress_meter_type_input = 'bar';
			}

			// Store the password if provided, clear if empty or checkbox checked.
			// For legacy hashed passwords (not shown in the form), preserve when field is blank.
			$existing_password = $existing_settings['voting']['password'] ?? '';
			$is_legacy_hash    = '' !== $existing_password && (bool) preg_match( '/^\$P\$|\$wp\$/', $existing_password );
			$hashed_password   = '';
			if ( $voting_password_clear ) {
				// Clear the password via checkbox (legacy hash flow).
				$hashed_password = '';
			} elseif ( ! empty( $voting_password ) ) {
				// Password provided - store lowercase for case-insensitive comparison.
				$hashed_password = strtolower( $voting_password );
			} elseif ( $is_legacy_hash ) {
				// Legacy hash: blank field means keep existing password.
				$hashed_password = $existing_password;
			}

			// Preserve existing results settings (results_visible) controlled via Voting Controls page.
			$existing_results = $existing_settings['results'] ?? array();

			$settings = array(
				'categories'      => $sanitized_categories,
				'grades'          => $sanitized_grades,
				'upload'          => array(
					'max_file_size_mb' => absint( $this->get_post_string( 'max_file_size_mb', '5' ) ),
					'max_width'        => absint( $this->get_post_string( 'max_width', '1920' ) ),
					'max_height'       => absint( $this->get_post_string( 'max_height', '1920' ) ),
					'allowed_formats'  => array( 'jpg', 'jpeg' ),
				),
				'voting'          => array(
					'score_matrix'        => $score_matrix,
					'open_categories'     => $existing_open_categories,
					'auth_mode'           => $auth_mode_input,
					'password'            => $hashed_password,
					'click_image_to_zoom' => $click_image_to_zoom,
					'ui_type'             => $voting_ui_type,
				),
				'slideshow'       => array(
					'progress_meter_type' => $progress_meter_type_input,
					'preview_duration'    => $existing_settings['slideshow']['preview_duration'] ?? 10,
					'voting_duration'     => $existing_settings['slideshow']['voting_duration'] ?? 15,
					'critique_duration'   => $existing_settings['slideshow']['critique_duration'] ?? 0,
				),
				'email_reminders' => array(
					'enabled'                => true,
					'days_before_open'       => 7,
					'days_before_close'      => 1,
					'include_qr_code_voting' => true,
				),
				'urls'            => array(
					'upload_page' => $upload_page_url,
					'voting_page' => $voting_page_url,
				),
				'results'         => $existing_results,
			);

			// Allow empty categories/grades for competitions (will fall back to global defaults).
			$validation = Competition_Settings::validate( $settings, false );

			if ( is_wp_error( $validation ) ) {
				add_settings_error(
					'photo_competition_manager',
					$validation->get_error_code(),
					$validation->get_error_message(),
					'error'
				);

				$this->redirect_with_settings_errors(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'edit',
							'competition' => $competition_id,
							'tab'         => 'settings',
						),
						admin_url( 'admin.php' )
					)
				);
			}

			$result = $this->competitions->update(
				$competition_id,
				array(
					'settings' => $settings,
				)
			);

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_manager',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'photo_competition_manager',
					'settings_updated',
					__( 'Competition settings updated successfully.', 'photo-competition-manager' ),
					'updated'
				);
			}

			$this->redirect_with_settings_errors(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager',
						'action'      => 'edit',
						'competition' => $competition_id,
						'tab'         => 'settings',
					),
					admin_url( 'admin.php' )
				)
			);
		}
	}

	/**
	 * Render admin dashboard overview.
	 *
	 * @return void
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view; actions enforce nonces during processing.
		$action_query = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view; actions enforce nonces during processing.
		$competition_query = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

		if ( 'edit' === $action_query && $competition_query ) {
			$this->render_edit_screen( $competition_query );
			return;
		}

		// Restore settings_errors from transient after redirect.
		$transient_errors = get_transient( 'photo_competition_manager_settings_errors' );
		if ( false !== $transient_errors ) {
			foreach ( $transient_errors as $error ) {
				add_settings_error( $error['setting'], $error['code'], $error['message'], $error['type'] );
			}
			delete_transient( 'photo_competition_manager_settings_errors' );
		}

		settings_errors( 'photo_competition_manager' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars for filtering only; no data mutation.
		$view         = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'active';
		$competitions = $this->competitions->all( 10, 'archived' === $view, 'archived' === $view );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Photo Competition Manager Dashboard', 'photo-competition-manager' ) . '</h1>';

		$this->render_competition_table( $competitions, $view );

		$this->render_create_form();

		echo '</div>';
	}

	/**
	 * Render the edit competition screen.
	 *
	 * @param  int $competition_id Competition ID.
	 * @return void
	 */
	private function render_edit_screen( int $competition_id ): void {
		// Restore settings_errors from transient after redirect.
		$transient_errors = get_transient( 'photo_competition_manager_settings_errors' );
		if ( false !== $transient_errors ) {
			foreach ( $transient_errors as $error ) {
				add_settings_error( $error['setting'], $error['code'], $error['message'], $error['type'] );
			}
			delete_transient( 'photo_competition_manager_settings_errors' );
		}

		settings_errors( 'photo_competition_manager' );

		$competition = $this->competitions->find( $competition_id );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Edit Competition', 'photo-competition-manager' ) . '</h1>';

		if ( ! $competition ) {
			echo '<p>' . esc_html__( 'Competition not found. Return to the list and try again.', 'photo-competition-manager' ) . '</p>';
			printf(
				'<a class="button" href="%s">%s</a>',
				esc_url( $this->dashboard_url() ),
				esc_html__( 'Back to competitions', 'photo-competition-manager' )
			);
			echo '</div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query var used to switch tabs only.
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
			esc_html__( 'Back to competitions', 'photo-competition-manager' )
		);

		echo '</div>';
	}

	/**
	 * Render tabs for competition edit screen.
	 *
	 * @param  int    $competition_id Competition ID.
	 * @param  string $current_tab    Current active tab.
	 * @return void
	 */
	private function render_competition_tabs( int $competition_id, string $current_tab ): void {
		$tabs = array(
			'general'  => __( 'General', 'photo-competition-manager' ),
			'settings' => __( 'Settings', 'photo-competition-manager' ),
		);

		echo '<h2 class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page'        => 'photo-competition-manager',
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
	 * @param  object $competition Competition data.
	 * @return void
	 */
	private function render_competition_general_form( object $competition ): void {
		echo '<form method="post" class="card" style="max-width: 720px; padding: 16px;">';
		wp_nonce_field( 'photo_competition_update_' . (int) $competition->id, 'photo_competition_nonce' );
		echo '<input type="hidden" name="photo_competition_action" value="update_competition" />';
		echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition->id ) . '" />';

		echo '<p>';
		echo '<label for="competition_title">' . esc_html__( 'Title', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="competition_title" name="competition_title" class="regular-text" required value="' . esc_attr( $competition->title ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_slug">' . esc_html__( 'Slug', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="competition_slug" name="competition_slug" class="regular-text" value="' . esc_attr( $competition->slug ) . '" />';
		echo '</p>';

		$label_format = $this->get_ui_date_label();
		echo '<p>';
		echo '<label for="competition_open_date">' . esc_html__( 'Open Date', 'photo-competition-manager' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_open_date" name="competition_open_date" value="' . esc_attr( $this->format_date_for_input( $competition->open_date ) ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_close_date">' . esc_html__( 'Close Date', 'photo-competition-manager' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_close_date" name="competition_close_date" value="' . esc_attr( $this->format_date_for_input( $competition->close_date ) ) . '" />';
		echo '</p>';

		submit_button( __( 'Update Competition', 'photo-competition-manager' ) );

		echo '</form>';
	}

	/**
	 * Render competition settings form.
	 *
	 * @param  object $competition Competition data.
	 * @return void
	 */
	private function render_competition_settings_form( object $competition ): void {
		$settings            = Competition_Settings::parse( $competition->settings );
		$categories          = Competition_Settings::get_categories( $settings );
		$grades              = Competition_Settings::get_grades( $settings );
		$upload              = Competition_Settings::get_upload_constraints( $settings );
		$voting              = Competition_Settings::get_voting_config( $settings );
		$slideshow           = $settings['slideshow'] ?? array();
		$progress_meter_type = $slideshow['progress_meter_type'] ?? 'bar';
		$voting_ui_type      = $voting['ui_type'] ?? '';
		if ( ! in_array( $voting_ui_type, array( 'buttons', 'dropdown' ), true ) ) {
			$voting_ui_type = Competition_Settings::get_voting_ui_type( $settings );
		}

		echo '<form method="post" class="card" style="max-width: 720px; padding: 16px;">';
		wp_nonce_field( 'photo_competition_update_settings_' . (int) $competition->id, 'photo_competition_nonce' );
		echo '<input type="hidden" name="photo_competition_action" value="update_competition_settings" />';
		echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition->id ) . '" />';

		echo '<h3>' . esc_html__( 'Categories', 'photo-competition-manager' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Define competition categories and upload quotas. Members can upload up to the specified number of images per category.', 'photo-competition-manager' ) . '</p>';

		echo '<div id="categories-container">';
		foreach ( $categories as $index => $category ) {
			$this->render_category_field( $index, $category );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-category" class="button">' . esc_html__( 'Add Category', 'photo-competition-manager' ) . '</button>';
		echo '</p>';

		echo '<h3>' . esc_html__( 'Grades', 'photo-competition-manager' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Define member grade levels for results grouping.', 'photo-competition-manager' ) . '</p>';

		echo '<div id="grades-container">';
		foreach ( $grades as $index => $grade ) {
			$this->render_grade_field( $index, $grade );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-grade" class="button">' . esc_html__( 'Add Grade', 'photo-competition-manager' ) . '</button>';
		echo '</p>';

		echo '<h3>' . esc_html__( 'Upload Constraints', 'photo-competition-manager' ) . '</h3>';

		echo '<p>';
		echo '<label for="max_file_size_mb">' . esc_html__( 'Max File Size (MB)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="number" id="max_file_size_mb" name="max_file_size_mb" min="1" max="50" value="' . esc_attr( $upload['max_file_size_mb'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_width">' . esc_html__( 'Max Width (pixels)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="number" id="max_width" name="max_width" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_width'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_height">' . esc_html__( 'Max Height (pixels)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="number" id="max_height" name="max_height" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_height'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<h3>' . esc_html__( 'Voting Configuration', 'photo-competition-manager' ) . '</h3>';

		$auth_mode = $voting['auth_mode'] ?? 'password';

		echo '<p>';
		echo '<label for="voting_auth_mode">' . esc_html__( 'Voting Authentication Mode', 'photo-competition-manager' ) . '</label><br />';
		echo '<select id="voting_auth_mode" name="voting_auth_mode">';
		echo '<option value="password"' . selected( $auth_mode, 'password', false ) . '>' . esc_html__( 'Password-based (traditional)', 'photo-competition-manager' ) . '</option>';
		echo '<option value="token"' . selected( $auth_mode, 'token', false ) . '>' . esc_html__( 'Email Magic Links (anonymous)', 'photo-competition-manager' ) . '</option>';
		echo '</select><br />';
		echo '<span class="description">' . esc_html__( 'Choose how voters authenticate. Password mode allows voters to enter their name and optional password. Token mode sends secure one-time voting links via email for anonymous voting.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_password">' . esc_html__( 'Voting Password (for password mode)', 'photo-competition-manager' ) . '</label><br />';

		$is_plaintext_password = ! empty( $voting['password'] ) && ! preg_match( '/^\$P\$|\$wp\$/', $voting['password'] );
		$is_legacy_hash        = ! empty( $voting['password'] ) && ! $is_plaintext_password;
		$password_value        = $is_plaintext_password ? $voting['password'] : '';

		echo '<input type="text" id="voting_password" name="voting_password" value="' . esc_attr( $password_value ) . '" class="regular-text" />';

		if ( $is_legacy_hash ) {
			echo '<br /><label>';
			echo '<input type="checkbox" id="voting_password_clear" name="voting_password_clear" value="1" />';
			echo ' ' . esc_html__( 'Remove password protection', 'photo-competition-manager' );
			echo '</label>';
			echo '<br /><span class="description">' . esc_html__( 'A password is currently set. Enter a new password to change it, check the box above to remove password protection, or leave both blank to keep the existing password. Passwords are not case-sensitive.', 'photo-competition-manager' ) . '</span>';
		} else {
			echo '<br /><span class="description">' . esc_html__( 'Leave blank for no password. Passwords are case insensitive.', 'photo-competition-manager' ) . '</span>';
		}
		echo '</p>';

		echo '<p>';
		$click_to_zoom = isset( $voting['click_image_to_zoom'] ) ? (bool) $voting['click_image_to_zoom'] : false;
		echo '<label for="click_image_to_zoom">';
		echo '<input type="checkbox" id="click_image_to_zoom" name="click_image_to_zoom" value="1"' . checked( $click_to_zoom, true, false ) . ' />';
		echo ' ' . esc_html__( 'Click image to zoom on voting form', 'photo-competition-manager' );
		echo '</label><br />';
		echo '<span class="description">' . esc_html__( 'When enabled, images in the voting form can be clicked to open full-size in a new tab. When disabled, images are not clickable to prevent accidental navigation. Recommended: off for touch devices.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_ui_type">' . esc_html__( 'Voting UI Type', 'photo-competition-manager' ) . '</label><br />';
		echo '<select id="voting_ui_type" name="voting_ui_type">';
		echo '<option value="buttons"' . selected( $voting_ui_type, 'buttons', false ) . '>' . esc_html__( 'Horizontal Score Buttons', 'photo-competition-manager' ) . '</option>';
		echo '<option value="dropdown"' . selected( $voting_ui_type, 'dropdown', false ) . '>' . esc_html__( 'Dropdown', 'photo-competition-manager' ) . '</option>';
		echo '</select><br />';
		echo '<span class="description">' . esc_html__( 'Pick the layout voters use in this competition. Leave set to buttons for the quickest scoring experience.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="score_matrix">' . esc_html__( 'Score Matrix (comma-separated)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="score_matrix" name="score_matrix" value="' . esc_attr( implode( ', ', $voting['score_matrix'] ) ) . '" class="regular-text" />';
		echo '<span class="description">' . esc_html__( 'E.g., 9, 8, 7, 6, 5', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<h3>' . esc_html__( 'Slideshow', 'photo-competition-manager' ) . '</h3>';

		echo '<p>';
		echo '<label>' . esc_html__( 'Progress Meter Style', 'photo-competition-manager' ) . '</label>';
		echo '</p>';

		$meter_types = array(
			'bar'    => __( 'Bar', 'photo-competition-manager' ),
			'line'   => __( 'Thin Line', 'photo-competition-manager' ),
			'dots'   => __( 'Dots', 'photo-competition-manager' ),
			'radial' => __( 'Radial', 'photo-competition-manager' ),
		);

		echo '<div class="progress-meter-selector" style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 20px;">';

		foreach ( $meter_types as $type => $label ) {
			$is_active = ( $type === $progress_meter_type ) ? ' active' : '';
			echo '<label class="progress-meter-card' . esc_attr( $is_active ) . '" style="cursor: pointer; border: 2px solid ' . ( $is_active ? '#0073aa' : '#ddd' ) . '; border-radius: 8px; padding: 12px; text-align: center; background: #1a1a1a; min-width: 140px; transition: border-color 0.2s;">';
			echo '<input type="radio" name="progress_meter_type" value="' . esc_attr( $type ) . '"' . checked( $progress_meter_type, $type, false ) . ' style="display: none;" />';
			echo '<div class="meter-preview" data-meter-type="' . esc_attr( $type ) . '" style="height: 50px; position: relative; margin-bottom: 8px; overflow: hidden; border-radius: 4px;"></div>';
			echo '<span style="color: #666; font-size: 13px; font-weight: 600;">' . esc_html( $label ) . '</span>';
			echo '</label>';
		}

		echo '</div>';
		echo '<span class="description">' . esc_html__( 'Choose the progress indicator style shown during the slideshow.', 'photo-competition-manager' ) . '</span>';

		echo '<h3>' . esc_html__( 'URLs', 'photo-competition-manager' ) . '</h3>';

		$urls = $settings['urls'] ?? array(
			'upload_page' => '',
			'voting_page' => '',
		);

		echo '<p>';
		echo '<label for="upload_page_url">' . esc_html__( 'Upload Page URL', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="url" id="upload_page_url" name="upload_page_url" value="' . esc_attr( $urls['upload_page'] ) . '" class="regular-text" placeholder="https://example.com/upload" />';
		echo '<br /><span class="description">' . esc_html__( 'The page where members can upload their images. This URL will be included in email notifications with the member\'s upload token.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_page_url">' . esc_html__( 'Voting Page URL', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="url" id="voting_page_url" name="voting_page_url" value="' . esc_attr( $urls['voting_page'] ) . '" class="regular-text" placeholder="https://example.com/vote" />';
		echo '<br /><span class="description">' . esc_html__( 'The page where members can vote on images. This URL will be included in voting notification emails.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		$share_hash = $competition->share_hash ?? '';
		if ( ! empty( $share_hash ) ) {
			echo '<p>';
			echo '<label>' . esc_html__( 'Results Share Hash', 'photo-competition-manager' ) . '</label><br />';
			echo '<code>' . esc_html( $share_hash ) . '</code>';

			$results_page_url = $urls['results_page'] ?? '';
			if ( ! empty( $results_page_url ) ) {
				$share_url = add_query_arg( 'share', $share_hash, $results_page_url );
				echo '<br /><span class="description">' . esc_html__( 'Share link:', 'photo-competition-manager' ) . ' <a href="' . esc_url( $share_url ) . '" target="_blank">' . esc_html( $share_url ) . '</a></span>';
			}
			echo '</p>';
		}

		submit_button( __( 'Save Settings', 'photo-competition-manager' ) );

		echo '</form>';
	}

	/**
	 * Render category field row.
	 *
	 * @param  int   $index    Category index.
	 * @param  array $category Category data.
	 * @return void
	 */
	private function render_category_field( int $index, array $category ): void {
		echo '<div class="category-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Label', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" name="categories[' . esc_attr( $index ) . '][label]" value="' . esc_attr( $category['label'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Slug', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" name="categories[' . esc_attr( $index ) . '][slug]" value="' . esc_attr( $category['slug'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Upload Quota', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="number" name="categories[' . esc_attr( $index ) . '][quota]" value="' . esc_attr( $category['quota'] ) . '" min="1" max="10" class="small-text" required />';
		echo '</p>';

		echo '<button type="button" class="button remove-category" style="color: #b32d2e;">' . esc_html__( 'Remove', 'photo-competition-manager' ) . '</button>';

		echo '</div>';
	}

	/**
	 * Render grade field row.
	 *
	 * @param  int   $index Grade index.
	 * @param  array $grade Grade data.
	 * @return void
	 */
	private function render_grade_field( int $index, array $grade ): void {
		echo '<div class="grade-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Label', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" name="grades[' . esc_attr( $index ) . '][label]" value="' . esc_attr( $grade['label'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<button type="button" class="button remove-grade" style="color: #b32d2e;">' . esc_html__( 'Remove', 'photo-competition-manager' ) . '</button>';

		echo '</div>';
	}


	/**
	 * Render competitions list table.
	 *
	 * @param  array<int, object> $competitions Competitions.
	 * @param  string             $view         Current view.
	 * @return void
	 */
	private function render_competition_table( array $competitions, string $view ): void {
		echo '<h2 class="screen-reader-text">' . esc_html__( 'Competition List', 'photo-competition-manager' ) . '</h2>';

		$total_active   = $this->competitions->count( false );
		$total_archived = $this->competitions->count( true );

		echo '<ul class="subsubsub">';
		$views = array(
			'active'   => array(
				'label' => __( 'Active', 'photo-competition-manager' ),
				'count' => $total_active,
			),
			'archived' => array(
				'label' => __( 'Archived', 'photo-competition-manager' ),
				'count' => max( 0, $total_archived ),
			),
		);

		$index = 0;
		foreach ( $views as $slug => $data ) {
			$url = add_query_arg(
				array(
					'page' => 'photo-competition-manager',
					'view' => $slug,
				),
				admin_url( 'admin.php' )
			);

			echo '<li><a href="' . esc_url( $url ) . '"' . ( $slug === $view ? ' class="current"' : '' ) . '>' . esc_html( $data['label'] ) . ' <span class="count">(' . esc_html( (string) $data['count'] ) . ')</span></a>';
			if ( ++$index < count( $views ) ) {
				echo ' | ';
			}
			echo '</li>';
		}
		echo '</ul>';

		if ( empty( $competitions ) ) {
			echo '<p>' . esc_html__( 'No competitions found for this view.', 'photo-competition-manager' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Opens', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Closes', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Last Updated', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'photo-competition-manager' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $competitions as $competition ) {
			$is_archived = ! empty( $competition->deleted_at );

			$edit_link = add_query_arg(
				array(
					'page'        => 'photo-competition-manager',
					'action'      => 'edit',
					'competition' => (int) $competition->id,
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td>' . esc_html( $competition->title ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $competition->open_date ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $competition->close_date ) ) . '</td>';
			$last_updated = ! empty( $competition->updated_at ) ? $competition->updated_at : $competition->created_at;
			echo '<td>' . esc_html( $this->format_datetime( $last_updated ) ) . '</td>';

			$actions = array(
				sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit', 'photo-competition-manager' ) ),
			);

			// Toggle uploads action.
			if ( ! $is_archived ) {
				$comp_settings  = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings );
				$uploads_closed = ! empty( $comp_settings['upload']['uploads_closed'] );

				$toggle_uploads_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'toggle_uploads',
							'competition' => (int) $competition->id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_toggle_uploads_' . (int) $competition->id
				);

				$toggle_label = $uploads_closed
					? __( 'Open Uploads', 'photo-competition-manager' )
					: __( 'Close Uploads', 'photo-competition-manager' );

				$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( $toggle_uploads_url ), esc_html( $toggle_label ) );
			}

			if ( $this->competitions->is_open( $competition ) && ! $is_archived ) {
				$send_email_link = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'send_emails',
							'competition' => (int) $competition->id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_send_emails_' . (int) $competition->id
				);

				$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( $send_email_link ), esc_html__( 'Send Upload Emails', 'photo-competition-manager' ) );
			} else {
				$actions[] = sprintf( '<span title="Send only on open competitions" style="color: #888;">%s</span>', esc_html__( 'Send Upload Emails', 'photo-competition-manager' ) );
			}

			// Generate Results Link action.
			$generate_link_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager',
						'action'      => 'generate_results_link',
						'competition' => (int) $competition->id,
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_generate_results_link_' . (int) $competition->id
			);

			$actions[] = sprintf(
				'<a href="%s" class="photo-comp-regenerate-hash" data-confirm="%s">%s</a>',
				esc_url( $generate_link_url ),
				esc_attr( __( 'This will generate a new results link and invalidate any previously shared link. Continue?', 'photo-competition-manager' ) ),
				esc_html__( 'Generate Results Link', 'photo-competition-manager' )
			);

			if ( $is_archived ) {
				$restore_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'restore',
							'competition' => (int) $competition->id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_restore_' . (int) $competition->id
				);

				$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( $restore_url ), esc_html__( 'Restore', 'photo-competition-manager' ) );
			} else {
				$archive_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'archive',
							'competition' => (int) $competition->id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_archive_' . (int) $competition->id
				);

				$actions[] = sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $archive_url ), esc_html__( 'Archive', 'photo-competition-manager' ) );
			}

			// Reset votes action.
			$reset_votes_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager',
						'action'      => 'reset_votes',
						'competition' => (int) $competition->id,
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_reset_votes_' . (int) $competition->id
			);

			$actions[] = sprintf(
				'<a href="%s" class="photo-comp-reset-votes" data-confirm="%s">%s</a>',
				esc_url( $reset_votes_url ),
				esc_attr( __( 'Reset all voting progress? This deletes all votes, tokens, and resets the workflow to step 1. This cannot be undone.', 'photo-competition-manager' ) ),
				esc_html__( 'Reset Voting', 'photo-competition-manager' )
			);

			// Delete competition action.
			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager',
						'action'      => 'delete',
						'competition' => (int) $competition->id,
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_delete_' . (int) $competition->id
			);

			$actions[] = sprintf(
				'<a href="%s" class="submitdelete photo-comp-delete" data-confirm="%s">%s</a>',
				esc_url( $delete_url ),
				esc_attr( __( 'Are you sure you want to permanently delete this competition? This will delete all images, votes, and tokens. This cannot be undone.', 'photo-competition-manager' ) ),
				esc_html__( 'Delete', 'photo-competition-manager' )
			);

			$allowed_html = array(
				'a'    => array(
					'href'         => array(),
					'class'        => array(),
					'data-confirm' => array(),
				),
				'span' => array(
					'title' => array(),
					'style' => array(),
					'class' => array(),
				),
			);

			echo '<td>' . wp_kses( implode( ' | ', $actions ), $allowed_html ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Render the create competition form.
	 *
	 * @return void
	 */
	private function render_create_form(): void {
		$label_format = $this->get_ui_date_label();

		echo '<form method="post" class="card" style="max-width: 720px; margin-bottom: 24px; padding: 16px;">';
		echo '<h2>' . esc_html__( 'Create Competition', 'photo-competition-manager' ) . '</h2>';

		wp_nonce_field( 'photo_competition_create', 'photo_competition_nonce' );
		echo '<input type="hidden" name="photo_competition_action" value="create_competition" />';

		echo '<p>';
		echo '<label for="competition_title">' . esc_html__( 'Title', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="competition_title" name="competition_title" class="regular-text" required />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_slug">' . esc_html__( 'Slug (optional)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="competition_slug" name="competition_slug" class="regular-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_open_date">' . esc_html__( 'Open Date', 'photo-competition-manager' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_open_date" name="competition_open_date" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_close_date">' . esc_html__( 'Close Date', 'photo-competition-manager' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_close_date" name="competition_close_date" />';
		echo '</p>';

		submit_button( __( 'Create Competition', 'photo-competition-manager' ) );

		echo '</form>';
	}
}
