<?php
/**
 * Pagination partial for the admin logs page.
 *
 * Reads $data keys: current_page, total_pages, base_url.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="tablenav" style="margin: 20px 0;">';
echo '<div class="tablenav-pages">';

echo '<span class="displaying-num">';
printf(
	/* translators: %s: Number of pages */
	esc_html__( 'Page %1$s of %2$s', 'photo-competition-manager' ),
	esc_html( number_format( $data['current_page'] ) ),
	esc_html( number_format( $data['total_pages'] ) )
);
echo '</span>';

echo '<span class="pagination-links">';

// First page.
if ( $data['current_page'] > 1 ) {
	echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', 1, $data['base_url'] ) ) . '">&laquo; ' . esc_html__( 'First', 'photo-competition-manager' ) . '</a> ';
}

// Previous page.
if ( $data['current_page'] > 1 ) {
	echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $data['current_page'] - 1, $data['base_url'] ) ) . '">&lsaquo; ' . esc_html__( 'Previous', 'photo-competition-manager' ) . '</a> ';
}

// Next page.
if ( $data['current_page'] < $data['total_pages'] ) {
	echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $data['current_page'] + 1, $data['base_url'] ) ) . '">' . esc_html__( 'Next', 'photo-competition-manager' ) . ' &rsaquo;</a> ';
}

// Last page.
if ( $data['current_page'] < $data['total_pages'] ) {
	echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $data['total_pages'], $data['base_url'] ) ) . '">' . esc_html__( 'Last', 'photo-competition-manager' ) . ' &raquo;</a>';
}

echo '</span>';
echo '</div>';
echo '</div>';
