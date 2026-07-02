<?php
/**
 * Logs table partial for the admin logs page.
 *
 * Reads $data['logs']: array of row objects, each pre-formatted by the
 * controller with: id, formatted_datetime, category_icon, category_label,
 * description, actor_name, competition_title, metadata.
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<table class="wp-list-table widefat fixed striped">';
echo '<thead>';
echo '<tr>';
echo '<th style="width: 150px;">' . esc_html__( 'Date/Time', 'photo-competition-manager' ) . '</th>';
echo '<th style="width: 100px;">' . esc_html__( 'Category', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Description', 'photo-competition-manager' ) . '</th>';
echo '<th style="width: 150px;">' . esc_html__( 'Actor', 'photo-competition-manager' ) . '</th>';
echo '<th style="width: 200px;">' . esc_html__( 'Competition', 'photo-competition-manager' ) . '</th>';
echo '<th style="width: 80px;">' . esc_html__( 'Details', 'photo-competition-manager' ) . '</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

foreach ( $data['logs'] as $log ) {
	$has_metadata = ! empty( $log->metadata ) && 'null' !== $log->metadata;

	echo '<tr>';

	// Date/Time.
	echo '<td>' . esc_html( $log->formatted_datetime ) . '</td>';

	// Category with icon.
	echo '<td>';
	echo '<span class="dashicons ' . esc_attr( $log->category_icon ) . '" style="color: #2271b1;"></span> ';
	echo esc_html( $log->category_label );
	echo '</td>';

	// Description.
	echo '<td>' . esc_html( $log->description ) . '</td>';

	// Actor.
	echo '<td>' . esc_html( $log->actor_name ) . '</td>';

	// Competition.
	echo '<td>' . esc_html( $log->competition_title ) . '</td>';

	// Details toggle.
	echo '<td>';
	if ( $has_metadata ) {
		echo '<button type="button" class="button-link log-metadata-toggle" data-log-id="' . esc_attr( $log->id ) . '">';
		echo esc_html__( 'View', 'photo-competition-manager' );
		echo '</button>';
		echo '<div id="log-metadata-' . esc_attr( $log->id ) . '" class="log-metadata" style="display: none; margin-top: 10px; padding: 10px; background: #f0f0f1; border: 1px solid #dcdcde;">';
		echo '<pre style="white-space: pre-wrap; word-wrap: break-word; font-size: 11px;">' . esc_html( $log->metadata ) . '</pre>';
		echo '</div>';
	} else {
		echo '—';
	}
	echo '</td>';

	echo '</tr>';

	// Metadata row (hidden by default).
	if ( $has_metadata ) {
		echo '<tr id="log-metadata-row-' . esc_attr( $log->id ) . '" class="log-metadata-row" style="display: none;">';
		echo '<td colspan="6">';
		echo '<div style="padding: 10px; background: #f0f0f1; border: 1px solid #dcdcde;">';
		echo '<strong>' . esc_html__( 'Metadata:', 'photo-competition-manager' ) . '</strong>';
		echo '<pre style="white-space: pre-wrap; word-wrap: break-word; font-size: 11px; margin-top: 10px;">' . esc_html( $log->metadata ) . '</pre>';
		echo '</div>';
		echo '</td>';
		echo '</tr>';
	}
}

echo '</tbody>';
echo '</table>';
