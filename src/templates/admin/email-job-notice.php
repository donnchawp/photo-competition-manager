<?php
/**
 * Background email job progress notice for admin pages.
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
 *     @type int      $failed_count    Emails that failed to send.
 *     @type string[] $errors          First few error log entries.
 *     @type string   $refresh_url     URL for the "Refresh now" link.
 * }
 */

defined( 'ABSPATH' ) || exit;

if ( 'processing' === $data['status'] || 'pending' === $data['status'] ) {
	echo '<div class="notice notice-info">';
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
	echo '<p><em>' . esc_html__( 'This page will refresh automatically every 5 seconds.', 'photo-competition-manager' ) . '</em> ';
	echo '<a href="' . esc_url( $data['refresh_url'] ) . '">' . esc_html__( 'Refresh now', 'photo-competition-manager' ) . '</a></p>';
	echo '</div>';

	// Auto-refresh every 5 seconds.
	echo '<meta http-equiv="refresh" content="5">';
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
			esc_html(
				/* translators: %d: Skipped count */
				_n(
					'%d member was skipped because they were emailed in the last 5 minutes.',
					'%d members were skipped because they were emailed in the last 5 minutes.',
					$data['skipped_count'],
					'photo-competition-manager'
				)
			),
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
