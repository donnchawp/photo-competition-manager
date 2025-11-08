<?php
/**
 * Base repository for custom tables.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use RuntimeException;
use wpdb;

/**
 * Abstract class Abstract_Repository
 *
 * @package PhotoCompetitionManager\Repository
 */
abstract class Abstract_Repository {

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
	 * @throws RuntimeException If the WordPress database object is not available.
	 */
	public function __construct( ?wpdb $wpdb = null ) {
		if ( $wpdb instanceof wpdb ) {
			$this->wpdb = $wpdb;
			return;
		}

		if ( isset( $GLOBALS['wpdb'] ) && $GLOBALS['wpdb'] instanceof wpdb ) {
			$this->wpdb = $GLOBALS['wpdb'];
			return;
		}

		throw new RuntimeException( 'WordPress database object is not available.' );
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
