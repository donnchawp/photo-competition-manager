<?php
/**
 * Whoever casts a ballot.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Votes_Repository;
use WP_Error;

/**
 * A voter, as the Ballots module builds them: a link voter or a named voter.
 * Ballots calls these; nothing else should.
 *
 * @since 0.4.0
 */
interface Voter {

	/**
	 * Whether this voter may cast a ballot in the category.
	 *
	 * @param string $category Category slug.
	 * @return bool
	 */
	public function may_vote_in( string $category ): bool;

	/**
	 * Store the voter's ballot.
	 *
	 * @param Votes_Repository $votes          Votes repository.
	 * @param int              $competition_id Competition ID.
	 * @param string           $category       Category slug.
	 * @param array<int,int>   $scores         Image ID => score.
	 * @return int|WP_Error Number of votes recorded, or error.
	 */
	public function record( Votes_Repository $votes, int $competition_id, string $category, array $scores );

	/**
	 * Whether the voter has a ballot in the category.
	 *
	 * @param Votes_Repository $votes          Votes repository.
	 * @param int              $competition_id Competition ID.
	 * @param string           $category       Category slug.
	 * @return bool
	 */
	public function has_ballot( Votes_Repository $votes, int $competition_id, string $category ): bool;
}
