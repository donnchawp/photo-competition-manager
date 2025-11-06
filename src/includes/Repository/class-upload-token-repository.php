<?php
/**
 * Repository for upload tokens.
 *
 * @package PhotoCompetitionManager\Repository
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


namespace PhotoCompetitionManager\Repository;

use WP_Error;

/**
 * Repository for upload authentication tokens.
 *
 * @package PhotoCompetitionManager\Repository
 */
class Upload_Token_Repository extends Abstract_Repository {

	/**
	 * Create a new upload token.
	 *
	 * @param int    $member_id      Member ID.
	 * @param int    $competition_id Competition ID.
	 * @param string $token_hash     Hashed token.
	 * @param string $expires_at     Expiration datetime.
	 * @return int|WP_Error Token ID or error.
	 */
	public function create( int $member_id, int $competition_id, string $token_hash, string $expires_at ) {
		if ( ! $this->table_exists() ) {
			return new WP_Error( 'missing_table', __( 'Upload token table is not available.', 'photo-competition-manager' ) );
		}

		if ( $member_id <= 0 || $competition_id <= 0 || empty( $token_hash ) || empty( $expires_at ) ) {
			return new WP_Error( 'invalid_data', __( 'Invalid token data provided.', 'photo-competition-manager' ) );
		}

		$payload = array(
			'member_id'      => $member_id,
			'competition_id' => $competition_id,
			'token_hash'     => $token_hash,
			'expires_at'     => $expires_at,
			'created_at'     => current_time( 'mysql' ),
		);

		$format = array( '%d', '%d', '%s', '%s', '%s' );

		$inserted = $this->wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create upload token.', 'photo-competition-manager' ), $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
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
		if ( ! $this->table_exists() || empty( $token_hash ) ) {
			return null;
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$token = $this->wpdb->get_row(
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

		// Track first access if token is found and hasn't been accessed before.
		if ( $token && null === $token->first_accessed_at ) {
			$this->wpdb->update(
				$this->table(),
				array( 'first_accessed_at' => current_time( 'mysql' ) ),
				array( 'id' => $token->id ),
				array( '%s' ),
				array( '%d' )
			);

			// Update the token object to reflect the change.
			$token->first_accessed_at = current_time( 'mysql' );
		}

		return $token;
	}

	/**
	 * Mark a token as used.
	 *
	 * @param int $token_id Token ID.
	 * @return bool|WP_Error
	 */
	public function mark_as_used( int $token_id ) {
		if ( ! $this->table_exists() || $token_id <= 0 ) {
			return new WP_Error( 'invalid_token', __( 'Token not found.', 'photo-competition-manager' ) );
		}

		$updated = $this->wpdb->update(
			$this->table(),
			array( 'used_at' => current_time( 'mysql' ) ),
			array( 'id' => $token_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not mark token as used.', 'photo-competition-manager' ), $this->wpdb->last_error );
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
	 * Check if member has a recent unused token for competition.
	 *
	 * @param int $member_id      Member ID.
	 * @param int $competition_id Competition ID.
	 * @return bool
	 */
	public function has_recent_token( int $member_id, int $competition_id ): bool {
		if ( ! $this->table_exists() ) {
			return false;
		}

		// Check for tokens created in the last 5 minutes that are still valid.
		$recent_threshold = gmdate( 'Y-m-d H:i:s', time() - ( 5 * MINUTE_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.PreparedSQL
		$count = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table()}
				WHERE member_id = %d
				AND competition_id = %d
				AND used_at IS NULL
				AND expires_at > %s
				AND created_at > %s",
				$member_id,
				$competition_id,
				current_time( 'mysql' ),
				$recent_threshold
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		return $count > 0;
	}

	/**
	 * Generate an upload URL with a fresh token for a member.
	 *
	 * Creates a new upload token and returns the complete URL that can be shared with the member.
	 *
	 * @since 1.0.0
	 * @param int    $competition_id  Competition ID.
	 * @param int    $member_id       Member ID.
	 * @param string $upload_page_url Base URL of the upload page containing the [competition_upload] shortcode.
	 * @return string|WP_Error Upload URL with token on success, WP_Error on failure.
	 */
	public function generate_upload_url( int $competition_id, int $member_id, string $upload_page_url ) {
		// Validate competition and member.
		$competitions_repo = new Competitions_Repository();
		$members_repo      = new Members_Repository();

		$competition = $competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new \WP_Error( 'missing_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$member = $members_repo->find( $member_id );
		if ( ! $member ) {
			return new \WP_Error( 'missing_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		// Create secure token.
		$token_string = bin2hex( random_bytes( 32 ) );
		$token_hash   = hash( 'sha256', $token_string );
		$expires_at   = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + ( 2 * WEEK_IN_SECONDS ) );

		$token_id = $this->create( $member_id, $competition_id, $token_hash, $expires_at );
		if ( is_wp_error( $token_id ) ) {
			return $token_id;
		}

		// Build magic link.
		$upload_url = add_query_arg(
			array(
				'token'       => $token_string,
				'competition' => $competition->slug,
			),
			$upload_page_url
		);

		return $upload_url;
	}

	/**
	 * Create a fresh upload token and email a magic link to a member.
	 *
	 * Treats recent token as success (rate-limited) to avoid spamming members.
	 *
	 * @since 1.0.0
	 * @param int    $competition_id Competition ID.
	 * @param int    $member_id      Member ID.
	 * @param string $upload_page_url Base URL of the upload page containing the [competition_upload] shortcode.
	 * @param bool   $force_send     Whether to force sending even if a recent token exists.
	 * @return bool|WP_Error True on success, WP_Error on hard failure (DB/email).
	 */
	public function send_upload_link_for_member( int $competition_id, int $member_id, string $upload_page_url, $force_send = false ) {
		// Validate competition and member.
		$competitions_repo = new Competitions_Repository();
		$members_repo      = new Members_Repository();

		$competition = $competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new \WP_Error( 'missing_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$member = $members_repo->find( $member_id );
		if ( ! $member ) {
			return new \WP_Error( 'missing_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		if ( empty( $member->email ) ) {
			return new \WP_Error( 'missing_email', __( 'Member does not have an email address.', 'photo-competition-manager' ) );
		}

		// Rate-limit: if a recent token exists, do not create/send a new one.
		if ( $this->has_recent_token( $member_id, $competition_id ) && ! $force_send ) {
			return true;
		}

		// Generate upload URL with token.
		$upload_url = $this->generate_upload_url( $competition_id, $member_id, $upload_page_url );
		if ( is_wp_error( $upload_url ) ) {
			return $upload_url;
		}

		// Send email.
		$email_service = new \PhotoCompetitionManager\Service\Email_Service();
		$sent          = $email_service->send_upload_link(
			$member->email,
			$member->name ?? $member->email,
			$competition->title,
			$upload_url
		);

		if ( ! $sent ) {
			return new \WP_Error( 'send_failed', __( 'Failed to send email.', 'photo-competition-manager' ) );
		}

		return true;
	}

	/**
	 * Email an upload link by member email without leaking existence (no enumeration).
	 *
	 * Always returns true unless there is a hard failure creating/sending.
	 *
	 * @since 1.0.0
	 * @param int    $competition_id Competition ID.
	 * @param string $member_email   Member email (unsanitized).
	 * @param string $upload_page_url Base URL for upload page.
	 * @return bool True if email was sent or intentionally suppressed; false only on hard failure.
	 */
	public function send_upload_link_by_email( int $competition_id, string $member_email, string $upload_page_url ): bool {
		$member_email = sanitize_email( $member_email );
		if ( empty( $member_email ) ) {
			return false;
		}

		$members_repo = new Members_Repository();
		$member       = $members_repo->find_by_email( $member_email );

		// If member doesn't exist, pretend success to avoid enumeration.
		if ( ! $member ) {
			return true;
		}

		$result = $this->send_upload_link_for_member( $competition_id, (int) $member->id, $upload_page_url );

		// Treat most errors as success to preserve privacy; only fail on hard send errors.
		if ( is_wp_error( $result ) ) {
			return 'send_failed' === $result->get_error_code() ? false : true;
		}

		return (bool) $result;
	}

	/**
	 * Get tracking data for all members in a competition.
	 *
	 * Returns array indexed by member_id with link sent/opened status.
	 *
	 * @since 1.1.0
	 * @param int $competition_id Competition ID.
	 * @return array<int, object> Array of tracking data indexed by member_id.
	 */
	public function get_tracking_by_competition( int $competition_id ): array {
		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT
					member_id,
					MIN(created_at) as first_sent_at,
					MIN(first_accessed_at) as first_opened_at,
					COUNT(*) as token_count
				FROM {$this->table()}
				WHERE competition_id = %d
				GROUP BY member_id",
				$competition_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		$tracking = array();
		foreach ( $results as $row ) {
			$tracking[ (int) $row->member_id ] = $row;
		}

		return $tracking;
	}

	/**
	 * Delete all upload tokens for a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @return bool
	 */
	public function delete_by_competition( int $competition_id ): bool {
		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return false;
		}

		$deleted = $this->wpdb->delete(
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
		return 'photocomp_upload_tokens';
	}
}
