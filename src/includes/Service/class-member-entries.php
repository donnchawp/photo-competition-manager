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
use PhotoCompetitionManager\Repository\Members_Repository;
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
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Constructor.
	 *
	 * @param Images_Repository|null           $images       Images repository.
	 * @param Competitions_Repository|null     $competitions Competitions repository.
	 * @param Recorded_Results_Repository|null $recorded     Recorded results repository.
	 * @param Members_Repository|null          $members      Members repository.
	 * @param Competition_Workflow|null        $workflow     Competition workflow.
	 * @param Entries|null                     $entries      Entries module.
	 * @param Competition_Labels|null          $labels       Competition labels.
	 */
	public function __construct(
		?Images_Repository $images = null,
		?Competitions_Repository $competitions = null,
		?Recorded_Results_Repository $recorded = null,
		?Members_Repository $members = null,
		?Competition_Workflow $workflow = null,
		?Entries $entries = null,
		?Competition_Labels $labels = null
	) {
		$this->images       = $images ?? new Images_Repository();
		$this->competitions = $competitions ?? new Competitions_Repository();
		$this->recorded     = $recorded ?? new Recorded_Results_Repository();
		$this->members      = $members ?? new Members_Repository();
		$this->workflow     = $workflow ?? new Competition_Workflow();
		$this->entries      = $entries ?? new Entries();
		$this->labels       = $labels ?? new Competition_Labels();
	}

	/**
	 * A member's entries.
	 *
	 * Each has the entry's id and status, the competition's title and open
	 * date, the category's label, the photographer (interclub only), whether
	 * the competition is archived, the image and thumbnail URLs ('' when
	 * archived or missing), and the total score, position and grade name
	 * (null until results are published; an entry ranked without a grade is
	 * "Ungraded", as on the results page).
	 *
	 * @since 0.5.0
	 *
	 * @param int    $member_id Member ID.
	 * @param string $sort      SORT_DATE (newest competition first) or SORT_POSITION (best position first, unpositioned last).
	 * @return array<int, object>
	 */
	public function for_member( int $member_id, string $sort ): array {
		$member   = $this->members->find( $member_id );
		$recorded = array_column( $this->recorded->find_by_member( $member_id ), null, 'entry_id' );
		$cache    = array();
		$rows     = array();

		foreach ( $this->images->find_by_member( $member_id ) as $entry ) {
			$competition_id = (int) $entry->competition_id;
			if ( ! array_key_exists( $competition_id, $cache ) ) {
				$cache[ $competition_id ] = $this->competitions->find( $competition_id, true );
			}

			$competition = $cache[ $competition_id ];
			if ( ! $competition ) {
				continue;
			}

			$archived  = null !== $competition->deleted_at;
			$published = $this->workflow->results_published( $competition );
			$result    = $published ? ( $recorded[ (int) $entry->id ] ?? null ) : null;
			$urls      = $archived ? array(
				'full'  => '',
				'thumb' => '',
			) : $this->entries->public_urls( $competition, $entry );
			$interclub = Competition_Settings::is_interclub( $competition );
			$labels    = array_column( Competition_Settings::get_categories( Competition_Settings::parse( $competition->settings ) ), 'label', 'slug' );

			$rows[] = (object) array(
				'id'                => (int) $entry->id,
				'status'            => (string) $entry->entry_status,
				'competition_title' => $this->labels->title_for( $competition, $member ),
				'competition_date'  => (string) $competition->open_date,
				'category'          => (string) ( $labels[ $entry->category ] ?? $entry->category ),
				'photographer'      => $interclub ? (string) $entry->photographer : '',
				'archived'          => $archived,
				'full'              => $urls['full'],
				'thumb'             => $urls['thumb'],
				'score'             => $result ? (int) $result->total_score : null,
				'position'          => $result ? (int) $result->position : null,
				'grade'             => $result ? $this->grade_label( $competition, (string) $result->grade ) : null,
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) use ( $sort ): int {
				if ( self::SORT_POSITION === $sort && $a->position !== $b->position ) {
					if ( null === $a->position || null === $b->position ) {
						return null === $a->position ? 1 : -1;
					}

					return $a->position <=> $b->position;
				}

				return array( $b->competition_date, $b->id ) <=> array( $a->competition_date, $a->id );
			}
		);

		return $rows;
	}

	/**
	 * The grade name of a recorded result: "Ungraded" for an entry ranked without a grade.
	 *
	 * @param object $competition Competition row.
	 * @param string $grade       Recorded grade slug, '' when ungraded.
	 * @return string
	 */
	private function grade_label( object $competition, string $grade ): string {
		return '' === $grade ? __( 'Ungraded', 'photo-competition-manager' ) : $this->labels->grade_label( $competition, $grade );
	}
}
