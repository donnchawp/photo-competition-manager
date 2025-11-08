<?php
/**
 * Repository for competition images.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;

/**
 * Class Images_Repository
 *
 * @package PhotoCompetitionManager\Repository
 */
class Images_Repository extends Abstract_Repository {

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
			' SELECT * FROM %s WHERE %s ORDER BY category, random_number',
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
	 * Get random number for a member in a competition.
	 *
	 * Each member gets one sequential number per competition (1, 2, 3...)
	 * shared across all their images in that competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @param int $member_id      Member ID.
	 * @return int
	 */
	public function get_member_random_number( int $competition_id, int $member_id ): int {
		if ( ! $this->table_exists() ) {
			return 1;
		}

		// Check if member already has images in this competition.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$existing_sql = sprintf(
			'SELECT random_number FROM %s WHERE competition_id = %%d AND member_id = %%d LIMIT 1',
			$this->table()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$existing = $this->wpdb->get_var( $this->wpdb->prepare( $existing_sql, $competition_id, $member_id ) );

		if ( $existing ) {
			return (int) $existing;
		}

		// Member doesn't have images yet, assign next sequential number.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$max_sql = sprintf(
			'SELECT MAX(random_number) FROM %s WHERE competition_id = %%d',
			$this->table()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$max = $this->wpdb->get_var( $this->wpdb->prepare( $max_sql, $competition_id ) );

		return $max ? (int) $max + 1 : 1;
	}

	/**
	 * Regenerate random numbers for all members in a competition.
	 *
	 * Assigns new sequential numbers (1, 2, 3...) to members randomly,
	 * but ensures each member gets one consistent number across all their images.
	 *
	 * @param int $competition_id Competition ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function regenerate_member_numbers( int $competition_id ) {
		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Invalid competition ID.', 'photo-competition-manager' ) );
		}

		// Get all unique member IDs who have submitted images to this competition.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = sprintf(
			'SELECT DISTINCT member_id FROM %s WHERE competition_id = %%d',
			$this->table()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$member_ids = $this->wpdb->get_col( $this->wpdb->prepare( $sql, $competition_id ) );

		if ( empty( $member_ids ) ) {
			return new WP_Error( 'no_images', __( 'No images found for this competition.', 'photo-competition-manager' ) );
		}

		// Shuffle member IDs to randomize the number assignment.
		shuffle( $member_ids );

		// Assign sequential numbers to each member.
		$next_number = 1;
		foreach ( $member_ids as $member_id ) {
			// Update all images for this member in this competition.
			$updated = $this->wpdb->update(
				$this->table(),
				array(
					'random_number' => $next_number,
					'updated_at'    => current_time( 'mysql' ),
				),
				array(
					'competition_id' => $competition_id,
					'member_id'      => (int) $member_id,
				),
				array( '%d', '%s' ),
				array( '%d', '%d' )
			);

			if ( false === $updated ) {
				return new WP_Error( 'db_update_failed', __( 'Failed to update random numbers.', 'photo-competition-manager' ), $this->wpdb->last_error );
			}

			++$next_number;
		}

		return true;
	}

	/**
	 * Create an image record.
	 *
	 * @param array<string, mixed> $data Image data.
	 * @return int|WP_Error Image ID or error.
	 */
	public function create( array $data ) {
		if ( ! $this->table_exists() ) {
			return new WP_Error( 'missing_table', __( 'Images table is not available.', 'photo-competition-manager' ) );
		}

		$competition_id = isset( $data['competition_id'] ) ? absint( $data['competition_id'] ) : 0;
		$member_id      = isset( $data['member_id'] ) ? absint( $data['member_id'] ) : 0;
		$category       = isset( $data['category'] ) ? sanitize_text_field( $data['category'] ) : '';
		$filename       = isset( $data['filename'] ) ? sanitize_file_name( $data['filename'] ) : '';

		if ( ! $competition_id || ! $member_id || ! $category || ! $filename ) {
			return new WP_Error( 'invalid_data', __( 'Competition ID, member ID, category, and filename are required.', 'photo-competition-manager' ) );
		}

		$random_number = isset( $data['random_number'] )
			? absint( $data['random_number'] )
			: $this->get_member_random_number( $competition_id, $member_id );

		$original_attachment_id = isset( $data['original_attachment_id'] ) ? absint( $data['original_attachment_id'] ) : null;

		$payload = array(
			'competition_id'         => $competition_id,
			'member_id'              => $member_id,
			'category'               => $category,
			'filename'               => $filename,
			'random_number'          => $random_number,
			'score'                  => null,
			'original_attachment_id' => $original_attachment_id,
			'created_at'             => current_time( 'mysql' ),
			'updated_at'             => current_time( 'mysql' ),
		);

		$format = array( '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' );

		$inserted = $this->wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create image record.', 'photo-competition-manager' ), $this->wpdb->last_error );
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
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		// Check if image exists.
		$image = $this->find( $id );
		if ( ! $image ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
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
			return new WP_Error( 'db_update_failed', __( 'Could not update image score.', 'photo-competition-manager' ), $this->wpdb->last_error );
		}

		return true;
	}

