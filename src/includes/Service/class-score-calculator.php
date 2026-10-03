<?php
/**
 * Calculate and update competition scores.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;

/**
 * Score Calculator Service.
 *
 * @since 0.1.0
 */
class Score_Calculator {

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images_repo;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes_repo;

	/**
	 * Constructor.
	 *
	 * @param Images_Repository $images_repo Images repository.
	 * @param Votes_Repository  $votes_repo  Votes repository.
	 */
	public function __construct( Images_Repository $images_repo, Votes_Repository $votes_repo ) {
		$this->images_repo = $images_repo;
		$this->votes_repo  = $votes_repo;
	}

	/**
	 * Calculate and update scores for all images in a competition.
	 *
	 * Stores the total score (sum of all votes) for each image.
	 *
	 * @param int         $competition_id Competition ID.
	 * @param string|null $category       Optional category filter.
	 * @return array{updated: int, errors: int}
	 */
	public function calculate_scores( int $competition_id, ?string $category = null ): array {
		$averages = $this->votes_repo->calculate_averages( $competition_id, $category );

		$updated = 0;
		$errors  = 0;

		foreach ( $averages as $image_id => $data ) {
			$result = $this->images_repo->update_score( $image_id, (int) $data['total_score'] );

			if ( is_wp_error( $result ) ) {
				++$errors;
			} else {
				++$updated;
			}
		}

		return array(
			'updated' => $updated,
			'errors'  => $errors,
		);
	}
}
