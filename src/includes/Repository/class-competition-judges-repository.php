<?php
/**
 * Repository for the judges assigned to a competition.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Repository for competition-judge assignments, each carrying the judge's private link token.
 *
 * @since 0.4.0
 *
 * @package PhotoCompetitionManager\Repository
 */
class Competition_Judges_Repository extends Abstract_Repository {

	/**
	 * Assign a judge to a competition.
	 *
	 * An already-assigned judge keeps their existing token.
	 *
	 * @since 0.4.0
	 * @param int $competition_id Competition ID.
	 * @param int $judge_id       Judge ID.
	 * @return string|WP_Error The raw link token or error.
	 */
	public function assign( int $competition_id, int $judge_id ) {
		global $wpdb;

		if ( $competition_id <= 0 || ! ( new Judges_Repository() )->find( $judge_id ) ) {
			return new WP_Error( 'invalid_data', __( 'Invalid competition or judge.', 'photo-competition-manager' ) );
		}

		$existing = $this->existing_token( $competition_id, $judge_id );
		if ( null !== $existing ) {
			return $existing;
		}

		$token = bin2hex( random_bytes( 32 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'competition_id' => $competition_id,
				'judge_id'       => $judge_id,
				'token'          => $token,
				'created_at'     => utc_time(),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			// A concurrent assign may have won the unique key.
			$existing = $this->existing_token( $competition_id, $judge_id );
			if ( null !== $existing ) {
				return $existing;
			}

			return new WP_Error( 'db_insert_failed', __( 'Could not assign the judge.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return $token;
	}

	/**
	 * Remove a judge from a competition, revoking their link. Their votes stay.
	 *
	 * @since 0.4.0
	 * @param int $competition_id Competition ID.
	 * @param int $judge_id       Judge ID.
	 * @return bool
	 */
	public function unassign( int $competition_id, int $judge_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete(
			$this->table(),
			array(
				'competition_id' => $competition_id,
				'judge_id'       => $judge_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Judges assigned to a competition, with their link token and email-sent time.
	 *
	 * @since 0.4.0
	 * @param int $competition_id Competition ID.
	 * @return object[]
	 */
	public function judges_for( int $competition_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT j.*, cj.token, cj.sent_at FROM %i AS cj
				INNER JOIN %i AS j ON j.id = cj.judge_id
				WHERE cj.competition_id = %d
				ORDER BY j.name, j.id',
				$this->table(),
				$wpdb->prefix . 'photocomp_judges',
				$competition_id
			)
		);

		return $rows ? $rows : array();
	}

	/**
	 * Find an assignment by its link token.
	 *
	 * @since 0.4.0
	 * @param string $token Raw link token.
	 * @return object|null Object with competition_id, judge_id, name and email.
	 */
	public function find_by_token( string $token ): ?object {
		global $wpdb;

		if ( '' === $token ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT cj.competition_id, cj.judge_id, j.name, j.email FROM %i AS cj
				INNER JOIN %i AS j ON j.id = cj.judge_id
				WHERE cj.token = %s
				LIMIT 1',
				$this->table(),
				$wpdb->prefix . 'photocomp_judges',
				$token
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Record that the judge's link email was sent.
	 *
	 * @since 0.4.0
	 * @param int $competition_id Competition ID.
	 * @param int $judge_id       Judge ID.
	 * @return void
	 */
	public function mark_sent( int $competition_id, int $judge_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table(),
			array( 'sent_at' => utc_time() ),
			array(
				'competition_id' => $competition_id,
				'judge_id'       => $judge_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Delete every judge assignment for a competition.
	 *
	 * @since 0.4.0
	 * @param int $competition_id Competition ID.
	 * @return void
	 */
	public function delete_by_competition( int $competition_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table(), array( 'competition_id' => $competition_id ), array( '%d' ) );
	}

	/**
	 * Look up an assignment's existing token.
	 *
	 * @param int $competition_id Competition ID.
	 * @param int $judge_id       Judge ID.
	 * @return string|null
	 */
	private function existing_token( int $competition_id, int $judge_id ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$token = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT token FROM %i WHERE competition_id = %d AND judge_id = %d',
				$this->table(),
				$competition_id,
				$judge_id
			)
		);

		return $token ? (string) $token : null;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'photocomp_competition_judges';
	}
}
