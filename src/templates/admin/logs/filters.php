<?php
/**
 * Filters form partial for the admin logs page.
 *
 * Reads $data keys: competitions, event_categories, event_types,
 * selected_competition, selected_category, selected_type, date_from, date_to.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<form method="get" class="logs-filters" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin: 20px 0;">';
echo '<input type="hidden" name="page" value="photo-competition-manager-logs" />';

echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">';

// Competition filter.
echo '<div>';
echo '<label for="competition-filter"><strong>' . esc_html__( 'Competition', 'photo-competition-manager' ) . '</strong></label><br>';
echo '<select id="competition-filter" name="competition" style="width: 100%;">';
echo '<option value="0">' . esc_html__( 'All Competitions', 'photo-competition-manager' ) . '</option>';
foreach ( $data['competitions'] as $comp ) {
	$selected = ( (int) $comp->id === $data['selected_competition'] ) ? ' selected' : '';
	echo '<option value="' . esc_attr( $comp->id ) . '"' . esc_attr( $selected ) . '>' . esc_html( $comp->title ) . '</option>';
}
echo '</select>';
echo '</div>';

// Event category filter.
echo '<div>';
echo '<label for="event-category-filter"><strong>' . esc_html__( 'Category', 'photo-competition-manager' ) . '</strong></label><br>';
echo '<select id="event-category-filter" name="event_category" style="width: 100%;">';
echo '<option value="">' . esc_html__( 'All Categories', 'photo-competition-manager' ) . '</option>';
foreach ( $data['event_categories'] as $category ) {
	$selected = ( $category === $data['selected_category'] ) ? ' selected' : '';
	$label    = $this->get_category_label( $category );
	echo '<option value="' . esc_attr( $category ) . '"' . esc_attr( $selected ) . '>' . esc_html( $label ) . '</option>';
}
echo '</select>';
echo '</div>';

// Event type filter.
echo '<div>';
echo '<label for="event-type-filter"><strong>' . esc_html__( 'Event Type', 'photo-competition-manager' ) . '</strong></label><br>';
echo '<select id="event-type-filter" name="event_type" style="width: 100%;">';
echo '<option value="">' . esc_html__( 'All Types', 'photo-competition-manager' ) . '</option>';
foreach ( $data['event_types'] as $found_type ) {
	$selected = ( $found_type === $data['selected_type'] ) ? ' selected' : '';
	$label    = $this->get_type_label( $found_type );
	echo '<option value="' . esc_attr( $found_type ) . '"' . esc_attr( $selected ) . '>' . esc_html( $label ) . '</option>';
}
echo '</select>';
echo '</div>';

// Date from.
echo '<div>';
echo '<label for="date-from-filter"><strong>' . esc_html__( 'From Date', 'photo-competition-manager' ) . '</strong></label><br>';
echo '<input type="date" id="date-from-filter" name="date_from" value="' . esc_attr( $data['date_from'] ) . '" style="width: 100%;" />';
echo '</div>';

// Date to.
echo '<div>';
echo '<label for="date-to-filter"><strong>' . esc_html__( 'To Date', 'photo-competition-manager' ) . '</strong></label><br>';
echo '<input type="date" id="date-to-filter" name="date_to" value="' . esc_attr( $data['date_to'] ) . '" style="width: 100%;" />';
echo '</div>';

echo '</div>';

// Buttons.
echo '<div style="margin-top: 15px;">';
submit_button( __( 'Filter', 'photo-competition-manager' ), 'primary', 'submit', false );
echo ' ';
echo '<a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager-logs' ) ) . '" class="button">' . esc_html__( 'Clear Filters', 'photo-competition-manager' ) . '</a>';
echo '</div>';

echo '</form>';
