<?php
/**
 * Entrants panel partial for the competition edit screen: who may enter, and
 * the selected entrants.
 *
 * Reads $data['competition_id'] (int), $data['entry_mode'] ('all' or
 * 'selected'), $data['open_label'] (string): the label for 'all',
 * $data['can_change'] (bool): false once voting has started,
 * $data['uploads_open'] (bool), $data['entrants'] (objects with id, name,
 * email, active and has_entries), $data['member_groups'] (arrays with label,
 * '' for an unnamed group, and members: objects with id, name and email) for
 * the Add entrants picker, and $data['grade_options'] (array<string,string>
 * of grade slug => label).
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

// One nonce field per form, without wp_nonce_field()'s id, which would repeat on the page.
$form_start = '<form method="post">'
	. '<input type="hidden" name="photo_competition_nonce" value="' . esc_attr( wp_create_nonce( 'photo_competition_entrants_' . $data['competition_id'] ) ) . '" />'
	. '<input type="hidden" name="competition_id" value="' . esc_attr( $data['competition_id'] ) . '" />';

$modes = array(
	'all'      => $data['open_label'],
	'selected' => __( 'Selected entrants only', 'photo-competition-manager' ),
);

echo '<div class="card" style="max-width: 720px; padding: 16px;">';
echo '<h3>' . esc_html__( 'Who may enter', 'photo-competition-manager' ) . '</h3>';

if ( $data['can_change'] ) {
	echo $form_start; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_attr() above.
	echo '<input type="hidden" name="photo_competition_action" value="set_entry_mode" />';

	foreach ( $modes as $mode_value => $label ) {
		echo '<p><label>';
		echo '<input type="radio" name="entry_mode" value="' . esc_attr( $mode_value ) . '"' . checked( $data['entry_mode'], $mode_value, false ) . ' />';
		echo ' ' . esc_html( $label );
		echo '</label></p>';
	}

	submit_button( __( 'Save Entry Setting', 'photo-competition-manager' ), 'secondary', 'save_entry_mode' );
	echo '</form>';
} else {
	echo '<p><strong>' . esc_html( $modes[ $data['entry_mode'] ] ) . '</strong></p>';
	echo '<p class="description">' . esc_html__( 'Voting has started, so who enters this competition can\'t change.', 'photo-competition-manager' ) . '</p>';
}

echo '</div>';

if ( 'selected' !== $data['entry_mode'] ) {
	return;
}

echo '<div class="card" style="max-width: 720px; padding: 16px;">';
echo '<h3>' . esc_html__( 'Entrants', 'photo-competition-manager' ) . '</h3>';

if ( empty( $data['entrants'] ) ) {
	echo '<p>' . esc_html__( 'There are no entrants yet.', 'photo-competition-manager' ) . '</p>';
} else {
	echo '<table class="widefat striped">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Member', 'photo-competition-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Email', 'photo-competition-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Actions', 'photo-competition-manager' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $data['entrants'] as $entrant ) {
		echo '<tr>';
		echo '<td>' . esc_html( $entrant->name );
		if ( ! $entrant->active ) {
			echo ' <em>' . esc_html__( '(inactive)', 'photo-competition-manager' ) . '</em>';
		}
		echo '</td>';
		echo '<td>' . esc_html( $entrant->email ) . '</td>';
		echo '<td>';

		if ( $data['uploads_open'] && $entrant->active ) {
			echo $form_start; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_attr() above.
			echo '<input type="hidden" name="photo_competition_action" value="send_entrant_upload_link" />';
			echo '<input type="hidden" name="member_id" value="' . esc_attr( $entrant->id ) . '" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Send upload link', 'photo-competition-manager' ) . '</button>';
			echo '</form> ';
		}

		if ( $data['can_change'] && $entrant->has_entries ) {
			echo '<span class="description">' . esc_html__( 'Has entries', 'photo-competition-manager' ) . '</span>';
		} elseif ( $data['can_change'] ) {
			echo $form_start; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_attr() above.
			echo '<input type="hidden" name="photo_competition_action" value="remove_entrant" />';
			echo '<input type="hidden" name="member_id" value="' . esc_attr( $entrant->id ) . '" />';
			echo '<button type="submit" class="button button-link-delete">' . esc_html__( 'Remove', 'photo-competition-manager' ) . '</button>';
			echo '</form>';
		}

		echo '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
}

if ( $data['can_change'] ) {
	echo '<h4>' . esc_html__( 'Add entrants', 'photo-competition-manager' ) . '</h4>';

	if ( empty( $data['member_groups'] ) ) {
		echo '<p>' . esc_html__( 'Every active member is already an entrant.', 'photo-competition-manager' ) . '</p>';
	} else {
		echo $form_start; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_attr() above.
		echo '<input type="hidden" name="photo_competition_action" value="add_entrants" />';
		echo '<p><select id="entrant_member_ids" name="member_ids[]" multiple size="8" style="min-width: 320px;">';

		foreach ( $data['member_groups'] as $group ) {
			if ( '' !== $group['label'] ) {
				echo '<optgroup label="' . esc_attr( $group['label'] ) . '">';
			}
			foreach ( $group['members'] as $member ) {
				echo '<option value="' . esc_attr( $member->id ) . '">' . esc_html( $member->name . ' (' . $member->email . ')' ) . '</option>';
			}
			if ( '' !== $group['label'] ) {
				echo '</optgroup>';
			}
		}

		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Hold Ctrl (Cmd on a Mac) to choose several.', 'photo-competition-manager' ) . '</p>';
		submit_button( __( 'Add Entrants', 'photo-competition-manager' ), 'secondary', 'add_entrants' );
		echo '</form>';
	}

	echo '<h4>' . esc_html__( 'Add a new member and enter them', 'photo-competition-manager' ) . '</h4>';
	echo $form_start; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_attr() above.
	echo '<input type="hidden" name="photo_competition_action" value="add_new_entrant" />';
	echo '<p><label for="entrant_member_name">' . esc_html__( 'Name', 'photo-competition-manager' ) . '</label><br />';
	echo '<input type="text" id="entrant_member_name" name="member_name" class="regular-text" required /></p>';
	echo '<p><label for="entrant_member_email">' . esc_html__( 'Email', 'photo-competition-manager' ) . '</label><br />';
	echo '<input type="email" id="entrant_member_email" name="member_email" class="regular-text" required /></p>';
	echo '<p><label for="entrant_member_grade">' . esc_html__( 'Grade', 'photo-competition-manager' ) . '</label><br />';
	echo '<select id="entrant_member_grade" name="member_grade" required>';
	echo '<option value="">' . esc_html__( 'Select grade', 'photo-competition-manager' ) . '</option>';
	foreach ( $data['grade_options'] as $grade_slug => $grade_label ) {
		echo '<option value="' . esc_attr( $grade_slug ) . '">' . esc_html( $grade_label ) . '</option>';
	}
	echo '</select></p>';
	submit_button( __( 'Add and Enter', 'photo-competition-manager' ), 'secondary', 'add_new_entrant' );
	echo '</form>';
}

echo '</div>';
