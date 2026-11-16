<?php
/**
 * Repository for competition types.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Support\Competition_Settings;
use WP_Error;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Repository for competition types, each holding default settings and grade names.
 *
 * @since 0.4.0
 *
 * @package PhotoCompetitionManager\Repository
 */
class Competition_Types_Repository extends Abstract_Repository {

	/**
	 * Create a type.
	 *
	 * @since 0.4.0
	 * @param string               $name        Type name.
	 * @param array<string, mixed> $settings    Default settings for the type's competitions.
	 * @param array<string,string> $grade_names Grade slug to the name this type shows for it.
	 * @return int|WP_Error New type ID or error.
	 */
	public function create( string $name, array $settings, array $grade_names = array() ) {
		global $wpdb;

		$name  = sanitize_text_field( $name );
		$valid = $this->validate_type( $name, $settings, 0 );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'name'        => $name,
				'settings'    => Competition_Settings::encode( $settings ),
				'grade_names' => Competition_Settings::encode( $grade_names ),
				'created_at'  => utc_time(),
			),
			array( '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create the type.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a type.
	 *
	 * @since 0.4.0
	 * @param int                  $id          Type ID.
	 * @param string               $name        Type name.
	 * @param array<string, mixed> $settings    Default settings.
	 * @param array<string,string> $grade_names Grade slug to name.
	 * @return true|WP_Error
	 */
	public function update( int $id, string $name, array $settings, array $grade_names ) {
		global $wpdb;

		$name  = sanitize_text_field( $name );
		$valid = $this->validate_type( $name, $settings, $id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$this->table(),
			array(
				'name'        => $name,
				'settings'    => Competition_Settings::encode( $settings ),
				'grade_names' => Competition_Settings::encode( $grade_names ),
				'updated_at'  => utc_time(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update the type.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Find a type by ID.
	 *
	 * @since 0.4.0
	 * @param int $id Type ID.
	 * @return object|null Row with `settings` and `grade_names` decoded to arrays.
	 */
	public function find( int $id ): ?object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->table(), $id ) );

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * List every type, ordered by name.
	 *
	 * @since 0.4.0
	 * @return object[]
	 */
	public function all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name, id', $this->table() ) );

		return $rows ? array_map( array( $this, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Count the types.
	 *
	 * @since 0.4.0
	 * @return int
	 */
	public function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table() ) );
	}

	/**
	 * Whether the club has more than one type, which is when types show.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	public function has_several(): bool {
		return $this->count() > 1;
	}

	/**
	 * The type, when the club has exactly one.
	 *
	 * @since 0.4.0
	 * @return object|null Null when there are none or several.
	 */
	public function only(): ?object {
		$types = $this->all();

		return 1 === count( $types ) ? $types[0] : null;
	}

	/**
	 * Delete a type and its member tags.
	 *
	 * @since 0.4.0
	 * @param int $id Type ID.
	 * @return true|WP_Error `type_in_use` while any competition, archived or not, has the type;
	 *                       `last_type` for the only type. When one type is left, every member is tagged with it.
	 */
	public function delete( int $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$in_use = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE type_id = %d', $wpdb->prefix . 'photocomp_competitions', $id ) );
		if ( $in_use > 0 ) {
			return new WP_Error( 'type_in_use', __( 'This type has competitions, so it cannot be deleted.', 'photo-competition-manager' ) );
		}

		if ( ! $this->has_several() ) {
			return new WP_Error( 'last_type', __( 'The last type cannot be deleted.', 'photo-competition-manager' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) ) ) {
			return new WP_Error( 'db_delete_failed', __( 'Could not delete the type.', 'photo-competition-manager' ), $wpdb->last_error );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'photocomp_member_types', array( 'type_id' => $id ), array( '%d' ) );

		// With one type every member carries its tag, so members who had none, or only the
		// deleted type, would otherwise drop out with no tag controls left to fix it.
		$only = $this->only();
		if ( $only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (member_id, type_id) SELECT id, %d FROM %i', $wpdb->prefix . 'photocomp_member_types', (int) $only->id, $wpdb->prefix . 'photocomp_members' ) );
		}

		return true;
	}

	/**
	 * Validate a type's name and settings.
	 *
	 * @param string               $name     Sanitized type name.
	 * @param array<string, mixed> $settings Settings to validate.
	 * @param int                  $id       The type being updated, or 0 for a new one.
	 * @return true|WP_Error `missing_name`, `duplicate_name` or `invalid_settings`.
	 */
	private function validate_type( string $name, array $settings, int $id ) {
		global $wpdb;

		if ( '' === $name ) {
			return $this->missing_name();
		}

		// Case-insensitive, as CSV import matches type names.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$taken = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE LOWER( name ) = LOWER( %s ) AND id <> %d LIMIT 1', $this->table(), $name, $id ) );
		if ( null !== $taken ) {
			return new WP_Error( 'duplicate_name', __( 'Another type already has that name.', 'photo-competition-manager' ) );
		}

		return $this->validate_settings( $settings );
	}

	/**
	 * Validate a type's settings, mapping any failure to `invalid_settings`.
	 *
	 * @param array<string, mixed> $settings Settings to validate.
	 * @return true|WP_Error
	 */
	private function validate_settings( array $settings ) {
		$valid = Competition_Settings::validate_type_settings( $settings );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error( 'invalid_settings', $valid->get_error_message(), $valid->get_error_code() );
		}

		return true;
	}

	/**
	 * The error for an empty type name.
	 *
	 * @return WP_Error
	 */
	private function missing_name(): WP_Error {
		return new WP_Error( 'missing_name', __( 'Enter a name for the type.', 'photo-competition-manager' ) );
	}

	/**
	 * Decode a row's JSON columns.
	 *
	 * @param object $row Table row.
	 * @return object
	 */
	private function hydrate( object $row ): object {
		$row->settings    = Competition_Settings::decode( $row->settings );
		$row->grade_names = Competition_Settings::decode( $row->grade_names );

		return $row;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'photocomp_competition_types';
	}
}
