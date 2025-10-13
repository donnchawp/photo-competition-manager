<?php
/**
 * Handle plugin activation.
 *
 * @package ClubCompetitions\Install
 */

namespace ClubCompetitions\Install;

use wpdb;

class Activator {

	/**
	 * Run installation routines.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
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
		$wpdb = $wpdb ?: $GLOBALS['wpdb'];

		$charset_collate = $wpdb->get_charset_collate();

		$members = "CREATE TABLE {$wpdb->prefix}clubcompete_members (
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

		$competitions = "CREATE TABLE {$wpdb->prefix}clubcompete_competitions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			open_date DATETIME NULL,
			close_date DATETIME NULL,
			voting_open DATETIME NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'draft',
			settings LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$images = "CREATE TABLE {$wpdb->prefix}clubcompete_images (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			competition_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(100) NOT NULL,
			filename VARCHAR(191) NOT NULL,
			random_number BIGINT UNSIGNED NOT NULL,
			score DECIMAL(6,2) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY competition (competition_id),
			KEY member (member_id)
		) {$charset_collate};";

		$votes = "CREATE TABLE {$wpdb->prefix}clubcompete_votes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			competition_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(100) NOT NULL,
			voter_name VARCHAR(191) NOT NULL,
			image_id BIGINT UNSIGNED NOT NULL,
			score DECIMAL(6,2) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY competition (competition_id),
			KEY image (image_id)
		) {$charset_collate};";

		return array(
			$members,
			$competitions,
			$images,
			$votes,
		);
	}
}
