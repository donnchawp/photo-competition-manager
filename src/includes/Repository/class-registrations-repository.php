<?php
/**
 * Repository for registrations waiting to be confirmed.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * A registration holds what someone typed into the registration form until
 * they confirm it from the emailed link. Its key is stored only as a hash.
 *
 * @since 0.6.0
 */
class Registrations_Repository extends Abstract_Repository {

	/**
	 * Store a registration.
	 *
	 * @param array{competition_id: int, name: string, email: string, grade: string, token_hash: string, expires_at: string, created_at: string} $data Registration, with its fields in this order.
	 * @return int Registration ID, or 0 when it couldn't be stored.
	 */
	public function create( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert( $this->table(), $data, array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * The registration with a key's hash, if it hasn't expired.
	 *
	 * @param string $token_hash Hash of the key.
	 * @param string $now        UTC datetime.
	 * @return object|null
	 */
	public function find_live( string $token_hash, string $now ): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s AND expires_at > %s', $this->table(), $token_hash, $now ) );

		return $row ? $row : null;
	}

	/**
	 * Every registration holding an email address.
	 *
	 * @param string $email Email address.
	 * @return array<int, object>
	 */
	public function find_by_email( string $email ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id', $this->table(), $email ) );
	}

	/**
	 * Delete one registration.
	 *
	 * @param int $id Registration ID.
	 * @return bool Whether it was there to delete, so only one request can use it.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Delete every registration holding an email address.
	 *
	 * @param string $email Email address.
	 * @return int How many were deleted.
	 */
	public function delete_by_email( string $email ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->delete( $this->table(), array( 'email' => $email ), array( '%s' ) );
	}

	/**
	 * Delete the registrations that have expired.
	 *
	 * @param string $now UTC datetime.
	 * @return int How many were deleted.
	 */
	public function delete_expired( string $now ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at <= %s', $this->table(), $now ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'photocomp_registrations';
	}
}
