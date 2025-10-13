<?php
/**
 * Repository for competitions.
 *
 * @package ClubCompetitions\Repository
 */

namespace ClubCompetitions\Repository;

use WP_Error;

use function ClubCompetitions\Support\format_slug;

class CompetitionsRepository extends AbstractRepository {

	/**
	 * Fetch competitions ordered by creation date.
	 *
	 * @param int $limit Number of records to return.
	 * @return array<int, object>
	 */
	public function all( int $limit = 20 ): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- LIMIT is prepared separately.
		$sql = sprintf(
			'SELECT * FROM %s ORDER BY created_at DESC LIMIT %%d',
			$this->table()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $limit ) );
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
			'title'        => $title,
			'slug'         => $slug,
			'status'       => $status ?: 'draft',
			'open_date'    => $open_date,
			'close_date'   => $close_date,
			'voting_open'  => $voting_open,
			'settings'     => isset( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : null,
			'created_at'   => $now,
			'updated_at'   => $now,
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
	 * Check whether a slug already exists.
	 *
	 * @param string $slug Competition slug.
	 * @return bool
	 */
	private function slug_exists( string $slug ): bool {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $this->wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table()} WHERE slug = %s",
			$slug
		);

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
