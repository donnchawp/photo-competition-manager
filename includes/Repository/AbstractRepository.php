<?php
/**
 * Base repository for custom tables.
 *
 * @package ClubCompetitions\Repository
 */

namespace ClubCompetitions\Repository;

use wpdb;

abstract class AbstractRepository {

	/**
	 * Database connection.
	 *
	 * @var wpdb
	 */
	protected $wpdb;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $wpdb WordPress database instance.
	 */
	public function __construct( wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?: $GLOBALS['wpdb'];
	}

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public function table(): string {
		return $this->wpdb->prefix . $this->table_suffix();
	}

	/**
	 * Determine whether the table exists.
	 *
	 * @return bool
	 */
	public function table_exists(): bool {
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery
		$found = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Retrieve table suffix (without prefix).
	 *
	 * @return string
	 */
	abstract protected function table_suffix(): string;
}
