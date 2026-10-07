<?php
/**
 * Email job progress notice for admin pages.
 *
 * @package PhotoCompetitionManager
 *
 * @var array $data {
 *     @type string   $status          Job status: pending, processing, completed or failed.
 *     @type string   $sending_label   Heading while the job is running.
 *     @type string   $sent_label      Heading once the job has completed.
 *     @type int      $processed_count Members processed so far.
 *     @type int      $total_count     Members in the job.
 *     @type int      $percent         Progress percentage.
 *     @type int      $sent_count      Emails sent.
 *     @type int      $skipped_count   Members skipped (e.g. rate limited).
 *     @type array    $skipped_label   Why they were skipped, from _n_noop().
 *     @type int      $failed_count    Emails that failed to send.
 *     @type string[] $errors          First few error log entries.
 *     @type string   $job_id          Job ID.
 *     @type string   $ajax_url        admin-ajax.php URL.
 *     @type string   $ajax_action     AJAX action that sends the next batch.
 *     @type string   $nonce           Nonce for the AJAX action, empty once the job has finished.
 * }
 */

defined( 'ABSPATH' ) || exit;

if ( 'processing' === $data['status'] || 'pending' === $data['status'] ) {
	printf(
		'<div class="notice notice-info photo-comp-email-job" data-job-id="%s" data-ajax-url="%s" data-action="%s" data-nonce="%s">',
		esc_attr( $data['job_id'] ),
		esc_url( $data['ajax_url'] ),
		esc_attr( $data['ajax_action'] ),
		esc_attr( $data['nonce'] )
	);
	echo '<p><strong>' . esc_html( $data['sending_label'] ) . '</strong></p>';
	echo '<p>';
	printf(
		/* translators: 1: Sent count, 2: Total count, 3: Progress percentage */
		esc_html__( 'Progress: %1$d of %2$d emails sent (%3$d%%)', 'photo-competition-manager' ),
		esc_html( $data['processed_count'] ),
		esc_html( $data['total_count'] ),
		absint( $data['percent'] )
	);
	echo '</p>';
	echo '<p><em>' . esc_html__( 'Keep this page open until sending finishes. If you leave, a notice on any admin page lets you carry on from where it stopped.', 'photo-competition-manager' ) . '</em></p>';
	echo '<p class="photo-comp-email-job-error" hidden>';
	echo esc_html__( 'Sending stopped:', 'photo-competition-manager' ) . ' <span>' . esc_html__( 'the server returned an error. Your login may have expired; reload the page to carry on.', 'photo-competition-manager' ) . '</span> ';
	echo '<button type="button" class="button">' . esc_html__( 'Try again', 'photo-competition-manager' ) . '</button>';
	echo '</p>';
	echo '</div>';
} elseif ( 'completed' === $data['status'] ) {
	echo '<div class="notice notice-success is-dismissible">';
	echo '<p><strong>' . esc_html( $data['sent_label'] ) . '</strong></p>';
	echo '<p>';
	printf(
		/* translators: 1: Sent count, 2: Total count */
		esc_html__( 'Sent %1$d of %2$d emails.', 'photo-competition-manager' ),
		esc_html( $data['sent_count'] ),
		esc_html( $data['total_count'] )
	);

	if ( $data['skipped_count'] > 0 ) {
		echo ' ';
		printf(
			esc_html( translate_nooped_plural( $data['skipped_label'], $data['skipped_count'], 'photo-competition-manager' ) ),
			esc_html( $data['skipped_count'] )
		);
	}

	if ( $data['failed_count'] > 0 ) {
		echo ' ';
		printf(
			/* translators: %d: Failed count */
			esc_html__( '%d emails failed to send.', 'photo-competition-manager' ),
			esc_html( $data['failed_count'] )
		);
	}

	echo '</p>';

	if ( $data['failed_count'] > 0 && ! empty( $data['errors'] ) ) {
		echo '<ul>';
		foreach ( $data['errors'] as $job_error ) {
			echo '<li>' . esc_html( $job_error ) . '</li>';
		}
		echo '</ul>';
	}

	echo '</div>';
} elseif ( 'failed' === $data['status'] ) {
	echo '<div class="notice notice-error is-dismissible">';
	echo '<p><strong>' . esc_html__( 'Email job failed.', 'photo-competition-manager' ) . '</strong></p>';

	if ( ! empty( $data['errors'] ) ) {
		echo '<ul>';
		foreach ( $data['errors'] as $job_error ) {
			echo '<li>' . esc_html( $job_error ) . '</li>';
		}
		echo '</ul>';
	}

	echo '</div>';
}
