<?php
/**
 * Rank a competition category's results within each grade.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Recorded_Results_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use WP_Error;

/**
 * The one ranking used by the admin Results screen and export, the results
 * email, and the results and top 3 pages.
 *
 * Once a competition's results are published, or it has closed, its results
 * are read from its record, so deleting a member, removing an entry or
 * changing a grade afterwards moves nobody. Until then they're worked out
 * from the votes on every read. A record made before the competition closed
 * counts only while results are published; otherwise it's made afresh.
 *
 * @since 0.4.0
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
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Recorded results repository.
	 *
	 * @var Recorded_Results_Repository
	 */
	private $record;

	/**
	 * Constructor.
	 *
	 * @since 0.4.0
	 *
	 * @param Images_Repository                $images       Images repository.
	 * @param Votes_Repository                 $votes        Votes repository.
	 * @param Members_Repository               $members      Members repository.
	 * @param Competitions_Repository|null     $competitions Competitions repository.
	 * @param Competition_Workflow|null        $workflow     Competition workflow.
	 * @param Recorded_Results_Repository|null $record       Recorded results repository.
	 */
	public function __construct(
		Images_Repository $images,
		Votes_Repository $votes,
		Members_Repository $members,
		?Competitions_Repository $competitions = null,
		?Competition_Workflow $workflow = null,
		?Recorded_Results_Repository $record = null
	) {
		$this->images       = $images;
		$this->votes        = $votes;
		$this->members      = $members;
		$this->competitions = $competitions ?? new Competitions_Repository();
		$this->workflow     = $workflow ?? new Competition_Workflow( $this->competitions, $images, $votes, null, $this );
		$this->record       = $record ?? new Recorded_Results_Repository();
	}

	/**
	 * Rank a category's entries within each of the club's grades.
	 *
	 * A competition whose results are published, or that has closed, is
	 * read from its record, and recorded first if it has no trusted one.
	 * Other competitions are worked out from the votes.
	 *
	 * In recorded results, an entry keeps the grade it was entered in, and
	 * an entry whose member or entry has since been deleted keeps its place
	 * with a null `member` or `image`. A grade no longer on the club's list
	 * follows the club's grades, labelled with its slug. Each entry's
	 * `recorded` says whether it was read from the record.
	 *
	 * @since 0.4.0
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return array<int, array{slug: string, label: string, ungraded: bool, entries: array<int, array{position: int, image: object|null, member: object|null, total_score: int, vote_count: int, recorded: bool}>}>
	 */
	public function rank_category( int $competition_id, string $category ): array {
		$competition = $this->competitions->find( $competition_id, true );

		if ( $competition && $this->workflow->reads_recorded_results( $competition ) && true === $this->record_if_missing( $competition ) ) {
			$groups = $this->rank_recorded( $competition_id, $category );
		} else {
			$groups = $this->rank_live( $competition_id, $category );
		}

		// Labelled here, not in empty_groups(): the upgrade records results
		// before translations can load.
		foreach ( $groups as $index => $group ) {
			if ( $group['ungraded'] ) {
				$groups[ $index ]['label'] = __( 'Ungraded', 'photo-competition-manager' );
			}
		}

		return $groups;
	}

	/**
	 * Record a competition's results as they are now, when they're published.
	 *
	 * The record is replaced, unless the competition has closed: then a
	 * trusted record stays, so nobody deleted since drops out of it.
	 *
	 * @since 0.4.0
	 *
	 * @param object $competition Competition row.
	 * @return true|WP_Error
	 */
	public function record( object $competition ) {
		if ( $this->workflow->has_closed( $competition ) ) {
			return $this->record_unless_trusted( $competition );
		}

		$replaced = $this->record->replace( (int) $competition->id, $this->live_rows( $competition ) );

		return is_wp_error( $replaced ) ? $replaced : true;
	}

	/**
	 * Record a competition's results if they're published, or it has closed,
	 * and they have no trusted record yet.
	 *
	 * @since 0.4.0
	 *
	 * @param object $competition Competition row.
	 * @return true|WP_Error True when there's nothing to record, or it's recorded.
	 */
	public function record_if_missing( object $competition ) {
		if ( ! $this->workflow->reads_recorded_results( $competition ) ) {
			return true;
		}

		return $this->record_unless_trusted( $competition );
	}

	/**
	 * Record a competition's results as they are now, replacing any record
	 * that isn't trusted.
	 *
	 * @param object $competition Competition row.
	 * @return true|WP_Error
	 */
	private function record_unless_trusted( object $competition ) {
		if ( $this->has_trusted_record( $competition ) ) {
			return true;
		}

		$replaced = $this->record->replace( (int) $competition->id, $this->live_rows( $competition ) );

		return is_wp_error( $replaced ) ? $replaced : true;
	}

	/**
	 * Whether the competition has a record that can be trusted: results are
	 * published, so publishing made it, or it was made once the competition
	 * had become Closed or Archived. A record made before then, while results
	 * were hidden, misses whatever changed since.
	 *
	 * @param object $competition Competition row.
	 * @return bool
	 */
	private function has_trusted_record( object $competition ): bool {
		$recorded_at = $this->record->recorded_at( (int) $competition->id );

		if ( null === $recorded_at ) {
			return false;
		}

		if ( $this->workflow->results_published( $competition ) ) {
			return true;
		}

		$closed_at = $this->workflow->closed_at( $competition );

		return null !== $closed_at && $recorded_at >= $closed_at;
	}

	/**
	 * The rows that record every category's results as they are now.
	 *
	 * @param object $competition Competition row.
	 * @return array<int, array{competition_id: int, category: string, entry_id: int, member_id: int|null, grade: string, total_score: int, vote_count: int, position: int}>
	 */
	private function live_rows( object $competition ): array {
		$categories = Competition_Settings::get_categories( Competition_Settings::parse( $competition->settings ) );
		$rows       = array();

		foreach ( $categories as $category ) {
			$slug = (string) ( $category['slug'] ?? '' );

			foreach ( $this->rank_live( (int) $competition->id, $slug ) as $group ) {
				foreach ( $group['entries'] as $entry ) {
					$rows[] = array(
						'competition_id' => (int) $competition->id,
						'category'       => $slug,
						'entry_id'       => (int) $entry['image']->id,
						'member_id'      => $entry['member'] ? (int) $entry['member']->id : null,
						'grade'          => $group['slug'],
						'total_score'    => $entry['total_score'],
						'vote_count'     => $entry['vote_count'],
						'position'       => $entry['position'],
					);
				}
			}
		}

		return $rows;
	}

	/**
	 * Group a category's recorded rows by the grade each entry was entered in.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return array<int, array{slug: string, label: string, ungraded: bool, entries: array<int, array{position: int, image: object|null, member: object|null, total_score: int, vote_count: int, recorded: bool}>}>
	 */
	private function rank_recorded( int $competition_id, string $category ): array {
		$rows = $this->record->find_by_category( $competition_id, $category );
		if ( empty( $rows ) ) {
			return array();
		}

		$images = array();
		foreach ( $this->images->find_by_competition( $competition_id, $category ) as $image ) {
			$images[ (int) $image->id ] = $image;
		}

		$members = $this->members->find_many( array_filter( array_map( 'intval', array_column( $rows, 'member_id' ) ) ) );
		$groups  = $this->empty_groups();

		foreach ( $rows as $row ) {
			$slug = (string) $row->grade;
			$key  = '' === $slug ? '' : 'grade:' . $slug;

			if ( ! isset( $groups[ $key ] ) ) {
				// A grade removed from the club's list since: its label is gone.
				$groups[ $key ] = array(
					'slug'     => $slug,
					'label'    => $slug,
					'ungraded' => false,
					'entries'  => array(),
				);
			}

			$groups[ $key ]['entries'][] = array(
				'position'    => (int) $row->position,
				'image'       => $images[ (int) $row->entry_id ] ?? null,
				'member'      => $members[ (int) $row->member_id ] ?? null,
				'total_score' => (int) $row->total_score,
				'vote_count'  => (int) $row->vote_count,
				'recorded'    => true,
			);
		}

		// Ungraded entries go last, after any grade no longer on the club's list.
		$ungraded = $groups[''];
		unset( $groups[''] );
		$groups[] = $ungraded;

		return array_values(
			array_filter(
				$groups,
				static fn( array $group ): bool => ! empty( $group['entries'] )
			)
		);
	}

	/**
	 * Empty groups for the club's grades, in order, then the ungraded group
	 * keyed '', unlabelled. Each grade is keyed 'grade:<slug>', so a numeric
	 * slug keeps its key.
	 *
	 * @return array<string, array{slug: string, label: string, ungraded: bool, entries: array}>
	 */
	private function empty_groups(): array {
		$groups = array();
		foreach ( Competition_Settings::club_grades() as $grade ) {
			$groups[ 'grade:' . $grade['slug'] ] = array(
				'slug'     => $grade['slug'],
				'label'    => $grade['label'],
				'ungraded' => false,
				'entries'  => array(),
			);
		}

		$groups[''] = array(
			'slug'     => '',
			'label'    => '',
			'ungraded' => true,
			'entries'  => array(),
		);

		return $groups;
	}

	/**
	 * Rank a category's entries within each of the club's grades, from the votes.
	 *
	 * An entry's total score is the sum of its current votes, 0 with none.
	 * Positions are dense: tied entries share a position and the next score
	 * takes the next one (1, 1, 2). Groups follow the club's grade order.
	 * Ungraded entries, whose member is missing or holds no club grade, are
	 * ranked in a trailing group marked `ungraded`. Empty groups are left out.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return array<int, array{slug: string, label: string, ungraded: bool, entries: array<int, array{position: int, image: object, member: object|null, total_score: int, vote_count: int, recorded: bool}>}>
	 */
	private function rank_live( int $competition_id, string $category ): array {
		$images = $this->images->find_by_competition( $competition_id, $category );
		if ( empty( $images ) ) {
			return array();
		}

		$votes   = $this->votes->calculate_averages( $competition_id, $category );
		$members = $this->members->find_many( array_column( $images, 'member_id' ) );

		$groups = $this->empty_groups();

		foreach ( $images as $image ) {
			$member = $members[ (int) $image->member_id ] ?? null;
			$key    = $member ? 'grade:' . $member->grade : '';

			$groups[ isset( $groups[ $key ] ) ? $key : '' ]['entries'][] = array(
				'image'       => $image,
				'member'      => $member,
				'total_score' => $votes[ (int) $image->id ]['total_score'] ?? 0,
				'vote_count'  => $votes[ (int) $image->id ]['vote_count'] ?? 0,
				'recorded'    => false,
			);
		}

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
