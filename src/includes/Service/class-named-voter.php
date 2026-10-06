<?php
/**
 * A voter known by the name they give.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Votes_Repository;

/**
 * A named voter: a name given with the club's voting password, taken on trust.
 * Built by Ballots::named_voter(), which checks the password.
 *
 * Names that differ only in case, accents or surrounding spaces are the same
 * voter. The name is trimmed and cut to what the votes table holds here; the
 * voter_name column's collation (utf8mb4_unicode_520_ci) matches the rest, on
 * purpose, both for has_voted() and for the unique key that stops a second
 * ballot.
 *
 * @since 0.4.0
 */
final class Named_Voter implements Voter {

	/**
	 * The voter's name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Constructor.
	 *
	 * @param string $name The voter's name, as given.
	 */
	public function __construct( string $name ) {
		// The votes table holds 191 characters, and a cut can leave a trailing space.
		$this->name = rtrim( mb_substr( sanitize_text_field( $name ), 0, 191 ) );
	}

	/**
	 * The voter's name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * A named voter may vote in any category.
	 *
	 * @param string $category Category slug.
	 * @return bool
	 */
	public function may_vote_in( string $category ): bool {
		return true;
	}

	/**
	 * Store the voter's ballot under their name.
	 *
	 * @param Votes_Repository $votes          Votes repository.
	 * @param int              $competition_id Competition ID.
	 * @param string           $category       Category slug.
	 * @param array<int,int>   $scores         Image ID => score.
	 * @return int|\WP_Error Number of votes recorded, or error.
	 */
	public function record( Votes_Repository $votes, int $competition_id, string $category, array $scores ) {
		return $votes->create_ballot( $competition_id, $category, $this->name, $scores );
	}

	/**
	 * Whether the voter has a ballot in the category.
	 *
	 * @param Votes_Repository $votes          Votes repository.
	 * @param int              $competition_id Competition ID.
	 * @param string           $category       Category slug.
	 * @return bool
	 */
	public function has_ballot( Votes_Repository $votes, int $competition_id, string $category ): bool {
		return $votes->has_voted( $competition_id, $category, $this->name );
	}
}
