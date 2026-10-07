<?php
/**
 * Repository for recorded results.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * A competition's recorded results: one row per entry, with its total score,
 * vote count, grade and position. A row outlives its entry and its member:
 * deleting either sets the row's entry or member ID to null.
 *
 * @since 0.4.0
 */
class Recorded_Results_Repository extends Abstract_Repository {

	/**
	 * Table suffix.
	 *
	 * @return string
	 */
	protected function table_suffix(): string {
		return 'photocomp_recorded_results';
	}

	/**
	 * Whether a competition's results are recorded.
	 *
	 * @param int $competition_id Competition ID.
	 * @return bool
	 */
	public function has_record( int $competition_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE competition_id = %d LIMIT 1', $this->table(), $competition_id ) );
	}

	/**
	 * A category's recorded rows, by position, in the order they were recorded.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return array<int, object>
	 */
	public function find_by_category( int $competition_id, string $category ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE competition_id = %d AND category = %s ORDER BY position, id',
				$this->table(),
				$competition_id,
				$category
			)
		);
	}

	/**
	 * Record rows in one statement, so they go in whole or not at all. A row
	 * for an entry that's already recorded is left as it is, so two requests
	 * recording the same competition at once leave one record.
	 *
	 * @param array<int, array{competition_id: int, category: string, entry_id: int, member_id: int|null, grade: string, total_score: int, vote_count: int, position: int}> $rows Rows to record.
	 * @return int|WP_Error Number of rows recorded, or error.
	 */
	public function insert( array $rows ) {
		global $wpdb;

		if ( empty( $rows ) ) {
			return 0;
		}

		$placeholders = array();
		$values       = array( $this->table() );
		$now          = utc_time();
		foreach ( $rows as $row ) {
			// A member ID of 0 stands for none: wpdb::prepare() has no NULL placeholder.
			$placeholders[] = '(%d, %s, %d, NULLIF(%d, 0), %s, %d, %d, %d, %s)';
			array_push(
				$values,
				(int) $row['competition_id'],
				(string) $row['category'],
				(int) $row['entry_id'],
				(int) $row['member_id'],
				(string) $row['grade'],
				(int) $row['total_score'],
				(int) $row['vote_count'],
				(int) $row['position'],
				$now
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- One row of placeholders per entry; the count matches at runtime.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders only holds literal placeholders.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (competition_id, category, entry_id, member_id, grade, total_score, vote_count, position, created_at)
				VALUES ' . implode( ', ', $placeholders ) . '
				ON DUPLICATE KEY UPDATE id = id',
				$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( false === $inserted ) {
			return new WP_Error( 'record_failed', __( 'Could not record the results.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return (int) $inserted;
	}

	/**
	 * Replace a competition's record with new rows.
	 *
	 * @param int                     $competition_id Competition ID.
	 * @param array<int, array{competition_id: int, category: string, entry_id: int, member_id: int|null, grade: string, total_score: int, vote_count: int, position: int}> $rows Rows to record.
	 * @return int|WP_Error Number of rows recorded, or error.
	 */
	public function replace( int $competition_id, array $rows ) {
		if ( ! $this->delete_by_competition( $competition_id ) ) {
			return new WP_Error( 'record_failed', __( 'Could not record the results.', 'photo-competition-manager' ) );
		}

		return $this->insert( $rows );
	}

	/**
	 * Delete a competition's record.
	 *
	 * @param int $competition_id Competition ID.
	 * @return bool
	 */
	public function delete_by_competition( int $competition_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete( $this->table(), array( 'competition_id' => $competition_id ), array( '%d' ) );
	}

	/**
	 * Keep an entry's recorded rows once the entry is deleted, without its ID.
	 *
	 * @param int $entry_id Entry ID.
	 * @return bool
	 */
	public function forget_entry( int $entry_id ): bool {
		return $this->forget( 'entry_id', $entry_id );
	}

	/**
	 * Keep a member's recorded rows once the member is deleted, without their ID.
	 *
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	public function forget_member( int $member_id ): bool {
		return $this->forget( 'member_id', $member_id );
	}

	/**
	 * Set an ID column to null wherever it holds an ID.
	 *
	 * @param string $column entry_id or member_id.
	 * @param int    $id     The ID.
	 * @return bool
	 */
	private function forget( string $column, int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = NULL WHERE %i = %d', $this->table(), $column, $column, $id ) );
	}
}
