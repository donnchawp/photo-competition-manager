<?php
/**
 * "Members without grades" error notice partial for the admin voting controls page.
 *
 * Closes both the notice wrapper and the outer `.wrap` div opened in
 * Voting_Controller::render(), since rendering stops immediately after.
 *
 * @package PhotoCompetitionManager
 *
 * @var array $data {
 *     @type array $members_without_grades List of members missing grades, each with
 *                                          'name', 'email', and optional 'image_count'.
 * }
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="notice notice-error">';
echo '<p><strong>' . esc_html__( 'ERROR: Some members have submitted images but do not have grades assigned!', 'photo-competition-manager' ) . '</strong></p>';
echo '<p>' . esc_html__( 'The following members need grades assigned before voting can proceed. Results will not display correctly without grades.', 'photo-competition-manager' ) . '</p>';
echo '<ul style="list-style: disc; margin-left: 20px;">';
foreach ( $data['members_without_grades'] as $member_info ) {
	echo '<li>';
	echo esc_html( $member_info['name'] ) . ' (' . esc_html( $member_info['email'] ) . ')';
	$image_count = isset( $member_info['image_count'] ) ? (int) $member_info['image_count'] : 0;
	$image_text  = sprintf(
		/* translators: %s: number of images */
		_n( '%s image', '%s images', $image_count, 'photo-competition-manager' ),
		number_format_i18n( $image_count )
	);
	echo ' - ' . esc_html( $image_text );
	echo '</li>';
}
echo '</ul>';
echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager-members' ) ) . '" class="button button-primary">' . esc_html__( 'Go to Members Page to Assign Grades', 'photo-competition-manager' ) . '</a></p>';
echo '</div>';
echo '</div>'; // Close wrap div.
