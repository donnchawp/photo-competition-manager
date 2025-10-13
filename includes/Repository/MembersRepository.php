<?php
/**
 * Repository for members.
 *
 * @package ClubCompetitions\Repository
 */

namespace ClubCompetitions\Repository;

class MembersRepository extends AbstractRepository {

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
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'clubcompete_members';
	}
}
