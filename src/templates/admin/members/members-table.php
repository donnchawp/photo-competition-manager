<?php
/**
 * Members list table partial (with bulk-actions form) for the admin members page.
 *
 * Reads $data['show_count'] (bool), $data['filtered_count'] (int),
 * $data['total_count'] (int), $data['grade_options'] (array<string,string> of
 * grade slug => label), and $data['rows']: array of row objects, each
 * pre-formatted by the controller with: member_id, name, email, grade_label,
 * status_label, joined, edit_link, delete_url, show_send_email, send_url,
 * upload_url.
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

if ( $data['show_count'] ) {
	echo '<p class="description" style="margin-bottom: 10px;">' . esc_html(
		sprintf(
			/* translators: 1: filtered count, 2: total count */
			__( 'Showing %1$d of %2$d members', 'photo-competition-manager' ),
			$data['filtered_count'],
			$data['total_count']
		)
	) . '</p>';
}

// Bulk actions form.
echo '<form method="post" id="bulk-members-form">';
wp_nonce_field( 'photo_competition_bulk_members', '_wpnonce' );

echo '<div class="tablenav top">';
echo '<div class="alignleft actions bulkactions">';
echo '<select name="action" id="bulk-action-selector-top">';
echo '<option value="-1">' . esc_html__( 'Bulk Actions', 'photo-competition-manager' ) . '</option>';
echo '<option value="bulk_activate">' . esc_html__( 'Activate', 'photo-competition-manager' ) . '</option>';
echo '<option value="bulk_deactivate">' . esc_html__( 'Deactivate', 'photo-competition-manager' ) . '</option>';
echo '<option value="bulk_update_grade">' . esc_html__( 'Update Grade', 'photo-competition-manager' ) . '</option>';
echo '</select>';

echo ' <select name="bulk_grade" id="bulk-grade-selector" style="display:none;">';
echo '<option value="">' . esc_html__( 'Select Grade...', 'photo-competition-manager' ) . '</option>';
foreach ( $data['grade_options'] as $grade_value => $grade_label ) {
	echo '<option value="' . esc_attr( $grade_value ) . '">' . esc_html( $grade_label ) . '</option>';
}
echo '</select>';

echo ' <button type="submit" class="button action">' . esc_html__( 'Apply', 'photo-competition-manager' ) . '</button>';
echo '</div>';
echo '</div>';

echo '<table class="widefat striped">';
echo '<thead><tr>';
echo '<td class="check-column"><input type="checkbox" id="cb-select-all-1" /></td>';
echo '<th>' . esc_html__( 'Name', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Email', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Grade', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Status', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Joined', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Actions', 'photo-competition-manager' ) . '</th>';
echo '</tr></thead>';
echo '<tbody>';

foreach ( $data['rows'] as $member_row ) {
	echo '<tr>';
	echo '<th scope="row" class="check-column"><input type="checkbox" name="member_ids[]" value="' . esc_attr( $member_row->member_id ) . '" /></th>';
	echo '<td>' . esc_html( $member_row->name ) . '</td>';
	echo '<td>' . esc_html( $member_row->email ) . '</td>';
	echo '<td>' . esc_html( $member_row->grade_label ) . '</td>';
	echo '<td>' . esc_html( $member_row->status_label ) . '</td>';
	echo '<td>' . esc_html( $member_row->joined ) . '</td>';

	$actions = array(
		sprintf( '<a href="%s">%s</a>', esc_url( $member_row->edit_link ), esc_html__( 'Edit', 'photo-competition-manager' ) ),
		sprintf(
			'<a href="%s" class="delete-member-link" data-member-name="%s">%s</a>',
			esc_url( $member_row->delete_url ),
			esc_attr( $member_row->name ),
			esc_html__( 'Delete', 'photo-competition-manager' )
		),
	);

	// Add "Send Upload Email" if we have an open competition and active member with email.
	if ( $member_row->show_send_email ) {
		$actions[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $member_row->send_url ),
			esc_html__( 'Send Upload Email', 'photo-competition-manager' )
		);

		// Add upload page link for copying/sharing.
		if ( ! empty( $member_row->upload_url ) ) {
			$actions[] = sprintf(
				'<a href="%s" target="_blank" title="%s">%s</a>',
				esc_url( $member_row->upload_url ),
				esc_attr__( 'Copy this link to share with the member', 'photo-competition-manager' ),
				esc_html__( 'Upload Link', 'photo-competition-manager' )
			);
		}
	} else {
		$actions[] = '<span class="button button-small" style="opacity:.5;cursor:not-allowed;" title="' . esc_attr__( 'Requires an active competition and active member with email', 'photo-competition-manager' ) . '">' . esc_html__( 'Send Upload Email', 'photo-competition-manager' ) . '</span>';
	}

	echo '<td>' . wp_kses_post( implode( ' | ', $actions ) ) . '</td>';
	echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</form>';
