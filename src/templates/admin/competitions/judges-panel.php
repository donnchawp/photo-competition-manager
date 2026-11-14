<?php
/**
 * Judges panel partial for the competition edit screen, shown when judges score the competition.
 *
 * Reads $data['competition_id'] (int), $data['judges'] (objects with id, name,
 * email and assigned: every judge, whether assigned or not), $data['assigned']
 * (objects with id, name, email, link and sent_at: the assigned judges, with
 * their link and when it was last emailed, or '') and $data['judges_url']
 * (string): the Judges screen.
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

// One nonce field per form, without wp_nonce_field()'s id, which would repeat on the page.
$nonce_field = '<input type="hidden" name="photo_competition_nonce" value="' . esc_attr( wp_create_nonce( 'photo_competition_judges_' . $data['competition_id'] ) ) . '" />';

echo '<div class="card" id="photo-comp-judges" style="max-width: 720px; padding: 16px;">';
echo '<h3>' . esc_html__( 'Judges', 'photo-competition-manager' ) . '</h3>';

if ( empty( $data['judges'] ) ) {
	echo '<p>' . esc_html__( 'There are no judges yet.', 'photo-competition-manager' ) . ' ';
	echo '<a href="' . esc_url( $data['judges_url'] ) . '">' . esc_html__( 'Add judges', 'photo-competition-manager' ) . '</a></p>';
	echo '</div>';
	return;
}

echo '<form method="post">';
echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_attr() above.
echo '<input type="hidden" name="photo_competition_action" value="update_competition_judges" />';
echo '<input type="hidden" name="competition_id" value="' . esc_attr( $data['competition_id'] ) . '" />';
echo '<p class="description">' . esc_html__( 'Choose who judges this competition.', 'photo-competition-manager' ) . '</p>';

foreach ( $data['judges'] as $judge ) {
	echo '<p><label>';
	echo '<input type="checkbox" name="judge_ids[]" value="' . esc_attr( $judge->id ) . '"' . checked( $judge->assigned, true, false ) . ' />';
	echo ' ' . esc_html( $judge->name ) . ' (' . esc_html( $judge->email ) . ')';
	echo '</label></p>';
}

submit_button( __( 'Save Judges', 'photo-competition-manager' ), 'secondary', 'save_judges' );
echo '</form>';

if ( ! empty( $data['assigned'] ) ) {
	echo '<table class="widefat striped">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Judge', 'photo-competition-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Link', 'photo-competition-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Last sent', 'photo-competition-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Actions', 'photo-competition-manager' ) . '</th>';
	echo '</tr></thead><tbody>';

	foreach ( $data['assigned'] as $judge ) {
		echo '<tr>';
		echo '<td>' . esc_html( $judge->name ) . '</td>';
		echo '<td><input type="text" readonly class="large-text" onclick="this.select()" value="' . esc_attr( $judge->link ) . '" /></td>';
		echo '<td>' . esc_html( '' !== $judge->sent_at ? $judge->sent_at : __( 'Never', 'photo-competition-manager' ) ) . '</td>';
		echo '<td>';
		echo '<form method="post">';
		echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_attr() above.
		echo '<input type="hidden" name="photo_competition_action" value="send_judge_link" />';
		echo '<input type="hidden" name="competition_id" value="' . esc_attr( $data['competition_id'] ) . '" />';
		echo '<input type="hidden" name="judge_id" value="' . esc_attr( $judge->id ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Send link', 'photo-competition-manager' ) . '</button>';
		echo '</form>';
		echo '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';

	echo '<form method="post">';
	echo $nonce_field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built with esc_attr() above.
	echo '<input type="hidden" name="photo_competition_action" value="send_all_judge_links" />';
	echo '<input type="hidden" name="competition_id" value="' . esc_attr( $data['competition_id'] ) . '" />';
	submit_button( __( 'Send link to all judges', 'photo-competition-manager' ), 'primary', 'send_all_judges' );
	echo '</form>';
}

echo '</div>';
