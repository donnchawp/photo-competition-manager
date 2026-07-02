<?php
/**
 * Results table partial for the admin results dashboard page, grouped by grade.
 *
 * @package PhotoCompetitionManager
 *
 * $data['grade_tables'] array<int, array{
 *     label: string,
 *     rows: array<int, array{
 *         rank: int,
 *         image_url: string|null,
 *         member_name: string|null,
 *         total_score: int,
 *         vote_count: int,
 *         detail_url: string,
 *     }>,
 * }> Per-grade result tables to render; grades with no results are omitted.
 */

defined( 'ABSPATH' ) || exit;

foreach ( $data['grade_tables'] as $grade_table ) {
	echo '<h3>' . esc_html( $grade_table['label'] ) . '</h3>';

	echo '<table class="wp-list-table widefat fixed striped" style="margin-bottom: 30px;">';
	echo '<thead>';
	echo '<tr>';
	echo '<th style="width: 60px;">' . esc_html__( 'Rank', 'photo-competition-manager' ) . '</th>';
	echo '<th style="width: 80px;">' . esc_html__( 'Image', 'photo-competition-manager' ) . '</th>';
	echo '<th>' . esc_html__( 'Member', 'photo-competition-manager' ) . '</th>';
	echo '<th style="width: 100px;">' . esc_html__( 'Score', 'photo-competition-manager' ) . '</th>';
	echo '<th style="width: 80px;">' . esc_html__( 'Votes', 'photo-competition-manager' ) . '</th>';
	echo '<th style="width: 120px;">' . esc_html__( 'Actions', 'photo-competition-manager' ) . '</th>';
	echo '</tr>';
	echo '</thead>';
	echo '<tbody>';

	foreach ( $grade_table['rows'] as $row ) {
		echo '<tr>';
		echo '<td><strong>' . absint( $row['rank'] ) . '</strong></td>';

		echo '<td>';
		if ( $row['image_url'] ) {
			echo '<img src="' . esc_url( $row['image_url'] ) . '" alt="' . esc_attr__( 'Image', 'photo-competition-manager' ) . '" style="max-width: 60px; height: auto; border: 1px solid #ddd;">';
		} else {
			echo '<span class="dashicons dashicons-format-image" style="font-size: 40px; color: #ddd;"></span>';
		}
		echo '</td>';

		echo '<td>';
		if ( null !== $row['member_name'] ) {
			echo esc_html( $row['member_name'] );
		} else {
			echo '<em>' . esc_html__( 'Unknown', 'photo-competition-manager' ) . '</em>';
		}
		echo '</td>';

		// Display total score (sum of all votes).
		echo '<td><strong>' . esc_html( number_format( $row['total_score'], 0 ) ) . '</strong></td>';
		echo '<td>' . absint( $row['vote_count'] ) . '</td>';

		echo '<td>';
		echo '<a href="' . esc_url( $row['detail_url'] ) . '" class="button button-small">' . esc_html__( 'View Details', 'photo-competition-manager' ) . '</a>';
		echo '</td>';

		echo '</tr>';
	}

	echo '</tbody>';
	echo '</table>';
}
