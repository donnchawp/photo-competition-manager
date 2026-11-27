<?php
/**
 * Competition types for tests.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

use PhotoCompetitionManager\Repository\Competition_Types_Repository;

/**
 * Give tests a type to create competitions under, as every competition needs one.
 */
class Type_Fixtures {

	/**
	 * The ID of an existing type, or of a new one when the site has none.
	 *
	 * @return int
	 */
	public static function id(): int {
		$types = new Competition_Types_Repository();
		$all   = $types->all();

		if ( $all ) {
			return (int) $all[0]->id;
		}

		$id = $types->create(
			'Individual',
			array(
				'categories' => array(
					array(
						'label' => 'Open',
						'slug'  => 'open',
						'quota' => 2,
					),
				),
			)
		);

		return (int) $id;
	}

	/**
	 * Remove every type and tag, inside the test transaction, so a test can set up exactly the types it needs.
	 *
	 * @return void
	 */
	public static function clear(): void {
		global $wpdb;

		$wpdb->query( "DELETE FROM {$wpdb->prefix}photocomp_competition_types" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}photocomp_member_types" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Create a type.
	 *
	 * @param string               $name        Type name.
	 * @param array<string,string> $grade_names   Grade slug to the name the type shows for it.
	 * @param string[]             $unused_grades Slugs of the club's grades the type doesn't use.
	 * @param array<string,mixed>  $settings      Settings beyond the one Open category, such as interclub.
	 * @return int Type ID.
	 * @throws \RuntimeException When the type's settings aren't valid.
	 */
	public static function create( string $name, array $grade_names = array(), array $unused_grades = array(), array $settings = array() ): int {
		$id = ( new Competition_Types_Repository() )->create(
			$name,
			$settings + array(
				'categories' => array(
					array(
						'label' => 'Open',
						'slug'  => 'open',
						'quota' => 2,
					),
				),
			),
			$grade_names,
			$unused_grades
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( 'Type_Fixtures::create: ' . $id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		return (int) $id;
	}

	/**
	 * Make a type's entrants clubs, for a test whose competition of the type
	 * is interclub. It writes the row directly, since the type can't change
	 * once a competition has it.
	 *
	 * @param int $type_id Type ID.
	 * @return void
	 */
	public static function make_interclub( int $type_id ): void {
		global $wpdb;

		$type                             = ( new Competition_Types_Repository() )->find( $type_id );
		$settings                         = $type->settings;
		$settings['interclub']['enabled'] = true;
		$wpdb->update( $wpdb->prefix . 'photocomp_competition_types', array( 'settings' => \PhotoCompetitionManager\Support\Competition_Settings::encode( $settings ) ), array( 'id' => $type_id ) );
	}
}
