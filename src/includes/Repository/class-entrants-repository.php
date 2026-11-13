<?php
/**
 * Repository for a competition's selected entrants.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use function PhotoCompetitionManager\Support\utc_time;

/**
 * The members entered in a competition open to selected entrants only.
 *
 * @since 0.5.0
 *
 * @package PhotoCompetitionManager\Repository
 */
class Entrants_Repository extends Abstract_Repository {

	/**
	 * Enter a member in a competition. Entering them twice changes nothing.
	 *
	 * @param int $competition_id Competition ID.
	 * @param int $member_id      Member ID.
	 * @return void
	 */
	public function add( int $competition_id, int $member_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (competition_id, member_id, created_at) VALUES (%d, %d, %s)',
				$this->table(),
				$competition_id,
				$member_id,
				utc_time()
			)
		);
	}

	/**
	 * Take a member out of a competition's entrants.
	 *
	 * @param int $competition_id Competition ID.
	 * @param int $member_id      Member ID.
	 * @return void
	 */
	public function remove( int $competition_id, int $member_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$this->table(),
			array(
				'competition_id' => $competition_id,
				'member_id'      => $member_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * A competition's entrants.
	 *
	 * @param int $competition_id Competition ID.
	 * @return int[] Member IDs, in ID order.
	 */
	public function member_ids( int $competition_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT member_id FROM %i WHERE competition_id = %d ORDER BY member_id', $this->table(), $competition_id ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * Whether a member is one of a competition's entrants.
	 *
	 * @param int $competition_id Competition ID.
	 * @param int $member_id      Member ID.
	 * @return bool
	 */
	public function has( int $competition_id, int $member_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE competition_id = %d AND member_id = %d',
				$this->table(),
				$competition_id,
				$member_id
			)
		);
	}

	/**
	 * Delete a competition's entrants.
	 *
	 * @param int $competition_id Competition ID.
	 * @return void
	 */
	public function delete_by_competition( int $competition_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table(), array( 'competition_id' => $competition_id ), array( '%d' ) );
	}

	/**
	 * Delete a member's entries in every competition.
	 *
	 * @param int $member_id Member ID.
	 * @return void
	 */
	public function delete_by_member( int $member_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table(), array( 'member_id' => $member_id ), array( '%d' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'photocomp_entrants';
	}
}
