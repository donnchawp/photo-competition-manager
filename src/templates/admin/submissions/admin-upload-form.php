<?php
/**
 * Admin upload-on-behalf-of-member form partial for the admin submissions page.
 *
 * Reads $data['competition_id'] (int), $data['members'] (array of member
 * objects), $data['categories'] (array of category config arrays with
 * 'slug'/'label' keys).
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 20px;">';
echo '<h3 style="margin-top: 0;">' . esc_html__( 'Upload Image for Member', 'photo-competition-manager' ) . '</h3>';
echo '<p class="description">' . esc_html__( 'Upload an image on behalf of a member. This bypasses competition date and status restrictions.', 'photo-competition-manager' ) . '</p>';
echo '<form method="post" enctype="multipart/form-data" style="margin-top: 15px;">';
wp_nonce_field( 'photo_competition_admin_upload_' . $data['competition_id'], '_wpnonce' );
echo '<input type="hidden" name="action" value="admin_upload" />';
echo '<input type="hidden" name="competition_id" value="' . esc_attr( $data['competition_id'] ) . '" />';

echo '<table class="form-table"><tbody>';

// Member selection.
echo '<tr>';
echo '<th scope="row"><label for="admin-upload-member">' . esc_html__( 'Member', 'photo-competition-manager' ) . '</label></th>';
echo '<td>';
echo '<select name="member_id" id="admin-upload-member" required>';
echo '<option value="">' . esc_html__( 'Select a member...', 'photo-competition-manager' ) . '</option>';
foreach ( $data['members'] as $member ) {
	printf(
		'<option value="%1$d">%2$s</option>',
		(int) $member->id,
		esc_html( $member->name )
	);
}
echo '</select>';
echo '</td>';
echo '</tr>';

// Category selection.
echo '<tr>';
echo '<th scope="row"><label for="admin-upload-category">' . esc_html__( 'Category', 'photo-competition-manager' ) . '</label></th>';
echo '<td>';
echo '<select name="category" id="admin-upload-category" required>';
echo '<option value="">' . esc_html__( 'Select a category...', 'photo-competition-manager' ) . '</option>';
foreach ( $data['categories'] as $category_option ) {
	printf(
		'<option value="%1$s">%2$s</option>',
		esc_attr( $category_option['slug'] ),
		esc_html( $category_option['label'] )
	);
}
echo '</select>';
echo '</td>';
echo '</tr>';

// File upload.
echo '<tr>';
echo '<th scope="row"><label for="admin-upload-file">' . esc_html__( 'Image File', 'photo-competition-manager' ) . '</label></th>';
echo '<td>';
echo '<input type="file" name="image_file" id="admin-upload-file" accept="image/jpeg,image/jpg" required />';
echo '<p class="description">' . esc_html__( 'Select a JPEG image to upload.', 'photo-competition-manager' ) . '</p>';
echo '</td>';
echo '</tr>';

echo '</tbody></table>';

echo '<p class="submit">';
echo '<button type="submit" class="button button-primary">' . esc_html__( 'Upload Image', 'photo-competition-manager' ) . '</button>';
echo '</p>';

echo '</form>';
echo '</div>';
