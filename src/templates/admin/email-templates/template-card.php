<?php
/**
 * Single email template card partial for the admin email templates page.
 *
 * Reads $data keys: template_key, template.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="card photo-comp-template-card" style="margin-bottom: 20px; padding: 20px; max-width: none;">';

echo '<h2 style="margin-top: 0;">' . esc_html( $data['template']['name'] );
if ( $data['template']['edited'] ) {
	echo ' <span class="photo-comp-template-edited">' . esc_html__( 'Edited', 'photo-competition-manager' ) . '</span>';
}
echo '</h2>';
echo '<p class="description">' . esc_html( $data['template']['description'] ) . '</p>';

// Only a notification can be switched off.
if ( $data['template']['notification'] ) {
	echo '<p>';
	echo '<label>';
	echo '<input type="checkbox" name="templates[' . esc_attr( $data['template_key'] ) . '][enabled]" value="1" ' . checked( $data['template']['enabled'], true, false ) . ' />';
	echo ' <strong>' . esc_html__( 'Enable this email notification', 'photo-competition-manager' ) . '</strong>';
	echo '</label>';
	echo '</p>';
}

// Subject field.
echo '<table class="form-table"><tbody>';
echo '<tr>';
echo '<th scope="row"><label for="template-' . esc_attr( $data['template_key'] ) . '-subject">' . esc_html__( 'Subject Line', 'photo-competition-manager' ) . '</label></th>';
echo '<td>';
echo '<input type="text" id="template-' . esc_attr( $data['template_key'] ) . '-subject" name="templates[' . esc_attr( $data['template_key'] ) . '][subject]" value="' . esc_attr( $data['template']['subject'] ) . '" class="large-text" />';
echo '</td>';
echo '</tr>';

// Body field.
echo '<tr>';
echo '<th scope="row"><label for="template-' . esc_attr( $data['template_key'] ) . '-body">' . esc_html__( 'Email Body', 'photo-competition-manager' ) . '</label></th>';
echo '<td>';

wp_editor(
	$data['template']['body'],
	'template_' . $data['template_key'] . '_body',
	array(
		'textarea_name' => 'templates[' . $data['template_key'] . '][body]',
		'textarea_rows' => 12,
		'media_buttons' => false,
		'teeny'         => true,
	)
);

echo '<p class="description">' . esc_html__( 'Available merge tags:', 'photo-competition-manager' ) . '</p>';
echo '<ul class="description">';
foreach ( $data['template']['merge_tags'] as $merge_tag => $tag_description ) {
	echo '<li><code>' . esc_html( $merge_tag ) . '</code> ' . esc_html( $tag_description ) . '</li>';
}
echo '</ul>';
echo '</td>';
echo '</tr>';

echo '</tbody></table>';

// Fills the fields in the browser; nothing is saved until the form is.
echo '<p>';
echo '<button type="button" class="button photo-comp-restore-default"'
	. ' data-subject-field="template-' . esc_attr( $data['template_key'] ) . '-subject"'
	. ' data-body-field="template_' . esc_attr( $data['template_key'] ) . '_body"'
	. ' data-default-subject="' . esc_attr( $data['template']['default_subject'] ) . '"'
	. ' data-default-body="' . esc_attr( $data['template']['default_body'] ) . '">'
	. esc_html__( 'Restore default', 'photo-competition-manager' ) . '</button>';
echo ' <span class="description">' . esc_html__( 'Fills in the default subject and body. Save the email templates to keep them.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '</div>';
