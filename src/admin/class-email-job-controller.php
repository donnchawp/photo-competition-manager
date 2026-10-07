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
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Service\Email_Job_Manager;
use PhotoCompetitionManager\Service\Email_Kinds;

/**
 * Sends email job batches for the progress notice's script, and flags jobs
 * that stopped because their page was closed.
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
	 * Action for admin-post.php that discards an abandoned job. The nonce action adds the job ID.
	 */
	const DISCARD_ACTION = 'photo_comp_discard_email_job';

	/**
	 * Query arg added when Discard found nothing to discard.
	 */
	const NOT_DISCARDED_ARG = 'email_job_not_discarded';

	/**
	 * Email job queue.
	 *
	 * @var Email_Job_Manager
	 */
	private $email_jobs;

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Constructor.
	 *
	 * @param Email_Job_Manager       $email_jobs   Email job queue.
	 * @param Competitions_Repository $competitions Competitions repository.
	 */
	public function __construct( Email_Job_Manager $email_jobs, Competitions_Repository $competitions ) {
		$this->email_jobs   = $email_jobs;
		$this->competitions = $competitions;
	}

	/**
	 * Register hooks for this controller.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_send_batch' ) );
		add_action( 'admin_post_' . self::DISCARD_ACTION, array( $this, 'handle_discard' ) );
		add_action( 'admin_notices', array( $this, 'render_abandoned_job_notices' ) );
	}

	/**
	 * Show a notice for each job that stopped partway, with links to carry on
	 * sending it or discard it.
	 *
	 * @return void
	 */
	public function render_abandoned_job_notices(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::NOT_DISCARDED_ARG ] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( "The email job wasn't discarded because it's still sending or has already finished.", 'photo-competition-manager' ) . '</p></div>';
		}

		// That job's progress notice is already on this page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_job = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';

		foreach ( $this->email_jobs->get_abandoned_jobs() as $job_id => $job ) {
			if ( $job_id === $current_job ) {
				continue;
			}

			$competition = $this->competitions->find( (int) $job['competition_id'], true );

			$notice = $this->render_template(
				'admin/abandoned-email-job-notice.php',
				array(
					'message'      => sprintf(
						Email_Kinds::get( $job['type'] )['job']['stopped'],
						$competition ? $competition->title : '#' . $job['competition_id'],
						$this->email_job_progress( $job ),
						$job['total_count']
					),
					'carry_on_url' => $this->carry_on_url( $job_id, $job ),
					'discard_url'  => $this->discard_url( $job_id ),
				)
			);

			echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		}
	}

	/**
	 * The admin page that sends a job, with the job in the URL so its
	 * progress notice picks up where it stopped.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $job    Job data.
	 * @return string URL.
	 */
	private function carry_on_url( string $job_id, array $job ): string {
		return add_query_arg(
			array(
				'page'        => Email_Kinds::get( $job['type'] )['job']['page'],
				'competition' => (int) $job['competition_id'],
				'job_id'      => $job_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Nonced URL that discards a job.
	 *
	 * @param string $job_id Job ID.
	 * @return string URL.
	 */
	private function discard_url( string $job_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::DISCARD_ACTION,
					'job_id' => $job_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::DISCARD_ACTION . '_' . $job_id
		);
	}

	/**
	 * Handler for admin-post.php: discard an abandoned job and go back.
	 *
	 * @return void
	 */
	public function handle_discard(): void {
		$job_id = isset( $_GET['job_id'] ) ? sanitize_text_field( wp_unslash( $_GET['job_id'] ) ) : '';

		check_admin_referer( self::DISCARD_ACTION . '_' . $job_id );

		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		/* translators: %s: Name of the user who discarded the job */
		$discarded = $this->email_jobs->discard_job( $job_id, sprintf( __( 'Discarded by %s', 'photo-competition-manager' ), wp_get_current_user()->display_name ) );

		$referer = wp_get_referer();
		$url     = remove_query_arg( self::NOT_DISCARDED_ARG, $referer ? $referer : $this->dashboard_url() );

		wp_safe_redirect( $discarded ? $url : add_query_arg( self::NOT_DISCARDED_ARG, '1', $url ) );
		exit;
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
