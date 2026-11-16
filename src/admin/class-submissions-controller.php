<?php
/**
 * Submissions controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Date_Formatting;
use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Competition_Labels;
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Competition_Entry;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Service\Upload_Link_Service;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Manage submissions viewing page.
 *
 * @since 0.1.0
 */
class Submissions_Controller {

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
	 * Entries module.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Constructor.
	 *
	 * @since 0.4.0 Takes Entries instead of Upload_Handler.
	 *
	 * @param Competitions_Repository $competitions   Competitions repository.
	 * @param Members_Repository      $members        Members repository.
	 * @param Images_Repository       $images         Images repository.
	 * @param Votes_Repository        $votes          Votes repository.
	 * @param Entries|null            $entries        Entries module.
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Members_Repository $members,
		Images_Repository $images,
		Votes_Repository $votes,
		?Entries $entries = null
	) {
		$this->competitions = $competitions;
		$this->members      = $members;
		$this->images       = $images;
		$this->votes        = $votes;
		$this->entries      = $entries ?? new Entries( $competitions, $images, $members );
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
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		// Only load on submissions page.
		if ( 'competitions_page_photo-competition-manager-submissions' !== $hook ) {
			return;
		}

		// Register and enqueue a dummy style handle to attach inline styles to.
		wp_register_style( 'photo-comp-submissions-style', '', array(), PHOTO_COMPETITION_MANAGER_VERSION );
		wp_enqueue_style( 'photo-comp-submissions-style' );

		// Add inline CSS for thumbnails.
		$inline_css = '.photo-comp-thumbnail img{max-width:120px;height:auto;border:1px solid #ccd0d4;padding:2px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,0.08);} .photo-comp-thumbnail{width:140px;} .photo-comp-photographer-field{width:12em;max-width:100%;} .photo-comp-entry-status .button{display:block;margin-top:4px;white-space:nowrap;}';
		wp_add_inline_style( 'photo-comp-submissions-style', $inline_css );

		// Register and enqueue a dummy script handle to attach inline scripts to.
		wp_register_script( 'photo-comp-submissions-script', '', array(), PHOTO_COMPETITION_MANAGER_VERSION, true );
		wp_enqueue_script( 'photo-comp-submissions-script' );

		// Add inline JavaScript for bulk actions.
		$inline_js = "
		document.addEventListener('DOMContentLoaded', function() {
			const selectAll = document.getElementById('cb-select-all');
			if (selectAll) {
				selectAll.addEventListener('change', function() {
					const checkboxes = document.querySelectorAll('input[name=\"image_ids[]\"]');
					checkboxes.forEach(function(checkbox) {
						checkbox.checked = selectAll.checked;
					});
				});
			}
			const bulkDeleteBtn = document.querySelector('.photo-comp-bulk-delete');
			if (bulkDeleteBtn) {
				bulkDeleteBtn.addEventListener('click', function(e) {
					const checkboxes = document.querySelectorAll('input[name=\"image_ids[]\"]:checked');
					if (checkboxes.length === 0) {
						e.preventDefault();
						alert(this.getAttribute('data-no-selection'));
						return false;
					}
					if (!confirm(this.getAttribute('data-confirm'))) {
						e.preventDefault();
						return false;
					}
				});
			}
			document.addEventListener('click', function(e) {
				if (e.target.classList.contains('photo-comp-regenerate') || e.target.classList.contains('photo-comp-delete-originals') || e.target.classList.contains('photo-comp-entry-status-button')) {
					var confirmMessage = e.target.getAttribute('data-confirm');
					if (confirmMessage && !confirm(confirmMessage)) {
						e.preventDefault();
						return false;
					}
				}
			});
		});
		";
		wp_add_inline_script( 'photo-comp-submissions-script', $inline_js );
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
				$this->redirect_with_settings_errors( admin_url( 'admin.php?page=photo-competition-manager-submissions' ) );
			}

			$result = $this->entries->regenerate_numbers( Actor::admin(), $competition_id );

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

			$this->redirect_with_settings_errors(
				add_query_arg(
					array(
						'page'           => 'photo-competition-manager-submissions',
						'competition_id' => $competition_id,
					),
					admin_url( 'admin.php' )
				)
			);
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
				$this->redirect_with_settings_errors( admin_url( 'admin.php?page=photo-competition-manager-submissions' ) );
			}

			$result = $this->entries->discard_originals( Actor::admin(), $competition_id );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_submissions',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} elseif ( 0 === $result['discarded'] && 0 === $result['failed'] ) {
				add_settings_error(
					'photo_competition_submissions',
					'no_originals',
					__( 'No original images found to delete.', 'photo-competition-manager' ),
					'updated'
				);
			} else {
				if ( $result['discarded'] > 0 ) {
					add_settings_error(
						'photo_competition_submissions',
						'originals_deleted',
						sprintf(
						/* translators: %d: number of deleted images */
							__( '%d original images deleted successfully.', 'photo-competition-manager' ),
							$result['discarded']
						),
						'updated'
					);
				}

				if ( $result['failed'] > 0 ) {
					add_settings_error(
						'photo_competition_submissions',
						'originals_not_deleted',
						sprintf(
						/* translators: %d: number of original images that could not be deleted */
							__( '%d original images could not be deleted. Deleting originals again retries them.', 'photo-competition-manager' ),
							$result['failed']
						),
						'error'
					);
				}
			}

			$this->redirect_with_settings_errors(
				add_query_arg(
					array(
						'page'           => 'photo-competition-manager-submissions',
						'competition_id' => $competition_id,
					),
					admin_url( 'admin.php' )
				)
			);
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
				$this->redirect_with_settings_errors( admin_url( 'admin.php?page=photo-competition-manager-submissions' ) );
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
					if ( is_wp_error( $this->entries->remove( Actor::admin(), $competition_id, $image_id ) ) ) {
						++$failed_count;
						continue;
					}

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

			$this->redirect_with_settings_errors(
				add_query_arg(
					array(
						'page'           => 'photo-competition-manager-submissions',
						'competition_id' => $competition_id,
					),
					admin_url( 'admin.php' )
				)
			);
		}

		if ( 'change_photographer' === $action ) {
			$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

			check_admin_referer( 'photo_competition_photographer_' . $competition_id );

			$image_id     = isset( $_POST['image_id'] ) ? absint( $_POST['image_id'] ) : 0;
			$photographer = isset( $_POST['photographer'] ) ? sanitize_text_field( wp_unslash( $_POST['photographer'] ) ) : '';

			$this->notice_and_redirect(
				'photo_competition_submissions',
				$this->entries->set_photographer( Actor::admin(), $competition_id, $image_id, $photographer ),
				'photographer_changed',
				__( 'Photographer saved.', 'photo-competition-manager' ),
				$this->entry_change_url( $competition_id )
			);
		}

		if ( 'change_entry_status' === $action ) {
			$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

			check_admin_referer( 'photo_competition_entry_status_' . $competition_id );

			// Each row's buttons post entry_status[<image ID>] = <new status>; only the pressed one is sent.
			$changes      = isset( $_POST['entry_status'] ) && is_array( $_POST['entry_status'] ) ? wp_unslash( $_POST['entry_status'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The key is absint()ed and the value sanitize_key()ed below.
			$image_id     = absint( (string) key( $changes ) );
			$entry_status = sanitize_key( (string) current( $changes ) );

			$this->notice_and_redirect(
				'photo_competition_submissions',
				$this->entries->set_entry_status( Actor::admin(), $competition_id, $image_id, $entry_status ),
				'entry_status_changed',
				sprintf(
					/* translators: %s: the entry's new status, such as Disqualified */
					__( 'The entry is now %s.', 'photo-competition-manager' ),
					Images_Repository::entry_status_label( $entry_status )
				),
				$this->entry_change_url( $competition_id )
			);
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
				$this->redirect_with_settings_errors( admin_url( 'admin.php?page=photo-competition-manager-submissions' ) );
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
			} elseif ( empty( $_FILES['image_file'] ) || ! isset( $_FILES['image_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['image_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is a file path and should not be sanitized.
				add_settings_error(
					'photo_competition_submissions',
					'missing_file',
					__( 'Please select an image file to upload.', 'photo-competition-manager' ),
					'error'
				);
			} else {
				// Get competition.
				$competition = $this->competitions->find( $competition_id, true );

				if ( ! $competition ) {
					add_settings_error(
						'photo_competition_submissions',
						'invalid_competition',
						__( 'Competition not found.', 'photo-competition-manager' ),
						'error'
					);
				} else {
					$details = array(
						'photographer' => isset( $_POST['photographer'] ) ? sanitize_text_field( wp_unslash( $_POST['photographer'] ) ) : '',
						'substitute'   => ! empty( $_POST['substitute'] ),
					);
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File array validated by Entries::add().
					$result = $this->entries->add( Actor::admin(), $competition_id, $member_id, $category, wp_unslash( $_FILES['image_file'] ), $details );

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

			$this->redirect_with_settings_errors(
				add_query_arg(
					array(
						'page'           => 'photo-competition-manager-submissions',
						'competition_id' => $competition_id,
					),
					admin_url( 'admin.php' )
				)
			);
		}
	}

	/**
	 * Render submissions viewer.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( 'photo_competition_submissions' );

		$competitions = $this->competitions->all( 200, true );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Submissions', 'photo-competition-manager' ) . '</h1>';

		if ( empty( $competitions ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_template( 'admin/submissions/notice-no-competitions.php' );
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
			// Default to the current competition, then the one that opened most
			// recently, so next month's competition, created early, doesn't win.
			$default = $this->competitions->find_current_or_latest_opened();
			if ( $default && isset( $competition_lookup[ (int) $default->id ] ) ) {
				$competition_id = (int) $default->id;
			} else {
				// Fall back to the first competition in the list if none has opened.
				$first          = reset( $competitions );
				$competition_id = $first ? (int) $first->id : 0;
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter input for list table.
		$member_id = isset( $_GET['member_id'] ) ? absint( wp_unslash( $_GET['member_id'] ) ) : 0;

		$members    = $this->members->all( 10000, false );
		$member_map = array();
		foreach ( $members as $member ) {
			$member_map[ (int) $member->id ] = $member;
		}

		$submissions = array();
		$scores_data = array();
		if ( $competition_id ) {
			$member_filter = $member_id > 0 ? $member_id : null;
			$submissions   = $this->images->find_uploads( $competition_id, null, $member_filter );

			// Sort submissions by member name.
			usort(
				$submissions,
				function ( $a, $b ) use ( $member_map ) {
					$name_a = isset( $member_map[ $a->member_id ] ) ? $member_map[ $a->member_id ]->name : '';
					$name_b = isset( $member_map[ $b->member_id ] ) ? $member_map[ $b->member_id ]->name : '';
					return strcasecmp( $name_a, $name_b );
				}
			);

			// Get average scores and vote counts for all submissions.
			$scores_data = $this->votes->calculate_averages( $competition_id );
		}

		$selected_competition = $competition_id && isset( $competition_lookup[ $competition_id ] ) ? $competition_lookup[ $competition_id ] : null;

		// The members who may enter: the type's members, or the competition's selected entrants.
		$eligible  = $selected_competition ? ( new Competition_Entry( $this->competitions, $this->members ) )->eligible_members( $selected_competition ) : array();
		$may_enter = array_map( 'intval', wp_list_pluck( $eligible, 'id' ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_filters( $competitions, $competition_id, $members, $member_id );

		if ( $selected_competition ) {
			printf(
				'<h2>%s</h2>',
				esc_html( ( new Competition_Labels() )->title_for_everyone( $selected_competition ) )
			);

			$interclub = Competition_Settings::parse( $selected_competition->settings )['interclub'];

			// Add regenerate numbers button.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_regenerate_numbers_form( $competition_id, (bool) $interclub['enabled'] );

			// Add delete original images button.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_delete_originals_form( $competition_id );

			// Get competition settings for categories.
			$categories = array();
			if ( ! empty( $selected_competition->settings ) ) {
				$settings = json_decode( $selected_competition->settings, true );
				if ( is_array( $settings ) ) {
					$categories = \PhotoCompetitionManager\Support\Competition_Settings::get_categories( $settings );
				}
			}

			// Add admin upload form.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_admin_upload_form( $competition_id, $members, $categories, $interclub );
		}

		// The photos come first, as what an admin comes to see; the status summary follows them.
		if ( empty( $submissions ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_template( 'admin/submissions/notice-no-submissions.php' );
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_submissions_table( $competition_id, $member_id, $submissions, $member_map, $scores_data, $selected_competition, $competition_lookup, $may_enter );
		}

		// The quota/status summary.
		if ( $selected_competition && ! empty( $selected_competition->settings ) ) {
			$settings = json_decode( $selected_competition->settings, true );
			if ( is_array( $settings ) ) {
				$categories = \PhotoCompetitionManager\Support\Competition_Settings::get_categories( $settings );

				if ( ! empty( $categories ) ) {
					// Count each member's entered images by category: substitutes and disqualified images take no place in the quota.
					$member_counts = array();
					foreach ( $submissions as $submission ) {
						if ( 'entered' !== $submission->entry_status ) {
							continue;
						}

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

					// Get link tracking data.
					$upload_token_repo = new \PhotoCompetitionManager\Repository\Upload_Token_Repository();
					$voting_token_repo = new \PhotoCompetitionManager\Repository\Voting_Token_Repository();
					$upload_tracking   = $upload_token_repo->get_tracking_by_competition( $competition_id );
					$voting_tracking   = $voting_token_repo->get_tracking_by_competition( $competition_id );

					// Build quota map.
					$quota_map = array();
					foreach ( $categories as $cat_config ) {
						$quota_map[ $cat_config['slug'] ] = array(
							'label' => $cat_config['label'],
							'quota' => $cat_config['quota'],
						);
					}

					// The members who may enter, then anyone else who has entries, such as a member deactivated since.
					$entrants = $eligible;
					$listed   = array_flip( $may_enter );
					foreach ( array_unique( array_map( 'intval', array_column( $submissions, 'member_id' ) ) ) as $entered_id ) {
						if ( ! isset( $listed[ $entered_id ] ) && isset( $member_map[ $entered_id ] ) ) {
							$entrants[] = $member_map[ $entered_id ];
						}
					}
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
					echo $this->render_submission_status( $categories, $member_id, $entrants, $member_counts, $upload_tracking, $voting_tracking );
				}
			}
		}

		echo '</div>';
	}

	/**
	 * Render the competition/member filter form.
	 *
	 * @param array<int,object> $competitions   All competitions.
	 * @param int               $competition_id Currently selected competition ID.
	 * @param array<int,object> $members        All members.
	 * @param int               $member_id      Currently selected member ID (0 = all).
	 * @return string
	 */
	private function render_filters( array $competitions, int $competition_id, array $members, int $member_id ): string {
		return $this->render_template(
			'admin/submissions/filters.php',
			array(
				'competitions'   => $competitions,
				'competition_id' => $competition_id,
				'members'        => $members,
				'member_id'      => $member_id,
			)
		);
	}

	/**
	 * Render the regenerate-random-numbers form.
	 *
	 * @param int  $competition_id Competition ID.
	 * @param bool $interclub      Whether the competition numbers each image rather than each member.
	 * @return string
	 */
	private function render_regenerate_numbers_form( int $competition_id, bool $interclub ): string {
		return $this->render_template(
			'admin/submissions/regenerate-numbers-form.php',
			array(
				'competition_id' => $competition_id,
				'interclub'      => $interclub,
			)
		);
	}

	/**
	 * Render the delete-original-images form.
	 *
	 * @param int $competition_id Competition ID.
	 * @return string
	 */
	private function render_delete_originals_form( int $competition_id ): string {
		return $this->render_template(
			'admin/submissions/delete-originals-form.php',
			array( 'competition_id' => $competition_id )
		);
	}

	/**
	 * Render the admin upload-on-behalf-of-member form.
	 *
	 * @param int               $competition_id Competition ID.
	 * @param array<int,object> $members        All members.
	 * @param array<int,array>  $categories     Category config for the competition.
	 * @param array             $interclub      The competition's interclub settings.
	 * @return string
	 */
	private function render_admin_upload_form( int $competition_id, array $members, array $categories, array $interclub ): string {
		return $this->render_template(
			'admin/submissions/admin-upload-form.php',
			array(
				'competition_id' => $competition_id,
				'members'        => $members,
				'categories'     => $categories,
				'interclub'      => $interclub,
			)
		);
	}

	/**
	 * Render the submission status/quota summary table.
	 *
	 * @param array<int,array>  $categories      Category config for the competition.
	 * @param int               $member_id       Currently selected member ID (0 = all).
	 * @param array<int,object> $members         All members.
	 * @param array             $member_counts   Submission counts by member id then category slug.
	 * @param array<int,object> $upload_tracking Upload-link tracking data by member id.
	 * @param array<int,object> $voting_tracking Voting-link tracking data by member id.
	 * @return string
	 */
	private function render_submission_status( array $categories, int $member_id, array $members, array $member_counts, array $upload_tracking, array $voting_tracking ): string {
		return $this->render_template(
			'admin/submissions/submission-status.php',
			array(
				'categories'      => $categories,
				'member_id'       => $member_id,
				'members'         => $members,
				'member_counts'   => $member_counts,
				'upload_tracking' => $upload_tracking,
				'voting_tracking' => $voting_tracking,
			)
		);
	}

	/**
	 * Render the submissions table (with its wrapping bulk-delete form).
	 *
	 * @param int               $competition_id       Competition ID.
	 * @param int               $member_id            The member the list is filtered to, or 0.
	 * @param array<int,object> $submissions          Submission records for the competition.
	 * @param array<int,object> $member_map           Members indexed by member id.
	 * @param array             $scores_data          Score/vote-count data indexed by image id.
	 * @param object|null       $selected_competition Currently selected competition, if any.
	 * @param array<int,object> $competition_lookup   Competitions indexed by competition id.
	 * @param int[]             $may_enter            IDs of the members who may enter the selected competition.
	 * @return string
	 */
	private function render_submissions_table( int $competition_id, int $member_id, array $submissions, array $member_map, array $scores_data, ?object $selected_competition, array $competition_lookup, array $may_enter ): string {
		// Each member's upload link, the same one their upload link emails carry,
		// for the members who may still enter: the upload page refuses anyone else's.
		$upload_links       = new Upload_Link_Service();
		$member_upload_urls = array();

		foreach ( $submissions as $submission ) {
			$submission_member_id = (int) $submission->member_id;
			if ( $selected_competition && ! isset( $member_upload_urls[ $submission_member_id ] ) && in_array( $submission_member_id, $may_enter, true ) ) {
				$upload_url = $upload_links->upload_url( $selected_competition, $submission_member_id );
				if ( ! is_wp_error( $upload_url ) ) {
					$member_upload_urls[ $submission_member_id ] = $upload_url;
				}
			}
		}

		$current_member_id = null;
		$rows              = array();
		$interclub         = $selected_competition && Competition_Settings::parse( $selected_competition->settings )['interclub']['enabled'];
		// The changes allowed depend only on an entry's category and status, so work them out once for each.
		$status_actions = array();

		foreach ( $submissions as $submission ) {
			$member_name = isset( $member_map[ $submission->member_id ] )
			? $member_map[ $submission->member_id ]->name
			: sprintf(
			/* translators: %d: Numeric member identifier when the name is unavailable. */
				__( 'Member #%d', 'photo-competition-manager' ),
				(int) $submission->member_id
			);

			$is_first_for_member = ( $current_member_id !== (int) $submission->member_id );
			$current_member_id   = (int) $submission->member_id;

			$current_competition = $selected_competition ?? ( $competition_lookup[ $submission->competition_id ] ?? null );
			$urls                = $current_competition
				? $this->entries->urls( $current_competition, $submission )
				: array(
					'full'  => '',
					'thumb' => '',
				);
			$thumb_url           = ! empty( $urls['thumb'] ) ? $urls['thumb'] : $urls['full'];

			// Get score data for this submission.
			$image_id    = (int) $submission->id;
			$total_score = '—';
			$vote_count  = 0;

			if ( isset( $scores_data[ $image_id ] ) ) {
				$score_info  = $scores_data[ $image_id ];
				$total_score = number_format( $score_info['total_score'], 0 );
				$vote_count  = $score_info['vote_count'];
			}

			$status_key = $submission->category . ':' . $submission->entry_status;
			if ( ! isset( $status_actions[ $status_key ] ) ) {
				$status_actions[ $status_key ] = $current_competition ? $this->status_actions( $current_competition, $submission ) : array();
			}

			$rows[] = (object) array(
				'image_id'             => $image_id,
				'member_name'          => $member_name,
				'show_upload_link'     => $is_first_for_member && isset( $member_upload_urls[ $current_member_id ] ),
				'member_upload_url'    => $member_upload_urls[ $current_member_id ] ?? '',
				'category'             => $submission->category,
				'full_url'             => $urls['full'],
				'thumb_url'            => $thumb_url,
				'filename'             => $submission->filename,
				'random_number'        => $submission->random_number,
				'total_score'          => $total_score,
				'vote_count'           => $vote_count,
				'formatted_created_at' => $this->format_datetime( $submission->created_at ),
				'photographer'         => (string) $submission->photographer,
				'status_label'         => Images_Repository::entry_status_label( (string) $submission->entry_status ),
				'status_actions'       => $status_actions[ $status_key ],
			);
		}

		return $this->render_template(
			'admin/submissions/submissions-table.php',
			array(
				'competition_id' => $competition_id,
				'member_id'      => $member_id,
				'interclub'      => $interclub,
				'rows'           => $rows,
			)
		);
	}

	/**
	 * The Submissions list an entry was changed from, with its member filter.
	 *
	 * @param int $competition_id Competition ID.
	 * @return string
	 */
	private function entry_change_url( int $competition_id ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The caller checked the nonce.
		$member_id = isset( $_POST['member_id'] ) ? absint( $_POST['member_id'] ) : 0;

		return add_query_arg(
			array_filter(
				array(
					'page'           => 'photo-competition-manager-submissions',
					'competition_id' => $competition_id,
					'member_id'      => $member_id,
				)
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The buttons that change an entry's status, as the competition stands.
	 *
	 * @param object $competition Competition row.
	 * @param object $entry       Entry row.
	 * @return array<int, array{status: string, label: string, confirm: string}>
	 */
	private function status_actions( object $competition, object $entry ): array {
		$actions = array();
		foreach ( $this->entries->entry_status_options( $competition, $entry ) as $entry_status ) {
			if ( 'disqualified' === $entry_status ) {
				$label = __( 'Disqualify', 'photo-competition-manager' );
			} elseif ( 'entered' === $entry_status ) {
				$label = 'substitute' === $entry->entry_status ? __( 'Promote', 'photo-competition-manager' ) : __( 'Reinstate', 'photo-competition-manager' );
			} else {
				$label = 'entered' === $entry->entry_status ? __( 'Make substitute', 'photo-competition-manager' ) : __( 'Reinstate as substitute', 'photo-competition-manager' );
			}

			$actions[] = array(
				'status'  => $entry_status,
				'label'   => $label,
				'confirm' => 'disqualified' === $entry_status ? __( 'Disqualify this entry? Once its category has votes, it can\'t be brought back without clearing them.', 'photo-competition-manager' ) : '',
			);
		}

		return $actions;
	}
}
