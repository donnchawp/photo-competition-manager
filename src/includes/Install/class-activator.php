<?php
/**
 * Handle plugin activation.
 *
 * @package PhotoCompetitionManager\Install
 */

namespace PhotoCompetitionManager\Install;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Email_Job_Manager;
use PhotoCompetitionManager\Support\Competition_Settings;
use wpdb;

/**
 * Plugin Activator.
 *
 * @since 0.1.0
 */
class Activator {

	/**
	 * Current data version. Bump it and add a step to maybe_upgrade() to migrate existing data.
	 */
	const DB_VERSION = 6;

	/**
	 * Option holding the installed data version.
	 */
	const DB_VERSION_OPTION = 'photo_comp_db_version';

	/**
	 * Run installation routines.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::add_capabilities();
		self::maybe_upgrade();
	}

	/**
	 * Migrate existing data when the installed data version is behind.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		// A kind of email has one name, so an email job's type is its kind's
		// key. First, because it needs no other step and every admin page
		// shows unfinished jobs by their kind. Renaming is safe to repeat.
		if ( $installed < 5 ) {
			self::name_email_jobs_by_kind();
		}

		// Leave the version alone on failure so the step runs again on the next request.
		if ( $installed < 1 && false === ( new Members_Repository() )->mark_inactive_emails() ) {
			return;
		}

		// Email jobs are sent from the admin page now, and the competition
		// closed email is gone, so these WP-Cron events have no handler and
		// its saved template is never used.
		if ( $installed < 2 ) {
			wp_unschedule_hook( 'photo_competition_daily_cron' );
			wp_unschedule_hook( 'photo_comp_send_email_batch' );
			wp_unschedule_hook( 'photo_comp_send_results_batch' );

			$templates = get_option( 'photo_comp_email_templates' );
			if ( is_array( $templates ) && isset( $templates['competition_closed'] ) ) {
				unset( $templates['competition_closed'] );
				update_option( 'photo_comp_email_templates', $templates );
			}
		}

		// The competition workflow moved out of the settings blob into its
		// own column, so saving settings can't wipe it.
		if ( $installed < 3 && ! self::move_workflow_out_of_settings() ) {
			return;
		}

		// A voter gets one vote per image. Unique keys enforce it, and votes
		// are the only record of a used voting token.
		if ( $installed < 4 && ( ! self::make_votes_unique() || ! self::drop_token_used_at() ) ) {
			return;
		}

		// A member has one voting token per category, and asking for a link
		// again renews it. Old tables never got the unique key that says so.
		if ( $installed < 6 && ! self::make_voting_tokens_unique() ) {
			return;
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Old email job types and the kind of email each one sends. Jobs from
	 * before job types existed have no type and send detailed results.
	 */
	const LEGACY_EMAIL_JOB_TYPES = array(
		''              => 'results_detailed',
		'results'       => 'results_detailed',
		'results_share' => 'results_published',
		'upload_link'   => 'upload_reminder',
	);

	/**
	 * Rename the type of every stored email job to its kind of email.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	private static function name_email_jobs_by_kind(): void {
		foreach ( Email_Job_Manager::get_all_jobs() as $job_id => $job ) {
			$type = (string) ( $job['type'] ?? '' );

			if ( isset( self::LEGACY_EMAIL_JOB_TYPES[ $type ] ) ) {
				$job['type'] = self::LEGACY_EMAIL_JOB_TYPES[ $type ];
				update_option( Email_Job_Manager::OPTION_PREFIX . $job_id, $job, false );
			}
		}
	}

	/**
	 * Old voting steps, as stored in settings, and the stage each one is now.
	 * Voting_Controller::STEP_STAGES has the same numbers today, but this is
	 * what the stored data meant, so it stays as it is if the page changes.
	 */
	const LEGACY_STEP_STAGES = array(
		1 => Competition_Workflow::STAGE_NOT_STARTED,
		2 => Competition_Workflow::STAGE_PREVIEWED,
		3 => Competition_Workflow::STAGE_VOTING,
		4 => Competition_Workflow::STAGE_SLIDESHOW_SHOWN,
		5 => Competition_Workflow::STAGE_CRITIQUE,
		6 => Competition_Workflow::STAGE_DONE,
	);

