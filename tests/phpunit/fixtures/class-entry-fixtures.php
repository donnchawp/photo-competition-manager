<?php
/**
 * Scored competition entries.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;

/**
 * Insert entries with votes, for tests about results.
 */
class Entry_Fixtures {

	/**
	 * Voting token for the next vote, so every vote has its own voter.
	 *
	 * @var int
	 */
	private static $next_voter = 1;

	/**
	 * Insert a member with any grade, and an entry of theirs with the given votes.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @param string $name           Member name; the email is made from it.
	 * @param string $grade          Grade, valid or not.
	 * @param int[]  $scores         Votes to give the entry.
	 * @return int Image ID.
	 */
	public static function insert_scored_entry( int $competition_id, string $category, string $name, string $grade, array $scores ): int {
		$member_id = Member_Fixtures::insert_with_grade( $name, sanitize_title( $name ) . '@example.com', $grade );

		return self::insert_entry( $competition_id, $category, $member_id, $scores );
	}

	/**
	 * Insert an entry for a member ID with the given votes.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @param int    $member_id      Member ID, which need not exist.
	 * @param int[]  $scores         Votes to give the entry.
	 * @return int Image ID.
	 */
	public static function insert_entry( int $competition_id, string $category, int $member_id, array $scores ): int {
		$image_id = (int) ( new Images_Repository() )->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $member_id,
				'category'       => $category,
				'filename'       => 'entry-' . $member_id . '.jpg',
			)
		);

		foreach ( $scores as $score ) {
			( new Votes_Repository() )->create_anonymous( $competition_id, $category, self::$next_voter++, $image_id, $score );
		}

		return $image_id;
	}
}
