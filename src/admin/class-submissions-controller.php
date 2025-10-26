<?php
/**
 * Submissions controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

use PhotoCompetitionManager\Admin\Traits\Date_Formatting;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Upload_Handler;

/**
 * Manage submissions viewing page.
 *
 * @since 0.1.0
 */
class Submissions_Controller {

	use Date_Formatting;

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
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes;

	/**
	 * Upload handler service.
	 *
	 * @var Upload_Handler
	 */
	private $upload_handler;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository $competitions   Competitions repository.
	 * @param Members_Repository      $members        Members repository.
	 * @param Images_Repository       $images         Images repository.
	 * @param Votes_Repository        $votes          Votes repository.
	 * @param Upload_Handler|null     $upload_handler Upload handler service.
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Members_Repository $members,
		Images_Repository $images,
		Votes_Repository $votes,
		?Upload_Handler $upload_handler = null
	) {
		$this->competitions   = $competitions;
		$this->members        = $members;
		$this->images         = $images;
		$this->votes          = $votes;
		$this->upload_handler = $upload_handler ?? new Upload_Handler( $competitions, $images, $members );
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

		if ( isset( $_POST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; mutations require explicit nonce checks below.
			$action = sanitize_key( wp_unslash( $_POST['action'] ) );
		}

		if ( 'regenerate_numbers' === $action ) {
			$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

			check_admin_referer( 'photo_competition_regenerate_numbers_' . $competition_id );

			if ( ! $competition_id ) {
				add_settings_error(
					'photo_competition_submissions',
					'invalid_competition',
					__( 'Invalid competition.', 'photo-competition-manager' ),
					'error'
				);
				wp_safe_redirect( admin_url( 'admin.php?page=photo-competition-manager-submissions' ) );
				exit;
			}

			$result = $this->images->regenerate_member_numbers( $competition_id );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_submissions',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'photo_competition_submissions',
					'numbers_regenerated',
					__( 'Random numbers regenerated successfully.', 'photo-competition-manager' ),
					'updated'
				);
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => 'photo-competition-manager-submissions',
						'competition_id' => $competition_id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'delete_original_images' === $action ) {
			$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

			check_admin_referer( 'photo_competition_delete_originals_' . $competition_id );

			if ( ! $competition_id ) {
				add_settings_error(
					'photo_competition_submissions',
					'invalid_competition',
					__( 'Invalid competition.', 'photo-competition-manager' ),
					'error'
				);
				wp_safe_redirect( admin_url( 'admin.php?page=photo-competition-manager-submissions' ) );
				exit;
			}

			// Get all original attachment IDs.
			$attachment_ids = $this->images->get_original_attachment_ids( $competition_id );

			if ( empty( $attachment_ids ) ) {
				add_settings_error(
					'photo_competition_submissions',
					'no_originals',
					__( 'No original images found to delete.', 'photo-competition-manager' ),
					'updated'
				);
			} else {
				// Delete all original attachments.
				$deleted_count = 0;
				foreach ( $attachment_ids as $attachment_id ) {
					if ( wp_delete_attachment( $attachment_id, true ) ) {
						++$deleted_count;
					}
				}

				// Clear the attachment IDs from the database.
				$result = $this->images->clear_original_attachment_ids( $competition_id );

				if ( is_wp_error( $result ) ) {
					add_settings_error(
						'photo_competition_submissions',
						$result->get_error_code(),
						$result->get_error_message(),
						'error'
					);
				} else {
					add_settings_error(
						'photo_competition_submissions',
						'originals_deleted',
						sprintf(
						/* translators: %d: number of deleted images */
							__( '%d original images deleted successfully.', 'photo-competition-manager' ),
							$deleted_count
						),
						'updated'
					);
				}
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => 'photo-competition-manager-submissions',
						'competition_id' => $competition_id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'bulk_delete_submissions' === $action ) {
			$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

			check_admin_referer( 'photo_competition_bulk_delete_' . $competition_id );

			if ( ! $competition_id ) {
				add_settings_error(
					'photo_competition_submissions',
					'invalid_competition',
					__( 'Invalid competition.', 'photo-competition-manager' ),
					'error'
				);
				wp_safe_redirect( admin_url( 'admin.php?page=photo-competition-manager-submissions' ) );
				exit;
			}

			$image_ids = isset( $_POST['image_ids'] ) && is_array( $_POST['image_ids'] )
				? array_map( 'absint', wp_unslash( $_POST['image_ids'] ) )
				: array();

			if ( empty( $image_ids ) ) {
				add_settings_error(
					'photo_competition_submissions',
					'no_images_selected',
					__( 'No images selected for deletion.', 'photo-competition-manager' ),
					'error'
				);
			} else {
				$deleted_count = 0;
				$failed_count  = 0;

				foreach ( $image_ids as $image_id ) {
					// Get image details to delete files.
					$image = $this->images->find( $image_id );
					if ( ! $image || (int) $image->competition_id !== $competition_id ) {
						++$failed_count;
						continue;
					}

					// Delete votes associated with this image.
					$this->votes->delete_by_image( $image_id );

					// Delete the image record from database.
					$result = $this->images->delete( $image_id );

					if ( is_wp_error( $result ) ) {
						++$failed_count;
						continue;
					}

					// Delete original attachment if it exists.
					if ( ! empty( $image->original_attachment_id ) ) {
						wp_delete_attachment( $image->original_attachment_id, true );
					}

					// Delete physical files (slideshow and thumbnail).
					$this->delete_submission_files( $image );

					++$deleted_count;
				}

				if ( $deleted_count > 0 ) {
					add_settings_error(
						'photo_competition_submissions',
						'bulk_delete_success',
						sprintf(
							/* translators: %d: number of deleted images */
							_n(
								'%d submission deleted successfully.',
								'%d submissions deleted successfully.',
								$deleted_count,
								'photo-competition-manager'
							),
							$deleted_count
						),
						'updated'
					);
				}

				if ( $failed_count > 0 ) {
					add_settings_error(
						'photo_competition_submissions',
						'bulk_delete_partial_failure',
						sprintf(
							/* translators: %d: number of failed deletions */
							_n(
								'%d submission could not be deleted.',
								'%d submissions could not be deleted.',
								$failed_count,
								'photo-competition-manager'
							),
							$failed_count
						),
						'error'
					);
				}
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => 'photo-competition-manager-submissions',
						'competition_id' => $competition_id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'admin_upload' === $action ) {
			$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

			check_admin_referer( 'photo_competition_admin_upload_' . $competition_id );

			if ( ! $competition_id ) {
				add_settings_error(
					'photo_competition_submissions',
					'invalid_competition',
					__( 'Invalid competition.', 'photo-competition-manager' ),
					'error'
				);
				wp_safe_redirect( admin_url( 'admin.php?page=photo-competition-manager-submissions' ) );
				exit;
			}

			$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;
			$category  = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';

			if ( ! $member_id || ! $category ) {
				add_settings_error(
					'photo_competition_submissions',
					'missing_data',
					__( 'Please select a member and category.', 'photo-competition-manager' ),
					'error'
				);
			} elseif ( empty( $_FILES['image_file'] ) || ! isset( $_FILES['image_file']['tmp_name'] ) || ! is_uploaded_file( sanitize_text_field( wp_unslash( $_FILES['image_file']['tmp_name'] ) ) ) ) {
				add_settings_error(
					'photo_competition_submissions',
					'missing_file',
					__( 'Please select an image file to upload.', 'photo-competition-manager' ),
					'error'
				);
			} else {
				// Get competition to temporarily bypass date/status checks for admins.
				$competition = $this->competitions->find( $competition_id, true );

				if ( ! $competition ) {
					add_settings_error(
						'photo_competition_submissions',
						'invalid_competition',
						__( 'Competition not found.', 'photo-competition-manager' ),
						'error'
					);
				} else {
					// Store original status and dates.
					$original_status     = $competition->status;
					$original_open_date  = $competition->open_date;
					$original_close_date = $competition->close_date;

					// Temporarily set competition to active with open dates to bypass upload handler checks.
					$temp_settings                             = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings );
					$temp_settings['upload']['uploads_closed'] = false;

					$this->competitions->update(
						$competition_id,
						array(
							'status'     => 'active',
							'open_date'  => gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ),
							'close_date' => gmdate( 'Y-m-d H:i:s', strtotime( '+1 hour' ) ),
							'settings'   => $temp_settings,
						)
					);

					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File array validated by Upload_Handler.
					$result = $this->upload_handler->handle_upload( $competition_id, $member_id, $category, wp_unslash( $_FILES['image_file'] ) );

					// Restore original competition settings.
					$this->competitions->update(
						$competition_id,
						array(
							'status'     => $original_status,
							'open_date'  => $original_open_date,
							'close_date' => $original_close_date,
							'settings'   => json_decode( $competition->settings, true ),
						)
					);

					if ( is_wp_error( $result ) ) {
						add_settings_error(
							'photo_competition_submissions',
							$result->get_error_code(),
							$result->get_error_message(),
							'error'
						);
					} else {
						add_settings_error(
							'photo_competition_submissions',
							'upload_success',
							__( 'Image uploaded successfully.', 'photo-competition-manager' ),
							'updated'
						);
					}
				}
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => 'photo-competition-manager-submissions',
						'competition_id' => $competition_id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Render submissions viewer.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( 'photo_competition_submissions' );

		$competitions = $this->competitions->all( 200, true );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Submissions', 'photo-competition-manager' ) . '</h1>';

		static $styles_output = false;
		if ( ! $styles_output ) {
			echo '<style id="photo-comp-submissions-css">.photo-comp-thumbnail img{max-width:120px;height:auto;border:1px solid #ccd0d4;padding:2px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,0.08);} .photo-comp-thumbnail{width:140px;}</style>';
			$styles_output = true;
		}

		if ( empty( $competitions ) ) {
			echo '<p>' . esc_html__( 'No competitions available yet. Create a competition first.', 'photo-competition-manager' ) . '</p>';
			echo '</div>';
			return;
		}

		$competition_lookup = array();
		foreach ( $competitions as $competition ) {
			$competition_lookup[ (int) $competition->id ] = $competition;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter input for list table.
		$competition_id = isset( $_GET['competition_id'] ) ? absint( wp_unslash( $_GET['competition_id'] ) ) : 0;
		if ( ! $competition_id || ! isset( $competition_lookup[ $competition_id ] ) ) {
			$first          = reset( $competitions );
			$competition_id = $first ? (int) $first->id : 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter input for list table.
		$member_id = isset( $_GET['member_id'] ) ? absint( wp_unslash( $_GET['member_id'] ) ) : 0;

		$members    = $this->members->all( false );
		$member_map = array();
		foreach ( $members as $member ) {
			$member_map[ (int) $member->id ] = $member;
		}

		$submissions = array();
		$scores_data = array();
		if ( $competition_id ) {
			$member_filter = $member_id > 0 ? $member_id : null;
			$submissions   = $this->images->find_by_competition( $competition_id, null, $member_filter );

			// Get average scores and vote counts for all submissions.
			$scores_data = $this->votes->calculate_averages( $competition_id );
		}

		$selected_competition = $competition_id && isset( $competition_lookup[ $competition_id ] ) ? $competition_lookup[ $competition_id ] : null;

		echo '<form method="get" class="photo-comp-filters">';
		echo '<input type="hidden" name="page" value="photo-competition-manager-submissions" />';
		echo '<label for="competition_id" class="screen-reader-text">' . esc_html__( 'Competition', 'photo-competition-manager' ) . '</label>';
		echo '<select name="competition_id" id="competition_id">';
		foreach ( $competitions as $competition ) {
			$label = $competition->title;
			if ( ! empty( $competition->deleted_at ) || 'archived' === $competition->status ) {
				$label .= ' ' . esc_html__( '(Archived)', 'photo-competition-manager' );
			}

			printf(
				'<option value="%1$d" %3$s>%2$s</option>',
				(int) $competition->id,
				esc_html( $label ),
				selected( $competition_id, $competition->id, false )
			);
		}
		echo '</select> ';

		echo '<label for="member_id" class="screen-reader-text">' . esc_html__( 'Member', 'photo-competition-manager' ) . '</label>';
		echo '<select name="member_id" id="member_id">';
		echo '<option value="0">' . esc_html__( 'All Members', 'photo-competition-manager' ) . '</option>';
		foreach ( $members as $member ) {
			printf(
				'<option value="%1$d" %3$s>%2$s</option>',
				(int) $member->id,
				esc_html( $member->name ),
				selected( $member_id, $member->id, false )
			);
		}
		echo '</select> ';

		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'photo-competition-manager' ) . '</button>';
		echo '</form>';

		if ( $selected_competition ) {
			printf(
				'<h2>%s</h2>',
				esc_html( $selected_competition->title )
			);

			// Add regenerate numbers button.
			echo '<form method="post" style="margin-bottom: 15px; display: inline-block; margin-right: 10px;">';
			wp_nonce_field( 'photo_competition_regenerate_numbers_' . $competition_id, '_wpnonce' );
			echo '<input type="hidden" name="action" value="regenerate_numbers" />';
			echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition_id ) . '" />';
			echo '<button type="submit" class="button" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to regenerate random numbers? Each member will still have the same number across all their images, but the numbers will be reassigned.', 'photo-competition-manager' ) ) . '\');">';
			echo esc_html__( 'Regenerate Random Numbers', 'photo-competition-manager' );
			echo '</button>';
			echo ' <span class="description">' . esc_html__( 'Reassign random numbers to members in this competition (each member keeps one consistent number).', 'photo-competition-manager' ) . '</span>';
			echo '</form>';

			// Add delete original images button.
			echo '<form method="post" style="margin-bottom: 15px; display: inline-block;">';
			wp_nonce_field( 'photo_competition_delete_originals_' . $competition_id, '_wpnonce' );
			echo '<input type="hidden" name="action" value="delete_original_images" />';
			echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition_id ) . '" />';
			echo '<button type="submit" class="button" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete all original images from the media library for this competition? This will keep thumbnails and slideshow images, but remove the high-resolution originals to save space. This action cannot be undone.', 'photo-competition-manager' ) ) . '\');">';
			echo esc_html__( 'Delete Original Images', 'photo-competition-manager' );
			echo '</button>';
			echo ' <span class="description">' . esc_html__( 'Remove high-resolution originals from media library (keeps thumbnails and slideshow images).', 'photo-competition-manager' ) . '</span>';
			echo '</form>';

			// Add admin upload form.
			echo '<div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 20px;">';
			echo '<h3 style="margin-top: 0;">' . esc_html__( 'Upload Image for Member', 'photo-competition-manager' ) . '</h3>';
			echo '<p class="description">' . esc_html__( 'Upload an image on behalf of a member. This bypasses competition date and status restrictions.', 'photo-competition-manager' ) . '</p>';
			echo '<form method="post" enctype="multipart/form-data" style="margin-top: 15px;">';
			wp_nonce_field( 'photo_competition_admin_upload_' . $competition_id, '_wpnonce' );
			echo '<input type="hidden" name="action" value="admin_upload" />';
			echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition_id ) . '" />';

			// Get competition settings for categories.
			$settings   = json_decode( $selected_competition->settings, true );
			$categories = array();
			if ( is_array( $settings ) ) {
				$categories = \PhotoCompetitionManager\Support\Competition_Settings::get_categories( $settings );
			}

			echo '<table class="form-table"><tbody>';

			// Member selection.
			echo '<tr>';
			echo '<th scope="row"><label for="admin-upload-member">' . esc_html__( 'Member', 'photo-competition-manager' ) . '</label></th>';
			echo '<td>';
			echo '<select name="member_id" id="admin-upload-member" required>';
			echo '<option value="">' . esc_html__( 'Select a member...', 'photo-competition-manager' ) . '</option>';
			foreach ( $members as $member ) {
				printf(
					'<option value="%1$d">%2$s</option>',
					(int) $member->id,
					esc_html( $member->name )
				);
			}
			echo '</select>';
			echo '</td>';
			echo '</tr>';

			// Category selection.
			echo '<tr>';
			echo '<th scope="row"><label for="admin-upload-category">' . esc_html__( 'Category', 'photo-competition-manager' ) . '</label></th>';
			echo '<td>';
			echo '<select name="category" id="admin-upload-category" required>';
			echo '<option value="">' . esc_html__( 'Select a category...', 'photo-competition-manager' ) . '</option>';
			foreach ( $categories as $cat ) {
				printf(
					'<option value="%1$s">%2$s</option>',
					esc_attr( $cat['slug'] ),
					esc_html( $cat['label'] )
				);
			}
			echo '</select>';
			echo '</td>';
			echo '</tr>';

			// File upload.
			echo '<tr>';
			echo '<th scope="row"><label for="admin-upload-file">' . esc_html__( 'Image File', 'photo-competition-manager' ) . '</label></th>';
			echo '<td>';
			echo '<input type="file" name="image_file" id="admin-upload-file" accept="image/jpeg,image/jpg" required />';
			echo '<p class="description">' . esc_html__( 'Select a JPEG image to upload.', 'photo-competition-manager' ) . '</p>';
			echo '</td>';
			echo '</tr>';

			echo '</tbody></table>';

			echo '<p class="submit">';
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Upload Image', 'photo-competition-manager' ) . '</button>';
			echo '</p>';

			echo '</form>';
			echo '</div>';
		}

		// Add quota/status summary section.
		if ( $selected_competition && ! empty( $selected_competition->settings ) ) {
			$settings = json_decode( $selected_competition->settings, true );
			if ( is_array( $settings ) ) {
				$categories = \PhotoCompetitionManager\Support\Competition_Settings::get_categories( $settings );

				if ( ! empty( $categories ) ) {
					// Build submission counts by member and category.
					$member_counts = array();
					foreach ( $submissions as $submission ) {
						$mid = (int) $submission->member_id;
						$cat = (string) $submission->category;

						if ( ! isset( $member_counts[ $mid ] ) ) {
							$member_counts[ $mid ] = array();
						}
						if ( ! isset( $member_counts[ $mid ][ $cat ] ) ) {
							$member_counts[ $mid ][ $cat ] = 0;
						}
						++$member_counts[ $mid ][ $cat ];
					}

					// Build quota map.
					$quota_map = array();
					foreach ( $categories as $cat_config ) {
						$quota_map[ $cat_config['slug'] ] = array(
							'label' => $cat_config['label'],
							'quota' => $cat_config['quota'],
						);
					}

					echo '<div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 20px;">';
					echo '<h3 style="margin-top: 0;">' . esc_html__( 'Submission Status', 'photo-competition-manager' ) . '</h3>';
					echo '<p class="description">' . esc_html__( 'Overview of submissions per member across all categories.', 'photo-competition-manager' ) . '</p>';

					echo '<table class="widefat" style="margin-top: 10px;">';
					echo '<thead><tr>';
					echo '<th>' . esc_html__( 'Member', 'photo-competition-manager' ) . '</th>';
					foreach ( $categories as $cat_config ) {
						echo '<th>' . esc_html( $cat_config['label'] ) . '</th>';
					}
					echo '<th>' . esc_html__( 'Total', 'photo-competition-manager' ) . '</th>';
					echo '</tr></thead>';
					echo '<tbody>';

					// If filtering by member, show only that member.
					$members_to_show = $member_id > 0 ? array_filter( $members, fn( $m ) => (int) $m->id === $member_id ) : $members;

					foreach ( $members_to_show as $member ) {
						$mid         = (int) $member->id;
						$total_count = 0;

						echo '<tr>';
						echo '<td><strong>' . esc_html( $member->name ) . '</strong></td>';

						foreach ( $categories as $cat_config ) {
							$cat_slug     = $cat_config['slug'];
							$quota        = $cat_config['quota'];
							$current      = $member_counts[ $mid ][ $cat_slug ] ?? 0;
							$total_count += $current;

							$status_text  = $current . '/' . $quota;
							$status_color = '';

							if ( 0 === $current ) {
								$status_color = '#999';
							} elseif ( $current >= $quota ) {
								$status_color = '#46b450'; // Green - complete.
							} else {
								$status_color = '#ffb900'; // Yellow - partial.
							}

							echo '<td style="color: ' . esc_attr( $status_color ) . '; font-weight: bold;">';
							echo esc_html( $status_text );
							echo '</td>';
						}

						echo '<td><strong>' . esc_html( (string) $total_count ) . '</strong></td>';
						echo '</tr>';
					}

					echo '</tbody>';
					echo '</table>';
					echo '</div>';
				}
			}
		}

		if ( empty( $submissions ) ) {
			echo '<p>' . esc_html__( 'No submissions found for the selected filters.', 'photo-competition-manager' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<form method="post" id="bulk-delete-form">';
		wp_nonce_field( 'photo_competition_bulk_delete_' . $competition_id, '_wpnonce' );
		echo '<input type="hidden" name="action" value="bulk_delete_submissions" />';
		echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition_id ) . '" />';

		echo '<div class="tablenav top">';
		echo '<div class="alignleft actions">';
		echo '<button type="submit" class="button" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete the selected submissions? This will permanently delete the database records, all associated votes, and all associated files (slideshow images, thumbnails, and originals). This action cannot be undone.', 'photo-competition-manager' ) ) . '\') && this.form.querySelectorAll(\'input[name=\\\'image_ids[]\\\']\').length > 0 && Array.from(this.form.querySelectorAll(\'input[name=\\\'image_ids[]\\\']\')).some(cb => cb.checked) ? true : (alert(\'' . esc_js( __( 'Please select at least one submission to delete.', 'photo-competition-manager' ) ) . '\'), false);">';
		echo esc_html__( 'Delete Selected', 'photo-competition-manager' );
		echo '</button>';
		echo '</div>';
		echo '</div>';

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<td class="check-column"><input type="checkbox" id="cb-select-all" /></td>';
		echo '<th>' . esc_html__( 'Member', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Category', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Image', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Filename', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Random #', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Total Score', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Votes', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Submitted', 'photo-competition-manager' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $submissions as $submission ) {
			$member_name = isset( $member_map[ $submission->member_id ] )
			? $member_map[ $submission->member_id ]->name
			: sprintf(
			/* translators: %d: Numeric member identifier when the name is unavailable. */
				__( 'Member #%d', 'photo-competition-manager' ),
				(int) $submission->member_id
			);

			$current_competition = $selected_competition ?? ( $competition_lookup[ $submission->competition_id ] ?? null );
			$urls                = $this->get_submission_urls( $current_competition, $submission );
			$thumb_url           = ! empty( $urls['thumb'] ) ? $urls['thumb'] : $urls['full'];

			// Get score data for this submission.
			$image_id    = (int) $submission->id;
			$total_score = '—';
			$vote_count  = 0;

			if ( isset( $scores_data[ $image_id ] ) ) {
				$score_info  = $scores_data[ $image_id ];
				$total_score = number_format( $score_info['average_score'], 0 );
				$vote_count  = $score_info['vote_count'];
			}

			echo '<tr>';
			echo '<th scope="row" class="check-column"><input type="checkbox" name="image_ids[]" value="' . esc_attr( $image_id ) . '" /></th>';
			echo '<td>' . esc_html( $member_name ) . '</td>';
			echo '<td>' . esc_html( $submission->category ) . '</td>';
			if ( $urls['full'] ) {
				echo '<td class="photo-comp-thumbnail"><a href="' . esc_url( $urls['full'] ) . '" target="_blank" rel="noopener noreferrer">';
				echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $submission->filename ) . '" width="120" height="120" loading="lazy" />';
				echo '</a></td>';
			} else {
				echo '<td>' . esc_html__( 'Unavailable', 'photo-competition-manager' ) . '</td>';
			}
			echo '<td>' . esc_html( $submission->filename ) . '</td>';
			echo '<td>' . esc_html( (string) $submission->random_number ) . '</td>';
			echo '<td>' . esc_html( $total_score ) . '</td>';
			echo '<td>' . esc_html( (string) $vote_count ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $submission->created_at ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</form>';

		// Add JavaScript for "select all" functionality.
		echo '<script>';
		echo 'document.addEventListener("DOMContentLoaded", function() {';
		echo '  const selectAll = document.getElementById("cb-select-all");';
		echo '  if (selectAll) {';
		echo '    selectAll.addEventListener("change", function() {';
		echo '      const checkboxes = document.querySelectorAll(\'input[name="image_ids[]"]\');';
		echo '      checkboxes.forEach(function(checkbox) {';
		echo '        checkbox.checked = selectAll.checked;';
		echo '      });';
		echo '    });';
		echo '  }';
		echo '});';
		echo '</script>';

		echo '</div>';
	}

	/**
	 * Build URLs for submission assets.
	 *
	 * @param  object|null $competition Competition object.
	 * @param  object      $submission  Submission record.
	 * @return array{full:string,thumb:string}
	 */
	private function get_submission_urls( ?object $competition, object $submission ): array {
		if ( ! $competition || empty( $competition->slug ) || empty( $submission->filename ) ) {
			return array(
				'full'  => '',
				'thumb' => '',
			);
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return array(
				'full'  => '',
				'thumb' => '',
			);
		}

		$base = trailingslashit( $uploads['baseurl'] ) . 'competitions/';
		$slug = sanitize_file_name( (string) $competition->slug );
		$cat  = sanitize_file_name( (string) $submission->category );

		$folder_url  = trailingslashit( $base . rawurlencode( $slug ) . '/' . rawurlencode( $cat ) );
		$folder_path = trailingslashit( trailingslashit( $uploads['basedir'] ) . 'competitions/' . $slug . '/' . $cat );

		$filename   = $submission->filename;
		$thumb_name = $this->get_thumbnail_filename( $filename );

		$full_path  = $folder_path . $filename;
		$thumb_path = $folder_path . $thumb_name;

		$full_url  = file_exists( $full_path ) ? $folder_url . rawurlencode( $filename ) : '';
		$thumb_url = file_exists( $thumb_path ) ? $folder_url . rawurlencode( $thumb_name ) : '';

		return array(
			'full'  => $full_url,
			'thumb' => $thumb_url,
		);
	}

	/**
	 * Determine thumbnail filename from base filename.
	 *
	 * @param  string $filename Base filename.
	 * @return string
	 */
	private function get_thumbnail_filename( string $filename ): string {
		$info = pathinfo( $filename );
		$base = $info['filename'] ?? $filename;
		$ext  = isset( $info['extension'] ) && '' !== $info['extension'] ? '.' . $info['extension'] : '';

		return $base . '-thumb' . $ext;
	}

	/**
	 * Delete physical files for a submission.
	 *
	 * @param  object $image Image record with competition_id, category, and filename.
	 * @return void
	 */
	private function delete_submission_files( object $image ): void {
		// Get competition to determine slug.
		$competition = $this->competitions->find( $image->competition_id, true );
		if ( ! $competition || empty( $competition->slug ) ) {
			return;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		$slug = sanitize_file_name( (string) $competition->slug );
		$cat  = sanitize_file_name( (string) $image->category );

		$folder_path = trailingslashit( trailingslashit( $uploads['basedir'] ) . 'competitions/' . $slug . '/' . $cat );

		$filename   = $image->filename;
		$thumb_name = $this->get_thumbnail_filename( $filename );

		$full_path  = $folder_path . $filename;
		$thumb_path = $folder_path . $thumb_name;

		// Delete slideshow image.
		if ( file_exists( $full_path ) ) {
			wp_delete_file( $full_path );
		}

		// Delete thumbnail.
		if ( file_exists( $thumb_path ) ) {
			wp_delete_file( $thumb_path );
		}
	}
}
