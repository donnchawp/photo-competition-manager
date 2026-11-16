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
	 * Entry statuses an image can have. Only entered images compete: they're
	 * voted on, judged and ranked. A substitute waits to replace a
	 * disqualified image, and a disqualified image is out of the competition.
	 *
	 * @since 0.4.0
	 *
	 * @var string[]
	 */
	public const ENTRY_STATUSES = array( 'entered', 'substitute', 'disqualified' );

	/**
	 * A photographer's name as it's stored: cleaned of tags and extra spaces, and cut to the column's 191 characters.
	 *
	 * @since 0.4.0
	 *
	 * @param string $photographer Photographer's name as given.
	 * @return string
	 */
	public static function clean_photographer( string $photographer ): string {
		return mb_substr( sanitize_text_field( $photographer ), 0, 191 );
	}

	/**
	 * An entry status's name, as people see it.
	 *
	 * @since 0.4.0
	 *
	 * @param string $entry_status One of ENTRY_STATUSES.
	 * @return string
	 */
	public static function entry_status_label( string $entry_status ): string {
		$labels = array(
			'entered'      => __( 'Entered', 'photo-competition-manager' ),
			'substitute'   => __( 'Substitute', 'photo-competition-manager' ),
			'disqualified' => __( 'Disqualified', 'photo-competition-manager' ),
		);

		return $labels[ $entry_status ] ?? $entry_status;
	}

	/**
	 * Find a competition's entered images: the ones voted on, judged and ranked.
	 *
	 * @since 0.4.0 Was find_by_competition(), which also returned substitutes and disqualified images. find_uploads() has them.
	 *
	 * @param int         $competition_id Competition ID.
	 * @param string|null $category       Optional category filter.
	 * @param int|null    $member_id      Optional member filter.
	 * @return array<int, object>
	 */
	public function find_entered( int $competition_id, ?string $category = null, ?int $member_id = null ): array {
		return $this->query_competition( $competition_id, $category, $member_id, array( 'entered' ) );
	}

	/**
	 * Find every image uploaded to a competition, whatever its entry status.
	 *
	 * @since 0.4.0
	 *
	 * @param int         $competition_id Competition ID.
	 * @param string|null $category       Optional category filter.
	 * @param int|null    $member_id      Optional member filter.
	 * @return array<int, object>
	 */
	public function find_uploads( int $competition_id, ?string $category = null, ?int $member_id = null ): array {
		return $this->query_competition( $competition_id, $category, $member_id, array() );
	}

	/**
	 * Find the images that take a place in a competition's panels: entered
	 * images and substitutes. A disqualified image takes none.
	 *
	 * @since 0.4.0
	 *
	 * @param int         $competition_id Competition ID.
	 * @param string|null $category       Optional category filter.
	 * @param int|null    $member_id      Optional member filter.
	 * @return array<int, object>
	 */
	public function find_in_panels( int $competition_id, ?string $category = null, ?int $member_id = null ): array {
		return $this->query_competition( $competition_id, $category, $member_id, array( 'entered', 'substitute' ) );
	}

	/**
	 * Query a competition's images.
	 *
	 * @param int         $competition_id Competition ID.
	 * @param string|null $category       Optional category filter.
	 * @param int|null    $member_id      Optional member filter.
	 * @param string[]    $statuses       Entry statuses to find; every status when empty.
	 * @return array<int, object>
	 */
	private function query_competition( int $competition_id, ?string $category, ?int $member_id, array $statuses ): array {
		global $wpdb;

		if ( $competition_id <= 0 ) {
			return array();
		}

		$query        = 'SELECT * FROM %i WHERE competition_id = %d';
		$prepare_args = array( $this->table(), $competition_id );

		if ( $statuses ) {
			$query       .= ' AND entry_status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$prepare_args = array_merge( $prepare_args, $statuses );
		}

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
	 * Shuffle images in a deterministic order based on competition and category.
	 *
	 * Produces the same random order for all viewers within a given competition
	 * and category, so the slideshow and voting form stay in sync. The order
	 * differs across categories to prevent identifying photographers.
	 *
	 * @param array  $images         Array of image objects.
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return array Shuffled array of image objects.
	 */
	public function shuffle_deterministic( array $images, int $competition_id, string $category ): array {
		if ( count( $images ) <= 1 ) {
			return $images;
		}

		$seed = $competition_id . '_' . $category;
		usort(
			$images,
			function ( $a, $b ) use ( $seed ) {
				return strcmp(
					md5( $seed . '_' . $a->id ),
					md5( $seed . '_' . $b->id )
				);
			}
		);

		return $images;
	}

	/**
	 * Find all images for a member.
	 *
	 * @param int $member_id Member ID.
	 * @return array<int, object>
	 */
	public function find_by_member( int $member_id ): array {
		global $wpdb;

		if ( $member_id <= 0 ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE member_id = %d ORDER BY created_at DESC',
				$this->table(),
				$member_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Find a specific image by ID.
	 *
	 * @param int $id Image ID.
	 * @return object|null
	 */
	public function find( int $id ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->table(),
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * Whether a competition's category has any entered images.
	 *
	 * @since 0.4.0
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return bool
	 */
	public function has_images( int $competition_id, string $category ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM %i WHERE competition_id = %d AND category = %s AND entry_status = 'entered' LIMIT 1", $this->table(), $competition_id, $category ) );
	}

	/**
	 * The members with entries in a competition.
	 *
	 * @since 0.4.0
	 * @param int $competition_id Competition ID.
	 * @return int[] Member IDs.
	 */
	public function member_ids( int $competition_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$member_ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT member_id FROM %i WHERE competition_id = %d',
				$this->table(),
				$competition_id
			)
		);

		return array_map( 'intval', $member_ids );
	}

	/**
	 * The grades of the members with entries in a competition.
	 *
	 * @since 0.4.0
	 * @param int $competition_id Competition ID.
	 * @return string[] Grade slugs.
	 */
	public function entrant_grades( int $competition_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT m.grade FROM %i i INNER JOIN %i m ON m.id = i.member_id WHERE i.competition_id = %d',
				$this->table(),
				$wpdb->prefix . 'photocomp_members',
				$competition_id
			)
		);
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

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$max = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(random_number) FROM %i WHERE competition_id = %d AND category = %s',
				$this->table(),
				$competition_id,
				$category
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

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

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT random_number FROM %i WHERE competition_id = %d AND member_id = %d LIMIT 1',
				$this->table(),
				$competition_id,
				$member_id
			)
		);

		if ( $existing ) {
			return (int) $existing;
		}

		// Member doesn't have images yet, assign next sequential number.
		$max = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(random_number) FROM %i WHERE competition_id = %d',
				$this->table(),
				$competition_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $max ? (int) $max + 1 : 1;
	}

	/**
	 * Make room for a new image's number at a random place among a category's numbers.
	 *
	 * The category's numbers run from 1 to however many images it has. A random place among
	 * them, up to one past the end, is picked, and the numbers from there up move along by one.
	 * Putting each new image in a random place keeps the numbers in a random order, so they
	 * don't show who uploaded which image. Used for an interclub competition, where every image
	 * has its own number.
	 *
	 * @since 0.4.0
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return int The new image's number.
	 */
	public function open_number_slot( int $competition_id, string $category ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE competition_id = %d AND category = %s', $this->table(), $competition_id, $category ) );
		$number = wp_rand( 1, $count + 1 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET random_number = random_number + 1 WHERE competition_id = %d AND category = %s AND random_number >= %d', $this->table(), $competition_id, $category, $number ) );

		return $number;
	}

	/**
	 * Close the gap an image's number leaves when it leaves a category: the numbers above it move down by one.
	 *
	 * @since 0.4.0
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @param int    $number         The number that left.
	 * @return void
	 */
	public function close_number_slot( int $competition_id, string $category, int $number ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET random_number = random_number - 1 WHERE competition_id = %d AND category = %s AND random_number > %d', $this->table(), $competition_id, $category, $number ) );
	}

	/**
	 * Set an image's number.
	 *
	 * @since 0.4.0
	 *
	 * @param int $id     Image ID.
	 * @param int $number Its number.
	 * @return true|WP_Error
	 */
	public function update_number( int $id, int $number ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array(
				'random_number' => $number,
				'updated_at'    => utc_time(),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update the image\'s number.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Give every image its own number within its category, 1 to the number of images there, in a random order.
	 *
	 * @since 0.4.0
	 *
	 * @param int $competition_id Competition ID.
	 * @return true|WP_Error
	 */
	public function regenerate_image_numbers( int $competition_id ) {
		$by_category = array();
		foreach ( $this->find_uploads( $competition_id ) as $image ) {
			$by_category[ $image->category ][] = (int) $image->id;
		}
		if ( empty( $by_category ) ) {
			return new WP_Error( 'no_images', __( 'No images found for this competition.', 'photo-competition-manager' ) );
		}

		foreach ( $by_category as $image_ids ) {
			shuffle( $image_ids );

			foreach ( $image_ids as $index => $image_id ) {
				$updated = $this->update_number( $image_id, $index + 1 );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}
		}

		return true;
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

		if ( $competition_id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Invalid competition ID.', 'photo-competition-manager' ) );
		}

		$member_ids = $this->member_ids( $competition_id );

		if ( empty( $member_ids ) ) {
			return new WP_Error( 'no_images', __( 'No images found for this competition.', 'photo-competition-manager' ) );
		}

		// Shuffle member IDs to randomize the number assignment.
		shuffle( $member_ids );

		// Assign sequential numbers to each member.
		$next_number = 1;
		foreach ( $member_ids as $member_id ) {
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

		$entry_status = $data['entry_status'] ?? 'entered';
		if ( ! in_array( $entry_status, self::ENTRY_STATUSES, true ) ) {
			return new WP_Error( 'invalid_entry_status', __( 'An image must be entered, a substitute or disqualified.', 'photo-competition-manager' ) );
		}

		$payload = array(
			'competition_id'         => $competition_id,
			'member_id'              => $member_id,
			'category'               => $category,
			'filename'               => $filename,
			'random_number'          => $random_number,
			'original_attachment_id' => $original_attachment_id,
			'photographer'           => isset( $data['photographer'] ) ? self::clean_photographer( (string) $data['photographer'] ) : '',
			'entry_status'           => $entry_status,
			'created_at'             => utc_time(),
			'updated_at'             => utc_time(),
		);

		$format = array( '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create image record.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update image category, and its filename in the new category's folder.
	 *
	 * @since 0.4.0 Takes the filename.
	 *
	 * @param int    $id       Image ID.
	 * @param string $category Category slug.
	 * @param string $filename Filename in the category's folder.
	 * @return bool|WP_Error
	 */
	public function update_category( int $id, string $category, string $filename ) {
		global $wpdb;

		if ( $id <= 0 ) {
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
				'filename'   => sanitize_file_name( $filename ),
				'updated_at' => utc_time(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update image category.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Set an image's photographer, cleaned and cut to the column's 191 characters.
	 *
	 * @since 0.4.0
	 *
	 * @param int    $id           Image ID.
	 * @param string $photographer Photographer's name, or '' for none.
	 * @return true|WP_Error
	 */
	public function update_photographer( int $id, string $photographer ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array(
				'photographer' => self::clean_photographer( $photographer ),
				'updated_at'   => utc_time(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update the image\'s photographer.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Set an image's entry status.
	 *
	 * @since 0.4.0
	 *
	 * @param int    $id           Image ID.
	 * @param string $entry_status One of ENTRY_STATUSES.
	 * @return true|WP_Error
	 */
	public function update_entry_status( int $id, string $entry_status ) {
		global $wpdb;

		if ( ! in_array( $entry_status, self::ENTRY_STATUSES, true ) ) {
			return new WP_Error( 'invalid_entry_status', __( 'An image must be entered, a substitute or disqualified.', 'photo-competition-manager' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array(
				'entry_status' => $entry_status,
				'updated_at'   => utc_time(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update the image\'s status.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Delete an image record.
	 *
	 * Also deletes any votes associated with the image. Its recorded results
	 * stay, without its ID.
	 *
	 * @param int $id Image ID.
	 * @return bool|WP_Error
	 */
	public function delete( int $id ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		// Check if image exists.
		$image = $this->find( $id );
		if ( ! $image ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		// The row goes first: if it won't, the entry keeps its votes. If the votes then won't
		// go, they're left on an entry that no longer exists, where nothing shows them.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$this->table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error( 'db_delete_failed', __( 'Could not delete image record.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		( new Votes_Repository() )->delete_by_image( $id );
		( new Recorded_Results_Repository() )->forget_entry( $id );

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'photocomp_images';
	}

	/**
	 * Get all original attachment IDs for a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @return array<int> Array of attachment IDs.
	 */
	public function get_original_attachment_ids( int $competition_id ): array {
		global $wpdb;

		if ( $competition_id <= 0 ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT original_attachment_id FROM %i WHERE competition_id = %d AND original_attachment_id IS NOT NULL',
				$this->table(),
				$competition_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', $results );
	}

	/**
	 * Clear an image's original attachment ID, once its original is gone.
	 *
	 * @since 0.4.0
	 *
	 * @param int $id Image ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function clear_original_attachment_id( int $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array(
				'original_attachment_id' => null,
				'updated_at'             => utc_time(),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not clear the original attachment ID.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}
}
