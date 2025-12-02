<?php
/**
 * Repository for members.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Repository for members.
 *
 * @package PhotoCompetitionManager\Repository
 */
class Members_Repository extends Abstract_Repository {

	/**
	 * Fetch members.
	 *
	 * @param int  $limit       Number of records to return.
	 * @param bool $only_active Whether to restrict to active members.
	 * @return array<int, object>
	 */
	public function all( int $limit = 1000, bool $only_active = true ): array {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		$query  = 'SELECT * FROM %i';
		$query .= $only_active ? ' WHERE active = 1 ' : ' ';
		$query .= 'ORDER BY name ASC LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->table(),
				$limit
			)
		);
	}

	/**
	 * Locate a member by ID.
	 *
	 * @param int $id Member ID.
	 * @return object|null
	 */
	public function find( int $id ) {
		global $wpdb;

		if ( ! $this->table_exists() || $id <= 0 ) {
			return null;
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->table(),
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
		global $wpdb;

		if ( ! $this->table_exists() || ! is_email( $email ) ) {
			return null;
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE email = %s',
				$this->table(),
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
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		$ids = array_unique( array_filter( array_map( 'absint', $ids ) ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built dynamically for IN clause; count matches at runtime.
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE id IN ($placeholders)", $this->table(), ...$ids ) );
		$map     = array();
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
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return new WP_Error( 'missing_table', __( 'Members table not available. Reactivate the plugin.', 'photo-competition-manager' ) );
		}

		$name  = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'Member name is required.', 'photo-competition-manager' ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'A valid email address is required.', 'photo-competition-manager' ) );
		}

		if ( $this->email_exists( $email ) ) {
			return new WP_Error( 'duplicate_email', __( 'A member with this email already exists.', 'photo-competition-manager' ) );
		}

		$grade  = isset( $data['grade'] ) ? sanitize_text_field( (string) $data['grade'] ) : '';
		$active = isset( $data['active'] ) ? (int) (bool) $data['active'] : 1;
		$now    = utc_time();

		$payload = array(
			'name'       => $name,
			'email'      => $email,
			'grade'      => $grade,
			'active'     => $active,
			'created_at' => $now,
			'updated_at' => $now,
		);

		$format = array( '%s', '%s', '%s', '%d', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create member.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a member record.
	 *
	 * @param int                  $id   Member ID.
	 * @param array<string, mixed> $data Member fields.
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $data ) {
		global $wpdb;

		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		$current = $this->find( $id );

		if ( ! $current ) {
			return new WP_Error( 'missing_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		$name = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : $current->name;

		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'Member name is required.', 'photo-competition-manager' ) );
		}

		$email = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : $current->email;

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'A valid email address is required.', 'photo-competition-manager' ) );
		}

		if ( $this->email_exists( $email, $id ) ) {
			return new WP_Error( 'duplicate_email', __( 'A member with this email already exists.', 'photo-competition-manager' ) );
		}

		$grade  = array_key_exists( 'grade', $data ) ? sanitize_text_field( (string) $data['grade'] ) : $current->grade;
		$active = array_key_exists( 'active', $data ) ? (int) (bool) $data['active'] : (int) $current->active;

		$payload = array(
			'name'       => $name,
			'email'      => $email,
			'grade'      => $grade,
			'active'     => $active,
			'updated_at' => utc_time(),
		);

		$format = array( '%s', '%s', '%s', '%d', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			$payload,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update member.', 'photo-competition-manager' ), $wpdb->last_error );
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
		return 'photocomp_members';
	}

	/**
	 * Delete a member and all associated data (images and votes).
	 *
	 * @param int $id Member ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function delete( int $id ) {
		global $wpdb;

		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		$member = $this->find( $id );

		if ( ! $member ) {
			return new WP_Error( 'missing_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		// Get images repository to find and delete member's images.
		$images_repo       = new Images_Repository();
		$competitions_repo = new Competitions_Repository();
		$images            = $images_repo->find_by_member( $id );

		// Delete votes for each image, physical files, and the image record itself.
		$votes_repo = new Votes_Repository();
		foreach ( $images as $image ) {
			// Delete votes on this image.
			$votes_repo->delete_by_image( (int) $image->id );

			// Delete original attachment if it exists.
			if ( ! empty( $image->original_attachment_id ) ) {
				wp_delete_attachment( (int) $image->original_attachment_id, true );
			}

			// Delete physical files (slideshow and thumbnail).
			$this->delete_image_files( $image, $competitions_repo );

			// Delete the image record.
			$images_repo->delete( (int) $image->id );
		}

		// Delete the member record.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$this->table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error( 'db_delete_failed', __( 'Could not delete member.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Delete physical image files for an image record.
	 *
	 * @param object                  $image              Image object.
	 * @param Competitions_Repository $competitions_repo  Competitions repository.
	 * @return void
	 */
	private function delete_image_files( object $image, Competitions_Repository $competitions_repo ): void {
		// Get competition to determine slug.
		$competition = $competitions_repo->find( (int) $image->competition_id, true );
		if ( ! $competition || empty( $competition->slug ) ) {
			return;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		$slug = sanitize_file_name( (string) $competition->slug );
		$cat  = sanitize_file_name( (string) $image->category );

		$folder_path = trailingslashit( trailingslashit( $uploads['basedir'] ) . 'competitions/' . $slug . '/' . $cat );

		$filename   = $image->filename;
		$thumb_name = $this->get_thumbnail_filename( $filename );

		$full_path  = $folder_path . $filename;
		$thumb_path = $folder_path . $thumb_name;

		// Delete slideshow image.
		if ( file_exists( $full_path ) ) {
			wp_delete_file( $full_path );
		}

		// Delete thumbnail.
		if ( file_exists( $thumb_path ) ) {
			wp_delete_file( $thumb_path );
		}
	}

	/**
	 * Generate thumbnail filename from original filename.
	 *
	 * @param string $filename Original filename.
	 * @return string Thumbnail filename.
	 */
	private function get_thumbnail_filename( string $filename ): string {
		$parts = pathinfo( $filename );
		return $parts['filename'] . '-thumb.' . ( $parts['extension'] ?? 'jpg' );
	}

	/**
	 * Determine whether an email already exists.
	 *
	 * @param string   $email      Member email.
	 * @param int|null $exclude_id Optional member ID to exclude.
	 * @return bool
	 */
	private function email_exists( string $email, ?int $exclude_id = null ): bool {
		global $wpdb;

		$params     = array( $email );
		$conditions = '';

		if ( $exclude_id ) {
			$conditions .= ' AND id != %d';
			$params[]    = $exclude_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $conditions is "field != %d" string.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE email=%s{$conditions}",
				$this->table(),
				...$params
			)
		) > 0;
		// phpcs:enable WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
