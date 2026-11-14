<?php
/**
 * Judges screen partial: the list with an add form, the edit form, or the delete confirmation.
 *
 * Reads $data['mode'] (list, edit or delete), $data['judge'] (object with id,
 * name, email, or null when not found; edit and delete only), $data['rows']
 * (list only; objects with name, email, edit_url, delete_url) and
 * $data['judges_url'] (string).
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

$back_link = sprintf(
	'<p><a href="%s">%s</a></p>',
	esc_url( $data['judges_url'] ),
	esc_html__( 'Back to judges', 'photo-competition-manager' )
);

if ( 'list' !== $data['mode'] && ! $data['judge'] ) {
	echo '<p>' . esc_html__( 'Judge not found.', 'photo-competition-manager' ) . '</p>';
	echo wp_kses_post( $back_link );
	return;
}

if ( 'delete' === $data['mode'] ) {
	echo '<form method="post" class="card" style="max-width: 520px; margin-bottom: 24px; padding: 16px;">';
	echo '<h2>' . esc_html__( 'Delete Judge', 'photo-competition-manager' ) . '</h2>';
	wp_nonce_field( 'photo_competition_delete_judge_' . (int) $data['judge']->id, 'photo_competition_judge_nonce' );
	echo '<input type="hidden" name="photo_competition_action" value="delete_judge" />';
	echo '<input type="hidden" name="judge_id" value="' . esc_attr( $data['judge']->id ) . '" />';
	echo '<p>' . esc_html(
		sprintf(
			/* translators: 1: judge name, 2: judge email */
			__( 'Delete %1$s (%2$s) and their links to every competition? Their scores stay in every competition they judged.', 'photo-competition-manager' ),
			$data['judge']->name,
			$data['judge']->email
		)
	) . '</p>';
	submit_button( __( 'Delete Judge', 'photo-competition-manager' ), 'delete' );
	echo '</form>';
	echo wp_kses_post( $back_link );
	return;
}

if ( 'edit' === $data['mode'] ) {
	echo '<form method="post" class="card" style="max-width: 520px; margin-bottom: 24px; padding: 16px;">';
	echo '<h2>' . esc_html__( 'Edit Judge', 'photo-competition-manager' ) . '</h2>';
	wp_nonce_field( 'photo_competition_judge_update_' . (int) $data['judge']->id, 'photo_competition_judge_nonce' );
	echo '<input type="hidden" name="photo_competition_action" value="update_judge" />';
	echo '<input type="hidden" name="judge_id" value="' . esc_attr( $data['judge']->id ) . '" />';
	echo '<p><label for="judge_name">' . esc_html__( 'Name', 'photo-competition-manager' ) . '</label><br />';
	echo '<input type="text" id="judge_name" name="judge_name" class="regular-text" required value="' . esc_attr( $data['judge']->name ) . '" /></p>';
	echo '<p><label for="judge_email">' . esc_html__( 'Email', 'photo-competition-manager' ) . '</label><br />';
	echo '<input type="email" id="judge_email" name="judge_email" class="regular-text" required value="' . esc_attr( $data['judge']->email ) . '" /></p>';
	submit_button( __( 'Update Judge', 'photo-competition-manager' ) );
	echo '</form>';
	echo wp_kses_post( $back_link );
	return;
}

if ( empty( $data['rows'] ) ) {
	echo '<p>' . esc_html__( 'No judges yet. Add the people who will score your judged competitions.', 'photo-competition-manager' ) . '</p>';
} else {
	echo '<table class="widefat striped">';
	echo '<thead><tr>';
	echo '<th>' . esc_html__( 'Name', 'photo-competition-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Email', 'photo-competition-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Actions', 'photo-competition-manager' ) . '</th>';
	echo '</tr></thead><tbody>';
	foreach ( $data['rows'] as $row ) {
		echo '<tr>';
		echo '<td>' . esc_html( $row->name ) . '</td>';
		echo '<td>' . esc_html( $row->email ) . '</td>';
		echo '<td>';
		printf( '<a href="%s">%s</a>', esc_url( $row->edit_url ), esc_html__( 'Edit', 'photo-competition-manager' ) );
		echo ' | ';
		printf( '<a href="%s">%s</a>', esc_url( $row->delete_url ), esc_html__( 'Delete', 'photo-competition-manager' ) );
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

echo '<form method="post" class="card" style="max-width: 520px; margin-top: 24px; padding: 16px;">';
echo '<h2>' . esc_html__( 'Add Judge', 'photo-competition-manager' ) . '</h2>';
wp_nonce_field( 'photo_competition_judge_create', 'photo_competition_judge_nonce' );
echo '<input type="hidden" name="photo_competition_action" value="create_judge" />';
echo '<p><label for="judge_name">' . esc_html__( 'Name', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="text" id="judge_name" name="judge_name" class="regular-text" required /></p>';
echo '<p><label for="judge_email">' . esc_html__( 'Email', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="email" id="judge_email" name="judge_email" class="regular-text" required /></p>';
submit_button( __( 'Add Judge', 'photo-competition-manager' ) );
echo '</form>';
