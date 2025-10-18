<?php
/**
 * Repository for members.
 *
 * @package ClubCompetitions\Repository
 */

namespace ClubCompetitions\Repository;

use WP_Error;

/**
 * Repository for members.
 *
 * @package ClubCompetitions\Repository
 */
class Members_Repository extends Abstract_Repository {

	/**
	 * Fetch active members.
	 *
	 * @param bool $only_active Whether to restrict to active members.
	 * @return array<int, object>
	 */
	public function all( bool $only_active = true ): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		$where = $only_active ? 'WHERE active = 1' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = sprintf( 'SELECT * FROM %s %s ORDER BY name ASC', $this->table(), $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $sql );
	}

	/**
	 * Locate a member by ID.
	 *
	 * @param int $id Member ID.
	 * @return object|null
	 */
	public function find( int $id ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return null;
		}

		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d",
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	/**
	 * Locate a member by email address.
	 *
	 * @param string $email Member email.
	 * @return object|null
	 */
	public function find_by_email( string $email ) {
		if ( ! $this->table_exists() || ! is_email( $email ) ) {
			return null;
		}

		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$table} WHERE email = %s",
				$email
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	/**
	 * Find multiple members.
	 *
	 * @param array<int> $ids Member IDs.
	 * @return array<int, object>
	 */
	public function find_many( array $ids ): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		$ids = array_unique( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = sprintf( 'SELECT * FROM %s WHERE id IN (%s)', $this->table(), $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$ids ) );

		$map = array();
		foreach ( $results as $member ) {
			$map[ (int) $member->id ] = $member;
		}

		return $map;
	}

	/**
	 * Create a member record.
	 *
	 * @param array<string, mixed> $data Member data.
	 * @return int|WP_Error
	 */
	public function create( array $data ) {
		if ( ! $this->table_exists() ) {
			return new WP_Error( 'missing_table', __( 'Members table not available. Reactivate the plugin.', 'club-competitions' ) );
		}

		$name  = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'Member name is required.', 'club-competitions' ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'A valid email address is required.', 'club-competitions' ) );
		}

		if ( $this->email_exists( $email ) ) {
			return new WP_Error( 'duplicate_email', __( 'A member with this email already exists.', 'club-competitions' ) );
		}

		$grade  = isset( $data['grade'] ) ? sanitize_text_field( (string) $data['grade'] ) : '';
		$active = isset( $data['active'] ) ? (int) (bool) $data['active'] : 1;
		$now    = current_time( 'mysql' );

		$payload = array(
			'name'       => $name,
			'email'      => $email,
			'grade'      => $grade,
			'active'     => $active,
			'created_at' => $now,
			'updated_at' => $now,
		);

		$format = array( '%s', '%s', '%s', '%d', '%s', '%s' );

		$inserted = $this->wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create member.', 'club-competitions' ), $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update a member record.
	 *
	 * @param int                  $id   Member ID.
	 * @param array<string, mixed> $data Member fields.
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $data ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_member', __( 'Member not found.', 'club-competitions' ) );
		}

		$current = $this->find( $id );

		if ( ! $current ) {
			return new WP_Error( 'missing_member', __( 'Member not found.', 'club-competitions' ) );
		}

		$name = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : $current->name;

		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'Member name is required.', 'club-competitions' ) );
		}

		$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : $current->email;

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'A valid email address is required.', 'club-competitions' ) );
		}

		if ( $this->email_exists( $email, $id ) ) {
			return new WP_Error( 'duplicate_email', __( 'A member with this email already exists.', 'club-competitions' ) );
		}

		$grade  = array_key_exists( 'grade', $data ) ? sanitize_text_field( (string) $data['grade'] ) : $current->grade;
		$active = array_key_exists( 'active', $data ) ? (int) (bool) $data['active'] : (int) $current->active;

		$payload = array(
			'name'       => $name,
			'email'      => $email,
			'grade'      => $grade,
			'active'     => $active,
			'updated_at' => current_time( 'mysql' ),
		);

		$format = array( '%s', '%s', '%s', '%d', '%s' );

		$updated = $this->wpdb->update(
			$this->table(),
			$payload,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update member.', 'club-competitions' ), $this->wpdb->last_error );
		}

		return true;
	}

	/**
	 * Toggle active flag.
	 *
	 * @param int  $id     Member ID.
	 * @param bool $active Active state.
	 * @return bool|WP_Error
	 */
	public function set_active( int $id, bool $active ) {
		return $this->update(
			$id,
			array(
				'active' => $active ? 1 : 0,
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'clubcompete_members';
	}

	/**
	 * Determine whether an email already exists.
	 *
	 * @param string   $email      Member email.
	 * @param int|null $exclude_id Optional member ID to exclude.
	 * @return bool
	 */
	private function email_exists( string $email, ?int $exclude_id = null ): bool {
		$params     = array( $email );
		$conditions = '';

		if ( $exclude_id ) {
			$conditions .= ' AND id != %d';
			$params[]    = $exclude_id;
		}

		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $this->wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE email=%s{$conditions}",
			...$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $sql ) > 0;
	}
}
