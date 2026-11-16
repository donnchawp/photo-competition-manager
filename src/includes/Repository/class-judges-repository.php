<?php
/**
 * Repository for external judges.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Repository for judges, the people who score a competition from outside the club.
 *
 * @since 0.4.0
 *
 * @package PhotoCompetitionManager\Repository
 */
class Judges_Repository extends Abstract_Repository {

	/**
	 * Create a judge.
	 *
	 * @since 0.4.0
	 * @param string $name  Judge name.
	 * @param string $email Judge email address.
	 * @return int|WP_Error New judge ID or error.
	 */
	public function create( string $name, string $email ) {
		global $wpdb;

		$fields = $this->validate( $name, $email );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'name'       => $fields['name'],
				'email'      => $fields['email'],
				'created_at' => utc_time(),
			),
			array( '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create the judge.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a judge's name and email.
	 *
	 * @since 0.4.0
	 * @param int    $id    Judge ID.
	 * @param string $name  Judge name.
	 * @param string $email Judge email address.
	 * @return true|WP_Error
	 */
	public function update( int $id, string $name, string $email ) {
		global $wpdb;

		$fields = $this->validate( $name, $email );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array(
				'name'       => $fields['name'],
				'email'      => $fields['email'],
				'updated_at' => utc_time(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update the judge.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Find a judge by ID.
	 *
	 * @since 0.4.0
	 * @param int $id Judge ID.
	 * @return object|null
	 */
	public function find( int $id ): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ) );

		return $row ? $row : null;
	}

	/**
	 * How admin screens and exports label a judge's votes.
	 *
	 * @since 0.4.0
	 * @param int $judge_id Judge ID.
	 * @return string "Judge: <name>", or "Former judge #<id>" once the judge is deleted.
	 */
	public function vote_label( int $judge_id ): string {
		$judge = $this->find( $judge_id );

		return $judge ? 'Judge: ' . $judge->name : 'Former judge #' . $judge_id;
	}

	/**
	 * List every judge, ordered by name.
	 *
	 * @since 0.4.0
	 * @return object[]
	 */
	public function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name, id', $this->table() ) );

		return $rows ? $rows : array();
	}

	/**
	 * Find the judges with an email address.
	 *
	 * @since 0.4.0
	 * @param string $email Email address.
	 * @return object[]
	 */
	public function find_by_email( string $email ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id', $this->table(), $email ) );

		return $rows ? $rows : array();
	}

	/**
	 * Delete a judge and their competition assignments. Their votes stay.
	 *
	 * @since 0.4.0
	 * @param int $id Judge ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( ( new Competition_Judges_Repository() )->table(), array( 'judge_id' => $id ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Validate and normalise a judge's name and email.
	 *
	 * @param string $name  Judge name.
	 * @param string $email Judge email address.
	 * @return array{name: string, email: string}|WP_Error
	 */
	private function validate( string $name, string $email ) {
		$name  = sanitize_text_field( $name );
		$email = sanitize_email( $email );

		if ( '' === $name ) {
			return new WP_Error( 'missing_name', __( 'Enter the judge\'s name.', 'photo-competition-manager' ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'Enter a valid email address for the judge.', 'photo-competition-manager' ) );
		}

		return array(
			'name'  => $name,
			'email' => $email,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'photocomp_judges';
	}
}
