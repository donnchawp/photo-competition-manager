<?php
/**
 * Repository for competitions.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;

use function PhotoCompetitionManager\Support\format_slug;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Repository for competitions.
 *
 * @package PhotoCompetitionManager\Repository
 */
class Competitions_Repository extends Abstract_Repository {

	/**
	 * Fetch competitions ordered by creation date.
	 *
	 * @param int  $limit Number of records to return.
	 * @param bool $include_archived Whether to include archived records.
	 * @param bool $only_archived Whether to return only archived records.
	 * @return array<int, object>
	 */
	public function all( int $limit = 20, bool $include_archived = false, bool $only_archived = false ): array {
		global $wpdb;

		if ( $only_archived ) {
			$conditions = 'deleted_at IS NOT NULL';
		} elseif ( $include_archived ) {
			$conditions = '1=1';
		} else {
			$conditions = 'deleted_at IS NULL';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $conditions is a hardcoded string.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . $conditions . ' ORDER BY created_at DESC LIMIT %d', $this->table(), (int) $limit ) );
	}

	/**
	 * Count competitions.
	 *
	 * @param bool $only_archived Whether to count only archived records.
	 * @return int
	 */
	public function count( bool $only_archived = false ): int {
		global $wpdb;

		$condition = $only_archived ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $condition is a hardcoded string.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $condition", $this->table() ) );
	}

	/**
	 * Locate a competition by ID.
	 *
	 * @param int  $id Competition ID.
	 * @param bool $include_archived Whether to include archived competitions.
	 * @return object|null
	 */
	public function find( int $id, bool $include_archived = false ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$conditions = '';

		if ( ! $include_archived ) {
			$conditions .= ' AND deleted_at IS NULL';
		}

		// phpcs:disable WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $conditions is a hardcoded string.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM %i WHERE id = %d{$conditions}",
				$this->table(),
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Locate a competition by slug.
	 *
	 * @param string $slug Competition slug.
	 * @param bool   $include_archived Whether to include archived competitions.
	 * @return object|null
	 */
	public function find_by_slug( string $slug, bool $include_archived = false ) {
		global $wpdb;

		if ( empty( $slug ) ) {
			return null;
		}

		$conditions = '';
		if ( ! $include_archived ) {
			$conditions .= ' AND deleted_at IS NULL';
		}

		// phpcs:disable WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $conditions is a hardcoded string.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM %i WHERE slug = %s{$conditions}",
				$this->table(),
				$slug
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Find the current active competition.
	 *
	 * Returns a competition whose open date has started, close date has not passed,
	 * and that has not been archived.
	 *
	 * @return object|null
	 */
	public function find_current_active() {
		global $wpdb;

		$current = utc_time();

		// phpcs:disable WordPress.DB.PreparedSQL
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE deleted_at IS NULL AND (open_date IS NULL OR open_date <= %s) AND (close_date IS NULL OR close_date >= %s) ORDER BY open_date DESC, created_at DESC LIMIT 1',
				$this->table(),
				$current,
				$current
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	/**
	 * Create a competition.
	 *
	 * @param array<string, mixed> $data Competition data.
	 * @return int|WP_Error
	 */
	public function create( array $data ) {
		global $wpdb;

		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'invalid_title', __( 'Competition title is required.', 'photo-competition-manager' ) );
		}

		$slug = isset( $data['slug'] ) && '' !== trim( (string) $data['slug'] )
			? sanitize_title( $data['slug'] )
			: format_slug( $title );

		if ( $this->slug_exists( $slug ) ) {
			return new WP_Error( 'duplicate_slug', __( 'A competition with this slug already exists.', 'photo-competition-manager' ) );
		}

		$open_date  = $this->normalize_date( $data['open_date'] ?? null );
		$close_date = $this->normalize_date( $data['close_date'] ?? null );
		$now        = utc_time();

		$payload = array(
			'title'      => $title,
			'slug'       => $slug,
			'open_date'  => $open_date,
			'close_date' => $close_date,
			'settings'   => isset( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : null,
			'share_hash' => isset( $data['share_hash'] ) ? sanitize_text_field( (string) $data['share_hash'] ) : '',
			'created_at' => $now,
			'updated_at' => $now,
		);

		$format = array(
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create competition.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a competition.
	 *
	 * @param int                  $id   Competition ID.
	 * @param array<string, mixed> $data Updated data.
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $data ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$current = $this->find( $id );

		if ( ! $current ) {
			return new WP_Error( 'missing_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : $current->title;

		if ( '' === $title ) {
			return new WP_Error( 'invalid_title', __( 'Competition title is required.', 'photo-competition-manager' ) );
		}

		$slug_source = isset( $data['slug'] ) && '' !== trim( (string) $data['slug'] )
			? sanitize_title( $data['slug'] )
			: ( $current->slug ? $current->slug : format_slug( $title ) );

		$slug = $slug_source ? $slug_source : format_slug( $title );

		if ( $this->slug_exists( $slug, $id ) ) {
			return new WP_Error( 'duplicate_slug', __( 'A competition with this slug already exists.', 'photo-competition-manager' ) );
		}

		$open_date  = array_key_exists( 'open_date', $data ) ? $this->normalize_date( $data['open_date'] ) : $current->open_date;
		$close_date = array_key_exists( 'close_date', $data ) ? $this->normalize_date( $data['close_date'] ) : $current->close_date;

		$payload = array(
			'title'      => $title,
			'slug'       => $slug,
			'open_date'  => $open_date,
			'close_date' => $close_date,
			'settings'   => isset( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : $current->settings,
			'updated_at' => utc_time(),
		);

		$format = array(
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			$payload,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update competition.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Soft delete (archive) a competition.
	 *
	 * @param int $id Competition ID.
	 * @return bool|WP_Error
	 */
	public function archive( int $id ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array(
				'deleted_at' => utc_time(),
				'updated_at' => utc_time(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_archive_failed', __( 'Could not archive competition.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Restore archived competition.
	 *
	 * @param int $id Competition ID.
	 * @return bool|WP_Error
	 */
	public function restore( int $id ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- plugin check doesn't like $this
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE %i SET deleted_at = NULL, updated_at = %s WHERE id = %d',
				$this->table(),
				utc_time(),
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $updated ) {
			return new WP_Error( 'db_restore_failed', __( 'Could not restore competition.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Permanently delete a competition and all associated data.
	 *
	 * This will delete:
	 * - All images for the competition
	 * - All votes for the competition
	 * - All upload tokens for the competition
	 * - All voting tokens for the competition
	 * - The competition record itself
	 *
	 * @param int $id Competition ID.
	 * @return bool|WP_Error
	 */
	public function delete( int $id ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		// Verify competition exists.
		$competition = $this->find( $id, true );
		if ( ! $competition ) {
			return new WP_Error( 'missing_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		// Delete all related data in proper order.
		$votes_repo        = new Votes_Repository();
		$images_repo       = new Images_Repository();
		$upload_token_repo = new Upload_Token_Repository();
		$voting_token_repo = new Voting_Token_Repository();

		// Delete votes first (they reference images).
		$votes_repo->delete_by_competition( $id );

		// Delete images.
		$images_repo->delete_by_competition( $id );

		// Delete tokens.
		$upload_token_repo->delete_by_competition( $id );
		$voting_token_repo->delete_by_competition( $id );

		// Finally, delete the competition itself.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$this->table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error( 'db_delete_failed', __( 'Could not delete competition.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Find a competition by its share hash.
	 *
	 * @param string $share_hash Share hash to look up.
	 * @return object|null
	 */
	public function find_by_share_hash( string $share_hash ) {
		global $wpdb;

		if ( empty( $share_hash ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE share_hash = %s AND deleted_at IS NULL LIMIT 1',
				$this->table(),
				$share_hash
			)
		);
	}

	/**
	 * Update the share hash for a competition.
	 *
	 * @param int    $id         Competition ID.
	 * @param string $share_hash New share hash.
	 * @return bool|WP_Error
	 */
	public function update_share_hash( int $id, string $share_hash ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array(
				'share_hash' => $share_hash,
				'updated_at' => utc_time(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update share hash.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Check whether a slug already exists.
	 *
	 * @param string   $slug        Competition slug.
	 * @param int|null $exclude_id  Competition ID to exclude.
	 * @return bool
	 */
	private function slug_exists( string $slug, ?int $exclude_id = null ): bool {
		global $wpdb;

		$params = array( $slug );

		$conditions = '';

		if ( $exclude_id ) {
			$conditions .= ' AND id != %d';
			$params[]    = $exclude_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE slug = %s' . $conditions,
			$this->table(),
			...$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is a prepared SQL string.
		return (int) $wpdb->get_var( $sql ) > 0;
	}

	/**
	 * Normalize user-supplied date.
	 *
	 * @param mixed $value Date value.
	 * @return string|null
	 */
	private function normalize_date( $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		$timestamp = strtotime( (string) $value );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'photocomp_competitions';
	}

	/**
	 * Send submission reminder emails to all active members for a competition.
	 *
	 * @since 1.0.0
	 * @param int $competition_id Competition ID.
	 * @return array{success: bool, sent_count: int, total_count: int, message: string}|WP_Error
	 */
	public function send_submission_reminder_emails( $competition_id ) {
		if ( $competition_id <= 0 ) {
			return new WP_Error(
				'invalid_competition',
				__( 'Competition not found.', 'photo-competition-manager' )
			);
		}

		$competition = $this->find( $competition_id );

		if ( ! $competition ) {
			return new WP_Error(
				'missing_competition',
				__( 'Competition not found.', 'photo-competition-manager' )
			);
		}

		if ( ! $this->is_open( $competition ) ) {
			return new WP_Error(
				'competition_not_open',
				__( 'Competition must be open to send reminder emails.', 'photo-competition-manager' )
			);
		}

		// Get members repository.
		$members_repository = new Members_Repository();
		$members            = $members_repository->all( 10000, false );

		if ( empty( $members ) ) {
			return new WP_Error(
				'no_members',
				__( 'No active members found.', 'photo-competition-manager' )
			);
		}

		// Determine upload page URL (page containing [competition_upload]); fall back to home URL.
		$upload_page_url = \PhotoCompetitionManager\Support\Competition_Settings::find_page_url_with_shortcode( 'competition_upload' );
		if ( empty( $upload_page_url ) ) {
			$upload_page_url = home_url( '/' );
		}
		$upload_page_url = apply_filters( 'photo_competition_manager_upload_page_url', $upload_page_url, $competition );

		// Use token repository to generate tokens and send emails.
		$token_repo    = new Upload_Token_Repository();
		$sent_count    = 0;
		$skipped_count = 0;
		$failed_count  = 0;
		$total_count   = count( $members );
		$errors        = array();

		foreach ( $members as $member ) {
			if ( empty( $member->email ) ) {
				continue;
			}

			// Check if member was recently sent an email (rate-limited).
			$has_recent = $token_repo->has_recent_email_send( (int) $member->id, (int) $competition_id );

			$result = $token_repo->send_upload_link_for_member(
				(int) $competition_id,
				(int) $member->id,
				$upload_page_url
			);

			if ( is_wp_error( $result ) ) {
				++$failed_count;
				$errors[] = sprintf(
					'%s: %s',
					$member->name ?? $member->email,
					$result->get_error_message()
				);
			} elseif ( true === $result ) {
				if ( $has_recent ) {
					++$skipped_count;
				} else {
					++$sent_count;
				}
			}
		}

		return array(
			'success'       => true,
			'sent_count'    => $sent_count,
			'skipped_count' => $skipped_count,
			'failed_count'  => $failed_count,
			'total_count'   => $total_count,
			'errors'        => $errors,
			'message'       => sprintf(
				/* translators: 1: Number of emails sent, 2: Total number of members */
				__( 'Sent %1$d of %2$d submission reminder emails.', 'photo-competition-manager' ),
				$sent_count,
				$total_count
			),
		);
	}

	/**
	 * Check if a competition is open (within date range and not deleted).
	 *
	 * @param object $competition Competition object.
	 * @return bool
	 */
	public function is_open( object $competition ): bool {
		if ( ! empty( $competition->deleted_at ) ) {
			return false;
		}

		$current = utc_time();

		// Check if open_date has passed (or is null).
		if ( ! empty( $competition->open_date ) && $competition->open_date > $current ) {
			return false;
		}

		// Check if close_date has not passed (or is null).
		if ( ! empty( $competition->close_date ) && $competition->close_date < $current ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a competition is accepting uploads.
	 *
	 * @param object $competition Competition object.
	 * @return bool
	 */
	public function is_accepting_uploads( object $competition ): bool {
		if ( ! $this->is_open( $competition ) ) {
			return false;
		}

		// Check uploads_closed setting.
		$settings = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings ?? '' );
		if ( ! empty( $settings['upload']['uploads_closed'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a competition is accepting votes for a specific category.
	 *
	 * @param object      $competition Competition object.
	 * @param string|null $category    Category slug (optional).
	 * @return bool
	 */
	public function is_accepting_votes( object $competition, ?string $category = null ): bool {
		if ( ! $this->is_open( $competition ) ) {
			return false;
		}

		// Check open_categories setting.
		if ( null !== $category ) {
			$settings        = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings ?? '' );
			$open_categories = $settings['open_categories'] ?? array();

			// If open_categories is set and category is not in it, voting is closed for this category.
			if ( ! empty( $open_categories ) && ! in_array( $category, $open_categories, true ) ) {
				return false;
			}
		}

		return true;
	}
}
