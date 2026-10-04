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
	 * Fetch open competitions, newest first.
	 *
	 * @since 0.3.0
	 *
	 * @return array<int, object>
	 */
	public function all_open(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- open_condition() is prepared.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . $this->open_condition() . ' ORDER BY created_at DESC', $this->table() ) );
	}

	/**
	 * WHERE clause for competitions open now: not archived, and
	 * open_date <= now < close_date. Matches Competition_Workflow::is_open().
	 *
	 * @since 0.3.0
	 *
	 * @return string Prepared SQL condition.
	 */
	private function open_condition(): string {
		global $wpdb;

		$current = utc_time();

		return $wpdb->prepare( 'deleted_at IS NULL AND (open_date IS NULL OR open_date <= %s) AND (close_date IS NULL OR close_date > %s)', $current, $current );
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
	 * Returns a competition whose open date has started, close date has not arrived,
	 * and that has not been archived.
	 *
	 * @return object|null
	 */
	public function find_current_active() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- open_condition() is prepared.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . $this->open_condition() . ' ORDER BY open_date DESC, created_at DESC LIMIT 1', $this->table() ) );
	}

	/**
	 * Fetch unarchived competitions, the one that opened last first.
	 *
	 * A competition without an open date opened when it was created.
	 *
	 * @since 0.4.0
	 *
	 * @return array<int, object>
	 */
	public function all_by_opening(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NULL ORDER BY COALESCE(open_date, created_at) DESC, created_at DESC', $this->table() ) );
	}

	/**
	 * Find the competition that opened most recently.
	 *
	 * Competitions that haven't opened yet are left out, so next month's
	 * competition, created early, doesn't win before it opens. A competition
	 * without an open date opened when it was created. Archived competitions
	 * are ignored.
	 *
	 * @since 0.3.0
	 *
	 * @return object|null
	 */
	public function find_latest_opened() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NULL AND COALESCE(open_date, created_at) <= %s ORDER BY COALESCE(open_date, created_at) DESC, created_at DESC LIMIT 1', $this->table(), utc_time() ) );
	}

	/**
	 * Find the competition an admin screen opens on by default.
	 *
	 * That's the current competition, or, between competitions, the one that
	 * opened most recently.
	 *
	 * @since 0.3.0
	 *
	 * @return object|null
	 */
	public function find_current_or_latest_opened() {
		return $this->find_current_active() ?? $this->find_latest_opened();
	}

	/**
	 * Find a competition whose dates overlap the given range.
	 *
	 * Only one competition may be open at a time from now on, so only the
	 * part of the range from now onwards is checked: competitions whose
	 * dates overlapped in the past don't block each other. A missing open
	 * date means the range starts now; a missing close date means it never
	 * ends, so a competition with no close date overlaps everything after it
	 * opens. A range that starts at the moment another closes does not
	 * overlap it, the same rule Competition_Workflow::is_open() uses.
	 * Archived competitions are ignored.
	 *
	 * @since 0.3.0
	 *
	 * @param string|null $open_date  Open date of the range, or null for now.
	 * @param string|null $close_date Close date of the range, or null for unbounded.
	 * @param int         $exclude_id Competition to leave out, e.g. the one being edited.
	 * @return object|null The first overlapping competition, or null.
	 */
	public function find_overlapping( ?string $open_date, ?string $close_date, int $exclude_id = 0 ) {
		global $wpdb;

		$now        = utc_time();
		$open_date  = max( $this->normalize_date( $open_date ) ?? $now, $now );
		$close_date = $this->normalize_date( $close_date );

		if ( null !== $close_date && $close_date <= $open_date ) {
			return null;
		}

		$conditions = 'deleted_at IS NULL AND id <> %d AND (close_date IS NULL OR close_date > %s)';
		$args       = array( $this->table(), $exclude_id, $open_date );

		if ( null !== $close_date ) {
			$conditions .= ' AND (open_date IS NULL OR open_date < %s)';
			$args[]      = $close_date;
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE ' . $conditions . ' ORDER BY created_at DESC LIMIT 1', ...$args )
		);
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	/**
	 * Build the error returned when a range overlaps another competition.
	 *
	 * @since 0.3.0
	 *
	 * @param string|null $open_date  Open date of the range, or null for now.
	 * @param string|null $close_date Close date of the range, or null for unbounded.
	 * @param int         $exclude_id Competition to leave out, e.g. the one being saved.
	 * @return WP_Error|null Error carrying the other competition as 'competition' data, or null.
	 */
	private function overlap_error( ?string $open_date, ?string $close_date, int $exclude_id = 0 ): ?WP_Error {
		$other = $this->find_overlapping( $open_date, $close_date, $exclude_id );

		if ( ! $other ) {
			return null;
		}

		return new WP_Error(
			'competition_overlap',
			sprintf(
				/* translators: %s: title of the overlapping competition */
				__( 'These dates overlap %s, and only one competition can be open at a time. Change its dates or close it first.', 'photo-competition-manager' ),
				$other->title
			),
			array( 'competition' => $other )
		);
	}

	/**
	 * Create a competition.
	 *
	 * Refuses dates that overlap another competition with a
	 * 'competition_overlap' error.
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
		$overlap    = $this->overlap_error( $open_date, $close_date );

		if ( $overlap ) {
			return $overlap;
		}

		$now = utc_time();

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
	 * When the dates change, refuses dates that overlap another competition
	 * with a 'competition_overlap' error.
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

		if ( array_key_exists( 'open_date', $data ) || array_key_exists( 'close_date', $data ) ) {
			$overlap = $this->overlap_error( $open_date, $close_date, $id );

			if ( $overlap ) {
				return $overlap;
			}
		}

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
	 * Refuses with a 'competition_overlap' error if its dates overlap
	 * another competition.
	 *
	 * @param int $id Competition ID.
	 * @return bool|WP_Error
	 */
	public function restore( int $id ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$archived = $this->find( $id, true );
		$overlap  = $archived ? $this->overlap_error( $archived->open_date, $archived->close_date, $id ) : null;

		if ( $overlap ) {
			return $overlap;
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
	 * Save a competition's workflow state.
	 *
	 * Only Competition_Workflow calls this. update() never touches the
	 * column, so saving a competition's settings can't wipe it.
	 *
	 * @since 0.4.0
	 *
	 * @param int                  $id       Competition ID.
	 * @param array<string, mixed> $workflow Workflow state.
	 * @return bool|WP_Error
	 */
	public function save_workflow( int $id, array $workflow ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array(
				'workflow'   => wp_json_encode( $workflow ),
				'updated_at' => utc_time(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update competition.', 'photo-competition-manager' ), $wpdb->last_error );
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
}
