<?php
/**
 * Competition-specific settings form partial for the competition edit screen.
 *
 * Reads $data['competition_id'] (int), $data['category_rows_html'] (string):
 * pre-rendered category row markup, one row per configured category.
 * $data['grade_rows_html'] (string): pre-rendered grade row markup, one row
 * per configured grade. $data['upload'] (array{max_file_size_mb: int,
 * max_width: int, max_height: int}): upload constraints. $data['auth_mode']
 * (string): 'password' or 'token'. $data['password_value'] (string):
 * plaintext password to prefill, or '' when unset/legacy-hashed.
 * $data['is_legacy_hash'] (bool): whether a legacy phpass-style password
 * hash is stored. $data['click_to_zoom'] (bool). $data['voting_ui_type']
 * (string): 'buttons' or 'dropdown'. $data['score_matrix_text'] (string):
 * comma-separated score matrix values. $data['progress_meter_type']
 * (string): 'bar', 'line', 'dots', or 'radial'. $data['urls']
 * (array{upload_page: string, voting_page: string, results_page?: string}).
 * $data['share_hash'] (string): results share hash, or '' when not
 * generated.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<form method="post" class="card" style="max-width: 720px; padding: 16px;">';
wp_nonce_field( 'photo_competition_update_settings_' . $data['competition_id'], 'photo_competition_nonce' );
echo '<input type="hidden" name="photo_competition_action" value="update_competition_settings" />';
echo '<input type="hidden" name="competition_id" value="' . esc_attr( $data['competition_id'] ) . '" />';

echo '<h3>' . esc_html__( 'Categories', 'photo-competition-manager' ) . '</h3>';
echo '<p class="description">' . esc_html__( 'Define competition categories and upload quotas. Members can upload up to the specified number of images per category.', 'photo-competition-manager' ) . '</p>';

echo '<div id="categories-container">';
echo $data['category_rows_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
echo '</div>';

echo '<p>';
echo '<button type="button" id="add-category" class="button">' . esc_html__( 'Add Category', 'photo-competition-manager' ) . '</button>';
echo '</p>';

echo '<h3>' . esc_html__( 'Grades', 'photo-competition-manager' ) . '</h3>';
echo '<p class="description">' . esc_html__( 'Define member grade levels for results grouping.', 'photo-competition-manager' ) . '</p>';

echo '<div id="grades-container">';
echo $data['grade_rows_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
echo '</div>';

echo '<p>';
echo '<button type="button" id="add-grade" class="button">' . esc_html__( 'Add Grade', 'photo-competition-manager' ) . '</button>';
echo '</p>';

echo '<h3>' . esc_html__( 'Upload Constraints', 'photo-competition-manager' ) . '</h3>';

echo '<p>';
echo '<label for="max_file_size_mb">' . esc_html__( 'Max File Size (MB)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="number" id="max_file_size_mb" name="max_file_size_mb" min="1" max="50" value="' . esc_attr( $data['upload']['max_file_size_mb'] ) . '" class="small-text" />';
echo '</p>';

echo '<p>';
echo '<label for="max_width">' . esc_html__( 'Max Width (pixels)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="number" id="max_width" name="max_width" min="800" max="5000" step="10" value="' . esc_attr( $data['upload']['max_width'] ) . '" class="small-text" />';
echo '</p>';

echo '<p>';
echo '<label for="max_height">' . esc_html__( 'Max Height (pixels)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="number" id="max_height" name="max_height" min="800" max="5000" step="10" value="' . esc_attr( $data['upload']['max_height'] ) . '" class="small-text" />';
echo '</p>';

echo '<h3>' . esc_html__( 'Voting Configuration', 'photo-competition-manager' ) . '</h3>';

echo '<p>';
echo '<label for="voting_auth_mode">' . esc_html__( 'Voting Authentication Mode', 'photo-competition-manager' ) . '</label><br />';
echo '<select id="voting_auth_mode" name="voting_auth_mode">';
echo '<option value="password"' . selected( $data['auth_mode'], 'password', false ) . '>' . esc_html__( 'Password-based (traditional)', 'photo-competition-manager' ) . '</option>';
echo '<option value="token"' . selected( $data['auth_mode'], 'token', false ) . '>' . esc_html__( 'Email Magic Links (anonymous)', 'photo-competition-manager' ) . '</option>';
echo '</select><br />';
echo '<span class="description">' . esc_html__( 'Choose how voters authenticate. Password mode allows voters to enter their name and optional password. Token mode sends secure one-time voting links via email for anonymous voting.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="voting_password">' . esc_html__( 'Voting Password (for password mode)', 'photo-competition-manager' ) . '</label><br />';

echo '<input type="text" id="voting_password" name="voting_password" value="' . esc_attr( $data['password_value'] ) . '" class="regular-text" />';

if ( $data['is_legacy_hash'] ) {
	echo '<br /><label>';
	echo '<input type="checkbox" id="voting_password_clear" name="voting_password_clear" value="1" />';
	echo ' ' . esc_html__( 'Remove password protection', 'photo-competition-manager' );
	echo '</label>';
	echo '<br /><span class="description">' . esc_html__( 'A password is currently set. Enter a new password to change it, check the box above to remove password protection, or leave both blank to keep the existing password. Passwords are not case-sensitive.', 'photo-competition-manager' ) . '</span>';
} else {
	echo '<br /><span class="description">' . esc_html__( 'Leave blank for no password. Passwords are case insensitive.', 'photo-competition-manager' ) . '</span>';
}
echo '</p>';

echo '<p>';
echo '<label for="click_image_to_zoom">';
echo '<input type="checkbox" id="click_image_to_zoom" name="click_image_to_zoom" value="1"' . checked( $data['click_to_zoom'], true, false ) . ' />';
echo ' ' . esc_html__( 'Click image to zoom on voting form', 'photo-competition-manager' );
echo '</label><br />';
echo '<span class="description">' . esc_html__( 'When enabled, images in the voting form can be clicked to open full-size in a new tab. When disabled, images are not clickable to prevent accidental navigation. Recommended: off for touch devices.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="voting_ui_type">' . esc_html__( 'Voting UI Type', 'photo-competition-manager' ) . '</label><br />';
echo '<select id="voting_ui_type" name="voting_ui_type">';
echo '<option value="buttons"' . selected( $data['voting_ui_type'], 'buttons', false ) . '>' . esc_html__( 'Horizontal Score Buttons', 'photo-competition-manager' ) . '</option>';
echo '<option value="dropdown"' . selected( $data['voting_ui_type'], 'dropdown', false ) . '>' . esc_html__( 'Dropdown', 'photo-competition-manager' ) . '</option>';
echo '</select><br />';
echo '<span class="description">' . esc_html__( 'Pick the layout voters use in this competition. Leave set to buttons for the quickest scoring experience.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="score_matrix">' . esc_html__( 'Score Matrix (comma-separated)', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="score_matrix" name="score_matrix" value="' . esc_attr( $data['score_matrix_text'] ) . '" class="regular-text" />';
echo '<span class="description">' . esc_html__( 'E.g., 9, 8, 7, 6, 5', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<h3>' . esc_html__( 'Slideshow', 'photo-competition-manager' ) . '</h3>';

echo '<p>';
echo '<label>' . esc_html__( 'Progress Meter Style', 'photo-competition-manager' ) . '</label>';
echo '</p>';

$meter_types = array(
	'bar'    => __( 'Bar', 'photo-competition-manager' ),
	'line'   => __( 'Thin Line', 'photo-competition-manager' ),
	'dots'   => __( 'Dots', 'photo-competition-manager' ),
	'radial' => __( 'Radial', 'photo-competition-manager' ),
);

echo '<div class="progress-meter-selector" style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 20px;">';

foreach ( $meter_types as $meter_type_slug => $label ) {
	$is_active = ( $meter_type_slug === $data['progress_meter_type'] ) ? ' active' : '';
	echo '<label class="progress-meter-card' . esc_attr( $is_active ) . '" style="cursor: pointer; border: 2px solid ' . ( $is_active ? '#0073aa' : '#ddd' ) . '; border-radius: 8px; padding: 12px; text-align: center; background: #1a1a1a; min-width: 140px; transition: border-color 0.2s;">';
	echo '<input type="radio" name="progress_meter_type" value="' . esc_attr( $meter_type_slug ) . '"' . checked( $data['progress_meter_type'], $meter_type_slug, false ) . ' style="display: none;" />';
	echo '<div class="meter-preview" data-meter-type="' . esc_attr( $meter_type_slug ) . '" style="height: 50px; position: relative; margin-bottom: 8px; overflow: hidden; border-radius: 4px;"></div>';
	echo '<span style="color: #666; font-size: 13px; font-weight: 600;">' . esc_html( $label ) . '</span>';
	echo '</label>';
}

echo '</div>';
echo '<span class="description">' . esc_html__( 'Choose the progress indicator style shown during the slideshow.', 'photo-competition-manager' ) . '</span>';

echo '<h3>' . esc_html__( 'URLs', 'photo-competition-manager' ) . '</h3>';

echo '<p>';
echo '<label for="upload_page_url">' . esc_html__( 'Upload Page URL', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="url" id="upload_page_url" name="upload_page_url" value="' . esc_attr( $data['urls']['upload_page'] ) . '" class="regular-text" placeholder="https://example.com/upload" />';
echo '<br /><span class="description">' . esc_html__( 'The page where members can upload their images. This URL will be included in email notifications with the member\'s upload token.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="voting_page_url">' . esc_html__( 'Voting Page URL', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="url" id="voting_page_url" name="voting_page_url" value="' . esc_attr( $data['urls']['voting_page'] ) . '" class="regular-text" placeholder="https://example.com/vote" />';
echo '<br /><span class="description">' . esc_html__( 'The page where members can vote on images. This URL will be included in voting notification emails.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

if ( ! empty( $data['share_hash'] ) ) {
	echo '<p>';
	echo '<label>' . esc_html__( 'Results Share Hash', 'photo-competition-manager' ) . '</label><br />';
	echo '<code>' . esc_html( $data['share_hash'] ) . '</code>';

	$results_page_url = $data['urls']['results_page'] ?? '';
	if ( ! empty( $results_page_url ) ) {
		$share_url = add_query_arg( 'share', $data['share_hash'], $results_page_url );
		echo '<br /><span class="description">' . esc_html__( 'Share link:', 'photo-competition-manager' ) . ' <a href="' . esc_url( $share_url ) . '" target="_blank">' . esc_html( $share_url ) . '</a></span>';
	}
	echo '</p>';
}

submit_button( __( 'Save Settings', 'photo-competition-manager' ) );

echo '</form>';
