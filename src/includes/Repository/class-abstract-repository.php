<?php
/**
 * Base repository for custom tables.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Abstract class Abstract_Repository
 *
 * @package PhotoCompetitionManager\Repository
 */
abstract class Abstract_Repository {

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public function table(): string {
		global $wpdb;

		return $wpdb->prefix . $this->table_suffix();
	}

	/**
	 * Determine whether the table exists.
	 *
	 * @return bool
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Retrieve table suffix (without prefix).
	 *
	 * @return string
	 */
	abstract protected function table_suffix(): string;
}
