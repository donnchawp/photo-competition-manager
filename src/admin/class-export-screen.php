<?php
/**
 * Admin interface for exporting data.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Entries;
use WP_Error;
use function PhotoCompetitionManager\Support\sanitize_csv_row;

/**
 * Export screen.
 *
 * @since 0.1.0
 */
class Export_Screen {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions_repository;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes_repository;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images_repository;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members_repository;

	/**
	 * Entries module.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->competitions_repository = new Competitions_Repository();
		$this->votes_repository        = new Votes_Repository();
		$this->images_repository       = new Images_Repository();
		$this->members_repository      = new Members_Repository();
		$this->entries                 = new Entries( $this->competitions_repository, $this->images_repository, $this->members_repository );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Render the export page.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Export Data', 'photo-competition-manager' ); ?></h1>

			<div class="card">
				<h2><?php esc_html_e( 'Export Votes', 'photo-competition-manager' ); ?></h2>
				<p><?php esc_html_e( 'Export the votes for a competition to a CSV file.', 'photo-competition-manager' ); ?></p>
				<form method="post">
					<input type="hidden" name="action" value="export_votes" />
					<?php wp_nonce_field( 'photo_competition_export_votes', 'photo_competition_export_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="competition_id"><?php esc_html_e( 'Competition', 'photo-competition-manager' ); ?></label>
							</th>
							<td>
								<select name="competition_id" id="competition_id" required>
									<?php
									$competitions = $this->competitions_repository->all( 100, true );
									foreach ( $competitions as $competition ) {
										printf(
											'<option value="%d">%s</option>',
											(int) $competition->id,
											esc_html( $competition->title )
										);
									}
									?>
								</select>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Export Votes', 'photo-competition-manager' ) ); ?>
				</form>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Export Uploading Users', 'photo-competition-manager' ); ?></h2>
				<p><?php esc_html_e( 'Export the list of users who uploaded images to a competition, with their image IDs.', 'photo-competition-manager' ); ?></p>
				<form method="post">
					<input type="hidden" name="action" value="export_uploading_users" />
					<?php wp_nonce_field( 'photo_competition_export_uploading_users', 'photo_competition_export_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="uploaders_competition_id"><?php esc_html_e( 'Competition', 'photo-competition-manager' ); ?></label>
							</th>
							<td>
								<select name="competition_id" id="uploaders_competition_id" required>
									<?php
									$competitions = $this->competitions_repository->all( 100, true );
									foreach ( $competitions as $competition ) {
										printf(
											'<option value="%d">%s</option>',
											(int) $competition->id,
											esc_html( $competition->title )
										);
									}
									?>
								</select>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Export Uploading Users', 'photo-competition-manager' ) ); ?>
				</form>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Export Original Images', 'photo-competition-manager' ); ?></h2>
				<p><?php esc_html_e( 'Download original images from the media library as a ZIP file. Original images can be deleted after export to save space.', 'photo-competition-manager' ); ?></p>
				<form method="post">
					<input type="hidden" name="action" value="export_originals" />
					<?php wp_nonce_field( 'photo_competition_export_originals', 'photo_competition_export_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="originals_competition_id"><?php esc_html_e( 'Competition', 'photo-competition-manager' ); ?></label>
							</th>
							<td>
								<select name="competition_id" id="originals_competition_id" required>
									<?php
									$competitions = $this->competitions_repository->all( 100, true );
									foreach ( $competitions as $competition ) {
										printf(
											'<option value="%d">%s</option>',
											(int) $competition->id,
											esc_html( $competition->title )
										);
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="delete_after_export">
									<?php esc_html_e( 'Delete After Export', 'photo-competition-manager' ); ?>
								</label>
							</th>
							<td>
								<label>
									<input type="checkbox" name="delete_after_export" id="delete_after_export" value="1" />
									<?php esc_html_e( 'Delete original images from media library after export (keeps thumbnails and slideshow images)', 'photo-competition-manager' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Export Original Images', 'photo-competition-manager' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle export actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['action'] ) || ! isset( $_POST['photo_competition_export_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			return;
		}

		$action = sanitize_key( $_POST['action'] );

		if ( 'export_votes' === $action ) {
			check_admin_referer( 'photo_competition_export_votes', 'photo_competition_export_nonce' );
			$this->export_votes();
		}

		if ( 'export_uploading_users' === $action ) {
			check_admin_referer( 'photo_competition_export_uploading_users', 'photo_competition_export_nonce' );
			$this->export_uploading_users();
		}

		if ( 'export_originals' === $action ) {
			check_admin_referer( 'photo_competition_export_originals', 'photo_competition_export_nonce' );
			$this->export_original_images();
		}
	}

	/**
	 * Export votes for a competition to a CSV file, separated by category.
	 *
	 * The rows come from votes_csv_rows().
	 *
	 * @return void
	 */
	private function export_votes(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		if ( ! $competition_id ) {
			return;
		}

		$votes = $this->votes_repository->get_votes_by_competition( $competition_id );
		if ( empty( $votes ) ) {
			return;
		}

		$rows = $this->votes_csv_rows( $votes, $this->images_repository->find_by_competition( $competition_id ) );

		$competition = $this->competitions_repository->find( $competition_id );
		$filename    = 'votes-' . ( $competition ? $competition->slug : $competition_id ) . '.csv';

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );
		foreach ( $rows as $row ) {
			fputcsv( $output, sanitize_csv_row( $row ) );
		}

