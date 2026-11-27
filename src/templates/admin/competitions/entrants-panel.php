<?php
/**
 * Entrants panel partial for the competition edit screen: who may enter, and
 * the selected entrants.
 *
 * Reads $data['competition_id'] (int), $data['entry_mode'] ('all' or
 * 'selected'), $data['open_label'] (string): the label for 'all',
 * $data['can_change'] (bool): false once voting has started or the competition
 * has closed, $data['locked_message'] (string): why, or '' while it can change,
 * $data['uploads_open'] (bool), $data['entrants'] (objects with id, name,
 * email, active, has_entries and paid), $data['has_fee'] (bool): whether the
 * competition charges an entry fee, $data['entry_fee'] (string) and
 * $data['instructions'] (string): the fee and payment instructions as saved,
 * $data['addable'] (objects with id, name and
 * email): the type's members who aren't entrants yet, for the Add entrants picker, $data['grade_options'] (array<string,string>
 * of grade slug => label), $data['interclub'] (bool): whether the entrants are
 * clubs, and $data['type_name'] (string): the competition's type, or '' when
 * the club has only one.
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
	echo '<p class="description">' . esc_html( $data['locked_message'] ) . '</p>';
}

echo '</div>';

if ( 'selected' !== $data['entry_mode'] ) {
	return;
}

// The fee can change at any time, since payment is often checked afterwards.
echo '<div class="card" style="max-width: 720px; padding: 16px;">';
echo '<h3>' . esc_html__( 'Entry fee', 'photo-competition-manager' ) . '</h3>';
echo $form_start; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_attr() above.
echo '<input type="hidden" name="photo_competition_action" value="set_entry_fee" />';
echo '<p><label for="entry_fee">' . esc_html__( 'Fee', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="entry_fee" name="entry_fee" class="regular-text" maxlength="191" value="' . esc_attr( $data['entry_fee'] ) . '" /></p>';
echo '<p class="description">' . esc_html__( 'Such as €20. Leave it blank for no fee. Each entrant pays once, whether a member or a club.', 'photo-competition-manager' ) . '</p>';
echo '<p><label for="payment_instructions">' . esc_html__( 'How to pay', 'photo-competition-manager' ) . '</label><br />';
echo '<textarea id="payment_instructions" name="payment_instructions" class="large-text" rows="3">' . esc_textarea( $data['instructions'] ) . '</textarea></p>';
echo '<p class="description">' . esc_html__( 'The plugin doesn\'t take payment. Once you\'ve checked someone has paid, mark them paid below. Entrants you add start as paid.', 'photo-competition-manager' ) . '</p>';
submit_button( __( 'Save Entry Fee', 'photo-competition-manager' ), 'secondary', 'save_entry_fee' );
echo '</form>';
echo '</div>';

echo '<div class="card" style="max-width: 720px; padding: 16px;">';
echo '<h3>' . esc_html__( 'Entrants', 'photo-competition-manager' ) . '</h3>';

if ( empty( $data['entrants'] ) ) {
	echo '<p>' . esc_html__( 'There are no entrants yet.', 'photo-competition-manager' ) . '</p>';
} else {
	echo '<table class="widefat striped">';
	echo '<thead><tr>';
	echo '<th>' . ( $data['interclub'] ? esc_html__( 'Club', 'photo-competition-manager' ) : esc_html__( 'Member', 'photo-competition-manager' ) ) . '</th>';
	echo '<th>' . esc_html__( 'Email', 'photo-competition-manager' ) . '</th>';
	if ( $data['has_fee'] ) {
		echo '<th>' . esc_html__( 'Fee', 'photo-competition-manager' ) . '</th>';
	}
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

		if ( $data['has_fee'] ) {
			echo '<td>';
			echo $entrant->paid ? esc_html__( 'Paid', 'photo-competition-manager' ) : '<strong>' . esc_html__( 'Unpaid', 'photo-competition-manager' ) . '</strong>';
			echo ' ' . $form_start; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_attr() above.
			echo '<input type="hidden" name="photo_competition_action" value="set_entrant_paid" />';
			echo '<input type="hidden" name="member_id" value="' . esc_attr( $entrant->id ) . '" />';
			echo '<input type="hidden" name="paid" value="' . ( $entrant->paid ? '0' : '1' ) . '" />';
			echo '<button type="submit" class="button-link" style="white-space: nowrap;">' . ( $entrant->paid ? esc_html__( 'Mark unpaid', 'photo-competition-manager' ) : esc_html__( 'Mark paid', 'photo-competition-manager' ) ) . '</button>';
			echo '</form>';
			echo '</td>';
		}

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
	// Adding an entrant emails their upload link only while the competition takes uploads.
	if ( $data['uploads_open'] ) {
		$upload_email_note = $data['interclub']
			? __( 'Each club you add is emailed its upload link straight away.', 'photo-competition-manager' )
			: __( 'Each member you add is emailed their upload link straight away.', 'photo-competition-manager' );
	} else {
		$upload_email_note = __( 'Uploads aren\'t open, so adding entrants sends no email. Once uploads open, use Send Upload Emails on the Competitions screen to send their upload links.', 'photo-competition-manager' );
	}
	echo '<p class="description">' . esc_html( $upload_email_note ) . '</p>';

	echo '<h4>' . ( $data['interclub'] ? esc_html__( 'Add clubs', 'photo-competition-manager' ) : esc_html__( 'Add entrants', 'photo-competition-manager' ) ) . '</h4>';

	if ( empty( $data['addable'] ) ) {
		echo '<p>' . ( $data['interclub'] ? esc_html__( 'Every club in this competition\'s type is already an entrant.', 'photo-competition-manager' ) : esc_html__( 'Every active member in this competition\'s type is already an entrant.', 'photo-competition-manager' ) ) . '</p>';
	} else {
		echo $form_start; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_attr() above.
		echo '<input type="hidden" name="photo_competition_action" value="add_entrants" />';
		echo '<p><select id="entrant_member_ids" name="member_ids[]" multiple size="8" style="min-width: 320px;">';

		foreach ( $data['addable'] as $member ) {
			echo '<option value="' . esc_attr( $member->id ) . '">' . esc_html( $member->name . ' (' . $member->email . ')' ) . '</option>';
		}

		echo '</select></p>';
		echo '<p class="description">' . esc_html__( 'Hold Ctrl (Cmd on a Mac) to choose several.', 'photo-competition-manager' ) . '</p>';
		submit_button( $data['interclub'] ? __( 'Add Clubs', 'photo-competition-manager' ) : __( 'Add Entrants', 'photo-competition-manager' ), 'secondary', 'add_entrants' );
		echo '</form>';
	}

	echo '<h4>' . ( $data['interclub'] ? esc_html__( 'Add a new club and enter it', 'photo-competition-manager' ) : esc_html__( 'Add a new member and enter them', 'photo-competition-manager' ) ) . '</h4>';
	if ( '' !== $data['type_name'] ) {
		echo '<p class="description">' . esc_html(
			sprintf(
				$data['interclub']
					/* translators: %s: Competition type name */
					? __( 'The new club will be added to the %s type.', 'photo-competition-manager' )
					/* translators: %s: Competition type name */
					: __( 'The new member will be added to the %s type.', 'photo-competition-manager' ),
				$data['type_name']
			)
		) . '</p>';
	}
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
