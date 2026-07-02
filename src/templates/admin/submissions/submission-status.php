<?php
/**
 * Submission status/quota summary partial for the admin submissions page.
 *
 * Reads $data['categories'] (array of category config arrays with
 * 'slug'/'label'/'quota' keys), $data['member_id'] (int, active member
 * filter; 0 = all), $data['members'] (array of member objects),
 * $data['member_counts'] (array keyed by member id => category slug =>
 * submission count), $data['upload_tracking'] and $data['voting_tracking']
 * (arrays keyed by member id of tracking objects with a first_opened_at
 * property).
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 20px;">';
echo '<h3 style="margin-top: 0;">' . esc_html__( 'Submission Status', 'photo-competition-manager' ) . '</h3>';
echo '<p class="description">' . esc_html__( 'Overview of submissions per member across all categories.', 'photo-competition-manager' ) . '</p>';

echo '<table class="widefat" style="margin-top: 10px;">';
echo '<thead><tr>';
echo '<th>' . esc_html__( 'Member', 'photo-competition-manager' ) . '</th>';
foreach ( $data['categories'] as $cat_config ) {
	echo '<th>' . esc_html( $cat_config['label'] ) . '</th>';
}
echo '<th>' . esc_html__( 'Total', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Upload Link Opened', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Voting Link Opened', 'photo-competition-manager' ) . '</th>';
echo '</tr></thead>';
echo '<tbody>';

// If filtering by member, show only that member.
$members_to_show = $data['member_id'] > 0 ? array_filter( $data['members'], fn( $m ) => (int) $m->id === $data['member_id'] ) : $data['members'];

foreach ( $members_to_show as $member ) {
	$mid         = (int) $member->id;
	$total_count = 0;

	echo '<tr>';
	echo '<td><strong>' . esc_html( $member->name ) . '</strong></td>';

	foreach ( $data['categories'] as $cat_config ) {
		$cat_slug     = $cat_config['slug'];
		$quota        = $cat_config['quota'];
		$current      = $data['member_counts'][ $mid ][ $cat_slug ] ?? 0;
		$total_count += $current;

		$status_text  = $current . '/' . $quota;
		$status_color = '';

		if ( 0 === $current ) {
			$status_color = '#999';
		} elseif ( $current >= $quota ) {
			$status_color = '#46b450'; // Green - complete.
		} else {
			$status_color = '#ffb900'; // Yellow - partial.
		}

		echo '<td style="color: ' . esc_attr( $status_color ) . '; font-weight: bold;">';
		echo esc_html( $status_text );
		echo '</td>';
	}

	echo '<td><strong>' . esc_html( (string) $total_count ) . '</strong></td>';

	// Upload link tracking.
	$upload_opened = isset( $data['upload_tracking'][ $mid ] ) && ! empty( $data['upload_tracking'][ $mid ]->first_opened_at );
	echo '<td style="text-align: center;">';
	if ( $upload_opened ) {
		echo '<span style="color: #46b450; font-size: 18px;" title="' . esc_attr__( 'Link opened', 'photo-competition-manager' ) . '">&#10003;</span>';
	} else {
		echo '<span style="color: #999; font-size: 18px;" title="' . esc_attr__( 'Not opened', 'photo-competition-manager' ) . '">&#10005;</span>';
	}
	echo '</td>';

	// Voting link tracking.
	$voting_opened = isset( $data['voting_tracking'][ $mid ] ) && ! empty( $data['voting_tracking'][ $mid ]->first_opened_at );
	echo '<td style="text-align: center;">';
	if ( $voting_opened ) {
		echo '<span style="color: #46b450; font-size: 18px;" title="' . esc_attr__( 'Link opened', 'photo-competition-manager' ) . '">&#10003;</span>';
	} else {
		echo '<span style="color: #999; font-size: 18px;" title="' . esc_attr__( 'Not opened', 'photo-competition-manager' ) . '">&#10005;</span>';
	}
	echo '</td>';

	echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</div>';