	/**
	 * Move every competition's workflow state from its settings into the
	 * workflow column, and remove it from settings.
	 *
	 * @since 0.4.0
	 *
	 * @return bool False if a competition couldn't be saved.
	 */
	private static function move_workflow_out_of_settings(): bool {
		global $wpdb;

		$repository = new Competitions_Repository();

		// Requests run upgrades without activating, so add the column here.
		// Only when it's missing: DDL ends the running transaction.
		if ( ! self::column_exists( $repository->table(), 'workflow' ) ) {
			self::create_tables();
		}

		// A failed run leaves the version behind and runs again, so only move
		// competitions not moved yet: a moved one's settings no longer hold
		// its workflow. Both columns change in one UPDATE, so a competition
		// is either moved or untouched.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, settings FROM %i WHERE workflow IS NULL', $repository->table() ) );

		foreach ( $rows as $row ) {
			$id       = (int) $row->id;
			$settings = json_decode( (string) $row->settings, true );
			$settings = is_array( $settings ) ? $settings : array();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$moved = $wpdb->update(
				$repository->table(),
				array(
					'workflow' => wp_json_encode( self::legacy_workflow( $id, $settings ) ),
					'settings' => wp_json_encode( self::without_workflow( $settings ) ),
				),
				array(
					'id'       => $id,
					'workflow' => null,
				)
			);

			if ( false === $moved ) {
				return false;
			}
		}

		// New competitions copy the club's settings, so clean those too.
		$club = json_decode( (string) get_option( 'photo_comp_default_settings', '' ), true );
		if ( is_array( $club ) ) {
			update_option( 'photo_comp_default_settings', Competition_Settings::encode( self::without_workflow( $club ) ) );
		}

		return true;
	}

	/**
	 * Delete all but the earliest vote each voter has on an image, then add
	 * the unique keys that stop a second one.
	 *
	 * @since 0.4.0
	 *
	 * @return bool False if the keys couldn't be added.
	 */
	private static function make_votes_unique(): bool {
		global $wpdb;

		$table = ( new Votes_Repository() )->table();

		// Only when the keys are missing: DDL ends the running transaction.
		if ( self::votes_are_unique( $table ) ) {
			return true;
		}

		$removed = array();

		foreach ( array( 'voting_token_id', 'voter_name' ) as $voter ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$duplicates = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT MIN(competition_id) AS competition_id, image_id, %i AS voter, MIN(id) AS earliest FROM %i
					WHERE %i IS NOT NULL
					GROUP BY image_id, %i
					HAVING COUNT(*) > 1',
					$voter,
					$table,
					$voter,
					$voter
				)
			);

