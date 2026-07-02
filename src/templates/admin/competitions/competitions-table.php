<?php
/**
 * Competitions list table partial for the admin dashboard page.
 *
 * Reads $data['views'] (array<int, array{label: string, count: int, url:
 * string, is_current: bool}>): the Active/Archived view-switcher links.
 * $data['rows'] (array<int, array{title: string, opens: string, closes:
 * string, last_updated: string, edit_url: string, is_archived: bool,
 * toggle_uploads_url: string, uploads_closed: bool, is_open: bool,
 * send_email_url: string, generate_link_url: string, restore_url: string,
 * archive_url: string, reset_votes_url: string, delete_url: string}>): rows
 * for the current view; empty when no competitions match.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2 class="screen-reader-text">' . esc_html__( 'Competition List', 'photo-competition-manager' ) . '</h2>';

echo '<ul class="subsubsub">';

$view_count = count( $data['views'] );
$view_index = 0;

foreach ( $data['views'] as $view_row ) {
	++$view_index;
	echo '<li><a href="' . esc_url( $view_row['url'] ) . '"' . ( $view_row['is_current'] ? ' class="current"' : '' ) . '>' . esc_html( $view_row['label'] ) . ' <span class="count">(' . esc_html( (string) $view_row['count'] ) . ')</span></a>';
	if ( $view_index < $view_count ) {
		echo ' | ';
	}
	echo '</li>';
}

echo '</ul>';

if ( empty( $data['rows'] ) ) {
	echo '<p>' . esc_html__( 'No competitions found for this view.', 'photo-competition-manager' ) . '</p>';
	return;
}

echo '<table class="widefat striped">';
echo '<thead><tr>';
echo '<th>' . esc_html__( 'Title', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Opens', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Closes', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Last Updated', 'photo-competition-manager' ) . '</th>';
echo '<th>' . esc_html__( 'Actions', 'photo-competition-manager' ) . '</th>';
echo '</tr></thead>';
echo '<tbody>';

$allowed_html = array(
	'a'    => array(
		'href'         => array(),
		'class'        => array(),
		'data-confirm' => array(),
	),
	'span' => array(
		'title' => array(),
		'style' => array(),
		'class' => array(),
	),
);

foreach ( $data['rows'] as $row ) {
	echo '<tr>';
	echo '<td>' . esc_html( $row['title'] ) . '</td>';
	echo '<td>' . esc_html( $row['opens'] ) . '</td>';
	echo '<td>' . esc_html( $row['closes'] ) . '</td>';
	echo '<td>' . esc_html( $row['last_updated'] ) . '</td>';

	$actions = array(
		sprintf( '<a href="%s">%s</a>', esc_url( $row['edit_url'] ), esc_html__( 'Edit', 'photo-competition-manager' ) ),
	);

	// Toggle uploads action.
	if ( ! $row['is_archived'] ) {
		$toggle_label = $row['uploads_closed']
			? __( 'Open Uploads', 'photo-competition-manager' )
			: __( 'Close Uploads', 'photo-competition-manager' );

		$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( $row['toggle_uploads_url'] ), esc_html( $toggle_label ) );
	}

	if ( $row['is_open'] && ! $row['is_archived'] ) {
		$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( $row['send_email_url'] ), esc_html__( 'Send Upload Emails', 'photo-competition-manager' ) );
	} else {
		$actions[] = sprintf( '<span title="Send only on open competitions" style="color: #888;">%s</span>', esc_html__( 'Send Upload Emails', 'photo-competition-manager' ) );
	}

	// Generate Results Link action.
	$actions[] = sprintf(
		'<a href="%s" class="photo-comp-regenerate-hash" data-confirm="%s">%s</a>',
		esc_url( $row['generate_link_url'] ),
		esc_attr( __( 'This will generate a new results link and invalidate any previously shared link. Continue?', 'photo-competition-manager' ) ),
		esc_html__( 'Generate Results Link', 'photo-competition-manager' )
	);

	if ( $row['is_archived'] ) {
		$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( $row['restore_url'] ), esc_html__( 'Restore', 'photo-competition-manager' ) );
	} else {
		$actions[] = sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $row['archive_url'] ), esc_html__( 'Archive', 'photo-competition-manager' ) );
	}

	// Reset votes action.
	$actions[] = sprintf(
		'<a href="%s" class="photo-comp-reset-votes" data-confirm="%s">%s</a>',
		esc_url( $row['reset_votes_url'] ),
		esc_attr( __( 'Reset all voting progress? This deletes all votes, tokens, and resets the workflow to step 1. This cannot be undone.', 'photo-competition-manager' ) ),
		esc_html__( 'Reset Voting', 'photo-competition-manager' )
	);

	// Delete competition action.
	$actions[] = sprintf(
		'<a href="%s" class="submitdelete photo-comp-delete" data-confirm="%s">%s</a>',
		esc_url( $row['delete_url'] ),
		esc_attr( __( 'Are you sure you want to permanently delete this competition? This will delete all images, votes, and tokens. This cannot be undone.', 'photo-competition-manager' ) ),
		esc_html__( 'Delete', 'photo-competition-manager' )
	);

	echo '<td>' . wp_kses( implode( ' | ', $actions ), $allowed_html ) . '</td>';
	echo '</tr>';
}

echo '</tbody>';
echo '</table>';
