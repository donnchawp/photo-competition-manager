<?php
/**
 * Repository for competition images.
 *
 * @package ClubCompetitions\Repository
 */

namespace ClubCompetitions\Repository;

use WP_Error;

class ImagesRepository extends AbstractRepository {

	/**
	 * Find all images for a competition.
	 *
	 * @param int         $competition_id Competition ID.
	 * @param string|null $category       Optional category filter.
	 * @param int|null    $member_id      Optional member filter.
	 * @return array<int, object>
	 */
	public function find_by_competition( int $competition_id, ?string $category = null, ?int $member_id = null ): array {
		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return array();
		}

		$conditions = array( 'competition_id = %d' );
		$params     = array( $competition_id );

		if ( null !== $category ) {
			$conditions[] = 'category = %s';
			$params[]     = $category;
		}

		if ( null !== $member_id ) {
			$conditions[] = 'member_id = %d';
			$params[]     = $member_id;
		}

		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = sprintf(
			'SELECT * FROM %s WHERE %s ORDER BY category, random_number',
			$this->table(),
			$where
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ) );
	}

	/**
	 * Find all images for a member.
	 *
	 * @param int $member_id Member ID.
	 * @return array<int, object>
	 */
	public function find_by_member( int $member_id ): array {
		if ( ! $this->table_exists() || $member_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = sprintf(
			'SELECT * FROM %s WHERE member_id = %%d ORDER BY created_at DESC',
			$this->table()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $member_id ) );
	}

	/**
	 * Find a specific image by ID.
	 *
	 * @param int $id Image ID.
	 * @return object|null
	 */
	public function find( int $id ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = sprintf(
			'SELECT * FROM %s WHERE id = %%d',
			$this->table()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_row( $this->wpdb->prepare( $sql, $id ) );
	}

	/**
	 * Count images by competition and category.
	 *
	 * @param int         $competition_id Competition ID.
	 * @param int         $member_id      Member ID.
	 * @param string|null $category       Category slug.
	 * @return int
	 */
	public function count_by_member_category( int $competition_id, int $member_id, ?string $category = null ): int {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		$conditions = array( 'competition_id = %d', 'member_id = %d' );
		$params     = array( $competition_id, $member_id );

		if ( null !== $category ) {
			$conditions[] = 'category = %s';
			$params[]     = $category;
		}

		$where = implode( ' AND ', $conditions );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = sprintf(
			'SELECT COUNT(*) FROM %s WHERE %s',
			$this->table(),
			$where
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$params ) );
	}

	/**
	 * Get next random number for a competition category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return int
	 */
	public function get_next_random_number( int $competition_id, string $category ): int {
		if ( ! $this->table_exists() ) {
			return 1;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = sprintf(
			'SELECT MAX(random_number) FROM %s WHERE competition_id = %%d AND category = %%s',
			$this->table()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$max = $this->wpdb->get_var( $this->wpdb->prepare( $sql, $competition_id, $category ) );

		return $max ? (int) $max + 1 : 1;
	}

	/**
	 * Create an image record.
	 *
	 * @param array<string, mixed> $data Image data.
	 * @return int|WP_Error Image ID or error.
	 */
	public function create( array $data ) {
		if ( ! $this->table_exists() ) {
			return new WP_Error( 'missing_table', __( 'Images table is not available.', 'club-competitions' ) );
		}

		$competition_id = isset( $data['competition_id'] ) ? absint( $data['competition_id'] ) : 0;
		$member_id      = isset( $data['member_id'] ) ? absint( $data['member_id'] ) : 0;
		$category       = isset( $data['category'] ) ? sanitize_text_field( $data['category'] ) : '';
		$filename       = isset( $data['filename'] ) ? sanitize_file_name( $data['filename'] ) : '';

		if ( ! $competition_id || ! $member_id || ! $category || ! $filename ) {
			return new WP_Error( 'invalid_data', __( 'Competition ID, member ID, category, and filename are required.', 'club-competitions' ) );
		}

		$random_number = isset( $data['random_number'] )
			? absint( $data['random_number'] )
			: $this->get_next_random_number( $competition_id, $category );

		$payload = array(
			'competition_id' => $competition_id,
			'member_id'      => $member_id,
			'category'       => $category,
			'filename'       => $filename,
			'random_number'  => $random_number,
			'score'          => null,
			'created_at'     => current_time( 'mysql' ),
			'updated_at'     => current_time( 'mysql' ),
		);

		$format = array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' );

		$inserted = $this->wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create image record.', 'club-competitions' ), $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update image score.
	 *
	 * @param int   $id    Image ID.
	 * @param float $score Score value.
	 * @return bool|WP_Error
	 */
	public function update_score( int $id, float $score ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'club-competitions' ) );
		}

		// Check if image exists.
		$image = $this->find( $id );
		if ( ! $image ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'club-competitions' ) );
		}

		$updated = $this->wpdb->update(
			$this->table(),
			array(
				'score'      => $score,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%f', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update image score.', 'club-competitions' ), $this->wpdb->last_error );
		}

		return true;
	}

	/**
	 * Delete an image record.
	 *
	 * @param int $id Image ID.
	 * @return bool|WP_Error
	 */
	public function delete( int $id ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'club-competitions' ) );
		}

		// Check if image exists.
		$image = $this->find( $id );
		if ( ! $image ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'club-competitions' ) );
		}

		$deleted = $this->wpdb->delete(
			$this->table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error( 'db_delete_failed', __( 'Could not delete image record.', 'club-competitions' ), $this->wpdb->last_error );
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'clubcompete_images';
	}
}
