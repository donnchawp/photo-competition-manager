<?php
/**
 * Repository for voting tokens.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Repository for voting authentication tokens.
 *
 * @package PhotoCompetitionManager\Repository
 */
class Voting_Token_Repository extends Abstract_Repository {

	/**
	 * Create a new voting token.
	 *
	 * @param int    $member_id      Member ID.
	 * @param int    $competition_id Competition ID.
	 * @param string $category        Category slug.
	 * @param string $token_hash     Hashed token.
	 * @param string $expires_at     Expiration datetime.
	 * @return int|WP_Error Token ID or error.
	 */
	public function create( int $member_id, int $competition_id, string $category, string $token_hash, string $expires_at ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return new WP_Error( 'missing_table', __( 'Voting token table is not available.', 'photo-competition-manager' ) );
		}

		if ( $member_id <= 0 || $competition_id <= 0 || empty( $category ) || empty( $token_hash ) || empty( $expires_at ) ) {
			return new WP_Error( 'invalid_data', __( 'Invalid token data provided.', 'photo-competition-manager' ) );
		}

		$payload = array(
			'member_id'      => $member_id,
			'competition_id' => $competition_id,
			'category'       => $category,
			'token_hash'     => $token_hash,
			'expires_at'     => $expires_at,
			'created_at'     => utc_time(),
		);

		$format = array( '%d', '%d', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create voting token.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Find a valid token by hash.
	 *
	 * Records the first access timestamp if this is the first time the token is used.
	 *
	 * @param string $token_hash Hashed token.
	 * @return object|null Token record or null if not found/invalid.
	 */
	public function find_valid_token( string $token_hash ) {
		global $wpdb;

		if ( ! $this->table_exists() || empty( $token_hash ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$token = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i
				WHERE token_hash = %s
				AND used_at IS NULL
				AND expires_at > %s
				LIMIT 1',
				$this->table(),
				$token_hash,
				utc_time()
			)
		);

		// Track first access if token is found and hasn't been accessed before.
		if ( $token && null === $token->first_accessed_at ) {
			$now = utc_time();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$this->table(),
				array( 'first_accessed_at' => $now ),
				array( 'id' => $token->id ),
				array( '%s' ),
				array( '%d' )
			);

			// Update the token object to reflect the change.
			$token->first_accessed_at = $now;
		}

		return $token;
	}

	/**
	 * Clean up expired tokens.
	 *
	 * @return int|false Number of deleted rows or false on failure.
	 */
	public function cleanup_expired() {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->query(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at < %s',
				$this->table(),
				utc_time()
			)
		);
	}

	/**
	 * Check if member has a recent unused token for competition/category.
	 *
	 * @param int    $member_id      Member ID.
	 * @param int    $competition_id Competition ID.
	 * @param string $category        Category slug.
	 * @return bool
	 */
	public function has_recent_token( int $member_id, int $competition_id, string $category ): bool {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return false;
		}

		// Check for tokens created in the last 5 minutes that are still valid.
		$now              = utc_time();
		$recent_threshold = utc_time( -5 * MINUTE_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				WHERE member_id = %d
				AND competition_id = %d
				AND category = %s
				AND used_at IS NULL
				AND expires_at > %s
				AND created_at > %s',
				$this->table(),
				$member_id,
				$competition_id,
				$category,
				$now,
				$recent_threshold
			)
		);

		return $count > 0;
	}

	/**
	 * Get tracking data for all members in a competition.
	 *
	 * Returns array indexed by member_id with link sent/opened status.
	 *
	 * @since 0.1.0
	 * @param int $competition_id Competition ID.
	 * @return array<int, object> Array of tracking data indexed by member_id.
	 */
	public function get_tracking_by_competition( int $competition_id ): array {
		global $wpdb;

		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT
					member_id,
					MIN(created_at) as first_sent_at,
					MIN(first_accessed_at) as first_opened_at,
					COUNT(*) as token_count
				FROM %i
				WHERE competition_id = %d
				GROUP BY member_id',
				$this->table(),
				$competition_id
			)
		);

		$tracking = array();
		foreach ( $results as $row ) {
			$tracking[ (int) $row->member_id ] = $row;
		}

		return $tracking;
	}

	/**
	 * Delete all voting tokens for a competition and category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return bool
	 */
	public function delete_by_competition_and_category( int $competition_id, string $category ): bool {
		global $wpdb;

		if ( ! $this->table_exists() || $competition_id <= 0 || empty( $category ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$this->table(),
			array(
				'competition_id' => $competition_id,
				'category'       => $category,
			),
			array( '%d', '%s' )
		);

		return false !== $deleted;
	}

	/**
	 * Delete all voting tokens for a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @return bool
	 */
	public function delete_by_competition( int $competition_id ): bool {
		global $wpdb;

		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$this->table(),
			array( 'competition_id' => $competition_id ),
			array( '%d' )
		);

		return false !== $deleted;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'photocomp_voting_tokens';
	}
}
