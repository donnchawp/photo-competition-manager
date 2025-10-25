<?php
/**
 * Calculate and update competition scores.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

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
	 * @param Images_Repository|null $images_repo Images repository.
	 * @param Votes_Repository|null  $votes_repo  Votes repository.
	 */
	public function __construct( ?Images_Repository $images_repo = null, ?Votes_Repository $votes_repo = null ) {
		$this->images_repo = $images_repo ? $images_repo : new Images_Repository();
		$this->votes_repo  = $votes_repo ? $votes_repo : new Votes_Repository();
	}

	/**
	 * Calculate and update scores for all images in a competition.
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
			$result = $this->images_repo->update_score( $image_id, $data['average_score'] );

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

	/**
	 * Get results for a competition with member details.
	 *
	 * @param int         $competition_id Competition ID.
	 * @param string|null $category       Optional category filter.
	 * @return array<object> Results sorted by score descending.
	 */
	public function get_results( int $competition_id, ?string $category = null ): array {
		// Get all images for the competition.
		$images = $this->images_repo->find_by_competition( $competition_id, $category );

		if ( empty( $images ) ) {
			return array();
		}

		// Get vote averages.
		$averages = $this->votes_repo->calculate_averages( $competition_id, $category );

		// Enrich images with score data.
		$results = array();
		foreach ( $images as $image ) {
			$image_id = (int) $image->id;

			// Use calculated average if available, otherwise use stored score.
			if ( isset( $averages[ $image_id ] ) ) {
				$image->average_score = $averages[ $image_id ]['average_score'];
				$image->vote_count    = $averages[ $image_id ]['vote_count'];
			} else {
				$image->average_score = null !== $image->score ? (float) $image->score : 0.0;
				$image->vote_count    = 0;
			}

			$results[] = $image;
		}

		// Sort by score descending.
		usort(
			$results,
			function ( $a, $b ) {
				if ( $a->average_score === $b->average_score ) {
					return 0;
				}
				return $a->average_score > $b->average_score ? -1 : 1;
			}
		);

		return $results;
	}

	/**
	 * Get leaderboard by grade.
	 *
	 * @param int                $competition_id Competition ID.
	 * @param string|null        $category       Optional category filter.
	 * @param array<int, object> $members        Members lookup array (keyed by member ID).
	 * @return array<string, array<object>> Results grouped by grade.
	 */
	public function get_leaderboard_by_grade( int $competition_id, ?string $category, array $members ): array {
		$results = $this->get_results( $competition_id, $category );

		$leaderboard = array();

		foreach ( $results as $image ) {
			$member_id = (int) $image->member_id;
			$grade     = isset( $members[ $member_id ] ) ? $members[ $member_id ]->grade : 'Unknown';

			if ( ! isset( $leaderboard[ $grade ] ) ) {
				$leaderboard[ $grade ] = array();
			}

			$leaderboard[ $grade ][] = $image;
		}

		return $leaderboard;
	}

	/**
	 * Get top N winners for a category/grade.
	 *
	 * @param int                $competition_id Competition ID.
	 * @param string             $category       Category slug.
	 * @param string             $grade          Grade name.
	 * @param array<int, object> $members        Members lookup array.
	 * @param int                $limit          Number of winners to return.
	 * @return array<object>
	 */
	public function get_top_winners( int $competition_id, string $category, string $grade, array $members, int $limit = 3 ): array {
		$results = $this->get_results( $competition_id, $category );

		// Filter by grade.
		$filtered = array_filter(
			$results,
			function ( $image ) use ( $members, $grade ) {
				$member_id = (int) $image->member_id;
				return isset( $members[ $member_id ] ) && $members[ $member_id ]->grade === $grade;
			}
		);

		// Return top N.
		return array_slice( $filtered, 0, $limit );
	}
}
