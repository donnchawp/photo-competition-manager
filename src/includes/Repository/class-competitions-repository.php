<?php
/**
 * Repository for competitions.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;

use function PhotoCompetitionManager\Support\format_slug;

/**
 * Repository for competitions.
 *
 * @package PhotoCompetitionManager\Repository
 */
class Competitions_Repository extends Abstract_Repository {

	/**
	 * Fetch competitions ordered by creation date.
	 *
	 * @param int  $limit Number of records to return.
	 * @param bool $include_archived Whether to include archived records.
	 * @param bool $only_archived Whether to return only archived records.
	 * @return array<int, object>
	 */
	public function all( int $limit = 20, bool $include_archived = false, bool $only_archived = false ): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		if ( $only_archived ) {
			$conditions = 'deleted_at IS NOT NULL';
		} elseif ( $include_archived ) {
			$conditions = '1=1';
		} else {
			$conditions = 'deleted_at IS NULL';
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- LIMIT is prepared separately.
		$sql = sprintf(
			'SELECT * FROM %s WHERE %s ORDER BY created_at DESC LIMIT %%d',
			$this->table(),
			$conditions
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $this->wpdb->prepare( $sql, $limit ) );
	}

	/**
	 * Count competitions.
	 *
	 * @param bool $only_archived Whether to count only archived records.
	 * @return int
	 */
	public function count( bool $only_archived = false ): int {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		$condition = $only_archived ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$condition}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Locate a competition by ID.
	 *
	 * @param int  $id Competition ID.
	 * @param bool $include_archived Whether to include archived competitions.
	 * @return object|null
	 */
	public function find( int $id, bool $include_archived = false ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return null;
		}

		$conditions = '';

