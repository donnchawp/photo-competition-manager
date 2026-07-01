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

			$token_repo = new Upload_Token_Repository();
			$result     = $token_repo->send_upload_link_for_member(
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
			$comp_settings  = Competition_Settings::parse( $active_competition->settings ?? '' );
			$uploads_closed = ! empty( $comp_settings['upload']['uploads_closed'] );

			$toggle_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager',
						'action'      => 'toggle_uploads',
						'competition' => (int) $active_competition->id,
						'ref_page'    => 'members',
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_toggle_uploads_' . (int) $active_competition->id
			);

			$notice_class = $uploads_closed ? 'notice-warning' : 'notice-info';
			$status_text  = $uploads_closed
				? __( 'Uploads are closed', 'photo-competition-manager' )
				: __( 'Uploads are open', 'photo-competition-manager' );
			$button_text  = $uploads_closed
				? __( 'Open Uploads', 'photo-competition-manager' )
				: __( 'Close Uploads', 'photo-competition-manager' );

			echo '<div class="notice ' . esc_attr( $notice_class ) . '" style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;">';
			echo '<p style="margin:0;"><strong>' . esc_html( $active_competition->title ) . ':</strong> ' . esc_html( $status_text ) . '</p>';
			echo '<a href="' . esc_url( $toggle_url ) . '" class="button">' . esc_html( $button_text ) . '</a>';
			echo '</div>';
		}

		if ( 'edit' === $member_action ) {
			$this->render_member_edit_form( $current );
		}

		// Display members list first (unless in edit mode).
		if ( 'edit' !== $member_action ) {
			// Display filter form.
			echo '<form method="get" class="photo-comp-filters" style="margin-bottom: 15px;">';
			echo '<input type="hidden" name="page" value="photo-competition-manager-members" />';

			echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search members...', 'photo-competition-manager' ) . '" style="margin-right: 10px;" />';

			echo '<select name="status" style="margin-right: 10px;">';
			echo '<option value="">' . esc_html__( 'All Statuses', 'photo-competition-manager' ) . '</option>';
			echo '<option value="active"' . selected( $status_filter, 'active', false ) . '>' . esc_html__( 'Active', 'photo-competition-manager' ) . '</option>';
			echo '<option value="inactive"' . selected( $status_filter, 'inactive', false ) . '>' . esc_html__( 'Inactive', 'photo-competition-manager' ) . '</option>';
			echo '</select>';

			echo '<select name="grade" style="margin-right: 10px;">';
			echo '<option value="">' . esc_html__( 'All Grades', 'photo-competition-manager' ) . '</option>';
			foreach ( $grade_options as $grade_value => $grade_label ) {
				echo '<option value="' . esc_attr( $grade_value ) . '"' . selected( $grade_filter, $grade_value, false ) . '>' . esc_html( $grade_label ) . '</option>';
			}
			echo '</select>';

			echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'photo-competition-manager' ) . '</button>';

			if ( ! empty( $search ) || ! empty( $status_filter ) || ! empty( $grade_filter ) ) {
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager-members' ) ) . '" class="button">' . esc_html__( 'Clear Filters', 'photo-competition-manager' ) . '</a>';
			}

			echo '</form>';

			if ( empty( $members ) ) {
				if ( ! empty( $search ) || ! empty( $status_filter ) || ! empty( $grade_filter ) ) {
					echo '<p>' . esc_html__( 'No members found matching the selected filters.', 'photo-competition-manager' ) . '</p>';
				} else {
					echo '<p>' . esc_html__( 'No members recorded yet. Import or create members to get started.', 'photo-competition-manager' ) . '</p>';
				}
			} else {
				// Show results count.
				$total_count    = count( $all_members );
				$filtered_count = count( $members );
				if ( $filtered_count < $total_count ) {
					echo '<p class="description" style="margin-bottom: 10px;">' . esc_html(
						sprintf(
							/* translators: 1: filtered count, 2: total count */
							__( 'Showing %1$d of %2$d members', 'photo-competition-manager' ),
							$filtered_count,
							$total_count
						)
					) . '</p>';
				}

				// Bulk actions form.
				echo '<form method="post" id="bulk-members-form">';
				wp_nonce_field( 'photo_competition_bulk_members', '_wpnonce' );

				echo '<div class="tablenav top">';
				echo '<div class="alignleft actions bulkactions">';
				echo '<select name="action" id="bulk-action-selector-top">';
				echo '<option value="-1">' . esc_html__( 'Bulk Actions', 'photo-competition-manager' ) . '</option>';
				echo '<option value="bulk_activate">' . esc_html__( 'Activate', 'photo-competition-manager' ) . '</option>';
				echo '<option value="bulk_deactivate">' . esc_html__( 'Deactivate', 'photo-competition-manager' ) . '</option>';
				echo '<option value="bulk_update_grade">' . esc_html__( 'Update Grade', 'photo-competition-manager' ) . '</option>';
				echo '</select>';

				echo ' <select name="bulk_grade" id="bulk-grade-selector" style="display:none;">';
				echo '<option value="">' . esc_html__( 'Select Grade...', 'photo-competition-manager' ) . '</option>';
				foreach ( $grade_options as $grade_value => $grade_label ) {
					echo '<option value="' . esc_attr( $grade_value ) . '">' . esc_html( $grade_label ) . '</option>';
				}
				echo '</select>';

				echo ' <button type="submit" class="button action">' . esc_html__( 'Apply', 'photo-competition-manager' ) . '</button>';
				echo '</div>';
				echo '</div>';

				echo '<table class="widefat striped">';
				echo '<thead><tr>';
				echo '<td class="check-column"><input type="checkbox" id="cb-select-all-1" /></td>';
				echo '<th>' . esc_html__( 'Name', 'photo-competition-manager' ) . '</th>';
				echo '<th>' . esc_html__( 'Email', 'photo-competition-manager' ) . '</th>';
				echo '<th>' . esc_html__( 'Grade', 'photo-competition-manager' ) . '</th>';
				echo '<th>' . esc_html__( 'Status', 'photo-competition-manager' ) . '</th>';
				echo '<th>' . esc_html__( 'Joined', 'photo-competition-manager' ) . '</th>';
				echo '<th>' . esc_html__( 'Actions', 'photo-competition-manager' ) . '</th>';
				echo '</tr></thead>';
				echo '<tbody>';

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

					echo '<tr>';
					echo '<th scope="row" class="check-column"><input type="checkbox" name="member_ids[]" value="' . esc_attr( $member->id ) . '" /></th>';
					echo '<td>' . esc_html( $member->name ) . '</td>';
					echo '<td>' . esc_html( $member->email ) . '</td>';
					echo '<td>' . esc_html( $grade_label ) . '</td>';
					echo '<td>' . esc_html( $status_label ) . '</td>';
					echo '<td>' . esc_html( $member->created_at ) . '</td>';

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

					$actions = array(
						sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit', 'photo-competition-manager' ) ),
						sprintf(
							'<a href="%s" class="delete-member-link" data-member-name="%s">%s</a>',
							esc_url( $delete_url ),
							esc_attr( $member->name ),
							esc_html__( 'Delete', 'photo-competition-manager' )
						),
					);

					// Add "Send Upload Email" if we have an open competition and active member with email.
					if ( $active_competition && $this->competitions->is_open( $active_competition ) && $member->active && ! empty( $member->email ) ) {
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

						$actions[] = sprintf(
							'<a href="%s">%s</a>',
							esc_url( $send_url ),
							esc_html__( 'Send Upload Email', 'photo-competition-manager' )
						);

						// Add upload page link for copying/sharing.
						$upload_url = $this->get_member_upload_url( (int) $member->id, $active_competition );
						if ( ! empty( $upload_url ) ) {
							$actions[] = sprintf(
								'<a href="%s" target="_blank" title="%s">%s</a>',
								esc_url( $upload_url ),
								esc_attr__( 'Copy this link to share with the member', 'photo-competition-manager' ),
								esc_html__( 'Upload Link', 'photo-competition-manager' )
							);
						}
					} else {
						$actions[] = '<span class="button button-small" style="opacity:.5;cursor:not-allowed;" title="' . esc_attr__( 'Requires an active competition and active member with email', 'photo-competition-manager' ) . '">' . esc_html__( 'Send Upload Email', 'photo-competition-manager' ) . '</span>';
					}

					echo '<td>' . wp_kses_post( implode( ' | ', $actions ) ) . '</td>';
					echo '</tr>';
				}

				echo '</tbody>';
				echo '</table>';
				echo '</form>';
			}

			// Show create and import forms after the list.
			$this->render_member_create_form();
			$this->render_member_import_form();
		}

		echo '</div>';
	}

	/**
	 * Render create member form.
	 *
	 * @return void
	 */
	private function render_member_create_form(): void {
		echo '<form method="post" class="card" style="max-width: 520px; margin-bottom: 24px; padding: 16px;">';
		echo '<h2>' . esc_html__( 'Add Member', 'photo-competition-manager' ) . '</h2>';

		wp_nonce_field( 'photo_competition_member_create', 'photo_competition_member_nonce' );

		echo '<input type="hidden" name="photo_competition_action" value="create_member" />';

		echo '<p>';
		echo '<label for="member_name">' . esc_html__( 'Name', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="member_name" name="member_name" class="regular-text" required />';
		echo '</p>';

		echo '<p>';
		echo '<label for="member_email">' . esc_html__( 'Email', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="email" id="member_email" name="member_email" class="regular-text" required />';
		echo '</p>';

		echo '<p>';
		echo '<label for="member_grade">' . esc_html__( 'Grade', 'photo-competition-manager' ) . '</label><br />';
		echo '<select id="member_grade" name="member_grade" class="regular-text" required>';
		echo '<option value="">' . esc_html__( 'Select grade', 'photo-competition-manager' ) . '</option>';
		foreach ( $this->get_grade_options() as $grade_slug => $grade_label ) {
			echo '<option value="' . esc_attr( $grade_slug ) . '">' . esc_html( $grade_label ) . '</option>';
		}
		echo '</select>';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="member_active" value="1" checked /> ';
		echo esc_html__( 'Active', 'photo-competition-manager' );
		echo '</label>';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="member_committee" value="1" /> ';
		echo esc_html__( 'Committee Member', 'photo-competition-manager' );
		echo '</label>';
		echo '</p>';

		submit_button( __( 'Add Member', 'photo-competition-manager' ) );

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
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Member not found. Return to the list to continue.', 'photo-competition-manager' ) . '</p></div>';
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( $this->members_url() ),
				esc_html__( 'Back to members', 'photo-competition-manager' )
			);
			return;
		}

		echo '<form method="post" class="card" style="max-width: 520px; margin-bottom: 24px; padding: 16px;">';
		echo '<h2>' . esc_html__( 'Edit Member', 'photo-competition-manager' ) . '</h2>';

		wp_nonce_field( 'photo_competition_member_update_' . (int) $member->id, 'photo_competition_member_nonce' );

		echo '<input type="hidden" name="photo_competition_action" value="update_member" />';
		echo '<input type="hidden" name="member_id" value="' . esc_attr( $member->id ) . '" />';

		echo '<p>';
		echo '<label for="member_name">' . esc_html__( 'Name', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="member_name" name="member_name" class="regular-text" required value="' . esc_attr( $member->name ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="member_email">' . esc_html__( 'Email', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="email" id="member_email" name="member_email" class="regular-text" required value="' . esc_attr( $member->email ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="member_grade">' . esc_html__( 'Grade', 'photo-competition-manager' ) . '</label><br />';
		echo '<select id="member_grade" name="member_grade" class="regular-text" required>';
		foreach ( $this->get_grade_options() as $grade_slug => $grade_label ) {
			echo '<option value="' . esc_attr( $grade_slug ) . '"' . selected( $member->grade, $grade_slug, false ) . '>' . esc_html( $grade_label ) . '</option>';
		}
		echo '</select>';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="member_active" value="1"' . checked( (bool) $member->active, true, false ) . ' /> ';
		echo esc_html__( 'Active', 'photo-competition-manager' );
		echo '</label>';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="member_committee" value="1"' . checked( (bool) ( $member->committee ?? false ), true, false ) . ' /> ';
		echo esc_html__( 'Committee Member', 'photo-competition-manager' );
		echo '</label>';
		echo '</p>';

		submit_button( __( 'Update Member', 'photo-competition-manager' ) );

		echo '</form>';

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( $this->members_url() ),
			esc_html__( 'Back to members', 'photo-competition-manager' )
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
	 * @return void
	 */
	private function render_member_import_form(): void {
		$sample_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'photo-competition-manager-members',
					'action' => 'download_sample_csv',
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_download_sample'
		);

		echo '<div style="margin-top: 30px;">';
		echo '<h2>' . esc_html__( 'Import Members from CSV', 'photo-competition-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Upload a CSV file to import multiple members at once. Existing members (matched by email) will be updated.', 'photo-competition-manager' ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="card" style="max-width: 600px; padding: 16px; margin-top: 10px;">';
		wp_nonce_field( 'photo_competition_import_members', 'photo_competition_import_nonce' );
		echo '<input type="hidden" name="photo_competition_action" value="import_members_csv" />';
		echo '<input type="hidden" name="page" value="photo-competition-manager-members" />';

		echo '<p>';
		echo '<label for="csv_file">' . esc_html__( 'CSV File', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="file" id="csv_file" name="csv_file" accept=".csv,.txt" required />';
		echo '</p>';

		echo '<p class="description">';
		echo esc_html__( 'CSV format: name,email,grade,active (active: 1=active, 0=inactive)', 'photo-competition-manager' );
		echo '<br />';
		echo '<a href="' . esc_url( $sample_url ) . '">' . esc_html__( 'Download sample CSV template', 'photo-competition-manager' ) . '</a>';
		echo '</p>';

		submit_button( __( 'Import Members', 'photo-competition-manager' ), 'secondary', 'submit', false );

		echo '</form>';
		echo '</div>';
	}
}
