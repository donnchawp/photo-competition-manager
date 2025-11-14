<?php
/**
 * Plugin uninstall handler.
 *
 * Fired when the plugin is uninstalled (not just deactivated).
 * Removes all plugin data including database tables, uploaded files, and options.
 *
 * @package PhotoCompetitionManager
 * @since 0.1.0
 */

// Exit if not called by WordPress uninstaller.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all plugin data.
 *
 * This function is called when the plugin is uninstalled via the WordPress admin.
 * It removes:
 * - All custom database tables
 * - All uploaded competition files and directories
 * - All WordPress attachments created by the plugin
 * - All plugin options
 * - Custom capabilities from roles
 *
 * @return void
 */
function photo_competition_manager_uninstall() {
	global $wpdb;

	// Remove custom capabilities from all roles.
	photo_competition_manager_remove_capabilities();

	// Delete all attachments created by the plugin (originals in media library).
	photo_competition_manager_delete_attachments();

	// Delete all uploaded competition files and directories.
	photo_competition_manager_delete_upload_directories();

	// Drop all custom database tables.
	photo_competition_manager_drop_tables( $wpdb );

	// Delete any plugin options (if any exist).
	photo_competition_manager_delete_options();
}

/**
 * Remove custom capabilities from all roles.
 *
 * @return void
 */
function photo_competition_manager_remove_capabilities() {
	$capability = 'manage_photo_competitions';
	$roles      = array( 'administrator', 'editor' );

	foreach ( $roles as $role_name ) {
		$role = get_role( $role_name );
		if ( $role && $role->has_cap( $capability ) ) {
			$role->remove_cap( $capability );
		}
	}
}

/**
 * Delete all attachments (originals) created by the plugin.
 *
 * This removes files from the media library that were uploaded through the plugin.
 * Attachments are identified by the '_photo_comp_slug' post meta.
 *
 * @return void
 */
function photo_competition_manager_delete_attachments() {
	global $wpdb;

	// Find all attachments with competition metadata.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$attachment_ids = $wpdb->get_col(
		"SELECT DISTINCT post_id
		FROM {$wpdb->postmeta}
		WHERE meta_key = '_photo_comp_slug'"
	);

	if ( empty( $attachment_ids ) ) {
		return;
	}

	// Delete each attachment and its files.
	foreach ( $attachment_ids as $attachment_id ) {
		// wp_delete_attachment() removes the file and all metadata.
		wp_delete_attachment( (int) $attachment_id, true );
	}
}

/**
 * Delete all competition upload directories and files.
 *
 * Removes the entire /wp-content/uploads/competitions/ directory tree.
 * This includes all slideshow images organized by competition/category.
 *
 * @return void
 */
function photo_competition_manager_delete_upload_directories() {
	$wp_upload_dir = wp_upload_dir();
	if ( $wp_upload_dir['error'] ) {
		return;
	}

	$competitions_dir = trailingslashit( $wp_upload_dir['basedir'] ) . 'competitions';

	// Only proceed if the directory exists.
	if ( ! file_exists( $competitions_dir ) || ! is_dir( $competitions_dir ) ) {
		return;
	}

	// Recursively delete the competitions directory.
	photo_competition_manager_recursive_delete( $competitions_dir );
}

/**
 * Recursively delete a directory and all its contents.
 *
 * @param string $dir Directory path to delete.
 * @return bool True on success, false on failure.
 */
function photo_competition_manager_recursive_delete( $dir ) {
	if ( ! file_exists( $dir ) || ! is_dir( $dir ) ) {
		return false;
	}

	// Prevent deletion of important WordPress directories.
	$upload_dir = wp_upload_dir();
	$basedir    = trailingslashit( $upload_dir['basedir'] );
	$dir_path   = trailingslashit( $dir );

	// Safety check: Only delete within uploads/competitions/.
	if ( strpos( $dir_path, $basedir . 'competitions/' ) !== 0 ) {
		return false;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir
	$items = scandir( $dir );
	if ( false === $items ) {
		return false;
	}

	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}

		$path = trailingslashit( $dir ) . $item;

		if ( is_dir( $path ) ) {
			// Recursively delete subdirectories.
			photo_competition_manager_recursive_delete( $path );
		} else {
			// Delete file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			wp_delete_file( $path );
		}
	}

	// Delete the now-empty directory.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	return rmdir( $dir );
}

/**
 * Drop all custom database tables created by the plugin.
 *
 * @param wpdb $wpdb WordPress database instance.
 * @return void
 */
function photo_competition_manager_drop_tables( $wpdb ) {
	// List of all custom tables (without prefix).
	$tables = array(
		'photocomp_members',
		'photocomp_competitions',
		'photocomp_images',
		'photocomp_votes',
		'photocomp_upload_tokens',
		'photocomp_voting_tokens',
		'photocomp_logs',
	);

	// Drop each table.
	foreach ( $tables as $table ) {
		$table_name = $wpdb->prefix . $table;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );
	}
}

/**
 * Delete any plugin-specific options from wp_options table.
 *
 * Currently the plugin doesn't store options, but this is here for future-proofing.
 *
 * @return void
 */
function photo_competition_manager_delete_options() {
	// If you add any options in the future, delete them here.
	// Example: delete_option( 'photo_competition_manager_version' ).
}

// Execute the uninstall.
photo_competition_manager_uninstall();
