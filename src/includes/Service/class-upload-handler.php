<?php
/**
 * Handle image upload and submission process.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Support\Image_Processor;
use WP_Error;

/**
 * Upload Handler Service.
 *
 * @since 0.1.0
 */
class Upload_Handler {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions_repo;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images_repo;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members_repo;

	/**
	 * Image processor.
	 *
	 * @var Image_Processor
	 */
	private $image_processor;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Images_Repository|null       $images_repo       Images repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 * @param Image_Processor|null         $image_processor   Image processor.
	 */
	public function __construct(
		?Competitions_Repository $competitions_repo = null,
		?Images_Repository $images_repo = null,
		?Members_Repository $members_repo = null,
		?Image_Processor $image_processor = null
	) {
		$this->competitions_repo = $competitions_repo ? $competitions_repo : new Competitions_Repository();
		$this->images_repo       = $images_repo ? $images_repo : new Images_Repository();
		$this->members_repo      = $members_repo ? $members_repo : new Members_Repository();
		$this->image_processor   = $image_processor ? $image_processor : new Image_Processor();
	}

	/**
	 * Handle image upload submission.
	 *
	 * @param int                  $competition_id Competition ID.
	 * @param int                  $member_id      Member ID.
	 * @param string               $category       Category slug.
	 * @param array<string, mixed> $file           Uploaded file from $_FILES.
	 * @return int|WP_Error Image ID on success, WP_Error on failure.
	 */
	public function handle_upload( int $competition_id, int $member_id, string $category, array $file ) {
		// Validate competition exists and is open.
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		// Check for admin bypass transient (set when admins upload on behalf of members).
		$transient_key  = 'photo_comp_admin_upload_' . $competition_id . '_' . $member_id . '_' . get_current_user_id();
		$admin_bypass   = get_transient( $transient_key );
		$skip_time_gate = false !== $admin_bypass;

		if ( ! $skip_time_gate ) {
			// Regular validation for public uploads.
			if ( ! $this->competitions_repo->is_accepting_uploads( $competition ) ) {
				return new WP_Error( 'competition_closed', __( 'Competition is not open for submissions.', 'photo-competition-manager' ) );
			}
		}

		// Validate member exists and is active.
		$member = $this->members_repo->find( $member_id );
		if ( ! $member ) {
			return new WP_Error( 'invalid_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		if ( ! $member->active ) {
			return new WP_Error( 'inactive_member', __( 'Member account is not active.', 'photo-competition-manager' ) );
		}

		// Parse competition settings.
		$settings   = Competition_Settings::parse( $competition->settings );
		$categories = Competition_Settings::get_categories( $settings );

		// Validate category exists.
		$category_config = null;
		foreach ( $categories as $cat ) {
			if ( $cat['slug'] === $category ) {
				$category_config = $cat;
				break;
			}
		}

		if ( ! $category_config ) {
			return new WP_Error( 'invalid_category', __( 'Invalid category.', 'photo-competition-manager' ) );
		}

		// Check quota.
		$current_count = $this->images_repo->count_by_member_category( $competition_id, $member_id, $category );
		$quota         = $category_config['quota'] ?? 1;

		if ( $current_count >= $quota ) {
			return new WP_Error(
				'quota_exceeded',
				sprintf(
					/* translators: 1: category label, 2: quota */
					__( 'You have already uploaded the maximum of %2$d image(s) for %1$s.', 'photo-competition-manager' ),
					$category_config['label'],
					$quota
				)
			);
		}

		// Get upload constraints.
		$constraints = Competition_Settings::get_upload_constraints( $settings );

		// Process and store the image.
		$counter  = $current_count + 1;
		$username = sanitize_title( $member->name );

		$result = $this->image_processor->process(
			$file,
			$competition->slug,
			$category,
			$username,
			$counter,
			$constraints
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$filename      = $result['filename'];
		$attachment_id = $result['attachment_id'];

		// Create database record.
		$image_id = $this->images_repo->create(
			array(
				'competition_id'         => $competition_id,
				'member_id'              => $member_id,
				'category'               => $category,
				'filename'               => $filename,
				'original_attachment_id' => $attachment_id,
			)
		);

		if ( is_wp_error( $image_id ) ) {
			// Clean up uploaded file and attachment if database insert fails.
			$this->image_processor->delete_files( $competition->slug, $category, $filename, $attachment_id );
			return $image_id;
		}

		// Send submission confirmed notification.
		$email_service = new Email_Service();
		$email_service->send_submission_confirmed_notification(
			$member->email,
			$member->name,
			$competition->title,
			$category_config['label'],
			$counter,
			$quota,
			null,
			! empty( $member->grade ) ? $member->grade : ''
		);

		return $image_id;
	}

	/**
	 * Get member's uploaded images for a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @param int $member_id      Member ID.
	 * @return array<int, object> Array of image records with URLs.
	 */
	public function get_member_submissions( int $competition_id, int $member_id ): array {
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return array();
		}

		$images = $this->images_repo->find_by_competition( $competition_id, null, $member_id );

		// Add URLs to each image.
		foreach ( $images as &$image ) {
			$image->url           = $this->image_processor->get_image_url( $competition->slug, $image->category, $image->filename );
			$image->thumbnail_url = $this->image_processor->get_thumbnail_url( $competition->slug, $image->category, $image->filename );
		}

		return $images;
	}

	/**
	 * Delete a member's submission.
	 *
	 * @param int $image_id      Image ID.
	 * @param int $member_id     Member ID (for ownership verification).
	 * @param int $competition_id Competition ID.
	 * @return true|WP_Error
	 */
	public function delete_submission( int $image_id, int $member_id, int $competition_id ) {
		$image = $this->images_repo->find( $image_id );
		if ( ! $image ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		// Verify ownership.
		if ( (int) $image->member_id !== $member_id ) {
			return new WP_Error( 'not_authorized', __( 'You are not authorized to delete this image.', 'photo-competition-manager' ) );
		}

		if ( (int) $image->competition_id !== $competition_id ) {
			return new WP_Error( 'invalid_competition', __( 'Image does not belong to this competition.', 'photo-competition-manager' ) );
		}

		// Check if competition is still open.
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition || ! $this->competitions_repo->is_accepting_uploads( $competition ) ) {
			return new WP_Error( 'competition_closed', __( 'Cannot delete images after competition has closed.', 'photo-competition-manager' ) );
		}

		// Delete files and original attachment.
		$attachment_id = isset( $image->original_attachment_id ) ? (int) $image->original_attachment_id : 0;
		$this->image_processor->delete_files( $competition->slug, $image->category, $image->filename, $attachment_id );

		// Delete database record.
		$result = $this->images_repo->delete( $image_id );

		return $result;
	}

	/**
	 * Get remaining quota for a member in a category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param int    $member_id      Member ID.
	 * @param string $category       Category slug.
	 * @return array{current: int, quota: int, remaining: int}|WP_Error
	 */
	public function get_quota_status( int $competition_id, int $member_id, string $category ) {
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$settings   = Competition_Settings::parse( $competition->settings );
		$categories = Competition_Settings::get_categories( $settings );

		$category_config = null;
		foreach ( $categories as $cat ) {
			if ( $cat['slug'] === $category ) {
				$category_config = $cat;
				break;
			}
		}

		if ( ! $category_config ) {
			return new WP_Error( 'invalid_category', __( 'Invalid category.', 'photo-competition-manager' ) );
		}

		$current   = $this->images_repo->count_by_member_category( $competition_id, $member_id, $category );
		$quota     = $category_config['quota'] ?? 1;
		$remaining = max( 0, $quota - $current );

		return array(
			'current'   => $current,
			'quota'     => $quota,
			'remaining' => $remaining,
		);
	}

	/**
	 * Get current upload count for a member in a category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param int    $member_id      Member ID.
	 * @param string $category       Category slug.
	 * @return int Current count of uploads.
	 */
	public function get_category_count( int $competition_id, int $member_id, string $category ): int {
		return $this->images_repo->count_by_member_category( $competition_id, $member_id, $category );
	}

	/**
	 * Update submission category.
	 *
	 * @param int    $submission_id   Submission ID.
	 * @param int    $member_id       Member ID (for ownership verification).
	 * @param int    $competition_id  Competition ID.
	 * @param string $new_category    New category slug.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function update_submission_category( int $submission_id, int $member_id, int $competition_id, string $new_category ) {
		// Verify submission exists and belongs to the member.
		$submission = $this->images_repo->find( $submission_id );
		if ( ! $submission ) {
			return new WP_Error( 'submission_not_found', __( 'Submission not found.', 'photo-competition-manager' ) );
		}

		if ( (int) $submission->member_id !== $member_id ) {
			return new WP_Error( 'permission_denied', __( 'You do not have permission to modify this submission.', 'photo-competition-manager' ) );
		}

		if ( (int) $submission->competition_id !== $competition_id ) {
			return new WP_Error( 'invalid_competition', __( 'Submission does not belong to this competition.', 'photo-competition-manager' ) );
		}

		// If category hasn't changed, nothing to do.
		if ( $submission->category === $new_category ) {
			return true;
		}

		// Validate new category exists in competition settings.
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$settings   = Competition_Settings::parse( $competition->settings );
		$categories = Competition_Settings::get_categories( $settings );

		$category_config = null;
		foreach ( $categories as $cat ) {
			if ( $cat['slug'] === $new_category ) {
				$category_config = $cat;
				break;
			}
		}

		if ( ! $category_config ) {
			return new WP_Error( 'invalid_category', __( 'Invalid category.', 'photo-competition-manager' ) );
		}

		// Check quota for the new category.
		// For category updates (moving existing images), allow moving into a category at quota
		// to support swaps. Only block if it would exceed quota.
		$current_count = $this->images_repo->count_by_member_category( $competition_id, $member_id, $new_category );
		$quota         = $category_config['quota'] ?? 1;

		if ( $current_count > $quota ) {
			return new WP_Error(
				'quota_exceeded',
				sprintf(
					/* translators: 1: category label, 2: current count, 3: quota limit */
					__( 'Category "%1$s" has too many images (%2$d/%3$d). Please remove images from this category first.', 'photo-competition-manager' ),
					$category_config['label'],
					$current_count,
					$quota
				)
			);
		}

		// Move the image files to the new category folder.
		$old_category = $submission->category;
		$move_result  = $this->image_processor->move_image_between_categories(
			$competition->slug,
			$old_category,
			$new_category,
			$submission->filename
		);

		if ( is_wp_error( $move_result ) ) {
			return $move_result;
		}

		// Update the category in the database.
		$result = $this->images_repo->update_category( $submission_id, $new_category );

		if ( is_wp_error( $result ) ) {
			// Rollback: Move files back to original category.
			$this->image_processor->move_image_between_categories(
				$competition->slug,
				$new_category,
				$old_category,
				$submission->filename
			);
			return $result;
		}

		return true;
	}
}
