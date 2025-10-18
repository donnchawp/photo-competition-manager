<?php
/**
 * Repository for competitions.
 *
 * @package ClubCompetitions\Repository
 */

namespace ClubCompetitions\Repository;

use WP_Error;

use function ClubCompetitions\Support\format_slug;

/**
 * Repository for competitions.
 *
 * @package ClubCompetitions\Repository
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
		if ( ! $this->table_exists() ) {
			return array();
		}

		if ( $only_archived ) {
			$conditions = 'deleted_at IS NOT NULL';
		} elseif ( $include_archived ) {
			$conditions = '1=1';
		} else {
			$conditions = 'deleted_at IS NULL';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- LIMIT is prepared separately.
		$sql = sprintf(
			'SELECT * FROM %s WHERE %s ORDER BY created_at DESC LIMIT %%d',
			$this->table(),
			$conditions
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $limit ) );
	}

	/**
	 * Count competitions.
	 *
	 * @param bool $only_archived Whether to count only archived records.
	 * @return int
	 */
	public function count( bool $only_archived = false ): int {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		$condition = $only_archived ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$condition}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Locate a competition by ID.
	 *
	 * @param int  $id Competition ID.
	 * @param bool $include_archived Whether to include archived competitions.
	 * @return object|null
	 */
	public function find( int $id, bool $include_archived = false ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return null;
		}

		$conditions = '';

		if ( ! $include_archived ) {
			$conditions .= ' AND deleted_at IS NULL';
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE id = %d{$conditions}",
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	/**
	 * Locate a competition by slug.
	 *
	 * @param string $slug Competition slug.
	 * @param bool   $include_archived Whether to include archived competitions.
	 * @return object|null
	 */
	public function find_by_slug( string $slug, bool $include_archived = false ) {
		if ( ! $this->table_exists() || empty( $slug ) ) {
			return null;
		}

		$conditions = '';
		if ( ! $include_archived ) {
			$conditions .= ' AND deleted_at IS NULL';
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE slug = %s{$conditions}",
				$slug
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
		if ( ! $this->table_exists() ) {
			return new WP_Error( 'missing_table', __( 'Competition table is not available. Activate the plugin again.', 'club-competitions' ) );
		}

		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'invalid_title', __( 'Competition title is required.', 'club-competitions' ) );
		}

		$slug = isset( $data['slug'] ) && '' !== trim( (string) $data['slug'] )
			? sanitize_title( $data['slug'] )
			: format_slug( $title );

		if ( $this->slug_exists( $slug ) ) {
			return new WP_Error( 'duplicate_slug', __( 'A competition with this slug already exists.', 'club-competitions' ) );
		}

		$status      = isset( $data['status'] ) ? sanitize_text_field( (string) $data['status'] ) : 'draft';
		$open_date   = $this->normalize_date( $data['open_date'] ?? null );
		$close_date  = $this->normalize_date( $data['close_date'] ?? null );
		$voting_open = $this->normalize_date( $data['voting_open'] ?? null );
		$now         = current_time( 'mysql' );

		$payload = array(
			'title'       => $title,
			'slug'        => $slug,
			'status'      => $status ? $status : 'draft',
			'open_date'   => $open_date,
			'close_date'  => $close_date,
			'voting_open' => $voting_open,
			'settings'    => isset( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : null,
			'created_at'  => $now,
			'updated_at'  => $now,
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

		$inserted = $this->wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create competition.', 'club-competitions' ), $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update a competition.
	 *
	 * @param int                  $id   Competition ID.
	 * @param array<string, mixed> $data Updated data.
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $data ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'club-competitions' ) );
		}

		$current = $this->find( $id );

		if ( ! $current ) {
			return new WP_Error( 'missing_competition', __( 'Competition not found.', 'club-competitions' ) );
		}

		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : $current->title;

		if ( '' === $title ) {
			return new WP_Error( 'invalid_title', __( 'Competition title is required.', 'club-competitions' ) );
		}

		$slug_source = isset( $data['slug'] ) && '' !== trim( (string) $data['slug'] )
			? sanitize_title( $data['slug'] )
			: ( $current->slug ? $current->slug : format_slug( $title ) );

		$slug = $slug_source ? $slug_source : format_slug( $title );

		if ( $this->slug_exists( $slug, $id ) ) {
			return new WP_Error( 'duplicate_slug', __( 'A competition with this slug already exists.', 'club-competitions' ) );
		}

		$status      = isset( $data['status'] ) ? sanitize_text_field( (string) $data['status'] ) : $current->status;
		$open_date   = array_key_exists( 'open_date', $data ) ? $this->normalize_date( $data['open_date'] ) : $current->open_date;
		$close_date  = array_key_exists( 'close_date', $data ) ? $this->normalize_date( $data['close_date'] ) : $current->close_date;
		$voting_open = array_key_exists( 'voting_open', $data ) ? $this->normalize_date( $data['voting_open'] ) : $current->voting_open;

		$payload = array(
			'title'       => $title,
			'slug'        => $slug,
			'status'      => $status ? $status : 'draft',
			'open_date'   => $open_date,
			'close_date'  => $close_date,
			'voting_open' => $voting_open,
			'settings'    => isset( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : $current->settings,
			'updated_at'  => current_time( 'mysql' ),
		);

		$format = array(
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		);

		$updated = $this->wpdb->update(
			$this->table(),
			$payload,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update competition.', 'club-competitions' ), $this->wpdb->last_error );
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
		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'club-competitions' ) );
		}

		$updated = $this->wpdb->update(
			$this->table(),
			array(
				'status'     => 'archived',
				'deleted_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_archive_failed', __( 'Could not archive competition.', 'club-competitions' ), $this->wpdb->last_error );
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
		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'club-competitions' ) );
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $this->wpdb->prepare(
			"UPDATE {$this->table()} SET deleted_at = NULL, updated_at = %s WHERE id = %d",
			current_time( 'mysql' ),
			$id
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$updated = $this->wpdb->query( $sql );

		if ( false === $updated ) {
			return new WP_Error( 'db_restore_failed', __( 'Could not restore competition.', 'club-competitions' ), $this->wpdb->last_error );
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
		$params = array( $slug );

		$conditions = '';

		if ( $exclude_id ) {
			$conditions .= ' AND id != %d';
			$params[]    = $exclude_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $this->wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table()} WHERE slug = %s{$conditions}",
			...$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $sql ) > 0;
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
		return 'clubcompete_competitions';
	}
}
