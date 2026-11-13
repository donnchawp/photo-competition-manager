<?php
/**
 * An external judge.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Votes_Repository;

/**
 * A judge scoring a judged competition. Built by Ballots::judge_voter(), which
 * checks their link. Whether a category is open is Ballots::cast()'s check.
 *
 * @since 0.4.0
 */
final class Judge_Voter implements Voter {

	/**
	 * The judge's assignment: competition_id, judge_id, name and email.
	 *
	 * @var object
	 */
	private $assignment;

	/**
	 * Constructor.
	 *
	 * @param object $assignment Assignment row from Competition_Judges_Repository::find_by_token().
	 */
	public function __construct( object $assignment ) {
		$this->assignment = $assignment;
	}

	/**
	 * The judge's ID.
	 *
	 * @return int
	 */
	public function judge_id(): int {
		return (int) $this->assignment->judge_id;
	}

	/**
	 * The judge's name.
	 *
	 * @return string
	 */
	public function name(): string {
		return (string) $this->assignment->name;
	}

	/**
	 * A judge may score any category that is open.
	 *
	 * @param string $category Category slug.
	 * @return bool
	 */
	public function may_vote_in( string $category ): bool {
		return true;
	}

	/**
	 * Store the judge's ballot.
	 *
	 * @param Votes_Repository $votes          Votes repository.
	 * @param int              $competition_id Competition ID.
	 * @param string           $category       Category slug.
	 * @param array<int,int>   $scores         Image ID => score.
	 * @return int|\WP_Error Number of votes recorded, or error.
	 */
	public function record( Votes_Repository $votes, int $competition_id, string $category, array $scores ) {
		return $votes->create_judge_ballot( $competition_id, $category, $this->judge_id(), $scores );
	}

	/**
	 * Whether the judge has a ballot in the category.
	 *
	 * @param Votes_Repository $votes          Votes repository.
	 * @param int              $competition_id Competition ID.
	 * @param string           $category       Category slug.
	 * @return bool
	 */
	public function has_ballot( Votes_Repository $votes, int $competition_id, string $category ): bool {
		return $votes->has_judge_voted( $competition_id, $category, $this->judge_id() );
	}
}
