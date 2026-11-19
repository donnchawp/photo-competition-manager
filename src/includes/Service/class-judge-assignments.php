<?php
/**
 * Which judged competitions still need judges.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competition_Judges_Repository;
use PhotoCompetitionManager\Repository\Competitions_Repository;

/**
 * A judged competition that hasn't closed needs judges until one is assigned.
 *
 * @since 0.5.0
 */
final class Judge_Assignments {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Competition judges repository.
	 *
	 * @var Competition_Judges_Repository
	 */
	private $competition_judges;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository|null       $competitions       Competitions repository.
	 * @param Competition_Judges_Repository|null $competition_judges Competition judges repository.
	 */
	public function __construct( ?Competitions_Repository $competitions = null, ?Competition_Judges_Repository $competition_judges = null ) {
		$this->competitions       = $competitions ?? new Competitions_Repository();
		$this->competition_judges = $competition_judges ?? new Competition_Judges_Repository();
	}

	/**
	 * Judged competitions, open or scheduled, with no judges assigned.
	 *
	 * @return array<int, object> Competition rows.
	 */
	public function competitions_needing_judges(): array {
		return array_values(
			array_filter(
				$this->competitions->all_unclosed(),
				function ( $competition ) {
					return Ballots::is_judged( $competition ) && empty( $this->competition_judges->judges_for( (int) $competition->id ) );
				}
			)
		);
	}
}
