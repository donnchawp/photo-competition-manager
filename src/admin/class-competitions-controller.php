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
			echo $this->render_edit_screen( $competition_query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
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

		echo $this->render_competition_table( $competitions, $view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.

		echo $this->render_create_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.

		echo '</div>';
	}

	/**
	 * Render the edit competition screen.
	 *
	 * @param  int $competition_id Competition ID.
	 * @return string
	 */
	private function render_edit_screen( int $competition_id ): string {
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

		if ( ! $competition ) {
			return $this->render_template(
				'admin/competitions/edit-screen.php',
				array(
					'found'         => false,
					'dashboard_url' => $this->dashboard_url(),
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query var used to switch tabs only.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

		$tabs_html = $this->render_competition_tabs( $competition_id, $current_tab );

		$form_html = 'settings' === $current_tab
			? $this->render_competition_settings_form( $competition )
			: $this->render_competition_general_form( $competition );

		return $this->render_template(
			'admin/competitions/edit-screen.php',
			array(
				'found'         => true,
				'tabs_html'     => $tabs_html,
				'form_html'     => $form_html,
				'dashboard_url' => $this->dashboard_url(),
			)
		);
	}

	/**
	 * Render tabs for competition edit screen.
	 *
	 * @param  int    $competition_id Competition ID.
	 * @param  string $current_tab    Current active tab.
	 * @return string
	 */
	private function render_competition_tabs( int $competition_id, string $current_tab ): string {
		$tabs = array(
			'general'  => __( 'General', 'photo-competition-manager' ),
			'settings' => __( 'Settings', 'photo-competition-manager' ),
		);

		return $this->render_template(
			'admin/competitions/competition-tabs.php',
			array(
				'competition_id' => $competition_id,
				'current_tab'    => $current_tab,
				'tabs'           => $tabs,
			)
		);
	}

	/**
	 * Render general competition form.
	 *
	 * @param  object $competition Competition data.
	 * @return string
	 */
	private function render_competition_general_form( object $competition ): string {
		return $this->render_template(
			'admin/competitions/general-form.php',
			array(
				'competition_id'   => (int) $competition->id,
				'title'            => $competition->title,
				'slug'             => $competition->slug,
				'label_format'     => $this->get_ui_date_label(),
				'open_date_value'  => $this->format_date_for_input( $competition->open_date ),
				'close_date_value' => $this->format_date_for_input( $competition->close_date ),
			)
		);
	}

	/**
	 * Render competition settings form.
	 *
	 * @param  object $competition Competition data.
	 * @return string
	 */
	private function render_competition_settings_form( object $competition ): string {
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

		$category_rows_html = '';
		foreach ( $categories as $index => $category ) {
			$category_rows_html .= $this->render_category_field( $index, $category );
		}

		$grade_rows_html = '';
		foreach ( $grades as $index => $grade ) {
			$grade_rows_html .= $this->render_grade_field( $index, $grade );
		}

		$auth_mode = $voting['auth_mode'] ?? 'password';

		$is_plaintext_password = ! empty( $voting['password'] ) && ! preg_match( '/^\$P\$|\$wp\$/', $voting['password'] );
		$is_legacy_hash        = ! empty( $voting['password'] ) && ! $is_plaintext_password;
		$password_value        = $is_plaintext_password ? $voting['password'] : '';

		$click_to_zoom = isset( $voting['click_image_to_zoom'] ) ? (bool) $voting['click_image_to_zoom'] : false;

		$urls = $settings['urls'] ?? array(
			'upload_page' => '',
			'voting_page' => '',
		);

		return $this->render_template(
			'admin/competitions/settings-form.php',
			array(
				'competition_id'      => (int) $competition->id,
				'category_rows_html'  => $category_rows_html,
				'grade_rows_html'     => $grade_rows_html,
				'upload'              => $upload,
				'auth_mode'           => $auth_mode,
				'password_value'      => $password_value,
				'is_legacy_hash'      => $is_legacy_hash,
				'click_to_zoom'       => $click_to_zoom,
				'voting_ui_type'      => $voting_ui_type,
				'score_matrix_text'   => implode( ', ', $voting['score_matrix'] ),
				'progress_meter_type' => $progress_meter_type,
				'urls'                => $urls,
				'share_hash'          => $competition->share_hash ?? '',
			)
		);
	}

	/**
	 * Render category field row.
	 *
	 * @param  int   $index    Category index.
	 * @param  array $category Category data.
	 * @return string
	 */
	private function render_category_field( int $index, array $category ): string {
		return $this->render_template(
			'admin/shared/category-field.php',
			array(
				'index' => $index,
				'label' => $category['label'],
				'slug'  => $category['slug'],
				'quota' => $category['quota'],
			)
		);
	}

	/**
	 * Render grade field row.
	 *
	 * @param  int   $index Grade index.
	 * @param  array $grade Grade data.
	 * @return string
	 */
	private function render_grade_field( int $index, array $grade ): string {
		return $this->render_template(
			'admin/shared/grade-field.php',
			array(
				'index' => $index,
				'label' => $grade['label'],
			)
		);
	}


	/**
	 * Render competitions list table.
	 *
	 * @param  array<int, object> $competitions Competitions.
	 * @param  string             $view         Current view.
	 * @return string
	 */
	private function render_competition_table( array $competitions, string $view ): string {
		$total_active   = $this->competitions->count( false );
		$total_archived = $this->competitions->count( true );

		$view_defs = array(
			'active'   => __( 'Active', 'photo-competition-manager' ),
			'archived' => __( 'Archived', 'photo-competition-manager' ),
		);

		$view_counts = array(
			'active'   => $total_active,
			'archived' => max( 0, $total_archived ),
		);

		$views = array();
		foreach ( $view_defs as $view_slug => $view_label ) {
			$views[] = array(
				'label'      => $view_label,
				'count'      => $view_counts[ $view_slug ],
				'url'        => add_query_arg(
					array(
						'page' => 'photo-competition-manager',
						'view' => $view_slug,
					),
					admin_url( 'admin.php' )
				),
				'is_current' => $view_slug === $view,
			);
		}

		$rows = array();
		foreach ( $competitions as $competition ) {
			$is_archived = ! empty( $competition->deleted_at );
			$comp_id     = (int) $competition->id;

			$edit_url = add_query_arg(
				array(
					'page'        => 'photo-competition-manager',
					'action'      => 'edit',
					'competition' => $comp_id,
				),
				admin_url( 'admin.php' )
			);

			$toggle_uploads_url = '';
			$uploads_closed     = false;
			if ( ! $is_archived ) {
				$comp_settings      = Competition_Settings::parse( $competition->settings );
				$uploads_closed     = ! empty( $comp_settings['upload']['uploads_closed'] );
				$toggle_uploads_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'toggle_uploads',
							'competition' => $comp_id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_toggle_uploads_' . $comp_id
				);
			}

			$is_open        = $this->competitions->is_open( $competition );
			$send_email_url = '';
			if ( $is_open && ! $is_archived ) {
				$send_email_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'send_emails',
							'competition' => $comp_id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_send_emails_' . $comp_id
				);
			}

			$generate_link_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager',
						'action'      => 'generate_results_link',
						'competition' => $comp_id,
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_generate_results_link_' . $comp_id
			);

			$restore_url = '';
			$archive_url = '';
			if ( $is_archived ) {
				$restore_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'restore',
							'competition' => $comp_id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_restore_' . $comp_id
				);
			} else {
				$archive_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'archive',
							'competition' => $comp_id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_archive_' . $comp_id
				);
			}

			$reset_votes_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager',
						'action'      => 'reset_votes',
						'competition' => $comp_id,
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_reset_votes_' . $comp_id
			);

			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager',
						'action'      => 'delete',
						'competition' => $comp_id,
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_delete_' . $comp_id
			);

			$last_updated_raw = ! empty( $competition->updated_at ) ? $competition->updated_at : $competition->created_at;

			$rows[] = array(
				'title'              => $competition->title,
				'opens'              => $this->format_datetime( $competition->open_date ),
				'closes'             => $this->format_datetime( $competition->close_date ),
				'last_updated'       => $this->format_datetime( $last_updated_raw ),
				'edit_url'           => $edit_url,
				'is_archived'        => $is_archived,
				'toggle_uploads_url' => $toggle_uploads_url,
				'uploads_closed'     => $uploads_closed,
				'is_open'            => $is_open,
				'send_email_url'     => $send_email_url,
				'generate_link_url'  => $generate_link_url,
				'restore_url'        => $restore_url,
				'archive_url'        => $archive_url,
				'reset_votes_url'    => $reset_votes_url,
				'delete_url'         => $delete_url,
			);
		}

		return $this->render_template(
			'admin/competitions/competitions-table.php',
			array(
				'views' => $views,
				'rows'  => $rows,
			)
		);
	}

	/**
	 * Render the create competition form.
	 *
	 * @return string
	 */
	private function render_create_form(): string {
		return $this->render_template(
			'admin/competitions/create-form.php',
			array(
				'label_format' => $this->get_ui_date_label(),
			)
		);
	}
}
