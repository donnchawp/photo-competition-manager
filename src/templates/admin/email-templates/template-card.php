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

echo '<h2 style="margin-top: 0;">' . esc_html( $data['template']['name'] ) . '</h2>';
echo '<p class="description">' . esc_html( $data['template']['description'] ) . '</p>';

// Enabled toggle.
echo '<p>';
echo '<label>';
echo '<input type="checkbox" name="templates[' . esc_attr( $data['template_key'] ) . '][enabled]" value="1" ' . checked( $data['template']['enabled'], true, false ) . ' />';
echo ' <strong>' . esc_html__( 'Enable this email notification', 'photo-competition-manager' ) . '</strong>';
echo '</label>';
echo '</p>';

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

echo '<p class="description">' . esc_html__( 'Available merge tags:', 'photo-competition-manager' ) . ' ';
$merge_tags_html = array_map(
	function ( $tag ) {
		return '<code>' . esc_html( $tag ) . '</code>';
	},
	$data['template']['merge_tags']
);
echo wp_kses_post( implode( ', ', $merge_tags_html ) );
echo '</p>';
echo '</td>';
echo '</tr>';

echo '</tbody></table>';

echo '</div>';
