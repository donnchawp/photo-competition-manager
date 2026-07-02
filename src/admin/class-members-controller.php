<?php
/**
 * Members controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Date_Formatting;
use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Service\Upload_Link_Service;
use PhotoCompetitionManager\Support\Competition_Settings;

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue scripts for members page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'competitions_page_photo-competition-manager-members' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'photo-comp-members-admin',
			PHOTO_COMPETITION_MANAGER_URL . 'assets/build/admin/members-admin.js',
			array(),
			PHOTO_COMPETITION_MANAGER_VERSION,
			true
		);

		wp_localize_script(
			'photo-comp-members-admin',
			'photoCompMembersAdmin',
			array(
				'selectBulkAction' => __( 'Please select a bulk action.', 'photo-competition-manager' ),
				'selectOneMember'  => __( 'Please select at least one member.', 'photo-competition-manager' ),
				'selectGrade'      => __( 'Please select a grade.', 'photo-competition-manager' ),
				'confirmDelete'    => __( 'Are you sure you want to delete this member and all their photos and votes?', 'photo-competition-manager' ),
				'memberLabel'      => __( 'Member:', 'photo-competition-manager' ),
				'cannotUndo'       => __( 'This action cannot be undone.', 'photo-competition-manager' ),
			)
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

		// Per-member "Send Upload Email".
		if ( 'send_member_upload_email' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			$member_id = isset( $_GET['member'] ) ? absint( wp_unslash( $_GET['member'] ) ) : 0;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

			if ( ! $member_id || ! $competition_id ) {
				add_settings_error(
					'photo_competition_members',
					'invalid_params',
					__( 'Invalid member or competition.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $this->members_url() );
				exit;
			}

			check_admin_referer( 'photo_competition_send_member_email_' . $member_id . '_' . $competition_id );

			$competition = $this->competitions->find( $competition_id );
			$member      = $this->members->find( $member_id );

			if ( ! $competition || ! $this->competitions->is_open( $competition ) ) {
				add_settings_error(
					'photo_competition_members',
					'invalid_competition',
					__( 'Competition must be open to send upload emails.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $this->members_url() );
				exit;
			}

			if ( ! $member || empty( $member->email ) || ! $member->active ) {
				add_settings_error(
					'photo_competition_members',
					'invalid_member',
					__( 'Member must be active and have an email address.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $this->members_url() );
				exit;
			}

			// Resolve upload page URL from competition settings or shortcode detection, fallback to home.
			$settings        = Competition_Settings::parse( $competition->settings );
			$urls            = $settings['urls'] ?? array();
			$upload_page_url = $urls['upload_page'] ?? '';

			if ( empty( $upload_page_url ) ) {
				$upload_page_url = Competition_Settings::find_page_url_with_shortcode( 'competition_upload' );
			}

			if ( empty( $upload_page_url ) ) {
				$upload_page_url = home_url( '/' );
			}

			$upload_page_url = apply_filters( 'photo_competition_manager_upload_page_url', $upload_page_url, $competition );

			$upload_link_service = new Upload_Link_Service();
			$result              = $upload_link_service->send_to_member(
				(int) $competition_id,
				(int) $member_id,
				$upload_page_url,
				true // Send email immediately.
			);

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_members',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'photo_competition_members',
					'upload_email_sent',
					sprintf(
					/* translators: 1: member name, 2: competition title */
						__( 'Upload email sent to %1$s for "%2$s".', 'photo-competition-manager' ),
						esc_html( $member->name ),
						esc_html( $competition->title )
					),
					'updated'
				);
			}

			$this->redirect_with_settings_errors( $this->members_url() );
		}

		if ( 'create_member' === $action ) {
			check_admin_referer( 'photo_competition_member_create', 'photo_competition_member_nonce' );

			$name_raw  = $this->get_post_string( 'member_name' );
			$email_raw = $this->get_post_string( 'member_email' );
			$grade_raw = $this->get_post_string( 'member_grade' );
			$is_active    = isset( $_POST['member_active'] );
			$is_committee = isset( $_POST['member_committee'] );
			$name         = sanitize_text_field( $name_raw );
			$email        = sanitize_email( $email_raw );
			$grade        = sanitize_text_field( $grade_raw );

			$data = array(
				'name'      => $name,
				'email'     => $email,
				'grade'     => $grade,
				'active'    => $is_active ? 1 : 0,
				'committee' => $is_committee ? 1 : 0,
			);

			$result = $this->members->create( $data );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_members',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'photo_competition_members',
					'member_created',
					__( 'Member created successfully.', 'photo-competition-manager' ),
					'updated'
				);
			}

			$this->redirect_with_settings_errors( $this->members_url() );
		}

		if ( 'update_member' === $action ) {
			$member_id = absint( $this->get_post_string( 'member_id' ) );

			check_admin_referer( 'photo_competition_member_update_' . $member_id, 'photo_competition_member_nonce' );

			$name_raw     = $this->get_post_string( 'member_name' );
			$email_raw    = $this->get_post_string( 'member_email' );
			$grade_raw    = $this->get_post_string( 'member_grade' );
			$is_active    = isset( $_POST['member_active'] );
			$is_committee = isset( $_POST['member_committee'] );
			$name         = sanitize_text_field( $name_raw );
			$email        = sanitize_email( $email_raw );
			$grade        = sanitize_text_field( $grade_raw );

			$data = array(
				'name'      => $name,
				'email'     => $email,
				'grade'     => $grade,
				'active'    => $is_active ? 1 : 0,
				'committee' => $is_committee ? 1 : 0,
			);

			$result = $this->members->update( $member_id, $data );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_members',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);

				$this->redirect_with_settings_errors(
					add_query_arg(
						array(
							'page'          => 'photo-competition-manager-members',
							'member_action' => 'edit',
							'member'        => $member_id,
						),
						admin_url( 'admin.php' )
					)
				);
			}

			add_settings_error(
				'photo_competition_members',
				'member_updated',
				__( 'Member updated successfully.', 'photo-competition-manager' ),
				'updated'
			);

			$this->redirect_with_settings_errors( $this->members_url() );
		}

		if ( 'delete_member' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			$member_id = isset( $_GET['member'] ) ? absint( wp_unslash( $_GET['member'] ) ) : 0;

			if ( ! $member_id ) {
				add_settings_error(
					'photo_competition_members',
					'invalid_member',
					__( 'Invalid member ID.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $this->members_url() );
				exit;
			}

			check_admin_referer( 'photo_competition_delete_member_' . $member_id );

			$member = $this->members->find( $member_id );

			if ( ! $member ) {
				add_settings_error(
					'photo_competition_members',
					'member_not_found',
					__( 'Member not found.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $this->members_url() );
				exit;
			}

			$result = $this->members->delete( $member_id );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_members',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'photo_competition_members',
					'member_deleted',
					sprintf(
						/* translators: %s: member name */
						__( 'Member "%s" and all their photos and votes have been deleted successfully.', 'photo-competition-manager' ),
						esc_html( $member->name )
					),
					'updated'
				);
			}

			$this->redirect_with_settings_errors( $this->members_url() );
		}

		// Handle CSV import.
		if ( 'import_members_csv' === $action ) {
			check_admin_referer( 'photo_competition_import_members', 'photo_competition_import_nonce' );

			if ( empty( $_FILES['csv_file'] ) || ! isset( $_FILES['csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is a file path and should not be sanitized.
				add_settings_error(
					'photo_competition_members',
					'no_file',
					__( 'Please select a CSV file to import.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $this->members_url() );
				exit;
			}

			$importer = new \PhotoCompetitionManager\Service\Member_CSV_Importer( $this->members );
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File upload data is validated in importer.
			$result = $importer->import( $_FILES['csv_file'] );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_members',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				$message = sprintf(
					/* translators: 1: imported count, 2: updated count, 3: skipped count */
					__( 'Import complete: %1$d new members, %2$d updated, %3$d skipped.', 'photo-competition-manager' ),
					$result['imported'],
					$result['updated'],
					$result['skipped']
				);

				if ( ! empty( $result['errors'] ) ) {
					$message .= ' ' . __( 'Errors:', 'photo-competition-manager' ) . ' ' . implode( ' ', array_slice( $result['errors'], 0, 5 ) );
					if ( count( $result['errors'] ) > 5 ) {
						$message .= sprintf(
							/* translators: %d: number of additional errors */
							__( ' ...and %d more errors.', 'photo-competition-manager' ),
							count( $result['errors'] ) - 5
						);
					}
				}

				add_settings_error(
					'photo_competition_members',
					'import_complete',
					$message,
					empty( $result['errors'] ) ? 'updated' : 'warning'
				);
			}

			$this->redirect_with_settings_errors( $this->members_url() );
		}

		// Handle sample CSV download.
		if ( 'download_sample_csv' === $action ) {
			check_admin_referer( 'photo_competition_download_sample' );

			$importer = new \PhotoCompetitionManager\Service\Member_CSV_Importer( $this->members );
			$csv      = $importer->generate_sample_csv();

			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=members-sample.csv' );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );

			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV content.
			exit;
		}

		// Handle bulk actions.
		if ( 'bulk_activate' === $action || 'bulk_deactivate' === $action || 'bulk_update_grade' === $action ) {
			check_admin_referer( 'photo_competition_bulk_members' );

			$member_ids = isset( $_POST['member_ids'] ) && is_array( $_POST['member_ids'] )
				? array_map( 'absint', wp_unslash( $_POST['member_ids'] ) )
				: array();

			if ( empty( $member_ids ) ) {
				add_settings_error(
					'photo_competition_members',
					'no_members_selected',
					__( 'No members selected.', 'photo-competition-manager' ),
					'error'
				);
			} else {
				$updated_count = 0;
				$failed_count  = 0;

				if ( 'bulk_activate' === $action ) {
					foreach ( $member_ids as $member_id ) {
						$result = $this->members->update( $member_id, array( 'active' => 1 ) );
						if ( is_wp_error( $result ) ) {
							++$failed_count;
						} else {
							++$updated_count;
						}
					}

					if ( $updated_count > 0 ) {
						add_settings_error(
							'photo_competition_members',
							'bulk_activated',
							sprintf(
								/* translators: %d: number of activated members */
								_n(
									'%d member activated successfully.',
									'%d members activated successfully.',
									$updated_count,
									'photo-competition-manager'
								),
								$updated_count
							),
							'updated'
						);
					}
				} elseif ( 'bulk_deactivate' === $action ) {
					foreach ( $member_ids as $member_id ) {
						$result = $this->members->update( $member_id, array( 'active' => 0 ) );
						if ( is_wp_error( $result ) ) {
							++$failed_count;
						} else {
							++$updated_count;
						}
					}

					if ( $updated_count > 0 ) {
						add_settings_error(
							'photo_competition_members',
							'bulk_deactivated',
							sprintf(
								/* translators: %d: number of deactivated members */
								_n(
									'%d member deactivated successfully.',
									'%d members deactivated successfully.',
									$updated_count,
									'photo-competition-manager'
								),
								$updated_count
							),
							'updated'
						);
					}
				} elseif ( 'bulk_update_grade' === $action ) {
					$new_grade = isset( $_POST['bulk_grade'] ) ? sanitize_text_field( wp_unslash( $_POST['bulk_grade'] ) ) : '';

					if ( empty( $new_grade ) ) {
						add_settings_error(
							'photo_competition_members',
							'no_grade_selected',
							__( 'Please select a grade.', 'photo-competition-manager' ),
							'error'
						);
					} else {
						foreach ( $member_ids as $member_id ) {
							$result = $this->members->update( $member_id, array( 'grade' => $new_grade ) );
							if ( is_wp_error( $result ) ) {
								++$failed_count;
							} else {
								++$updated_count;
							}
						}

						if ( $updated_count > 0 ) {
							add_settings_error(
								'photo_competition_members',
								'bulk_grade_updated',
								sprintf(
									/* translators: %d: number of members with updated grade */
									_n(
										'%d member grade updated successfully.',
										'%d member grades updated successfully.',
										$updated_count,
										'photo-competition-manager'
									),
									$updated_count
								),
								'updated'
							);
						}
					}
				}

				if ( $failed_count > 0 ) {
					add_settings_error(
						'photo_competition_members',
						'bulk_partial_failure',
						sprintf(
							/* translators: %d: number of failed updates */
							_n(
								'%d member could not be updated.',
								'%d members could not be updated.',
								$failed_count,
								'photo-competition-manager'
							),
							$failed_count
						),
						'error'
					);
				}
			}

			$this->redirect_with_settings_errors( $this->members_url() );
		}
	}

	/**
	 * Render members list.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( 'photo_competition_members' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view.
		$member_action = isset( $_GET['member_action'] ) ? sanitize_text_field( wp_unslash( $_GET['member_action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view.
		$member_id = isset( $_GET['member'] ) ? absint( wp_unslash( $_GET['member'] ) ) : 0;
		$current   = null;

		if ( 'edit' === $member_action && $member_id ) {
			$current = $this->members->find( $member_id );
		}

		// Get filter parameters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter input for list table.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter input for list table.
		$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter input for list table.
		$grade_filter = isset( $_GET['grade'] ) ? sanitize_text_field( wp_unslash( $_GET['grade'] ) ) : '';

		$all_members = $this->members->all( 10000, false );

		// Apply filters.
		$members = array_filter(
			$all_members,
			function ( $member ) use ( $search, $status_filter, $grade_filter ) {
				// Search filter (name or email).
				if ( ! empty( $search ) ) {
					$search_lower = strtolower( $search );
					$name_match   = false !== stripos( $member->name, $search );
					$email_match  = false !== stripos( $member->email, $search );
					if ( ! $name_match && ! $email_match ) {
						return false;
					}
				}

				// Status filter.
				if ( '' !== $status_filter ) {
					if ( 'active' === $status_filter && ! $member->active ) {
						return false;
					}
					if ( 'inactive' === $status_filter && $member->active ) {
						return false;
					}
				}

				// Grade filter.
				if ( ! empty( $grade_filter ) && $member->grade !== $grade_filter ) {
					return false;
				}

				return true;
			}
		);

		// Find currently active competition for per-member email action.
		$active_competition = $this->competitions->find_current_active();

		// Get grade options for label lookups.
		$grade_options = $this->get_grade_options();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Members', 'photo-competition-manager' ) . '</h1>';

		// Show upload status with toggle button for active competition.
		if ( $active_competition && $this->competitions->is_open( $active_competition ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_uploads_status_notice( $active_competition );
		}

		if ( 'edit' === $member_action ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_member_edit_form( $current );
		}

		// Display members list first (unless in edit mode).
		if ( 'edit' !== $member_action ) {
			// Display filter form.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_filters( $search, $status_filter, $grade_filter, $grade_options );

			if ( empty( $members ) ) {
				$has_filters = ! empty( $search ) || ! empty( $status_filter ) || ! empty( $grade_filter );
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
				echo $this->render_template( 'admin/members/empty-state.php', array( 'has_filters' => $has_filters ) );
			} else {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
				echo $this->render_members_table( $members, $all_members, $active_competition, $grade_options );
			}

			// Show create and import forms after the list.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_member_create_form();
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_member_import_form();
		}

		echo '</div>';
	}

	/**
	 * Render the upload-status notice bar for the active competition.
	 *
	 * @param  object $competition Active competition.
	 * @return string
	 */
	private function render_uploads_status_notice( object $competition ): string {
		$comp_settings  = Competition_Settings::parse( $competition->settings ?? '' );
		$uploads_closed = ! empty( $comp_settings['upload']['uploads_closed'] );

		$toggle_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'photo-competition-manager',
					'action'      => 'toggle_uploads',
					'competition' => (int) $competition->id,
					'ref_page'    => 'members',
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_toggle_uploads_' . (int) $competition->id
		);

		$notice_class = $uploads_closed ? 'notice-warning' : 'notice-info';
		$status_text  = $uploads_closed
			? __( 'Uploads are closed', 'photo-competition-manager' )
			: __( 'Uploads are open', 'photo-competition-manager' );
		$button_text  = $uploads_closed
			? __( 'Open Uploads', 'photo-competition-manager' )
			: __( 'Close Uploads', 'photo-competition-manager' );

		return $this->render_template(
			'admin/members/uploads-status-notice.php',
			array(
				'notice_class' => $notice_class,
				'title'        => $competition->title,
				'status_text'  => $status_text,
				'toggle_url'   => $toggle_url,
				'button_text'  => $button_text,
			)
		);
	}

	/**
	 * Render the search/status/grade filter form.
	 *
	 * @param  string               $search        Current search term.
	 * @param  string               $status_filter Current status filter.
	 * @param  string               $grade_filter  Current grade filter.
	 * @param  array<string,string> $grade_options Grade slug => label options.
	 * @return string
	 */
	private function render_filters( string $search, string $status_filter, string $grade_filter, array $grade_options ): string {
		return $this->render_template(
			'admin/members/filters.php',
			array(
				'search'        => $search,
				'status_filter' => $status_filter,
				'grade_filter'  => $grade_filter,
				'grade_options' => $grade_options,
			)
		);
	}

	/**
	 * Render the members list table with its wrapping bulk-actions form.
	 *
	 * @param  array<int,object>    $members            Filtered members to display.
	 * @param  array<int,object>    $all_members        All members (for the results count).
	 * @param  object|null          $active_competition Active competition, if any.
	 * @param  array<string,string> $grade_options      Grade slug => label options.
	 * @return string
	 */
	private function render_members_table( array $members, array $all_members, ?object $active_competition, array $grade_options ): string {
		$total_count    = count( $all_members );
		$filtered_count = count( $members );

		$rows = array();

		foreach ( $members as $member ) {
			$edit_link    = add_query_arg(
				array(
					'page'          => 'photo-competition-manager-members',
					'member_action' => 'edit',
					'member'        => (int) $member->id,
				),
				admin_url( 'admin.php' )
			);
			$status_label = $member->active ? __( 'Active', 'photo-competition-manager' ) : __( 'Inactive', 'photo-competition-manager' );
			$grade_label  = $grade_options[ $member->grade ] ?? $member->grade;

			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'   => 'photo-competition-manager-members',
						'action' => 'delete_member',
						'member' => (int) $member->id,
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_delete_member_' . (int) $member->id
			);

			$show_send_email = (bool) ( $active_competition && $this->competitions->is_open( $active_competition ) && $member->active && ! empty( $member->email ) );
			$send_url        = '';
			$upload_url      = '';

			// Add "Send Upload Email" if we have an open competition and active member with email.
			if ( $show_send_email ) {
				$send_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager-members',
							'action'      => 'send_member_upload_email',
							'member'      => (int) $member->id,
							'competition' => (int) $active_competition->id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_send_member_email_' . (int) $member->id . '_' . (int) $active_competition->id
				);

				// Upload page link for copying/sharing.
				$upload_url = $this->get_member_upload_url( (int) $member->id, $active_competition );
			}

			$rows[] = (object) array(
				'member_id'       => (int) $member->id,
				'name'            => $member->name,
				'email'           => $member->email,
				'grade_label'     => $grade_label,
				'status_label'    => $status_label,
				'joined'          => $member->created_at,
				'edit_link'       => $edit_link,
				'delete_url'      => $delete_url,
				'show_send_email' => $show_send_email,
				'send_url'        => $send_url,
				'upload_url'      => $upload_url,
			);
		}

		return $this->render_template(
			'admin/members/members-table.php',
			array(
				'show_count'     => $filtered_count < $total_count,
				'filtered_count' => $filtered_count,
				'total_count'    => $total_count,
				'grade_options'  => $grade_options,
				'rows'           => $rows,
			)
		);
	}

	/**
	 * Render create member form.
	 *
	 * @return string
	 */
	private function render_member_create_form(): string {
		return $this->render_template(
			'admin/members/create-form.php',
			array( 'grade_options' => $this->get_grade_options() )
		);
	}

	/**
	 * Render edit member form.
	 *
	 * @param  object|null $member Member row.
	 * @return string
	 */
	private function render_member_edit_form( $member ): string {
		if ( ! $member ) {
			return $this->render_template(
				'admin/members/edit-form-not-found.php',
				array( 'members_url' => $this->members_url() )
			);
		}

		return $this->render_template(
			'admin/members/edit-form.php',
			array(
				'member'        => $member,
				'grade_options' => $this->get_grade_options(),
				'members_url'   => $this->members_url(),
			)
		);
	}

	/**
	 * Retrieve grade options from default settings.
	 *
	 * @return array<string, string>
	 */
	private function get_grade_options(): array {
		$settings = Competition_Settings::global_settings();
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

		if ( empty( $upload_page_url ) ) {
			$upload_page_url = Competition_Settings::find_page_url_with_shortcode( 'competition_upload' );
		}

		if ( empty( $upload_page_url ) ) {
			return '';
		}

		$upload_page_url = apply_filters( 'photo_competition_manager_upload_page_url', $upload_page_url, $competition );

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
	 * Render member import form.
	 *
	 * @return string
	 */
	private function render_member_import_form(): string {
		return $this->render_template( 'admin/members/import-form.php' );
	}
}