		if ( ! $include_archived ) {
			$conditions .= ' AND deleted_at IS NULL';
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE id = %d{$conditions}",
				$id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	/**
	 * Locate a competition by slug.
	 *
	 * @param string $slug Competition slug.
	 * @param bool   $include_archived Whether to include archived competitions.
	 * @return object|null
	 */
	public function find_by_slug( string $slug, bool $include_archived = false ) {
		if ( ! $this->table_exists() || empty( $slug ) ) {
			return null;
		}

		$conditions = '';
		if ( ! $include_archived ) {
			$conditions .= ' AND deleted_at IS NULL';
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE slug = %s{$conditions}",
				$slug
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	/**
	 * Find the current active competition.
	 *
	 * Returns a competition whose open date has started, close date has not passed,
	 * and that has not been archived.
	 *
	 * @return object|null
	 */
	public function find_current_active() {
		if ( ! $this->table_exists() ) {
			return null;
		}

		$current = current_time( 'mysql' );

		// phpcs:disable WordPress.DB.PreparedSQL
		return $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table()}\n\t\t\t\tWHERE deleted_at IS NULL\n\t\t\t\tAND (open_date IS NULL OR open_date <= %s)\n\t\t\t\tAND (close_date IS NULL OR close_date >= %s)\n\t\t\t\tORDER BY open_date DESC, created_at DESC\n\t\t\t\tLIMIT 1",
				$current,
				$current
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL
	}

	/**
	 * Create a competition.
	 *
	 * @param array<string, mixed> $data Competition data.
	 * @return int|WP_Error
	 */
	public function create( array $data ) {
		if ( ! $this->table_exists() ) {
			return new WP_Error( 'missing_table', __( 'Competition table is not available. Activate the plugin again.', 'photo-competition-manager' ) );
		}

		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : '';

		if ( '' === $title ) {
			return new WP_Error( 'invalid_title', __( 'Competition title is required.', 'photo-competition-manager' ) );
		}

		$slug = isset( $data['slug'] ) && '' !== trim( (string) $data['slug'] )
			? sanitize_title( $data['slug'] )
			: format_slug( $title );

		if ( $this->slug_exists( $slug ) ) {
			return new WP_Error( 'duplicate_slug', __( 'A competition with this slug already exists.', 'photo-competition-manager' ) );
		}

		$open_date  = $this->normalize_date( $data['open_date'] ?? null );
		$close_date = $this->normalize_date( $data['close_date'] ?? null );
		$now        = current_time( 'mysql' );

		$payload = array(
			'title'      => $title,
			'slug'       => $slug,
			'open_date'  => $open_date,
			'close_date' => $close_date,
			'settings'   => isset( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : null,
			'created_at' => $now,
			'updated_at' => $now,
		);

		$format = array(
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		);

		$inserted = $this->wpdb->insert( $this->table(), $payload, $format );

		if ( false === $inserted ) {
			return new WP_Error( 'db_insert_failed', __( 'Could not create competition.', 'photo-competition-manager' ), $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Update a competition.
	 *
	 * @param int                  $id   Competition ID.
	 * @param array<string, mixed> $data Updated data.
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $data ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$current = $this->find( $id );

		if ( ! $current ) {
			return new WP_Error( 'missing_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$title = isset( $data['title'] ) ? sanitize_text_field( (string) $data['title'] ) : $current->title;

		if ( '' === $title ) {
			return new WP_Error( 'invalid_title', __( 'Competition title is required.', 'photo-competition-manager' ) );
		}

		$slug_source = isset( $data['slug'] ) && '' !== trim( (string) $data['slug'] )
			? sanitize_title( $data['slug'] )
			: ( $current->slug ? $current->slug : format_slug( $title ) );

		$slug = $slug_source ? $slug_source : format_slug( $title );

		if ( $this->slug_exists( $slug, $id ) ) {
			return new WP_Error( 'duplicate_slug', __( 'A competition with this slug already exists.', 'photo-competition-manager' ) );
		}

		$open_date  = array_key_exists( 'open_date', $data ) ? $this->normalize_date( $data['open_date'] ) : $current->open_date;
		$close_date = array_key_exists( 'close_date', $data ) ? $this->normalize_date( $data['close_date'] ) : $current->close_date;

		$payload = array(
			'title'      => $title,
			'slug'       => $slug,
			'open_date'  => $open_date,
			'close_date' => $close_date,
			'settings'   => isset( $data['settings'] ) ? wp_json_encode( $data['settings'] ) : $current->settings,
			'updated_at' => current_time( 'mysql' ),
		);

		$format = array(
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		);

		$updated = $this->wpdb->update(
			$this->table(),
			$payload,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_update_failed', __( 'Could not update competition.', 'photo-competition-manager' ), $this->wpdb->last_error );
		}

		return true;
	}

	/**
	 * Soft delete (archive) a competition.
	 *
	 * @param int $id Competition ID.
	 * @return bool|WP_Error
	 */
	public function archive( int $id ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$updated = $this->wpdb->update(
			$this->table(),
			array(
				'deleted_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return new WP_Error( 'db_archive_failed', __( 'Could not archive competition.', 'photo-competition-manager' ), $this->wpdb->last_error );
		}

		return true;
	}

	/**
	 * Restore archived competition.
	 *
	 * @param int $id Competition ID.
	 * @return bool|WP_Error
	 */
	public function restore( int $id ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $this->wpdb->prepare(
			"UPDATE {$this->table()} SET deleted_at = NULL, updated_at = %s WHERE id = %d",
			current_time( 'mysql' ),
			$id
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$updated = $this->wpdb->query( $sql );

		if ( false === $updated ) {
			return new WP_Error( 'db_restore_failed', __( 'Could not restore competition.', 'photo-competition-manager' ), $this->wpdb->last_error );
		}

		return true;
	}

	/**
	 * Permanently delete a competition and all associated data.
	 *
	 * This will delete:
	 * - All images for the competition
	 * - All votes for the competition
	 * - All upload tokens for the competition
	 * - All voting tokens for the competition
	 * - The competition record itself
	 *
	 * @param int $id Competition ID.
	 * @return bool|WP_Error
	 */
	public function delete( int $id ) {
		if ( ! $this->table_exists() || $id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		// Verify competition exists.
		$competition = $this->find( $id, true );
		if ( ! $competition ) {
			return new WP_Error( 'missing_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		// Delete all related data in proper order.
		$votes_repo        = new Votes_Repository();
		$images_repo       = new Images_Repository();
		$upload_token_repo = new Upload_Token_Repository();
		$voting_token_repo = new Voting_Token_Repository();

		// Delete votes first (they reference images).
		$votes_repo->delete_by_competition( $id );

		// Delete images.
		$images_repo->delete_by_competition( $id );

		// Delete tokens.
		$upload_token_repo->delete_by_competition( $id );
		$voting_token_repo->delete_by_competition( $id );

		// Finally, delete the competition itself.
		$deleted = $this->wpdb->delete(
			$this->table(),
			array( 'id' => $id ),
			array( '%d' )
		);

		if ( false === $deleted ) {
			return new WP_Error( 'db_delete_failed', __( 'Could not delete competition.', 'photo-competition-manager' ), $this->wpdb->last_error );
		}

		return true;
	}

	/**
	 * Check whether a slug already exists.
	 *
	 * @param string   $slug        Competition slug.
	 * @param int|null $exclude_id  Competition ID to exclude.
	 * @return bool
	 */
	private function slug_exists( string $slug, ?int $exclude_id = null ): bool {
		$params = array( $slug );

		$conditions = '';

		if ( $exclude_id ) {
			$conditions .= ' AND id != %d';
			$params[]    = $exclude_id;
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $this->wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table()} WHERE slug = %s{$conditions}",
			...$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $sql ) > 0;
	}

	/**
	 * Normalize user-supplied date.
	 *
	 * @param mixed $value Date value.
	 * @return string|null
	 */
	private function normalize_date( $value ): ?string {
		if ( empty( $value ) ) {
			return null;
		}

		$timestamp = strtotime( (string) $value );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function table_suffix(): string {
		return 'photocomp_competitions';
	}

	/**
	 * Send submission reminder emails to all active members for a competition.
	 *
	 * @since 1.0.0
	 * @param int $competition_id Competition ID.
	 * @return array{success: bool, sent_count: int, total_count: int, message: string}|WP_Error
	 */
	public function send_submission_reminder_emails( $competition_id ) {
		if ( ! $this->table_exists() || $competition_id <= 0 ) {
			return new WP_Error(
				'invalid_competition',
				__( 'Competition not found.', 'photo-competition-manager' )
			);
		}

		$competition = $this->find( $competition_id );

		if ( ! $competition ) {
			return new WP_Error(
				'missing_competition',
				__( 'Competition not found.', 'photo-competition-manager' )
			);
		}

		if ( ! $this->is_open( $competition ) ) {
			return new WP_Error(
				'competition_not_open',
				__( 'Competition must be open to send reminder emails.', 'photo-competition-manager' )
			);
		}

		// Get members repository.
		$members_repository = new Members_Repository();
		$members            = $members_repository->all( 9999, false, false );

		if ( empty( $members ) ) {
			return new WP_Error(
				'no_members',
				__( 'No active members found.', 'photo-competition-manager' )
			);
		}

		// Determine upload page URL (page containing [competition_upload]); fall back to home URL.
		$upload_page_url = home_url( '/' );
		if ( function_exists( 'get_pages' ) ) {
			$pages = get_pages(
				array(
					'number' => 100,
				)
			);
			if ( is_array( $pages ) ) {
				foreach ( $pages as $page ) {
					if ( ! empty( $page->post_content ) && function_exists( 'has_shortcode' ) && has_shortcode( $page->post_content, 'competition_upload' ) ) {
						$upload_page_url = get_permalink( $page->ID );
						break;
					}
				}
			}
		}
		$upload_page_url = apply_filters( 'photo_comp_upload_page_url', $upload_page_url, $competition );

		// Use token repository to generate tokens and send emails.
		$token_repo  = new Upload_Token_Repository();
		$sent_count  = 0;
		$total_count = count( $members );

		foreach ( $members as $member ) {
			if ( empty( $member->email ) ) {
				continue;
			}

			$result = $token_repo->send_upload_link_for_member(
				(int) $competition_id,
				(int) $member->id,
				$upload_page_url
			);

			if ( true === $result ) {
				++$sent_count;
			}
		}

		return array(
			'success'     => true,
			'sent_count'  => $sent_count,
			'total_count' => $total_count,
			'message'     => sprintf(
				/* translators: 1: Number of emails sent, 2: Total number of members */
				__( 'Sent %1$d of %2$d submission reminder emails.', 'photo-competition-manager' ),
				$sent_count,
				$total_count
			),
		);
	}

	/**
	 * Check if a competition is open (within date range and not deleted).
	 *
	 * @param object $competition Competition object.
	 * @return bool
	 */
	public function is_open( object $competition ): bool {
		if ( ! empty( $competition->deleted_at ) ) {
			return false;
		}

		$current = current_time( 'mysql' );

		// Check if open_date has passed (or is null).
		if ( ! empty( $competition->open_date ) && $competition->open_date > $current ) {
			return false;
		}

		// Check if close_date has not passed (or is null).
		if ( ! empty( $competition->close_date ) && $competition->close_date < $current ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a competition is accepting uploads.
	 *
	 * @param object $competition Competition object.
	 * @return bool
	 */
	public function is_accepting_uploads( object $competition ): bool {
		if ( ! $this->is_open( $competition ) ) {
			return false;
		}

		// Check uploads_closed setting.
		$settings = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings ?? '' );
		if ( ! empty( $settings['uploads_closed'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a competition is accepting votes for a specific category.
	 *
	 * @param object      $competition Competition object.
	 * @param string|null $category    Category slug (optional).
	 * @return bool
	 */
	public function is_accepting_votes( object $competition, ?string $category = null ): bool {
		if ( ! $this->is_open( $competition ) ) {
			return false;
		}

		// Check open_categories setting.
		if ( null !== $category ) {
			$settings        = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings ?? '' );
			$open_categories = $settings['open_categories'] ?? array();

			// If open_categories is set and category is not in it, voting is closed for this category.
			if ( ! empty( $open_categories ) && ! in_array( $category, $open_categories, true ) ) {
				return false;
			}
		}

		return true;
	}
}
