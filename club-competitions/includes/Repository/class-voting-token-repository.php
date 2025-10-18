<?php
/**
 * Repository for voting tokens.
 *
 * @package ClubCompetitions\Repository
 */

namespace ClubCompetitions\Repository;

use WP_Error;

/**
 * Repository for voting authentication tokens.
 *
 * @package ClubCompetitions\Repository
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
		if ( ! $this->table_exists() ) {
			return new WP_Error( 'missing_table', __( 'Voting token table is not available.', 'club-competitions' ) );
		}

		if ( $member_id <= 0 || $competition_id <= 0 || empty( $category ) || empty( $token_hash ) || empty( $expires_at ) ) {
			return new WP_Error( 'invalid_data', __( 'Invalid token data provided.', 'club-competitions' ) );
		}

		$payload = array(
			'member_id'      => $member_id,
			'competition_id' => $competition_id,
			'category'       => $category,
			'token_hash'     => $token_hash,
			'expires_at'     => $expires_at,
			'created_at'     => current_time( 'mysql' ),
		);

		$format = array( '%d', '%d', '%s', '%s', '%s', '%s' );

		$inserted = $this->wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create voting token.', 'club-competitions' ), $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Find a valid token by hash.
	 *
	 * @param string $token_hash Hashed token.
	 * @return object|null Token record or null if not found/invalid.
	 */
	public function find_valid_token( string $token_hash ) {
		if ( ! $this->table_exists() || empty( $token_hash ) ) {
			return null;
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table()}
				WHERE token_hash = %s
				AND used_at IS NULL
				AND expires_at > %s
				LIMIT 1",
				$token_hash,
				current_time( 'mysql' )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	/**
	 * Mark a token as used.
	 *
	 * @param int $token_id Token ID.
	 * @return bool|WP_Error
	 */
	public function mark_as_used( int $token_id ) {
		if ( ! $this->table_exists() || $token_id <= 0 ) {
			return new WP_Error( 'invalid_token', __( 'Token not found.', 'club-competitions' ) );
		}

		$updated = $this->wpdb->update(
			$this->table(),
			array( 'used_at' => current_time( 'mysql' ) ),
			array( 'id' => $token_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not mark token as used.', 'club-competitions' ), $this->wpdb->last_error );
		}

		if ( 0 === $updated ) {
			return new WP_Error( 'token_not_found', __( 'Token does not exist.', 'club-competitions' ) );
		}

		return true;
	}

	/**
	 * Clean up expired tokens.
	 *
	 * @return int|false Number of deleted rows or false on failure.
	 */
	public function cleanup_expired() {
		if ( ! $this->table_exists() ) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		return $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table()} WHERE expires_at < %s",
				current_time( 'mysql' )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL
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
		if ( ! $this->table_exists() ) {
			return false;
		}

		// Check for tokens created in the last 5 minutes that are still valid.
		$recent_threshold = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - ( 5 * MINUTE_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.PreparedSQL
		$count = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()}
				WHERE member_id = %d
				AND competition_id = %d
				AND category = %s
				AND used_at IS NULL
				AND expires_at > %s
				AND created_at > %s",
				$member_id,
				$competition_id,
				$category,
				current_time( 'mysql' ),
				$recent_threshold
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		return $count > 0;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'clubcompete_voting_tokens';
	}
}