		// phpcs:ignore
		fclose( $output );
		exit;
	}

	/**
	 * The votes CSV's rows, one section per category.
	 *
	 * Columns are aligned across all categories using the member's random_number.
	 * If a member didn't upload to a category, their column shows 0 for all voters.
	 * Votes for entries that no longer exist are left out.
	 *
	 * @since 0.4.0
	 *
	 * @param object[] $votes  The competition's votes.
	 * @param object[] $images The competition's entries.
	 * @return array<int, array<int, int|string>> Rows, not yet made safe for a spreadsheet.
	 */
	public function votes_csv_rows( array $votes, array $images ): array {
		// Build mappings for alignment across categories.
		$image_map              = array(); // image_id => random_number.
		$all_random_numbers     = array(); // All unique random_numbers in competition.
		$random_to_image_by_cat = array(); // category => random_number => image_id.

		foreach ( $images as $image ) {
			$image_id      = (int) $image->id;
			$random_number = (int) $image->random_number;
			$category      = $image->category;

			$image_map[ $image_id ] = $random_number;

			// Track all unique random_numbers.
			if ( ! in_array( $random_number, $all_random_numbers, true ) ) {
				$all_random_numbers[] = $random_number;
			}

			// Map random_number to image_id per category.
			if ( ! isset( $random_to_image_by_cat[ $category ] ) ) {
				$random_to_image_by_cat[ $category ] = array();
			}
			$random_to_image_by_cat[ $category ][ $random_number ] = $image_id;
		}

		// Sort random numbers for consistent column order.
		sort( $all_random_numbers, SORT_NUMERIC );

		// Group votes by category, then by voter, then by image_id.
		// Skip votes for images that no longer exist.
		$votes_by_category = array();
		foreach ( $votes as $vote ) {
			$image_id = (int) $vote->image_id;

			// Skip votes for deleted/non-existent images.
			if ( ! isset( $image_map[ $image_id ] ) ) {
				continue;
			}

			$category = $vote->category;
			// A voting-link vote has no name, so it's labelled by its token, as on the Results screen.
			$voter = $vote->voter_name ? $vote->voter_name : 'Token #' . $vote->voting_token_id;

			if ( ! isset( $votes_by_category[ $category ] ) ) {
				$votes_by_category[ $category ] = array();
			}
			if ( ! isset( $votes_by_category[ $category ][ $voter ] ) ) {
				$votes_by_category[ $category ][ $voter ] = array();
			}

			// Store vote keyed by image_id.
			$votes_by_category[ $category ][ $voter ][ $image_id ] = $vote->score;
		}

		// Each category is a separate section.
		$rows = array();
		foreach ( $votes_by_category as $category => $votes_by_voter ) {
			// Category header.
			$rows[] = array( 'Category: ' . $category );

			// Column header with ALL random numbers (aligned across categories).
			$header = array( 'Voter' );
			foreach ( $all_random_numbers as $random_number ) {
				$header[] = 'Image #' . $random_number;
			}
			$rows[] = $header;

			// Rows for this category, with votes in random_number order.
			ksort( $votes_by_voter ); // Sort voters alphabetically.
			foreach ( $votes_by_voter as $voter => $voter_votes ) {
				$row = array( $voter );
				foreach ( $all_random_numbers as $random_number ) {
					// Check if this random_number has an image in this category.
					$image_id_for_random = $random_to_image_by_cat[ $category ][ $random_number ] ?? null;

					if ( null === $image_id_for_random ) {
						// Member didn't upload to this category - show 0.
						$row[] = 0;
					} elseif ( isset( $voter_votes[ $image_id_for_random ] ) ) {
						// Voter voted for this image.
						$row[] = (int) $voter_votes[ $image_id_for_random ];
					} else {
						// Image exists but voter didn't vote for it.
						$row[] = '';
					}
				}
				$rows[] = $row;
			}

			// Blank line between categories.
			$rows[] = array( '' );
		}

		return $rows;
	}

	/**
	 * Export the list of users who uploaded images to a CSV file, separated by category.
	 *
	 * @return void
	 */
	private function export_uploading_users(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		if ( ! $competition_id ) {
			return;
		}

		$images = $this->images_repository->find_by_competition( $competition_id );
		if ( empty( $images ) ) {
			return;
		}

		$competition = $this->competitions_repository->find( $competition_id );
		$filename    = 'uploading-users-' . ( $competition ? $competition->slug : $competition_id ) . '.csv';

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );

		// Group images by category.
		$images_by_category = array();
		foreach ( $images as $image ) {
			$category = $image->category;
			if ( ! isset( $images_by_category[ $category ] ) ) {
				$images_by_category[ $category ] = array();
			}
			$images_by_category[ $category ][] = $image;
		}

		$members = $this->members_repository->find_many( array_column( $images, 'member_id' ) );

		// Write each category as a separate section.
		foreach ( $images_by_category as $category => $category_images ) {
			// Write category header.
			fputcsv( $output, sanitize_csv_row( array( 'Category: ' . $category ) ) );
			fputcsv( $output, sanitize_csv_row( array( 'ID', 'Name', 'Email' ) ) );

			// Build user list for this category.
			$users = array();
			foreach ( $category_images as $image ) {
				$member  = $members[ (int) $image->member_id ] ?? null;
				$users[] = array(
					'random_number' => $image->random_number,
					'name'          => $member ? $member->name : '',
					'email'         => $member ? $member->email : '',
				);
			}

			// Sort by random number.
			usort(
				$users,
				function ( $a, $b ) {
					return $a['random_number'] - $b['random_number'];
				}
			);

			foreach ( $users as $user ) {
				fputcsv(
					$output,
					sanitize_csv_row(
						array(
							$user['random_number'],
							$user['name'],
							$user['email'],
						)
					)
				);
			}

			// Add blank line between categories.
			fputcsv( $output, array( '' ) );
		}

		// phpcs:ignore
		fclose( $output );
		exit;
	}

	/**
	 * Export original images to a ZIP file.
	 *
	 * @return void
	 */
	private function export_original_images(): void {
		// Nonce is verified in handle_actions() before this method is called.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$delete_after_export = isset( $_POST['delete_after_export'] ) && '1' === $_POST['delete_after_export'];

		if ( ! $competition_id ) {
			return;
		}

		// Once the download starts, a refusal can't be shown.
		if ( $delete_after_export ) {
			$allowed = $this->entries->can_discard_originals( Actor::admin(), $competition_id );
			if ( is_wp_error( $allowed ) ) {
				wp_die( esc_html( $allowed->get_error_message() ) );
			}
		}

		$originals = $this->entries->originals( $competition_id );
		if ( empty( $originals ) ) {
			wp_die( esc_html__( 'No original images found for this competition.', 'photo-competition-manager' ) );
		}

		$zip_path = $this->build_originals_zip( $competition_id, $originals );
		if ( is_wp_error( $zip_path ) ) {
			wp_die( esc_html( $zip_path->get_error_message() ) );
		}

		$zip_filename = basename( $zip_path );

		// Send the ZIP file to the browser.
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename=' . $zip_filename );
		header( 'Content-Length: ' . filesize( $zip_path ) );

		// Streamed, since a ZIP of full-size originals can be bigger than PHP's memory limit.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		$sent = readfile( $zip_path );

		// Clean up temporary ZIP file.
		wp_delete_file( $zip_path );

		// Originals WordPress won't delete keep their IDs and are logged, so a retry picks them up.
		if ( $delete_after_export && false !== $sent ) {
			// Only the ones in the ZIP: an entry may have been added since.
			$this->entries->discard_originals( Actor::admin(), $competition_id, array_keys( $originals ) );
		}

		exit;
	}

	/**
	 * Build a ZIP of a competition's originals at the size they were uploaded.
	 *
	 * @since 0.4.0
	 *
	 * @param int                $competition_id Competition ID, for the ZIP's name.
	 * @param array<int, string> $originals      Original file paths, from Entries::originals().
	 * @return string|WP_Error Path of the ZIP file, which the caller deletes.
	 */
	public function build_originals_zip( int $competition_id, array $originals ) {
		$competition  = $this->competitions_repository->find( $competition_id );
		$zip_filename = 'originals-' . ( $competition ? $competition->slug : $competition_id ) . '.zip';

		// Create temporary directory for the zip file.
		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'photo-competition-manager-temp';

		if ( ! file_exists( $temp_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			wp_mkdir_p( $temp_dir );
		}

		$zip_path = trailingslashit( $temp_dir ) . $zip_filename;

		// Create ZIP archive.
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_failed', __( 'Could not create ZIP file.', 'photo-competition-manager' ) );
		}

		// Add each original to the ZIP. Originals live in month folders, so two can share a name.
		$names = array();
		foreach ( $originals as $attachment_id => $file_path ) {
			$name = basename( $file_path );
			if ( isset( $names[ $name ] ) ) {
				$name = $attachment_id . '-' . $name;
			}
			$names[ $name ] = true;

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false is handled below.
			if ( ! @$zip->addFile( $file_path, $name ) ) {
				$zip->close();
				wp_delete_file( $zip_path );
				return new WP_Error( 'zip_failed', __( 'Could not create ZIP file.', 'photo-competition-manager' ) );
			}
		}

		// The files are only read here, so this is where a vanished or unreadable original shows up.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false is handled below.
		if ( ! @$zip->close() ) {
			wp_delete_file( $zip_path );
			return new WP_Error( 'zip_failed', __( 'Could not create ZIP file.', 'photo-competition-manager' ) );
		}

		return $zip_path;
	}
}
