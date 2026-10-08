<?php
/**
 * Repository for members.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Support\Competition_Settings;
use WP_Error;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Repository for members.
 *
 * @package PhotoCompetitionManager\Repository
 */
class Members_Repository extends Abstract_Repository {

	/**
	 * Prefix added to a deactivated member's email address.
	 */
	const DEACTIVATED_PREFIX = 'deactivated-';

	/**
	 * Suffix added to a deactivated member's email address. `.invalid` never resolves, so mail to it always bounces.
	 */
	const DEACTIVATED_SUFFIX = '.invalid';

	/**
	 * Length of the email column.
	 */
	const EMAIL_MAX_LENGTH = 191;

	/**
	 * Mark an email address as belonging to a deactivated member.
	 *
	 * @param string $email Email address, marked or not.
	 * @return string
	 */
	public static function mark_deactivated_email( string $email ): string {
		return self::DEACTIVATED_PREFIX . self::unmark_deactivated_email( $email ) . self::DEACTIVATED_SUFFIX;
	}

	/**
	 * Both forms an email address is stored in: as given, and marked as
	 * deactivated. A lookup by address matches either.
	 *
	 * @since 0.4.0
	 *
	 * @param string $email Email address, marked or not.
	 * @return array{0: string, 1: string} The unmarked and marked address.
	 */
	public static function email_forms( string $email ): array {
		$email = self::unmark_deactivated_email( $email );

		return array( $email, self::mark_deactivated_email( $email ) );
	}

	/**
	 * Remove the deactivated marker from an email address.
	 *
	 * The marker matches in any case, as it does in MySQL's case-insensitive comparisons.
	 *
	 * @param string $email Email address, marked or not.
	 * @return string
	 */
	public static function unmark_deactivated_email( string $email ): string {
		$prefix_length = strlen( self::DEACTIVATED_PREFIX );
		$suffix_length = strlen( self::DEACTIVATED_SUFFIX );

		if (
			strlen( $email ) > $prefix_length + $suffix_length
			&& 0 === strncasecmp( $email, self::DEACTIVATED_PREFIX, $prefix_length )
			&& 0 === substr_compare( $email, self::DEACTIVATED_SUFFIX, -$suffix_length, $suffix_length, true )
		) {
			return substr( $email, $prefix_length, -$suffix_length );
		}

		return $email;
	}

	/**
	 * Fetch members.
	 *
	 * @param int  $limit       Number of records to return.
	 * @param bool $only_active Whether to restrict to active members.
	 * @return array<int, object>
	 */
	public function all( int $limit = 1000, bool $only_active = true ): array {
		global $wpdb;

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

		if ( $id <= 0 ) {
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
	 * Matches a deactivated member by their original address too, preferring an
	 * active record.
	 *
	 * @param string $email Member email.
	 * @return object|null
	 */
	public function find_by_email( string $email ) {
		if ( ! is_email( $email ) ) {
			return null;
		}

		return $this->find_all_by_email( $email )[0] ?? null;
	}

	/**
	 * Fetch every member record holding an email address, whether or not it's
	 * marked as deactivated. Active records come first, then the oldest.
	 *
	 * @since 0.4.0
	 *
	 * @param string $email Email address, marked or not.
	 * @return array<int, object>
	 */
	public function find_all_by_email( string $email ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE email IN (%s, %s) ORDER BY active DESC, id ASC',
				$this->table(),
				...self::email_forms( $email )
			)
		);
	}

