<?php
/**
 * Add-member form partial for the admin members page.
 *
 * Reads $data['grade_options'] (array<string,string> of grade slug => label).
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<form method="post" class="card" style="max-width: 520px; margin-bottom: 24px; padding: 16px;">';
echo '<h2>' . esc_html__( 'Add Member', 'photo-competition-manager' ) . '</h2>';

wp_nonce_field( 'photo_competition_member_create', 'photo_competition_member_nonce' );

echo '<input type="hidden" name="photo_competition_action" value="create_member" />';

echo '<p>';
echo '<label for="member_name">' . esc_html__( 'Name', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="member_name" name="member_name" class="regular-text" required />';
echo '</p>';

echo '<p>';
echo '<label for="member_email">' . esc_html__( 'Email', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="email" id="member_email" name="member_email" class="regular-text" required />';
echo '</p>';

echo '<p>';
echo '<label for="member_grade">' . esc_html__( 'Grade', 'photo-competition-manager' ) . '</label><br />';
echo '<select id="member_grade" name="member_grade" class="regular-text" required>';
echo '<option value="">' . esc_html__( 'Select grade', 'photo-competition-manager' ) . '</option>';
foreach ( $data['grade_options'] as $grade_slug => $grade_label ) {
	echo '<option value="' . esc_attr( $grade_slug ) . '">' . esc_html( $grade_label ) . '</option>';
}
echo '</select>';
echo '</p>';

echo '<p>';
echo '<label>';
echo '<input type="checkbox" name="member_active" value="1" checked /> ';
echo esc_html__( 'Active', 'photo-competition-manager' );
echo '</label>';
echo '</p>';

echo '<p>';
echo '<label>';
echo '<input type="checkbox" name="member_committee" value="1" /> ';
echo esc_html__( 'Committee Member', 'photo-competition-manager' );
echo '</label>';
echo '</p>';

submit_button( __( 'Add Member', 'photo-competition-manager' ) );

echo '</form>';
