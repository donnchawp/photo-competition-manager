<?php
/**
 * Repository for upload tokens.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Repository for upload authentication tokens.
 *
 * @package PhotoCompetitionManager\Repository
 */
class Upload_Token_Repository extends Abstract_Repository {

	/**
	 * Find or create an upload token for a member and competition.
	 *
	 * Returns existing token if one exists, otherwise creates a new one.
	 * This ensures only one token per member per competition.
	 *
	 * @param int $member_id      Member ID.
	 * @param int $competition_id Competition ID.
	 * @return object|WP_Error Token object or error.
	 */
	public function find_or_create( int $member_id, int $competition_id ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return new WP_Error( 'missing_table', __( 'Upload token table is not available.', 'photo-competition-manager' ) );
		}

		if ( $member_id <= 0 || $competition_id <= 0 ) {
			return new WP_Error( 'invalid_data', __( 'Invalid member or competition ID.', 'photo-competition-manager' ) );
		}

		// Try to find existing token.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i
				WHERE member_id = %d
				AND competition_id = %d
				LIMIT 1',
				$this->table(),
				$member_id,
				$competition_id
			)
		);

		if ( $existing ) {
			// Refresh token if expired.
			if ( $existing->expires_at < utc_time() ) {
				$new_token      = bin2hex( random_bytes( 32 ) );
				$new_expires_at = utc_time( 2 * WEEK_IN_SECONDS );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$updated = $wpdb->update(
					$this->table(),
					array(
						'token'      => $new_token,
						'expires_at' => $new_expires_at,
						'used_at'    => null,
					),
					array( 'id' => $existing->id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					return new WP_Error( 'db_update_failed', __( 'Could not refresh token.', 'photo-competition-manager' ) );
				}

				// Return updated token object.
				$existing->token      = $new_token;
				$existing->expires_at = $new_expires_at;
				$existing->used_at    = null;
			}

			return $existing;
		}

		// Create new token.
		$token_string = bin2hex( random_bytes( 32 ) );
		$expires_at   = utc_time( 2 * WEEK_IN_SECONDS );

		$payload = array(
			'member_id'      => $member_id,
			'competition_id' => $competition_id,
			'token'          => $token_string,
			'expires_at'     => $expires_at,
			'created_at'     => utc_time(),
		);

		$format = array( '%d', '%d', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create upload token.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		// Return the newly created token.
		$token_id = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$token = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->table(),
				$token_id
			)
		);

		return $token ? $token : new WP_Error( 'token_not_found', __( 'Token was created but could not be retrieved.', 'photo-competition-manager' ) );
	}

	/**
	 * Find a valid token by token string.
	 *
	 * Records the first access timestamp if this is the first time the token is used.
	 *
	 * @param string $token_string Token string.
	 * @return object|null Token record or null if not found/invalid.
	 */
	public function find_valid_token( string $token_string ) {
		global $wpdb;

		if ( ! $this->table_exists() || empty( $token_string ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$token = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i
				WHERE token = %s
				AND used_at IS NULL
				AND expires_at > %s
				LIMIT 1',
				$this->table(),
				$token_string,
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
	 * Mark a token as used.
	 *
	 * @param int $token_id Token ID.
	 * @return bool|WP_Error
	 */
	public function mark_as_used( int $token_id ) {
		global $wpdb;

		if ( ! $this->table_exists() || $token_id <= 0 ) {
			return new WP_Error( 'invalid_token', __( 'Token not found.', 'photo-competition-manager' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array( 'used_at' => utc_time() ),
			array( 'id' => $token_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not mark token as used.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
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
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at < %s',
				$this->table(),
				utc_time()
			)
		);
	}

	/**
	 * Check if member has a recent unused token for competition.
	 *
	 * @param int $member_id      Member ID.
	 * @param int $competition_id Competition ID.
	 * @return bool
	 */
	/**
	 * Check if a member has been sent an email within the rate limit window.
	 *
	 * @param int $member_id      Member ID.
	 * @param int $competition_id Competition ID.
	 * @return bool True if an email was sent within the last 5 minutes.
	 */
	public function has_recent_email_send( int $member_id, int $competition_id ): bool {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return false;
		}

		// Check if sent_at is within the last 5 minutes.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i
				WHERE member_id = %d
				AND competition_id = %d
				AND sent_at IS NOT NULL
				AND sent_at > DATE_SUB(%s, INTERVAL 5 MINUTE)',
				$this->table(),
				$member_id,
				$competition_id,
				utc_time()
			)
		);

		return $count > 0;
	}

	/**
	 * Generate an upload URL for a member using their existing or new token.
	 *
	 * Finds existing token or creates one if needed. Does not update sent_at.
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

		// Get or create token.
		$token_obj = $this->find_or_create( $member_id, $competition_id );
		if ( is_wp_error( $token_obj ) ) {
			return $token_obj;
		}

		// Build magic link using the token from the database.
		$upload_url = add_query_arg(
			array(
				'token'       => $token_obj->token,
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

		// Rate-limit: if an email was sent recently, skip unless forced.
		if ( $this->has_recent_email_send( $member_id, $competition_id ) && ! $force_send ) {
			return true;
		}

		// Get or create token for this member/competition.
		$token_obj = $this->find_or_create( $member_id, $competition_id );
		if ( is_wp_error( $token_obj ) ) {
			return $token_obj;
		}

		// Generate upload URL.
		$upload_url = $this->generate_upload_url( $competition_id, $member_id, $upload_page_url );
		if ( is_wp_error( $upload_url ) ) {
			return $upload_url;
		}

		// Get voting page URL from competition settings.
		$settings        = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings );
		$voting_page_url = $settings['urls']['voting_page'] ?? null;

		// Send email.
		$email_service = new \PhotoCompetitionManager\Service\Email_Service();
		$sent          = $email_service->send_upload_link(
			$member->email,
			$member->name ?? $member->email,
			$competition->title,
			$upload_url,
			$competition_id,
			$voting_page_url
		);

		if ( ! $sent ) {
			return new \WP_Error( 'send_failed', __( 'Failed to send email.', 'photo-competition-manager' ) );
		}

		// Update sent_at timestamp to track when email was sent.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table(),
			array( 'sent_at' => utc_time() ),
			array( 'id' => $token_obj->id ),
			array( '%s' ),
			array( '%d' )
		);

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
		global $wpdb;

		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
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
	 * Delete all upload tokens for a competition.
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
		return 'photocomp_upload_tokens';
	}
}