	/**
	 * Update image category.
	 *
	 * @param int    $id       Image ID.
	 * @param string $category Category slug.
	 * @return bool|WP_Error
	 */
	public function update_category( int $id, string $category ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		// Check if image exists.
		$image = $this->find( $id );
		if ( ! $image ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		$updated = $this->wpdb->update(
			$this->table(),
			array(
				'category'   => $category,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update image category.', 'photo-competition-manager' ), $this->wpdb->last_error );
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
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		// Check if image exists.
		$image = $this->find( $id );
		if ( ! $image ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		$deleted = $this->wpdb->delete(
			$this->table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error( 'db_delete_failed', __( 'Could not delete image record.', 'photo-competition-manager' ), $this->wpdb->last_error );
		}

		return true;
	}

	/**
	 * Delete all images for a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @return bool
	 */
	public function delete_by_competition( int $competition_id ): bool {
		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return false;
		}

		$deleted = $this->wpdb->delete(
			$this->table(),
			array( 'competition_id' => $competition_id ),
			array( '%d' )
		);

		return false !== $deleted;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'photocomp_images';
	}

	/**
	 * Get all images with uploader info.
	 *
	 * @return array<object>
	 */
	public function get_all_images_with_uploader_info(): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		$images_table  = $this->table();
		$members_table = $this->wpdb->prefix . 'photocomp_members';

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = "
			SELECT
				i.id,
				i.competition_id,
				i.member_id,
				i.category,
				i.filename,
				i.random_number,
				i.score,
				i.created_at,
				m.name AS member_name,
				m.email AS member_email
			FROM {$images_table} AS i
			LEFT JOIN {$members_table} AS m ON i.member_id = m.id
			ORDER BY i.member_id ASC
		";
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $sql );
	}

	/**
	 * Get all original attachment IDs for a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @return array<int> Array of attachment IDs.
	 */
	public function get_original_attachment_ids( int $competition_id ): array {
		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = sprintf(
			'SELECT original_attachment_id FROM %s WHERE competition_id = %%d AND original_attachment_id IS NOT NULL',
			$this->table()
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_col( $this->wpdb->prepare( $sql, $competition_id ) );

		return array_map( 'intval', $results );
	}

	/**
	 * Clear original attachment IDs for a competition.
	 *
	 * Sets original_attachment_id to NULL for all images in a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function clear_original_attachment_ids( int $competition_id ) {
		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Invalid competition ID.', 'photo-competition-manager' ) );
		}

		$updated = $this->wpdb->update(
			$this->table(),
			array(
				'original_attachment_id' => null,
				'updated_at'             => current_time( 'mysql' ),
			),
			array( 'competition_id' => $competition_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not clear original attachment IDs.', 'photo-competition-manager' ), $this->wpdb->last_error );
		}

		return true;
	}
}
