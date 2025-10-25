<?php
/**
 * Results analytics service.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Provides analytical methods for competition results.
 *
 * @since 1.0.0
 */
class Results_Analytics {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

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
	 * @param Images_Repository       $images       Images repository.
	 * @param Members_Repository      $members      Members repository.
	 * @param Votes_Repository        $votes        Votes repository.
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Images_Repository $images,
		Members_Repository $members,
		Votes_Repository $votes
	) {
		$this->competitions = $competitions;
		$this->images       = $images;
		$this->members      = $members;
		$this->votes        = $votes;
	}

	/**
	 * Get competition summary statistics.
	 *
	 * @param int $competition_id Competition ID.
	 * @return array{
	 *     total_images: int,
	 *     total_votes: int,
	 *     total_members: int,
	 *     average_score: float,
	 *     categories: array<string, array{images: int, votes: int}>
	 * }
	 */
	public function get_competition_summary( int $competition_id ): array {
		$competition = $this->competitions->find( $competition_id );
		if ( ! $competition ) {
			return array(
				'total_images'  => 0,
				'total_votes'   => 0,
				'total_members' => 0,
				'average_score' => 0.0,
				'categories'    => array(),
			);
		}

		$settings   = Competition_Settings::parse( $competition->settings );
		$categories = Competition_Settings::get_categories( $settings );

		$total_images       = 0;
		$total_votes        = 0;
		$total_score        = 0.0;
		$member_ids         = array();
		$category_breakdown = array();

		foreach ( $categories as $category ) {
			$category_slug = $category['slug'] ?? '';
			if ( empty( $category_slug ) ) {
				continue;
			}

			$images = $this->images->find_by_competition( $competition_id, $category_slug );
			$votes  = $this->votes->find_by_competition( $competition_id, $category_slug );

			$category_images = count( $images );
			$category_votes  = count( $votes );

			$total_images += $category_images;
			$total_votes  += $category_votes;

			// Track unique members.
			foreach ( $images as $image ) {
				$member_ids[ $image->member_id ] = true;
			}

			// Sum scores for average calculation.
			foreach ( $votes as $vote ) {
				$total_score += (float) $vote->score;
			}

			$category_breakdown[ $category_slug ] = array(
				'label'  => $category['label'] ?? $category_slug,
				'images' => $category_images,
				'votes'  => $category_votes,
			);
		}

		$average_score = $total_votes > 0 ? $total_score / $total_votes : 0.0;

		return array(
			'total_images'  => $total_images,
			'total_votes'   => $total_votes,
			'total_members' => count( $member_ids ),
			'average_score' => round( $average_score, 2 ),
			'categories'    => $category_breakdown,
		);
	}

	/**
	 * Get detailed category breakdown.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return array{
	 *     images: int,
	 *     votes: int,
	 *     average_score: float,
	 *     min_score: float,
	 *     max_score: float,
	 *     participation_rate: float
	 * }
	 */
	public function get_category_breakdown( int $competition_id, string $category ): array {
		$images = $this->images->find_by_competition( $competition_id, $category );
		$votes  = $this->votes->find_by_competition( $competition_id, $category );

		$image_count = count( $images );
		$vote_count  = count( $votes );

		if ( 0 === $vote_count ) {
			return array(
				'images'             => $image_count,
				'votes'              => 0,
				'average_score'      => 0.0,
				'min_score'          => 0.0,
				'max_score'          => 0.0,
				'participation_rate' => 0.0,
			);
		}

		$scores = array_map(
			function ( $vote ) {
				return (float) $vote->score;
			},
			$votes
		);

		$total_score   = array_sum( $scores );
		$average_score = $total_score / $vote_count;
		$min_score     = min( $scores );
		$max_score     = max( $scores );

		// Calculate participation rate (images with votes / total images).
		$averages           = $this->votes->calculate_averages( $competition_id, $category );
		$images_with_votes  = count( $averages );
		$participation_rate = $image_count > 0 ? ( $images_with_votes / $image_count ) * 100 : 0.0;

		return array(
			'images'             => $image_count,
			'votes'              => $vote_count,
			'average_score'      => round( $average_score, 2 ),
			'min_score'          => round( $min_score, 2 ),
			'max_score'          => round( $max_score, 2 ),
			'participation_rate' => round( $participation_rate, 1 ),
		);
	}

	/**
	 * Get score distribution for histogram visualization.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return array<float, int> Score => frequency mapping.
	 */
	public function get_score_distribution( int $competition_id, string $category ): array {
		$votes = $this->votes->find_by_competition( $competition_id, $category );

		$distribution = array();

		foreach ( $votes as $vote ) {
			$score = (float) $vote->score;
			if ( ! isset( $distribution[ $score ] ) ) {
				$distribution[ $score ] = 0;
			}
			++$distribution[ $score ];
		}

		// Sort by score ascending.
		ksort( $distribution );

		return $distribution;
	}

	/**
	 * Get voting timeline data.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @param string $interval       Grouping interval ('hour' or 'day').
	 * @return array<string, int> Timestamp => vote count.
	 */
	public function get_voting_timeline( int $competition_id, string $category, string $interval = 'hour' ): array {
		$votes = $this->votes->find_by_competition( $competition_id, $category );

		$timeline = array();

		foreach ( $votes as $vote ) {
			$timestamp = strtotime( $vote->created_at );

			if ( 'day' === $interval ) {
				$key = gmdate( 'Y-m-d', $timestamp );
			} else {
				$key = gmdate( 'Y-m-d H:00', $timestamp );
			}

			if ( ! isset( $timeline[ $key ] ) ) {
				$timeline[ $key ] = 0;
			}
			++$timeline[ $key ];
		}

		// Sort chronologically.
		ksort( $timeline );

		return $timeline;
	}

	/**
	 * Get voter participation statistics.
	 *
	 * @param int $competition_id Competition ID.
	 * @return array{
	 *     total_voters: int,
	 *     total_votes: int,
	 *     average_votes_per_voter: float,
	 *     voters: array<array{name: string, votes: int}>
	 * }
	 */
	public function get_voter_participation( int $competition_id ): array {
		$all_votes = $this->votes->get_votes_by_competition( $competition_id );

		$voter_stats = array();
		$total_votes = 0;

		foreach ( $all_votes as $vote ) {
			$voter_key = $vote->voter_name ? $vote->voter_name : 'Token #' . $vote->voting_token_id;

			if ( ! isset( $voter_stats[ $voter_key ] ) ) {
				$voter_stats[ $voter_key ] = 0;
			}
			++$voter_stats[ $voter_key ];
			++$total_votes;
		}

		$total_voters        = count( $voter_stats );
		$avg_votes_per_voter = $total_voters > 0 ? $total_votes / $total_voters : 0.0;

		// Convert to array format.
		$voters = array();
		foreach ( $voter_stats as $name => $vote_count ) {
			$voters[] = array(
				'name'  => $name,
				'votes' => $vote_count,
			);
		}

		// Sort by vote count descending.
		usort(
			$voters,
			function ( $a, $b ) {
				return $b['votes'] - $a['votes'];
			}
		);

		return array(
			'total_voters'            => $total_voters,
			'total_votes'             => $total_votes,
			'average_votes_per_voter' => round( $avg_votes_per_voter, 1 ),
			'voters'                  => $voters,
		);
	}

	/**
	 * Get detailed analytics for a specific image.
	 *
	 * @param int $image_id Image ID.
	 * @return array{
	 *     image: object|null,
	 *     member: object|null,
	 *     votes: array<object>,
	 *     statistics: array{
	 *         count: int,
	 *         average: float,
	 *         median: float,
	 *         min: float,
	 *         max: float,
	 *         std_dev: float
	 *     }
	 * }
	 */
	public function get_image_details( int $image_id ): array {
		$image = $this->images->find( $image_id );
		if ( ! $image ) {
			return array(
				'image'      => null,
				'member'     => null,
				'votes'      => array(),
				'statistics' => array(
					'count'   => 0,
					'average' => 0.0,
					'median'  => 0.0,
					'min'     => 0.0,
					'max'     => 0.0,
					'std_dev' => 0.0,
				),
			);
		}

		$member = $this->members->find( (int) $image->member_id );
		$votes  = $this->votes->find_by_image( $image_id );

		$scores = array_map(
			function ( $vote ) {
				return (float) $vote->score;
			},
			$votes
		);

		$count = count( $scores );

		if ( 0 === $count ) {
			$statistics = array(
				'count'   => 0,
				'average' => 0.0,
				'median'  => 0.0,
				'min'     => 0.0,
				'max'     => 0.0,
				'std_dev' => 0.0,
			);
		} else {
			sort( $scores );
			$average = array_sum( $scores ) / $count;
			$median  = $this->calculate_median( $scores );
			$min     = min( $scores );
			$max     = max( $scores );
			$std_dev = $this->calculate_std_dev( $scores, $average );

			$statistics = array(
				'count'   => $count,
				'average' => round( $average, 2 ),
				'median'  => round( $median, 2 ),
				'min'     => round( $min, 2 ),
				'max'     => round( $max, 2 ),
				'std_dev' => round( $std_dev, 2 ),
			);
		}

		return array(
			'image'      => $image,
			'member'     => $member,
			'votes'      => $votes,
			'statistics' => $statistics,
		);
	}

	/**
	 * Calculate median of an array of numbers.
	 *
	 * @param array<float> $values Sorted array of values.
	 * @return float
	 */
	private function calculate_median( array $values ): float {
		$count = count( $values );
		if ( 0 === $count ) {
			return 0.0;
		}

		$middle = (int) floor( $count / 2 );

		if ( 0 === $count % 2 ) {
			// Even number of elements - average of middle two.
			return ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2;
		} else {
			// Odd number - middle element.
			return $values[ $middle ];
		}
	}

	/**
	 * Calculate standard deviation.
	 *
	 * @param array<float> $values  Array of values.
	 * @param float        $average Pre-calculated average.
	 * @return float
	 */
	private function calculate_std_dev( array $values, float $average ): float {
		$count = count( $values );
		if ( $count <= 1 ) {
			return 0.0;
		}

		$variance = 0.0;
		foreach ( $values as $value ) {
			$variance += pow( $value - $average, 2 );
		}

		return sqrt( $variance / $count );
	}
}
