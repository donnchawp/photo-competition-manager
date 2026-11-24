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
 * from the votes on every read.
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
		// before translations can load. A grade is shown under its competition
		// type's name when it has one; a grade the club no longer has keeps its slug.
		$labels = $competition ? ( new Competition_Labels() )->grade_labels( $competition ) : array();
		foreach ( $groups as $index => $group ) {
			if ( $group['ungraded'] ) {
				$groups[ $index ]['label'] = __( 'Ungraded', 'photo-competition-manager' );
			} elseif ( isset( $labels[ $group['slug'] ] ) ) {
				$groups[ $index ]['label'] = $labels[ $group['slug'] ];
			}
		}

		return $groups;
	}

	/**
	 * Rank an interclub competition's clubs within each of the club's grades.
	 *
	 * A club's panel total is the sum of its entries' total scores in that category, and its
	 * total adds up its panels. Both are ranked within the grade with dense positions (1, 1, 2).
	 * A club needs a panel in every category that any club in its grade entered to get a total
	 * and a place; one that skipped a panel is listed after the placed clubs. The scores come
	 * from rank_category(), so recorded results stay recorded. Ungraded entries, and entries of
	 * a club deleted since, are left out. Clubs with the same place are in name order.
	 *
	 * @since 0.4.0
	 *
	 * @param int                       $competition_id Competition ID.
	 * @param array<string, array>|null $rankings       Each category's rank_category() groups, keyed by slug, when the
	 *                                                  caller has them already; any category missing is ranked here.
	 * @return array<int, array{slug: string, label: string, clubs: array<int, array{member: object, panels: array<string, array{total: int, position: int}|null>, total: int|null, position: int|null}>}>
	 *         Panels are keyed by category slug, in the competition's order, null for a skipped one.
	 */
	public function rank_clubs( int $competition_id, ?array $rankings = null ): array {
		$competition = $this->competitions->find( $competition_id, true );
		if ( ! $competition ) {
			return array();
		}

		$categories = array_column( Competition_Settings::get_categories( Competition_Settings::parse( $competition->settings ) ), 'slug' );

		// Each grade's clubs, with each club's total score in each category it entered.
		$grades = array();
		foreach ( $this->empty_groups() as $key => $group ) {
			if ( ! $group['ungraded'] ) {
				$grades[ $key ] = array(
					'slug'  => $group['slug'],
					'label' => $group['label'],
					'clubs' => array(),
				);
			}
		}

		foreach ( $categories as $category ) {
			foreach ( $rankings[ $category ] ?? $this->rank_category( $competition_id, $category ) as $group ) {
				if ( $group['ungraded'] ) {
					continue;
				}

				$key = 'grade:' . $group['slug'];
				if ( ! isset( $grades[ $key ] ) ) {
					$grades[ $key ] = array(
						'slug'  => $group['slug'],
						'clubs' => array(),
					);
				}
				$grades[ $key ]['label'] = $group['label'];

				foreach ( $group['entries'] as $entry ) {
					if ( ! $entry['member'] ) {
						continue;
					}

					$club_id = (int) $entry['member']->id;
					$club    = $grades[ $key ]['clubs'][ $club_id ] ?? array(
						'member' => $entry['member'],
						'scores' => array(),
					);

					$club['scores'][ $category ]         = ( $club['scores'][ $category ] ?? 0 ) + $entry['total_score'];
					$grades[ $key ]['clubs'][ $club_id ] = $club;
				}
			}
		}

		$ranked = array();
		foreach ( $grades as $grade ) {
			if ( empty( $grade['clubs'] ) ) {
				continue;
			}

			$ranked[] = array(
				'slug'  => $grade['slug'],
				'label' => $grade['label'],
				'clubs' => $this->rank_grade_clubs( $grade['clubs'], $categories ),
			);
		}

		return $ranked;
	}

	/**
	 * Rank one grade's clubs by panel and overall.
	 *
	 * @param array<int, array{member: object, scores: array<string, int>}> $clubs      Clubs keyed by member ID, with their score in each category they entered.
	 * @param string[]                                                      $categories The competition's category slugs, in order.
	 * @return array<int, array{member: object, panels: array<string, array{total: int, position: int}|null>, total: int|null, position: int|null}>
	 */
	private function rank_grade_clubs( array $clubs, array $categories ): array {
		$entered = array();
		foreach ( $categories as $category ) {
			$totals = array();
			foreach ( $clubs as $club_id => $club ) {
				if ( isset( $club['scores'][ $category ] ) ) {
					$totals[ $club_id ] = $club['scores'][ $category ];
				}
			}

			if ( $totals ) {
				$entered[ $category ] = self::dense_positions( $totals );
			}
		}

		$overall = array();
		foreach ( $clubs as $club_id => $club ) {
			if ( ! array_diff_key( $entered, $club['scores'] ) ) {
				$overall[ $club_id ] = array_sum( $club['scores'] );
			}
		}
		$places = self::dense_positions( $overall );

		$ranked = array();
		foreach ( $clubs as $club_id => $club ) {
			$panels = array();
			foreach ( $categories as $category ) {
				$panels[ $category ] = isset( $club['scores'][ $category ] )
					? array(
						'total'    => $club['scores'][ $category ],
						'position' => $entered[ $category ][ $club_id ],
					)
					: null;
			}

			$ranked[] = array(
				'member'   => $club['member'],
				'panels'   => $panels,
				'total'    => $overall[ $club_id ] ?? null,
				'position' => $places[ $club_id ] ?? null,
			);
		}

		usort(
			$ranked,
			static function ( array $a, array $b ): int {
				return array( null === $a['position'], $a['position'], $a['member']->name ) <=> array( null === $b['position'], $b['position'], $b['member']->name );
			}
		);

		return $ranked;
	}

	/**
	 * Dense positions for totals: the highest is 1, ties share one, and the next total takes the next.
	 *
	 * @param array<int, int> $totals Totals keyed by ID.
	 * @return array<int, int> Positions keyed by the same IDs.
	 */
	private static function dense_positions( array $totals ): array {
		$distinct = array_values( array_unique( $totals ) );
		rsort( $distinct );
		$positions = array_flip( $distinct );

		return array_map(
			static function ( int $total ) use ( $positions ): int {
				return $positions[ $total ] + 1;
			},
			$totals
		);
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
		if ( $this->workflow->has_closed( $competition ) && $this->has_trusted_record( $competition ) ) {
			return true;
		}

		return $this->replace_record( $competition );
	}

	/**
	 * Correct the grade members entered a closed competition in, and rank its
	 * categories again from the recorded scores.
	 *
	 * Results are recorded with each member's grade at the time, and a
	 * competition recorded by the 0.4.0 upgrade took members' grades on the
	 * upgrade day. Each member's grade applies to all their entries in the
	 * competition. A grade must be one of the club's, unless it's the one
	 * already recorded. Members with no recorded entries are ignored.
	 *
	 * @since 0.5.1
	 *
	 * @param object             $competition Competition row.
	 * @param array<int, string> $grades      Member ID => grade slug.
	 * @return array<int, array{from: string, to: string}>|WP_Error The members whose grade changed, or an error, when nothing changes.
	 */
	public function correct_grades( object $competition, array $grades ) {
		// Until it has closed, publishing again records it afresh and would undo the correction.
		if ( ! $this->workflow->has_closed( $competition ) ) {
			return new WP_Error( 'competition_not_closed', __( 'Grades can be corrected once the competition has closed. Until then, change the member\'s grade on the Members screen.', 'photo-competition-manager' ) );
		}

		$recorded = $this->record_if_missing( $competition );
		if ( is_wp_error( $recorded ) ) {
			return $recorded;
		}

		$rows    = $this->record->find_by_competition( (int) $competition->id );
		$current = self::grades_of( $rows );

		$changes = array();
		foreach ( $grades as $member_id => $grade ) {
			$member_id = (int) $member_id;
			$grade     = (string) $grade;
			if ( ! isset( $current[ $member_id ] ) || $current[ $member_id ] === $grade ) {
				continue;
			}

			if ( ! Competition_Settings::is_club_grade( $grade ) ) {
				return new WP_Error( 'invalid_grade', __( 'Choose one of the club\'s grades.', 'photo-competition-manager' ) );
			}

			$changes[ $member_id ] = array(
				'from' => $current[ $member_id ],
				'to'   => $grade,
			);
		}

		if ( empty( $changes ) ) {
			return array();
		}

		// Each category's grades, ranked again with the corrected grades.
		$groups = array();
		foreach ( $rows as $row ) {
			$grade = $changes[ (int) $row->member_id ]['to'] ?? (string) $row->grade;

			$groups[ $row->category . "\0" . $grade ][] = array(
				'id'          => (int) $row->id,
				'grade'       => $grade,
				'total_score' => (int) $row->total_score,
			);
		}

		$ranked = array();
		foreach ( $groups as $entries ) {
			array_push( $ranked, ...$this->assign_positions( $entries ) );
		}

		$updated = $this->record->update_rankings( $ranked );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$this->log_grade_corrections( $competition, $changes );

		return $changes;
	}

	/**
	 * The grade each member's entries are recorded in, in a competition.
	 * Members deleted since aren't in it.
	 *
	 * @since 0.5.1
	 *
	 * @param object $competition Competition row.
	 * @return array<int, string> Member ID => grade slug, '' for ungraded.
	 */
	public function recorded_grades( object $competition ): array {
		return self::grades_of( $this->record->find_by_competition( (int) $competition->id ) );
	}

	/**
	 * Member ID => recorded grade, from recorded rows.
	 *
	 * @param array<int, object> $rows Recorded rows.
	 * @return array<int, string>
	 */
	private static function grades_of( array $rows ): array {
		$grades = array();
		foreach ( $rows as $row ) {
			if ( null !== $row->member_id ) {
				$grades[ (int) $row->member_id ] = (string) $row->grade;
			}
		}

		return $grades;
	}

	/**
	 * Log each member whose grade in a competition was corrected.
	 *
	 * @param object                                      $competition Competition row.
	 * @param array<int, array{from: string, to: string}> $changes     Member ID => old and new grade slug.
	 * @return void
	 */
	private function log_grade_corrections( object $competition, array $changes ): void {
		$labels  = new Competition_Labels();
		$logger  = new Event_Logger();
		$members = $this->members->find_many( array_keys( $changes ) );
		$title   = $labels->title_for_everyone( $competition );
		foreach ( $changes as $member_id => $change ) {
			$logger->log(
				(int) $competition->id,
				'grade_corrected',
				'results',
				sprintf(
					/* translators: 1: member name, 2: competition title, 3: old grade name, 4: new grade name */
					__( '%1$s\'s grade in %2$s was corrected from %3$s to %4$s.', 'photo-competition-manager' ),
					isset( $members[ $member_id ] ) ? $members[ $member_id ]->name : '#' . $member_id,
					$title,
					$labels->recorded_grade_label( $competition, $change['from'] ),
					$labels->grade_label( $competition, $change['to'] )
				),
				array(
					'member_id' => $member_id,
					'from'      => $change['from'],
					'to'        => $change['to'],
				)
			);
		}
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
		if ( ! $this->workflow->reads_recorded_results( $competition ) || $this->has_trusted_record( $competition ) ) {
			return true;
		}

		return $this->replace_record( $competition );
	}

	/**
	 * Record a competition's results as they are now, replacing any record.
	 *
	 * The first read after close can replace a record too, not only the
	 * admin's Publish Results. Replacing deletes then inserts, so a read made
	 * between the two by another request finds no rows.
	 *
	 * @param object $competition Competition row.
	 * @return true|WP_Error
	 */
	private function replace_record( object $competition ) {
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
		foreach ( $this->images->find_entered( $competition_id, $category ) as $image ) {
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
		$images = $this->images->find_entered( $competition_id, $category );
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

		$positions = self::dense_positions( array_column( $entries, 'total_score' ) );
		foreach ( $positions as $index => $position ) {
			$entries[ $index ]['position'] = $position;
		}

		return $entries;
	}
}
