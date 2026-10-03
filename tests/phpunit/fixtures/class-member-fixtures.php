<?php
/**
 * Member rows the repository would refuse to save.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

use PhotoCompetitionManager\Repository\Members_Repository;

use function PhotoCompetitionManager\Support\utc_time;

/**
 * Insert members directly, for tests about grades that aren't in the club's
 * list. Members_Repository::create() rejects those, but members saved before
 * it checked grades can still hold one.
 */
class Member_Fixtures {

	/**
	 * Insert a member with any grade, bypassing the repository's checks.
	 *
	 * @param string $name   Name.
	 * @param string $email  Email.
	 * @param string $grade  Grade, valid or not.
	 * @param bool   $active Whether the member is active.
	 * @return int Member ID.
	 */
	public static function insert_with_grade( string $name, string $email, string $grade, bool $active = true ): int {
		global $wpdb;

		$now = utc_time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			( new Members_Repository() )->table(),
			array(
				'name'       => $name,
				'email'      => $active ? $email : Members_Repository::mark_deactivated_email( $email ),
				'grade'      => $grade,
				'active'     => (int) $active,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);

		return (int) $wpdb->insert_id;
	}
}
