<?php
/**
 * Handle plugin deactivation.
 *
 * @package PhotoCompetitionManager\Install
 */

namespace PhotoCompetitionManager\Install;

/**
 * Plugin Deactivator.
 *
 * @since 0.1.0
 */
class Deactivator {

	/**
	 * Run deactivation routines.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		self::remove_capabilities();
	}

	/**
	 * Remove custom capabilities from roles.
	 *
	 * @return void
	 */
	private static function remove_capabilities(): void {
		$capability = 'manage_photo_competitions';

		// Remove capability from administrator role.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->remove_cap( $capability );
		}

		// Remove capability from editor role.
		$editor_role = get_role( 'editor' );
		if ( $editor_role ) {
			$editor_role->remove_cap( $capability );
		}
	}
}
