<?php
/**
 * Submissions controller for admin interface.
 *
 * @package ClubCompetitions\Admin
 */

namespace ClubCompetitions\Admin;

use ClubCompetitions\Admin\Traits\Date_Formatting;
use ClubCompetitions\Repository\Competitions_Repository;
use ClubCompetitions\Repository\Images_Repository;
use ClubCompetitions\Repository\Members_Repository;
use ClubCompetitions\Repository\Votes_Repository;

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
	 * Constructor.
	 *
	 * @param Competitions_Repository $competitions Competitions repository.
	 * @param Members_Repository      $members      Members repository.
	 * @param Images_Repository       $images       Images repository.
	 * @param Votes_Repository        $votes        Votes repository.
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Members_Repository $members,
		Images_Repository $images,
		Votes_Repository $votes
	) {
		$this->competitions = $competitions;
		$this->members      = $members;
		$this->images       = $images;
		$this->votes        = $votes;
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

			check_admin_referer( 'club_competitions_regenerate_numbers_' . $competition_id );

			if ( ! $competition_id ) {
				add_settings_error(
					'club_competitions_submissions',
					'invalid_competition',
					__( 'Invalid competition.', 'club-competitions' ),
					'error'
				);
				wp_safe_redirect( admin_url( 'admin.php?page=club-competitions-submissions' ) );
				exit;
			}

			$result = $this->images->regenerate_member_numbers( $competition_id );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions_submissions',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'club_competitions_submissions',
					'numbers_regenerated',
					__( 'Random numbers regenerated successfully.', 'club-competitions' ),
					'updated'
				);
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => 'club-competitions-submissions',
						'competition_id' => $competition_id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'delete_original_images' === $action ) {
			$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

			check_admin_referer( 'club_competitions_delete_originals_' . $competition_id );

			if ( ! $competition_id ) {
				add_settings_error(
					'club_competitions_submissions',
					'invalid_competition',
					__( 'Invalid competition.', 'club-competitions' ),
					'error'
				);
				wp_safe_redirect( admin_url( 'admin.php?page=club-competitions-submissions' ) );
				exit;
			}

			// Get all original attachment IDs.
			$attachment_ids = $this->images->get_original_attachment_ids( $competition_id );

			if ( empty( $attachment_ids ) ) {
				add_settings_error(
					'club_competitions_submissions',
					'no_originals',
					__( 'No original images found to delete.', 'club-competitions' ),
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
						'club_competitions_submissions',
						$result->get_error_code(),
						$result->get_error_message(),
						'error'
					);
				} else {
					add_settings_error(
						'club_competitions_submissions',
						'originals_deleted',
						sprintf(
						/* translators: %d: number of deleted images */
							__( '%d original images deleted successfully.', 'club-competitions' ),
							$deleted_count
						),
						'updated'
					);
				}
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => 'club-competitions-submissions',
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'club-competitions' ) );
		}

		settings_errors( 'club_competitions_submissions' );

		$competitions = $this->competitions->all( 200, true );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Submissions', 'club-competitions' ) . '</h1>';

		static $styles_output = false;
		if ( ! $styles_output ) {
			echo '<style id="club-competitions-submissions-css">.club-competitions-thumbnail img{max-width:120px;height:auto;border:1px solid #ccd0d4;padding:2px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,0.08);} .club-competitions-thumbnail{width:140px;}</style>';
			$styles_output = true;
		}

		if ( empty( $competitions ) ) {
			echo '<p>' . esc_html__( 'No competitions available yet. Create a competition first.', 'club-competitions' ) . '</p>';
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

		echo '<form method="get" class="club-competitions-filters">';
		echo '<input type="hidden" name="page" value="club-competitions-submissions" />';
		echo '<label for="competition_id" class="screen-reader-text">' . esc_html__( 'Competition', 'club-competitions' ) . '</label>';
		echo '<select name="competition_id" id="competition_id">';
		foreach ( $competitions as $competition ) {
			$label = $competition->title;
			if ( ! empty( $competition->deleted_at ) || 'archived' === $competition->status ) {
				$label .= ' ' . esc_html__( '(Archived)', 'club-competitions' );
			}

			printf(
				'<option value="%1$d" %3$s>%2$s</option>',
				(int) $competition->id,
				esc_html( $label ),
				selected( $competition_id, $competition->id, false )
			);
		}
		echo '</select> ';

		echo '<label for="member_id" class="screen-reader-text">' . esc_html__( 'Member', 'club-competitions' ) . '</label>';
		echo '<select name="member_id" id="member_id">';
		echo '<option value="0">' . esc_html__( 'All Members', 'club-competitions' ) . '</option>';
		foreach ( $members as $member ) {
			printf(
				'<option value="%1$d" %3$s>%2$s</option>',
				(int) $member->id,
				esc_html( $member->name ),
				selected( $member_id, $member->id, false )
			);
		}
		echo '</select> ';

		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'club-competitions' ) . '</button>';
		echo '</form>';

		if ( $selected_competition ) {
			printf(
				'<h2>%s</h2>',
				esc_html( $selected_competition->title )
			);

			// Add regenerate numbers button.
			echo '<form method="post" style="margin-bottom: 15px; display: inline-block; margin-right: 10px;">';
			wp_nonce_field( 'club_competitions_regenerate_numbers_' . $competition_id, '_wpnonce' );
			echo '<input type="hidden" name="action" value="regenerate_numbers" />';
			echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition_id ) . '" />';
			echo '<button type="submit" class="button" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to regenerate random numbers? Each member will still have the same number across all their images, but the numbers will be reassigned.', 'club-competitions' ) ) . '\');">';
			echo esc_html__( 'Regenerate Random Numbers', 'club-competitions' );
			echo '</button>';
			echo ' <span class="description">' . esc_html__( 'Reassign random numbers to members in this competition (each member keeps one consistent number).', 'club-competitions' ) . '</span>';
			echo '</form>';

			// Add delete original images button.
			echo '<form method="post" style="margin-bottom: 15px; display: inline-block;">';
			wp_nonce_field( 'club_competitions_delete_originals_' . $competition_id, '_wpnonce' );
			echo '<input type="hidden" name="action" value="delete_original_images" />';
			echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition_id ) . '" />';
			echo '<button type="submit" class="button" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to delete all original images from the media library for this competition? This will keep thumbnails and slideshow images, but remove the high-resolution originals to save space. This action cannot be undone.', 'club-competitions' ) ) . '\');">';
			echo esc_html__( 'Delete Original Images', 'club-competitions' );
			echo '</button>';
			echo ' <span class="description">' . esc_html__( 'Remove high-resolution originals from media library (keeps thumbnails and slideshow images).', 'club-competitions' ) . '</span>';
			echo '</form>';
		}

		if ( empty( $submissions ) ) {
			echo '<p>' . esc_html__( 'No submissions found for the selected filters.', 'club-competitions' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Member', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Category', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Image', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Filename', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Random #', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Total Score', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Votes', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Submitted', 'club-competitions' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $submissions as $submission ) {
			$member_name = isset( $member_map[ $submission->member_id ] )
			? $member_map[ $submission->member_id ]->name
			: sprintf(
			/* translators: %d: Numeric member identifier when the name is unavailable. */
				__( 'Member #%d', 'club-competitions' ),
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
			echo '<td>' . esc_html( $member_name ) . '</td>';
			echo '<td>' . esc_html( $submission->category ) . '</td>';
			if ( $urls['full'] ) {
				echo '<td class="club-competitions-thumbnail"><a href="' . esc_url( $urls['full'] ) . '" target="_blank" rel="noopener noreferrer">';
				echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $submission->filename ) . '" width="120" height="120" loading="lazy" />';
				echo '</a></td>';
			} else {
				echo '<td>' . esc_html__( 'Unavailable', 'club-competitions' ) . '</td>';
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
}