	/**
	 * Find multiple members.
	 *
	 * @param array<int> $ids Member IDs.
	 * @return array<int, object>
	 */
	public function find_many( array $ids ): array {
		global $wpdb;

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

		$name  = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		$email = isset( $data['email'] ) ? self::unmark_deactivated_email( sanitize_email( (string) $data['email'] ) ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'Member name is required.', 'photo-competition-manager' ) );
		}

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'A valid email address is required.', 'photo-competition-manager' ) );
		}

		if ( $this->email_exists( $email ) ) {
			return new WP_Error( 'duplicate_email', __( 'A member with this email already exists.', 'photo-competition-manager' ) );
		}

		$grade = (string) ( $data['grade'] ?? '' );

		if ( ! Competition_Settings::is_club_grade( $grade ) ) {
			return self::invalid_grade_error();
		}

		$active    = isset( $data['active'] ) ? (int) (bool) $data['active'] : 1;
		$committee = isset( $data['committee'] ) ? (int) (bool) $data['committee'] : 0;
		$now       = utc_time();

		$payload = array(
			'name'       => $name,
			'email'      => $active ? $email : self::mark_deactivated_email( $email ),
			'grade'      => $grade,
			'active'     => $active,
			'committee'  => $committee,
			'created_at' => $now,
			'updated_at' => $now,
		);

		$format = array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' );

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

		if ( $id <= 0 ) {
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

		$email = self::unmark_deactivated_email( isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : $current->email );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'A valid email address is required.', 'photo-competition-manager' ) );
		}

		if ( $this->email_exists( $email, $id ) ) {
			return new WP_Error( 'duplicate_email', __( 'A member with this email already exists.', 'photo-competition-manager' ) );
		}

		// Only a grade being set is checked, so a member saved with a bad
		// grade can still be deactivated or renamed.
		if ( array_key_exists( 'grade', $data ) ) {
			$grade = (string) $data['grade'];

			if ( ! Competition_Settings::is_club_grade( $grade ) ) {
				return self::invalid_grade_error();
			}
		} else {
			$grade = $current->grade;
		}

		$active    = array_key_exists( 'active', $data ) ? (int) (bool) $data['active'] : (int) $current->active;
		$committee = array_key_exists( 'committee', $data ) ? (int) (bool) $data['committee'] : (int) ( $current->committee ?? 0 );

		$payload = array(
			'name'       => $name,
			'email'      => $active ? $email : self::mark_deactivated_email( $email ),
			'grade'      => $grade,
			'active'     => $active,
			'committee'  => $committee,
			'updated_at' => utc_time(),
		);

		$format = array( '%s', '%s', '%s', '%d', '%d', '%s' );

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
	 * Fetch active committee members.
	 *
	 * @return array<int, object>
	 */
	public function find_committee_members(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE committee = 1 AND active = 1 ORDER BY name ASC',
				$this->table()
			)
		);
	}

	/**
	 * The error for a grade that isn't in the club's list.
	 *
	 * @since 0.4.0
	 * @return WP_Error
	 */
	public static function invalid_grade_error(): WP_Error {
		return new WP_Error( 'invalid_grade', __( 'Choose a grade from the club\'s list of grades.', 'photo-competition-manager' ) );
	}

	/**
	 * Fetch the members holding a grade, active or inactive.
	 *
	 * @since 0.4.0
	 * @param string $grade Grade slug.
	 * @return array<int, object>
	 */
	public function find_by_grade( string $grade ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE grade = %s ORDER BY name ASC',
				$this->table(),
				$grade
			)
		);
	}

	/**
	 * Fetch all active members (unbounded).
	 *
	 * @return array<int, object>
	 */
	public function find_active_members(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE active = 1 ORDER BY name ASC',
				$this->table()
			)
		);
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
	 * Delete a member.
	 *
	 * Their entries have to be removed first, through the Entries module, so their files go
	 * with them. Until then this refuses with `has_entries`. Their recorded results stay,
	 * without their ID.
	 *
	 * @since 0.4.0 Refuses while the member has entries, instead of removing them itself.
	 *
	 * @param int $id Member ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function delete( int $id ) {
		global $wpdb;

		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		$member = $this->find( $id );

		if ( ! $member ) {
			return new WP_Error( 'missing_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		if ( ( new Images_Repository() )->find_by_member( $id ) ) {
			return new WP_Error( 'has_entries', __( 'This member still has entries. Remove them before deleting the member.', 'photo-competition-manager' ) );
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

		( new Recorded_Results_Repository() )->forget_member( $id );

		return true;
	}

	/**
	 * Mark the email of every inactive member that isn't marked yet.
	 *
	 * Used to upgrade members deactivated before the marker existed. Addresses too long to mark
	 * without overflowing the column are left alone rather than truncated.
	 *
	 * @return int|false Number of members marked, or false if the update failed.
	 */
	public function mark_inactive_emails() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$marked = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET email = CONCAT(%s, email, %s) WHERE active = 0 AND email NOT LIKE %s AND CHAR_LENGTH(email) <= %d',
				$this->table(),
				self::DEACTIVATED_PREFIX,
				self::DEACTIVATED_SUFFIX,
				$wpdb->esc_like( self::DEACTIVATED_PREFIX ) . '%' . $wpdb->esc_like( self::DEACTIVATED_SUFFIX ),
				self::EMAIL_MAX_LENGTH - strlen( self::DEACTIVATED_PREFIX . self::DEACTIVATED_SUFFIX )
			)
		);

		return false === $marked ? false : (int) $marked;
	}

	/**
	 * Determine whether an email already exists, marked or not.
	 *
	 * @param string   $email      Member email, unmarked.
	 * @param int|null $exclude_id Optional member ID to exclude.
	 * @return bool
	 */
	private function email_exists( string $email, ?int $exclude_id = null ): bool {
		global $wpdb;

		$params     = self::email_forms( $email );
		$conditions = '';

		if ( $exclude_id ) {
			$conditions .= ' AND id != %d';
			$params[]    = $exclude_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $conditions is "field != %d" string; replacement count matches at runtime.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE email IN (%s, %s){$conditions}",
				$this->table(),
				...$params
			)
		) > 0;
		// phpcs:enable WordPress.DB.PreparedSQL,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