			foreach ( $duplicates as $duplicate ) {
				$competition_id = (int) $duplicate->competition_id;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$removed[ $competition_id ] = ( $removed[ $competition_id ] ?? 0 ) + (int) $wpdb->query(
					$wpdb->prepare(
						'DELETE FROM %i WHERE image_id = %d AND %i = %s AND id <> %d',
						$table,
						$duplicate->image_id,
						$voter,
						$duplicate->voter,
						$duplicate->earliest
					)
				);
			}
		}

		// Totals come from the votes, so say which competitions' results changed.
		// The upgrade runs on whichever request comes first, so the system is
		// the actor, not the current user. It runs before translations can
		// load, so the description is in English.
		foreach ( array_filter( $removed ) as $competition_id => $count ) {
			( new Logs_Repository() )->create(
				array(
					'competition_id' => $competition_id,
					'event_type'     => 'duplicate_votes_removed',
					'event_category' => 'voting',
					'actor_type'     => 'system',
					'actor_name'     => 'System',
					'description'    => sprintf( 'Upgrade removed %d duplicate vote(s), keeping each voter\'s earliest vote for an image.', $count ),
					'metadata'       => array( 'removed' => $count ),
				)
			);
		}

		self::create_tables();

		return self::votes_are_unique( $table );
	}

	/**
	 * Add the unique key that gives a member one voting token per
	 * competition and category.
	 *
	 * @since 0.4.0
	 *
	 * @return bool False if the key couldn't be added.
	 */
	private static function make_voting_tokens_unique(): bool {
		$table = ( new Voting_Token_Repository() )->table();

		// Only when the key is missing: DDL ends the running transaction.
		if ( self::has_keys( $table, array( 'member_competition_category' ) ) ) {
			return true;
		}

		self::create_tables();

		return self::has_keys( $table, array( 'member_competition_category' ) );
	}

	/**
	 * Whether the votes table has its unique keys.
	 *
	 * @since 0.4.0
	 *
	 * @param string $table Votes table.
	 * @return bool
	 */
	private static function votes_are_unique( string $table ): bool {
		return self::has_keys( $table, array( 'image_token', 'image_voter' ) );
	}

	/**
	 * Whether a table has all of the named keys.
	 *
	 * @since 0.4.0
	 *
	 * @param string        $table Table name.
	 * @param array<string> $keys  Key names.
	 * @return bool
	 */
	private static function has_keys( string $table, array $keys ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW INDEX FROM %i', $table ), 2 );

		return array() === array_diff( $keys, $found );
	}

	/**
	 * Whether a table has a column.
	 *
	 * @since 0.4.0
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 * @return bool
	 */
	private static function column_exists( string $table, string $column ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, $column ) );
	}

	/**
	 * Drop voting_tokens.used_at. Nothing ever set it.
	 *
	 * @since 0.4.0
	 *
	 * @return bool False if the column couldn't be dropped.
	 */
	private static function drop_token_used_at(): bool {
		global $wpdb;

		$table = ( new Voting_Token_Repository() )->table();

		if ( ! self::column_exists( $table, 'used_at' ) ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		return false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN used_at', $table ) );
	}

	/**
	 * Remove the old workflow keys from a settings array.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private static function without_workflow( array $settings ): array {
		unset(
			$settings['upload']['uploads_closed'],
			$settings['voting']['open_categories'],
			$settings['voting']['category_steps'],
			$settings['voting']['voted_categories'],
			$settings['results']
		);

		return array_filter( $settings, fn( $value ) => array() !== $value );
	}

	/**
	 * Work out a competition's workflow from its old settings.
	 *
	 * Old pages reconciled the stored step with the live state on every
	 * load. This applies that once: an open category is voting, unless its
	 * slideshow was already shown, and a voted one is at least at critique.
	 *
	 * @since 0.4.0
	 *
	 * @param int                  $id       Competition ID.
	 * @param array<string, mixed> $settings Old settings.
	 * @return array<string, mixed>
	 */
	private static function legacy_workflow( int $id, array $settings ): array {
		$voting = is_array( $settings['voting'] ?? null ) ? $settings['voting'] : array();
		$stages = array();

		foreach ( (array) ( $voting['category_steps'] ?? array() ) as $slug => $step ) {
			$stages[ $slug ] = self::LEGACY_STEP_STAGES[ (int) $step ] ?? Competition_Workflow::STAGE_NOT_STARTED;
		}

		foreach ( (array) ( $voting['open_categories'] ?? array() ) as $slug ) {
			if ( Competition_Workflow::STAGE_SLIDESHOW_SHOWN !== ( $stages[ $slug ] ?? '' ) ) {
				$stages[ $slug ] = Competition_Workflow::STAGE_VOTING;
			}
		}

		$prefix = $id . '_';

		foreach ( (array) ( $voting['voted_categories'] ?? array() ) as $key ) {
			if ( 0 !== strpos( (string) $key, $prefix ) ) {
				continue;
			}

			$slug = substr( (string) $key, strlen( $prefix ) );
			if ( ! in_array( $stages[ $slug ] ?? '', array( Competition_Workflow::STAGE_CRITIQUE, Competition_Workflow::STAGE_DONE ), true ) ) {
				$stages[ $slug ] = Competition_Workflow::STAGE_CRITIQUE;
			}
		}

		return array(
			'uploads_closed'    => ! empty( $settings['upload']['uploads_closed'] ),
			'results_published' => ! empty( $settings['results']['results_visible'] ),
			'stages'            => array_diff( $stages, array( Competition_Workflow::STAGE_NOT_STARTED ) ),
		);
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

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// Temporarily suppress duplicate key errors from dbDelta.
		// This happens when tables already exist with the correct schema.
		$suppress_errors = $wpdb->suppress_errors();

		foreach ( self::get_schema( $wpdb ) as $sql ) {
			dbDelta( $sql );
		}

		// Restore error reporting.
		$wpdb->suppress_errors( $suppress_errors );
	}

	/**
	 * Get SQL schema for custom tables.
	 *
	 * Exposed for testing.
	 *
	 * @param wpdb|null $wpdb WordPress database instance.
	 * @return array<string>
	 */
	public static function get_schema( ?wpdb $wpdb = null ): array {
		$wpdb = $wpdb ? $wpdb : $GLOBALS['wpdb'];

		$charset_collate = $wpdb->get_charset_collate();

		$members = "CREATE TABLE {$wpdb->prefix}photocomp_members (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			email VARCHAR(191) NOT NULL,
			grade VARCHAR(100) NOT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			committee TINYINT(1) NOT NULL DEFAULT 0,
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
			workflow LONGTEXT NULL,
			share_hash VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL,
			deleted_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY share_hash (share_hash)
		) {$charset_collate};";

		$images = "CREATE TABLE {$wpdb->prefix}photocomp_images (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			competition_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(100) NOT NULL,
			filename VARCHAR(191) NOT NULL,
			random_number BIGINT UNSIGNED NOT NULL,
			score INT NULL,
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
			score INT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY competition (competition_id),
			KEY image (image_id),
			KEY voting_token (voting_token_id),
			KEY voter_name (voter_name),
			UNIQUE KEY image_token (image_id, voting_token_id),
			UNIQUE KEY image_voter (image_id, voter_name)
		) {$charset_collate};";

		$upload_tokens = "CREATE TABLE {$wpdb->prefix}photocomp_upload_tokens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			competition_id BIGINT UNSIGNED NOT NULL,
			token VARCHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			first_accessed_at DATETIME NULL,
			sent_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY member_competition (member_id, competition_id),
			KEY token (token),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		$voting_tokens = "CREATE TABLE {$wpdb->prefix}photocomp_voting_tokens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			competition_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(100) NOT NULL,
			token_hash VARCHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			first_accessed_at DATETIME NULL,
			sent_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY member_competition_category (member_id, competition_id, category),
			KEY token_hash (token_hash),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		$logs = "CREATE TABLE {$wpdb->prefix}photocomp_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			competition_id BIGINT UNSIGNED NULL,
			event_type VARCHAR(100) NOT NULL,
			event_category VARCHAR(50) NOT NULL,
			actor_type VARCHAR(50) NOT NULL,
			actor_id BIGINT UNSIGNED NULL,
			actor_name VARCHAR(191) NULL,
			description TEXT NOT NULL,
			metadata LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY competition_id (competition_id),
			KEY event_type (event_type),
			KEY event_category (event_category),
			KEY created_at (created_at)
		) {$charset_collate};";

		return array(
			$members,
			$competitions,
			$images,
			$votes,
			$upload_tokens,
			$voting_tokens,
			$logs,
		);
	}
}
