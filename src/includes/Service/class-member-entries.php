<?php
/**
 * Every image a member has entered.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Recorded_Results_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * A member's entries from every competition, for their own page. Total
 * score and position come from the recorded results, and only once the competition's
 * results are published. Entries in an archived competition are listed
 * without their image, since archived images aren't served.
 *
 * @since 0.5.0
 */
class Member_Entries {

	/**
	 * Sort by competition, newest first.
	 */
	const SORT_DATE = 'date';

	/**
	 * Sort by position, best first, unpositioned last.
	 */
	const SORT_POSITION = 'position';

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Recorded results repository.
	 *
	 * @var Recorded_Results_Repository
	 */
	private $recorded;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Entries module, for image URLs.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Competition labels.
	 *
	 * @var Competition_Labels
	 */
	private $labels;

	/**
	 * Constructor.
	 *
	 * @param Images_Repository|null           $images       Images repository.
	 * @param Competitions_Repository|null     $competitions Competitions repository.
	 * @param Recorded_Results_Repository|null $recorded     Recorded results repository.
	 * @param Competition_Workflow|null        $workflow     Competition workflow.
	 * @param Entries|null                     $entries      Entries module.
	 * @param Competition_Labels|null          $labels       Competition labels.
	 */
	public function __construct(
		?Images_Repository $images = null,
		?Competitions_Repository $competitions = null,
		?Recorded_Results_Repository $recorded = null,
		?Competition_Workflow $workflow = null,
		?Entries $entries = null,
		?Competition_Labels $labels = null
	) {
		$this->images       = $images ?? new Images_Repository();
		$this->competitions = $competitions ?? new Competitions_Repository();
		$this->recorded     = $recorded ?? new Recorded_Results_Repository();
		$this->workflow     = $workflow ?? new Competition_Workflow();
		$this->entries      = $entries ?? new Entries();
		$this->labels       = $labels ?? new Competition_Labels();
	}

	/**
	 * A member's entries.
	 *
	 * Each has the entry's id and status, the competition's title and open
	 * date, the category's label, the photographer (interclub only), the
	 * image and thumbnail URLs ('' when the competition is archived or the
	 * file is missing), whether the member has hidden it from past
	 * competitions, whether it could show there at all (an entered image in
	 * a competition that isn't archived), and the total score, position and grade name (null
	 * until results are published; an entry ranked without a grade is
	 * "Ungraded", as on the results page).
	 *
	 * @since 0.5.0
	 *
	 * @param object $member Member row.
	 * @param string $sort   SORT_DATE (newest competition first) or SORT_POSITION (best position first, unpositioned last).
	 * @return array<int, object>
	 */
	public function for_member( object $member, string $sort ): array {
		$member_id    = (int) $member->id;
		$recorded     = array_column( $this->recorded->find_by_member( $member_id ), null, 'entry_id' );
		$competitions = array();
		$rows         = array();

		foreach ( $this->images->find_by_member( $member_id ) as $entry ) {
			$competition_id = (int) $entry->competition_id;
			if ( ! array_key_exists( $competition_id, $competitions ) ) {
				$competitions[ $competition_id ] = $this->competition( $competition_id, $member );
			}

			$competition = $competitions[ $competition_id ];
			if ( ! $competition ) {
				continue;
			}

			$result = $competition->published ? ( $recorded[ (int) $entry->id ] ?? null ) : null;
			$urls   = $competition->archived ? array(
				'full'  => '',
				'thumb' => '',
			) : $this->entries->public_urls( $competition->row, $entry );

			$rows[] = (object) array(
				'id'                => (int) $entry->id,
				'status'            => (string) $entry->entry_status,
				'competition_title' => $competition->title,
				'competition_date'  => (string) $competition->row->open_date,
				'category'          => (string) ( $competition->categories[ $entry->category ] ?? $entry->category ),
				'photographer'      => $competition->interclub ? (string) $entry->photographer : '',
				'full'              => $urls['full'],
				'thumb'             => $urls['thumb'],
				'archive_hidden'    => (bool) (int) $entry->archive_hidden,
				'may_show'          => Member_Visibility::may_show( $entry, $competition->row ),
				'score'             => $result ? (int) $result->total_score : null,
				'position'          => $result ? (int) $result->position : null,
				'grade'             => $result ? $this->labels->recorded_grade_label( $competition->row, (string) $result->grade ) : null,
			);
		}

		$by_date = static function ( $row ): array {
			return array( $row->competition_date, $row->id );
		};
		usort(
			$rows,
			static function ( $a, $b ) use ( $sort, $by_date ): int {
				if ( self::SORT_POSITION === $sort ) {
					$order = ( $a->position ?? PHP_INT_MAX ) <=> ( $b->position ?? PHP_INT_MAX );
					if ( 0 !== $order ) {
						return $order;
					}
				}

				return $by_date( $b ) <=> $by_date( $a );
			}
		);

		return $rows;
	}

	/**
	 * What every entry in one competition shares, worked out once.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param object $member         Member row, for the title.
	 * @return object|null The row, title, category labels by slug, and whether it's archived, published and interclub; null if it's gone.
	 */
	private function competition( int $competition_id, object $member ): ?object {
		$row = $this->competitions->find( $competition_id, true );
		if ( ! $row ) {
			return null;
		}

		return (object) array(
			'row'        => $row,
			'title'      => $this->labels->title_for( $row, $member ),
			'categories' => array_column( Competition_Settings::get_categories( Competition_Settings::parse( $row->settings ) ), 'label', 'slug' ),
			'archived'   => null !== $row->deleted_at,
			'published'  => $this->workflow->results_published( $row ),
			'interclub'  => Competition_Settings::is_interclub( $row ),
		);
	}
}
