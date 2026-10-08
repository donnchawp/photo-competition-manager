<?php
/**
 * Repository for competition logs.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use function PhotoCompetitionManager\Support\utc_time;

/**
 * Repository for competition logs.
 *
 * @since 0.1.0
 */
class Logs_Repository extends Abstract_Repository {

	/**
	 * Retrieve table suffix (without prefix).
	 *
	 * @return string
	 */
	protected function table_suffix(): string {
		return 'photocomp_logs';
	}

	/**
	 * Create a new log entry.
	 *
	 * @param array $data Log data.
	 * @return int|false The number of rows inserted, or false on error.
	 */
	public function create( array $data ) {
		global $wpdb;

		$defaults = array(
			'competition_id' => null,
			'event_type'     => '',
			'event_category' => '',
			'actor_type'     => '',
			'actor_id'       => null,
			'actor_name'     => '',
			'description'    => '',
			'metadata'       => null,
			'created_at'     => utc_time(),
		);

		$data = wp_parse_args( $data, $defaults );

		// Serialize metadata if it's an array.
		if ( is_array( $data['metadata'] ) ) {
			$data['metadata'] = wp_json_encode( $data['metadata'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$this->table(),
			$data,
			array(
				'%d', // competition_id.
				'%s', // event_type.
				'%s', // event_category.
				'%s', // actor_type.
				'%d', // actor_id.
				'%s', // actor_name.
				'%s', // description.
				'%s', // metadata.
				'%s', // created_at.
			)
		);

		return $result;
	}

	/**
	 * Find logs by competition ID.
	 *
	 * @param int   $competition_id Competition ID.
	 * @param int   $limit Number of records to return.
	 * @param int   $offset Offset for pagination.
	 * @param array $filters Optional filters (event_category, event_type, date_from, date_to).
	 * @return array<int, object>
	 */
	public function find_by_competition( int $competition_id, int $limit = 50, int $offset = 0, array $filters = array() ): array {
		global $wpdb;

		$query        = 'SELECT * FROM %i WHERE competition_id = %d';
		$prepare_args = array( $this->table(), $competition_id );

		// Apply filters.
		if ( ! empty( $filters['event_category'] ) ) {
			$query         .= ' AND event_category = %s';
			$prepare_args[] = $filters['event_category'];
		}

		if ( ! empty( $filters['event_type'] ) ) {
			$query         .= ' AND event_type = %s';
			$prepare_args[] = $filters['event_type'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$query         .= ' AND created_at >= %s';
			$prepare_args[] = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$query         .= ' AND created_at <= %s';
			$prepare_args[] = $filters['date_to'];
		}

		$query .= ' ORDER BY created_at DESC LIMIT %d OFFSET %d';

		// Add LIMIT and OFFSET parameters.
		$prepare_args[] = (int) $limit;
		$prepare_args[] = (int) $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query contains only placeholders (%d, %s), not user input.
		return $wpdb->get_results( $wpdb->prepare( $query, ...$prepare_args ) );
	}

	/**
	 * Find all logs with pagination and filters.
	 *
	 * @param int   $limit Number of records to return.
	 * @param int   $offset Offset for pagination.
	 * @param array $filters Optional filters (competition_id, event_category, event_type, date_from, date_to).
	 * @return array<int, object>
	 */
	public function paginate( int $limit = 50, int $offset = 0, array $filters = array() ): array {
		global $wpdb;

		$query        = 'SELECT * FROM %i WHERE 1=1';
		$prepare_args = array( $this->table() );

		// Apply filters.
		if ( ! empty( $filters['competition_id'] ) ) {
			$query         .= ' AND competition_id = %d';
			$prepare_args[] = (int) $filters['competition_id'];
		}

		if ( ! empty( $filters['event_category'] ) ) {
			$query         .= ' AND event_category = %s';
			$prepare_args[] = $filters['event_category'];
		}

		if ( ! empty( $filters['event_type'] ) ) {
			$query         .= ' AND event_type = %s';
			$prepare_args[] = $filters['event_type'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$query         .= ' AND created_at >= %s';
			$prepare_args[] = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$query         .= ' AND created_at <= %s';
			$prepare_args[] = $filters['date_to'];
		}

		$query .= ' ORDER BY created_at DESC LIMIT %d OFFSET %d';

		// Add LIMIT and OFFSET parameters.
		$prepare_args[] = (int) $limit;
		$prepare_args[] = (int) $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query contains only placeholders (%d, %s), not user input.
		return $wpdb->get_results( $wpdb->prepare( $query, ...$prepare_args ) );
	}

	/**
	 * Count logs with filters.
	 *
	 * @param array $filters Optional filters (competition_id, event_category, event_type, date_from, date_to).
	 * @return int
	 */
	public function count( array $filters = array() ): int {
		global $wpdb;

		$query        = 'SELECT COUNT(*) FROM %i WHERE 1=1';
		$prepare_args = array( $this->table() );

		// Apply filters.
		if ( ! empty( $filters['competition_id'] ) ) {
			$query         .= ' AND competition_id = %d';
			$prepare_args[] = (int) $filters['competition_id'];
		}

		if ( ! empty( $filters['event_category'] ) ) {
			$query         .= ' AND event_category = %s';
			$prepare_args[] = $filters['event_category'];
		}

		if ( ! empty( $filters['event_type'] ) ) {
			$query         .= ' AND event_type = %s';
			$prepare_args[] = $filters['event_type'];
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$query         .= ' AND created_at >= %s';
			$prepare_args[] = $filters['date_from'];
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$query         .= ' AND created_at <= %s';
			$prepare_args[] = $filters['date_to'];
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query contains only placeholders (%d, %s), not user input.
		return (int) $wpdb->get_var( $wpdb->prepare( $query, ...$prepare_args ) );
	}

	/**
	 * Delete logs older than a specified date.
	 *
	 * @param string $date Date in MySQL format (Y-m-d H:i:s).
	 * @return int|false The number of rows deleted, or false on error.
	 */
	public function delete_older_than( string $date ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $this->table(), $date ) );
	}

	/**
	 * Every log row about a member: rows whose metadata holds one of their email
	 * addresses, or their member ID. Oldest first.
	 *
	 * @since 0.4.0
	 *
	 * @param int      $member_id Member ID.
	 * @param string[] $emails    The member's email addresses.
	 * @return array<int, object>
	 */
	public function find_about_member( int $member_id, array $emails ): array {
		global $wpdb;

		list( $conditions, $patterns ) = $this->about_member( $member_id, $emails );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $conditions holds only placeholders, one per pattern.
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$conditions} ORDER BY created_at, id", $this->table(), ...$patterns ) );
	}

	/**
	 * Delete every log row about a member, as find_about_member() finds them.
	 *
	 * @since 0.4.0
	 *
	 * @param int      $member_id Member ID.
	 * @param string[] $emails    The member's email addresses.
	 * @return int|false The number of rows deleted, or false on error.
	 */
	public function delete_about_member( int $member_id, array $emails ) {
		global $wpdb;

		list( $conditions, $patterns ) = $this->about_member( $member_id, $emails );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $conditions holds only placeholders, one per pattern.
		return $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE {$conditions}", $this->table(), ...$patterns ) );
	}

	/**
	 * The condition that finds the log rows about a member, and its values.
	 *
	 * @param int      $member_id Member ID.
	 * @param string[] $emails    The member's email addresses.
	 * @return array{0: string, 1: string[]} Conditions of placeholders only, and a LIKE pattern for each.
	 */
	private function about_member( int $member_id, array $emails ): array {
		global $wpdb;

		// Metadata is JSON, so a value is matched with its quotes, or the
		// punctuation after it, and never as part of a longer one.
		$patterns = array(
			'%"member_id":' . $member_id . ',%',
			'%"member_id":' . $member_id . '}%',
			'%"member_id":"' . $member_id . '"%',
		);
		foreach ( $emails as $email ) {
			$patterns[] = '%' . $wpdb->esc_like( (string) wp_json_encode( $email ) ) . '%';
		}

		return array( implode( ' OR ', array_fill( 0, count( $patterns ), 'metadata LIKE %s' ) ), $patterns );
	}

	/**
	 * Get distinct event categories.
	 *
	 * @return array<int, string>
	 */
	public function get_event_categories(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT event_category FROM %i ORDER BY event_category ASC', $this->table() ) );

		return $results ? $results : array();
	}

	/**
	 * Get distinct event types.
	 *
	 * @return array<int, string>
	 */
	public function get_event_types(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT event_type FROM %i ORDER BY event_type ASC', $this->table() ) );

		return $results ? $results : array();
	}
}
