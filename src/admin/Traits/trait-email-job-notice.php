<?php
/**
 * Email job progress notice for admin screens.
 *
 * @package PhotoCompetitionManager\Admin\Traits
 */

namespace PhotoCompetitionManager\Admin\Traits;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Email_Job_Controller;
use PhotoCompetitionManager\Service\Email_Job_Manager;

/**
 * Renders the progress of an email job. While the job is unfinished, the
 * notice's script sends it batch by batch and swaps in the updated notice.
 *
 * Requires the Form_Rendering trait.
 *
 * @since 0.3.0
 */
trait Email_Job_Notice {

	/**
	 * Render the progress notice for the job in `$_GET['job_id']`, if any.
	 *
	 * @param Email_Job_Manager $email_jobs Email job queue.
	 * @return string Notice HTML, or an empty string when there is no job.
	 */
	private function render_email_job_notice( Email_Job_Manager $email_jobs ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['job_id'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$job_id = sanitize_text_field( wp_unslash( $_GET['job_id'] ) );
		$job    = $email_jobs->get_job_status( $job_id );

		return $job ? $this->render_job_notice( $job_id, $job ) : '';
	}

	/**
	 * Render the progress notice for a job.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $job    Job data.
	 * @return string Notice HTML.
	 */
	private function render_job_notice( string $job_id, array $job ): string {
		$running = in_array( $job['status'], array( 'pending', 'processing' ), true );

		if ( $running ) {
			wp_enqueue_script(
				'photo-comp-email-job',
				PHOTO_COMPETITION_MANAGER_URL . 'assets/js/admin-email-job.js',
				array(),
				PHOTO_COMPETITION_MANAGER_VERSION,
				true
			);
		}

		$labels = array(
			'results'       => array(
				__( 'Sending results emails...', 'photo-competition-manager' ),
				__( 'Email results sent successfully!', 'photo-competition-manager' ),
			),
			'upload_link'   => array(
				__( 'Sending upload link emails...', 'photo-competition-manager' ),
				__( 'Upload link emails sent.', 'photo-competition-manager' ),
			),
			'results_share' => array(
				__( 'Sending results link emails...', 'photo-competition-manager' ),
				__( 'Results link emails sent.', 'photo-competition-manager' ),
			),
			'voting_opened' => array(
				__( 'Sending voting opened emails...', 'photo-competition-manager' ),
				__( 'Voting opened emails sent.', 'photo-competition-manager' ),
			),
		);
		$label  = $labels[ $job['type'] ?? 'results' ] ?? $labels['results'];

		$processed = count( $job['processed_ids'] );

		return $this->render_template(
			'admin/email-job-notice.php',
			array(
				'status'          => $job['status'],
				'sending_label'   => $label[0],
				'sent_label'      => $label[1],
				'processed_count' => $processed,
				'total_count'     => $job['total_count'],
				'percent'         => $job['total_count'] > 0 ? ( $processed / $job['total_count'] ) * 100 : 0,
				'sent_count'      => $job['sent_count'],
				'skipped_count'   => $job['skipped_count'] ?? 0,
				'failed_count'    => $job['failed_count'],
				'errors'          => array_slice( $job['error_log'], 0, 5 ),
				'job_id'          => $job_id,
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'ajax_action'     => Email_Job_Controller::AJAX_ACTION,
				'nonce'           => $running ? wp_create_nonce( Email_Job_Controller::AJAX_ACTION ) : '',
			)
		);
	}
}
