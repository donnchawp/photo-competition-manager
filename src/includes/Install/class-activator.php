<?php
/**
 * Handle plugin activation.
 *
 * @package PhotoCompetitionManager\Install
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


namespace PhotoCompetitionManager\Install;

use wpdb;

/**
 * Plugin Activator.
 *
 * @since 0.1.0
 */
class Activator {

	/**
	 * Run installation routines.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::add_capabilities();
	}

	/**
	 * Add custom capabilities to appropriate roles.
	 *
	 * @return void
	 */
	private static function add_capabilities(): void {
		$capability = 'manage_photo_competitions';

		// Add capability to administrator role.
		$admin_role = get_role( 'administrator' );
		if ( $admin_role ) {
			$admin_role->add_cap( $capability );
		}

		// Add capability to editor role.
		$editor_role = get_role( 'editor' );
		if ( $editor_role ) {
			$editor_role->add_cap( $capability );
		}
	}

	/**
	 * Generate SQL schema and create tables.
	 *
	 * @return void
	 */
	private static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::get_schema( $wpdb ) as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Get SQL schema for custom tables.
	 *
	 * Exposed for testing.
	 *
	 * @param wpdb|null $wpdb WordPress database instance.
	 * @return array<string>
	 */
	public static function get_schema( wpdb $wpdb = null ): array {
		$wpdb = $wpdb ? $wpdb : $GLOBALS['wpdb'];

		$charset_collate = $wpdb->get_charset_collate();

		$members = "CREATE TABLE {$wpdb->prefix}photocomp_members (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			email VARCHAR(191) NOT NULL,
			grade VARCHAR(100) NOT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY email (email)
		) {$charset_collate};";

		$competitions = "CREATE TABLE {$wpdb->prefix}photocomp_competitions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			open_date DATETIME NULL,
			close_date DATETIME NULL,
			settings LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$images = "CREATE TABLE {$wpdb->prefix}photocomp_images (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			competition_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(100) NOT NULL,
			filename VARCHAR(191) NOT NULL,
			random_number BIGINT UNSIGNED NOT NULL,
			score DECIMAL(6,2) NULL,
			original_attachment_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY competition (competition_id),
			KEY member (member_id),
			KEY original_attachment (original_attachment_id)
		) {$charset_collate};";

		$votes = "CREATE TABLE {$wpdb->prefix}photocomp_votes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			competition_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(100) NOT NULL,
			voter_name VARCHAR(191) NULL,
			voting_token_id BIGINT UNSIGNED NULL,
			image_id BIGINT UNSIGNED NOT NULL,
			score DECIMAL(6,2) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY competition (competition_id),
			KEY image (image_id),
			KEY voting_token (voting_token_id),
			KEY voter_name (voter_name)
		) {$charset_collate};";

		$upload_tokens = "CREATE TABLE {$wpdb->prefix}photocomp_upload_tokens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			competition_id BIGINT UNSIGNED NOT NULL,
			token_hash VARCHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME NULL,
			first_accessed_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY token_hash (token_hash),
			KEY member_competition (member_id, competition_id),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		$voting_tokens = "CREATE TABLE {$wpdb->prefix}photocomp_voting_tokens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			competition_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(100) NOT NULL,
			token_hash VARCHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME NULL,
			first_accessed_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY token_hash (token_hash),
			KEY member_competition_category (member_id, competition_id, category),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		return array(
			$members,
			$competitions,
			$images,
			$votes,
			$upload_tokens,
			$voting_tokens,
		);
	}
}
