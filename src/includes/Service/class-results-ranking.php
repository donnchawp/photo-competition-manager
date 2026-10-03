<?php
/**
 * Rank a competition category's results within each grade.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * The one ranking used by the admin Results screen and export, the results
 * email, and the results and top 3 pages.
 *
 * @since 0.3.0
 */
class Results_Ranking {

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
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Constructor.
	 *
	 * @param Images_Repository  $images  Images repository.
	 * @param Votes_Repository   $votes   Votes repository.
	 * @param Members_Repository $members Members repository.
	 */
	public function __construct( Images_Repository $images, Votes_Repository $votes, Members_Repository $members ) {
		$this->images  = $images;
		$this->votes   = $votes;
		$this->members = $members;
	}

	/**
	 * Rank a category's entries within each of the club's grades.
	 *
	 * An entry's total score is the sum of its current votes, 0 with none.
	 * Positions are dense: tied entries share a position and the next score
	 * takes the next one (1, 1, 2). Groups follow the club's grade order.
	 * Ungraded entries, whose member is missing or holds no club grade, are
	 * ranked in a trailing group marked `ungraded`. Empty groups are left out.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return array<int, array{slug: string, label: string, ungraded: bool, entries: array<int, array{position: int, image: object, member: object|null, total_score: int, vote_count: int}>}>
	 */
	public function rank_category( int $competition_id, string $category ): array {
		$images = $this->images->find_by_competition( $competition_id, $category );
		if ( empty( $images ) ) {
			return array();
		}

		$votes   = $this->votes->calculate_averages( $competition_id, $category );
		$members = $this->members->find_many( array_column( $images, 'member_id' ) );

		$groups = array();
		foreach ( Competition_Settings::club_grades() as $grade ) {
			$groups[ $grade['slug'] ] = array(
				'slug'     => $grade['slug'],
				'label'    => $grade['label'],
				'ungraded' => false,
				'entries'  => array(),
			);
		}

		$ungraded = array(
			'slug'     => '',
			'label'    => __( 'Ungraded', 'photo-competition-manager' ),
			'ungraded' => true,
			'entries'  => array(),
		);

		foreach ( $images as $image ) {
			$member = $members[ (int) $image->member_id ] ?? null;
			$entry  = array(
				'image'       => $image,
				'member'      => $member,
				'total_score' => $votes[ (int) $image->id ]['total_score'] ?? 0,
				'vote_count'  => $votes[ (int) $image->id ]['vote_count'] ?? 0,
			);

			$slug = $member ? (string) $member->grade : '';
			if ( isset( $groups[ $slug ] ) ) {
				$groups[ $slug ]['entries'][] = $entry;
			} else {
				$ungraded['entries'][] = $entry;
			}
		}

		$groups[] = $ungraded;

		$ranked = array();
		foreach ( $groups as $group ) {
			if ( empty( $group['entries'] ) ) {
				continue;
			}

			$group['entries'] = $this->assign_positions( $group['entries'] );
			$ranked[]         = $group;
		}

		return $ranked;
	}

	/**
	 * Sort entries by total score, highest first, and give each its dense position.
	 *
	 * @param array<int, array{total_score: int}> $entries Entries in one group, without positions.
	 * @return array<int, array{total_score: int, position: int}>
	 */
	private function assign_positions( array $entries ): array {
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return $b['total_score'] <=> $a['total_score'];
			}
		);

		$position       = 0;
		$previous_score = null;
		foreach ( $entries as $index => $entry ) {
			if ( $entry['total_score'] !== $previous_score ) {
				++$position;
				$previous_score = $entry['total_score'];
			}
			$entries[ $index ]['position'] = $position;
		}

		return $entries;
	}
}
