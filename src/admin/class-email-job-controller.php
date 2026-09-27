<?php
/**
 * Email job controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Email_Job_Notice;
use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Service\Email_Job_Manager;

/**
 * Sends email job batches for the progress notice's script.
 *
 * @since 0.3.0
 */
class Email_Job_Controller {

	use Email_Job_Notice;
	use Form_Rendering;

	/**
	 * AJAX action, also the nonce action, that sends the next batch of a job.
	 */
	const AJAX_ACTION = 'photo_comp_send_email_batch';

	/**
	 * Email job queue.
	 *
	 * @var Email_Job_Manager
	 */
	private $email_jobs;

	/**
	 * Constructor.
	 *
	 * @param Email_Job_Manager $email_jobs Email job queue.
	 */
	public function __construct( Email_Job_Manager $email_jobs ) {
		$this->email_jobs = $email_jobs;
	}

	/**
	 * Register hooks for this controller.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_send_batch' ) );
	}

	/**
	 * AJAX handler: send the next batch of a job and return its updated notice.
	 *
	 * @return void
	 */
	public function handle_send_batch(): void {
		check_ajax_referer( self::AJAX_ACTION );

		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'photo-competition-manager' ) ), 403 );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
		$job    = $this->email_jobs->process_batch( $job_id );

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'Email job not found.', 'photo-competition-manager' ) ), 404 );
		}

		wp_send_json_success( array( 'html' => $this->render_job_notice( $job_id, $job ) ) );
	}
}
