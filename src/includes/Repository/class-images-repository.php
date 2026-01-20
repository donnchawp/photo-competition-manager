<?php
/**
 * Repository for competition images.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;
use function PhotoCompetitionManager\Support\utc_time;

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
		global $wpdb;

		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return array();
		}

		$query        = 'SELECT * FROM %i WHERE competition_id = %d';
		$prepare_args = array( $this->table(), $competition_id );

		if ( null !== $category ) {
			$query         .= ' AND category = %s';
			$prepare_args[] = $category;
		}

		if ( null !== $member_id ) {
			$query         .= ' AND member_id = %d';
			$prepare_args[] = $member_id;
		}

		$query .= ' ORDER BY category, random_number';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query contains only placeholders (%d, %s), not user input.
		return $wpdb->get_results( $wpdb->prepare( $query, ...$prepare_args ) );
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

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE member_id = %d ORDER BY created_at DESC',
			$this->table(),
			$member_id
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is prepared.
		return $wpdb->get_results( $sql );
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

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = $wpdb->prepare(
			'SELECT * FROM %i WHERE id = %d',
			$this->table(),
			$id
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is prepared.
		return $wpdb->get_row( $sql );
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
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 0;
		}

		$query        = 'SELECT COUNT(*) FROM %i WHERE competition_id = %d AND member_id = %d';
		$prepare_args = array( $this->table(), $competition_id, $member_id );

		if ( null !== $category ) {
			$query         .= ' AND category = %s';
			$prepare_args[] = $category;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $query contains only placeholders (%d, %s), not user input.
		return (int) $wpdb->get_var( $wpdb->prepare( $query, ...$prepare_args ) );
	}

	/**
	 * Get next random number for a competition category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return int
	 */
	public function get_next_random_number( int $competition_id, string $category ): int {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 1;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$max = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT MAX(random_number) FROM %i WHERE competition_id = %d AND category = %s',
				$this->table(),
				$competition_id,
				$category
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
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
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 1;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT random_number FROM %i WHERE competition_id = %d AND member_id = %d LIMIT 1',
				$this->table(),
				$competition_id,
				$member_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( $existing ) {
			return (int) $existing;
		}

		// Member doesn't have images yet, assign next sequential number.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$max = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT MAX(random_number) FROM %i WHERE competition_id = %d',
				$this->table(),
				$competition_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

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
		global $wpdb;

		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Invalid competition ID.', 'photo-competition-manager' ) );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$member_ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT DISTINCT member_id FROM %i WHERE competition_id = %d',
				$this->table(),
				$competition_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $member_ids ) ) {
			return new WP_Error( 'no_images', __( 'No images found for this competition.', 'photo-competition-manager' ) );
		}

		// Shuffle member IDs to randomize the number assignment.
		shuffle( $member_ids );

		// Assign sequential numbers to each member.
		$next_number = 1;
		foreach ( $member_ids as $member_id ) {
			// Update all images for this member in this competition.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$updated = $wpdb->update(
				$this->table(),
				array(
					'random_number' => $next_number,
					'updated_at'    => utc_time(),
				),
				array(
					'competition_id' => $competition_id,
					'member_id'      => (int) $member_id,
				),
				array( '%d', '%s' ),
				array( '%d', '%d' )
			);

			if ( false === $updated ) {
				return new WP_Error( 'db_update_failed', __( 'Failed to update random numbers.', 'photo-competition-manager' ), $wpdb->last_error );
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
		global $wpdb;

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
			'created_at'             => utc_time(),
			'updated_at'             => utc_time(),
		);

		$format = array( '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create image record.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update image score.
	 *
	 * @param int $id    Image ID.
	 * @param int $score Score value.
	 * @return bool|WP_Error
	 */
	public function update_score( int $id, int $score ) {
		global $wpdb;

		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		// Check if image exists.
		$image = $this->find( $id );
		if ( ! $image ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array(
				'score'      => $score,
				'updated_at' => utc_time(),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update image score.', 'photo-competition-manager' ), $wpdb->last_error );
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
		global $wpdb;

		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		// Check if image exists.
		$image = $this->find( $id );
		if ( ! $image ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array(
				'category'   => $category,
				'updated_at' => utc_time(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update image category.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Delete an image record.
	 *
	 * Also deletes any votes associated with the image.
	 *
	 * @param int $id Image ID.
	 * @return bool|WP_Error
	 */
	public function delete( int $id ) {
		global $wpdb;

		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		// Check if image exists.
		$image = $this->find( $id );
		if ( ! $image ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		// Delete any votes for this image first.
		$votes_repo = new Votes_Repository();
		$votes_repo->delete_by_image( $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$this->table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error( 'db_delete_failed', __( 'Could not delete image record.', 'photo-competition-manager' ), $wpdb->last_error );
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
		global $wpdb;

		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
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
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT i.id, i.competition_id, i.member_id, i.category, i.filename, i.random_number, i.score, i.created_at, m.name AS member_name, m.email AS member_email FROM %i AS i LEFT JOIN %i AS m ON i.member_id = m.id ORDER BY i.member_id ASC',
				$this->table(),
				$wpdb->prefix . 'photocomp_members'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get all original attachment IDs for a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @return array<int> Array of attachment IDs.
	 */
	public function get_original_attachment_ids( int $competition_id ): array {
		global $wpdb;

		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT original_attachment_id FROM %i WHERE competition_id = %d AND original_attachment_id IS NOT NULL',
				$this->table(),
				$competition_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

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
		global $wpdb;

		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Invalid competition ID.', 'photo-competition-manager' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array(
				'original_attachment_id' => null,
				'updated_at'             => utc_time(),
			),
			array( 'competition_id' => $competition_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not clear original attachment IDs.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}
}
