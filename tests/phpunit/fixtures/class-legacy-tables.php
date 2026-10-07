<?php
/**
 * Tables shaped as older versions of the plugin left them.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

use PhotoCompetitionManager\Repository\Voting_Token_Repository;

/**
 * Hide a real table behind a temporary one with an older shape, for tests
 * about upgrades and about sites whose upgrade hasn't finished.
 *
 * Creating a temporary table doesn't end the test's transaction. The test
 * drops it again with DROP TEMPORARY TABLE in tearDown().
 */
class Legacy_Tables {

	/**
	 * Hide the voting tokens table behind one shaped as version 5 left it on
	 * sites installed before November 2025: member_competition_category is
	 * a plain key, not a unique one, so a member can hold several tokens for
	 * a category. dbDelta can't make it unique, as the name is taken.
	 *
	 * @return string The table name.
	 */
	public static function shadow_v5_voting_tokens(): string {
		global $wpdb;

		$table = ( new Voting_Token_Repository() )->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"CREATE TEMPORARY TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				member_id BIGINT UNSIGNED NOT NULL,
				competition_id BIGINT UNSIGNED NOT NULL,
				category VARCHAR(100) NOT NULL,
				token_hash VARCHAR(64) NOT NULL,
				expires_at DATETIME NOT NULL,
				first_accessed_at DATETIME NULL,
				sent_at DATETIME NULL,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY member_competition_category (member_id, competition_id, category),
				KEY token_hash (token_hash),
				KEY expires_at (expires_at)
			) {$wpdb->get_charset_collate()}"
		);

		return $table;
	}
}
