<?php
/**
 * Handle image upload and submission process.
 *
 * @package ClubCompetitions\Service
 */

namespace ClubCompetitions\Service;

use ClubCompetitions\Repository\Competitions_Repository;
use ClubCompetitions\Repository\Images_Repository;
use ClubCompetitions\Repository\Members_Repository;
use ClubCompetitions\Support\Competition_Settings;
use ClubCompetitions\Support\Image_Processor;
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
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'club-competitions' ) );
		}

		if ( 'active' !== $competition->status ) {
			return new WP_Error( 'competition_closed', __( 'Competition is not open for submissions.', 'club-competitions' ) );
		}

		// Check if competition is within submission period.
		$now = current_time( 'mysql' );
		if ( $competition->open_date && $now < $competition->open_date ) {
			return new WP_Error( 'not_open_yet', __( 'Competition submissions have not opened yet.', 'club-competitions' ) );
		}

		if ( $competition->close_date && $now > $competition->close_date ) {
			return new WP_Error( 'closed', __( 'Competition submissions have closed.', 'club-competitions' ) );
		}

		// Validate member exists and is active.
		$member = $this->members_repo->find( $member_id );
		if ( ! $member ) {
			return new WP_Error( 'invalid_member', __( 'Member not found.', 'club-competitions' ) );
		}

		if ( ! $member->active ) {
			return new WP_Error( 'inactive_member', __( 'Member account is not active.', 'club-competitions' ) );
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
			return new WP_Error( 'invalid_category', __( 'Invalid category.', 'club-competitions' ) );
		}

		// Check quota.
		$current_count = $this->images_repo->count_by_member_category( $competition_id, $member_id, $category );
		$quota         = $category_config['quota'] ?? 1;

		if ( $current_count >= $quota ) {
			return new WP_Error(
				'quota_exceeded',
				sprintf(
					/* translators: 1: category label, 2: quota */
					__( 'You have already uploaded the maximum of %2$d image(s) for %1$s.', 'club-competitions' ),
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

		$filename = $this->image_processor->process(
			$file,
			$competition->slug,
			$category,
			$username,
			$counter,
			$constraints
		);

		if ( is_wp_error( $filename ) ) {
			return $filename;
		}

		// Create database record.
		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $member_id,
				'category'       => $category,
				'filename'       => $filename,
			)
		);

		if ( is_wp_error( $image_id ) ) {
			// Clean up uploaded file if database insert fails.
			$this->image_processor->delete_files( $competition->slug, $category, $filename );
			return $image_id;
		}

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
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'club-competitions' ) );
		}

		// Verify ownership.
		if ( (int) $image->member_id !== $member_id ) {
			return new WP_Error( 'not_authorized', __( 'You are not authorized to delete this image.', 'club-competitions' ) );
		}

		if ( (int) $image->competition_id !== $competition_id ) {
			return new WP_Error( 'invalid_competition', __( 'Image does not belong to this competition.', 'club-competitions' ) );
		}

		// Check if competition is still open.
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition || 'active' !== $competition->status ) {
			return new WP_Error( 'competition_closed', __( 'Cannot delete images after competition has closed.', 'club-competitions' ) );
		}

		// Delete files.
		$this->image_processor->delete_files( $competition->slug, $image->category, $image->filename );

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
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'club-competitions' ) );
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
			return new WP_Error( 'invalid_category', __( 'Invalid category.', 'club-competitions' ) );
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
}
