<?php
/**
 * Admin interface for exporting data.
 *
 * @package ClubCompetitions\Admin
 */

namespace ClubCompetitions\Admin;

use ClubCompetitions\Repository\Competitions_Repository;
use ClubCompetitions\Repository\Votes_Repository;
use ClubCompetitions\Repository\Images_Repository;
use ClubCompetitions\Repository\Members_Repository;

/**
 * Manage Club Competitions export screen.
 *
 * @since 1.0.0
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
	 * Constructor.
	 */
	public function __construct() {
		$this->competitions_repository = new Competitions_Repository();
		$this->votes_repository        = new Votes_Repository();
		$this->images_repository       = new Images_Repository();
		$this->members_repository      = new Members_Repository();
	}

	/**
	 * Render the export page.
	 *
	 * @return void
	 */
	public function render(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Export Data', 'club-competitions' ); ?></h1>

			<div class="card">
				<h2><?php esc_html_e( 'Export Votes', 'club-competitions' ); ?></h2>
				<p><?php esc_html_e( 'Export the votes for a competition to a CSV file.', 'club-competitions' ); ?></p>
				<form method="post">
					<input type="hidden" name="action" value="export_votes" />
					<?php wp_nonce_field( 'club_competitions_export_votes', 'club_competitions_export_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="competition_id"><?php esc_html_e( 'Competition', 'club-competitions' ); ?></label>
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
					<?php submit_button( __( 'Export Votes', 'club-competitions' ) ); ?>
				</form>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Export Uploading Users', 'club-competitions' ); ?></h2>
				<p><?php esc_html_e( 'Export the list of users who uploaded images to a competition, with their image IDs.', 'club-competitions' ); ?></p>
				<form method="post">
					<input type="hidden" name="action" value="export_uploading_users" />
					<?php wp_nonce_field( 'club_competitions_export_uploading_users', 'club_competitions_export_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="uploaders_competition_id"><?php esc_html_e( 'Competition', 'club-competitions' ); ?></label>
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
					<?php submit_button( __( 'Export Uploading Users', 'club-competitions' ) ); ?>
				</form>
			</div>

			<div class="card">
				<h2><?php esc_html_e( 'Export Original Images', 'club-competitions' ); ?></h2>
				<p><?php esc_html_e( 'Download original images from the media library as a ZIP file. Original images can be deleted after export to save space.', 'club-competitions' ); ?></p>
				<form method="post">
					<input type="hidden" name="action" value="export_originals" />
					<?php wp_nonce_field( 'club_competitions_export_originals', 'club_competitions_export_nonce' ); ?>
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="originals_competition_id"><?php esc_html_e( 'Competition', 'club-competitions' ); ?></label>
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
									<?php esc_html_e( 'Delete After Export', 'club-competitions' ); ?>
								</label>
							</th>
							<td>
								<label>
									<input type="checkbox" name="delete_after_export" id="delete_after_export" value="1" />
									<?php esc_html_e( 'Delete original images from media library after export (keeps thumbnails and slideshow images)', 'club-competitions' ); ?>
								</label>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Export Original Images', 'club-competitions' ) ); ?>
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
		if ( ! isset( $_POST['action'] ) || ! isset( $_POST['club_competitions_export_nonce'] ) ) {
			return;
		}

		$action = sanitize_key( $_POST['action'] );

		if ( 'export_votes' === $action ) {
			check_admin_referer( 'club_competitions_export_votes', 'club_competitions_export_nonce' );
			$this->export_votes();
		}

		if ( 'export_uploading_users' === $action ) {
			check_admin_referer( 'club_competitions_export_uploading_users', 'club_competitions_export_nonce' );
			$this->export_uploading_users();
		}

		if ( 'export_originals' === $action ) {
			check_admin_referer( 'club_competitions_export_originals', 'club_competitions_export_nonce' );
			$this->export_original_images();
		}
	}

	/**
	 * Export votes for a competition to a CSV file.
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

		$competition = $this->competitions_repository->find( $competition_id );
		$filename    = 'votes-' . ( $competition ? $competition->slug : $competition_id ) . '.csv';

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$output = fopen( 'php://output', 'w' );

		// Group votes by voter.
		$votes_by_voter = array();
		foreach ( $votes as $vote ) {
			$votes_by_voter[ $vote->voter_name ][] = $vote->score;
		}

		// Write header.
		$max_votes = 0;
		foreach ( $votes_by_voter as $voter_votes ) {
			$max_votes = max( $max_votes, count( $voter_votes ) );
		}
		$header = array( 'Voter' );
		for ( $i = 1; $i <= $max_votes; $i++ ) {
			$header[] = 'Vote ' . $i;
		}
		fputcsv( $output, $header );

		// Write rows.
		foreach ( $votes_by_voter as $voter => $voter_votes ) {
			$row = array( $voter );
			foreach ( $voter_votes as $vote ) {
				$row[] = (int) $vote;
			}
			fputcsv( $output, $row );
		}

		// phpcs:ignore
		fclose( $output );
		exit;
	}

	/**
	 * Export the list of users who uploaded images to a CSV file.
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

		fputcsv( $output, array( 'ID', 'Name', 'Email' ) );

		// Group images by member.
		$users = array();
		foreach ( $images as $image ) {
			if ( ! isset( $users[ $image->member_id ] ) ) {
				$member                     = $this->members_repository->find( $image->member_id );
				$users[ $image->member_id ] = array(
					'random_number' => $image->random_number,
					'name'          => $member ? $member->name : '',
					'email'         => $member ? $member->email : '',
				);
			}
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
				array(
					$user['random_number'],
					$user['name'],
					$user['email'],
				)
			);
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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$competition_id      = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$delete_after_export = isset( $_POST['delete_after_export'] ) && '1' === $_POST['delete_after_export'];

		if ( ! $competition_id ) {
			return;
		}

		// Get all original attachment IDs for this competition.
		$attachment_ids = $this->images_repository->get_original_attachment_ids( $competition_id );

		if ( empty( $attachment_ids ) ) {
			wp_die( esc_html__( 'No original images found for this competition.', 'club-competitions' ) );
		}

		$competition  = $this->competitions_repository->find( $competition_id );
		$zip_filename = 'originals-' . ( $competition ? $competition->slug : $competition_id ) . '.zip';

		// Create temporary directory for the zip file.
		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'club-competitions-temp';

		if ( ! file_exists( $temp_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			wp_mkdir_p( $temp_dir );
		}

		$zip_path = trailingslashit( $temp_dir ) . $zip_filename;

		// Create ZIP archive.
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not create ZIP file.', 'club-competitions' ) );
		}

		// Add each attachment to the ZIP.
		foreach ( $attachment_ids as $attachment_id ) {
			$file_path = get_attached_file( $attachment_id );

			if ( $file_path && file_exists( $file_path ) ) {
				$filename = basename( $file_path );
				$zip->addFile( $file_path, $filename );
			}
		}

		$zip->close();

		// Send the ZIP file to the browser.
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename=' . $zip_filename );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		readfile( $zip_path );

		// Clean up temporary ZIP file.
		wp_delete_file( $zip_path );

		// Delete originals if requested.
		if ( $delete_after_export ) {
			foreach ( $attachment_ids as $attachment_id ) {
				wp_delete_attachment( $attachment_id, true );
			}

			// Clear the attachment IDs from the database.
			$this->images_repository->clear_original_attachment_ids( $competition_id );
		}

		exit;
	}
}
