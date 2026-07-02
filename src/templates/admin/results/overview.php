<?php
/**
 * Results overview partial for the admin results dashboard page.
 *
 * @package PhotoCompetitionManager
 *
 * $data['competition_options'] array<int, array{url: string, selected: bool, title: string}> Competition selector options.
 * $data['summary_cards_html']  string Pre-rendered summary card HTML (trusted, pre-escaped).
 * $data['category_tabs']       array<int, array{url: string, label: string, active: bool, count: int}> Category tab data; empty when the competition has no categories.
 * $data['selected_category']   string Selected category slug, empty when there are no categories.
 * $data['breakdown']           array{images?: int, votes?: int, average_score?: float, min_score?: float, max_score?: float, participation_rate?: float} Category breakdown stats; empty when no category is selected.
 * $data['results_table_html'] string Pre-rendered results table HTML (trusted, pre-escaped); empty when no category is selected.
 * $data['recalculate_url']    string Nonced recalculate-scores URL.
 * $data['export_url']         string Nonced CSV export URL.
 * $data['email_url']          string Nonced email-results URL.
 * $data['share_hash']         string Competition share hash, empty when not generated.
 * $data['results_page']       string Configured results page URL, empty when not set.
 * $data['share_url']          string Share link URL; only set when both share_hash and results_page are present.
 * $data['send_committee_url'] string Nonced send-to-committee URL; only set when both share_hash and results_page are present.
 * $data['send_all_url']       string Nonced send-to-all URL; only set when both share_hash and results_page are present.
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="wrap photo-competition-manager-results-dashboard">';
echo '<h1>' . esc_html__( 'Results Dashboard', 'photo-competition-manager' ) . '</h1>';

// Competition selector.
echo '<div class="competition-selector" style="margin: 20px 0;">';
echo '<label for="competition-select" style="margin-right: 10px;"><strong>' . esc_html__( 'Competition:', 'photo-competition-manager' ) . '</strong></label>';
echo '<select id="competition-select">';
foreach ( $data['competition_options'] as $option ) {
	$selected = $option['selected'] ? ' selected' : '';
	echo '<option value="' . esc_url( $option['url'] ) . '"' . esc_attr( $selected ) . '>' . esc_html( $option['title'] ) . '</option>';
}
echo '</select>';
echo '</div>';

// Summary cards.
echo '<div class="photo-comp-summary-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">';

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
echo $data['summary_cards_html'];

echo '</div>';

// Category tabs.
if ( ! empty( $data['category_tabs'] ) ) {
	echo '<div class="nav-tab-wrapper" style="margin: 20px 0;">';
	foreach ( $data['category_tabs'] as $category_tab ) {
		$active_class = $category_tab['active'] ? ' nav-tab-active' : '';

		echo '<a href="' . esc_url( $category_tab['url'] ) . '" class="nav-tab' . esc_attr( $active_class ) . '">';
		echo esc_html( $category_tab['label'] );

		// Show count badge.
		echo ' <span class="count">(' . absint( $category_tab['count'] ) . ')</span>';

		echo '</a>';
	}
	echo '</div>';
}

// Category breakdown.
if ( ! empty( $data['selected_category'] ) ) {
	$breakdown = $data['breakdown'];

	echo '<div class="photo-comp-category-stats" style="background: #f9f9f9; padding: 15px; margin: 20px 0; border-left: 4px solid #2271b1;">';
	echo '<h3>' . esc_html__( 'Category Statistics', 'photo-competition-manager' ) . '</h3>';
	echo '<p>';
	echo '<strong>' . esc_html__( 'Images:', 'photo-competition-manager' ) . '</strong> ' . absint( $breakdown['images'] ) . ' | ';
	echo '<strong>' . esc_html__( 'Votes:', 'photo-competition-manager' ) . '</strong> ' . absint( $breakdown['votes'] ) . ' | ';
	echo '<strong>' . esc_html__( 'Avg Score:', 'photo-competition-manager' ) . '</strong> ' . esc_html( number_format( (float) $breakdown['average_score'], 2 ) ) . ' | ';
	echo '<strong>' . esc_html__( 'Range:', 'photo-competition-manager' ) . '</strong> ' . esc_html( number_format( (float) $breakdown['min_score'], 0 ) ) . ' - ' . esc_html( number_format( (float) $breakdown['max_score'], 0 ) ) . ' | ';
	echo '<strong>' . esc_html__( 'Participation:', 'photo-competition-manager' ) . '</strong> ' . esc_html( number_format( (float) $breakdown['participation_rate'], 1 ) ) . '%';
	echo '</p>';
	echo '</div>';

	// Results table grouped by grade.
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
	echo $data['results_table_html'];
}

// Action buttons.
echo '<div class="photo-comp-actions" style="margin: 20px 0;">';

echo '<a href="' . esc_url( $data['recalculate_url'] ) . '" class="button button-secondary">';
echo '<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> ';
echo esc_html__( 'Recalculate Scores', 'photo-competition-manager' );
echo '</a> ';

echo '<a href="' . esc_url( $data['export_url'] ) . '" class="button button-primary">';
echo '<span class="dashicons dashicons-download" style="margin-top: 3px;"></span> ';
echo esc_html__( 'Export Results (CSV)', 'photo-competition-manager' );
echo '</a> ';

echo '<a href="' . esc_url( $data['email_url'] ) . '" class="button button-secondary">';
echo '<span class="dashicons dashicons-email" style="margin-top: 3px;"></span> ';
echo esc_html__( 'Email Results', 'photo-competition-manager' );
echo '</a>';

echo '</div>';

// Share results section.
echo '<div class="photo-comp-share-results" style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #2271b1;">';
echo '<h3 style="margin-top: 0;">' . esc_html__( 'Share Results', 'photo-competition-manager' ) . '</h3>';

if ( ! empty( $data['share_hash'] ) && ! empty( $data['results_page'] ) ) {
	echo '<p class="description">';
	echo esc_html__( 'Share link (bypasses visibility setting):', 'photo-competition-manager' );
	echo '<br><code>' . esc_html( $data['share_url'] ) . '</code>';
	echo '</p>';
	echo '<p>';
	echo '<a href="' . esc_url( $data['send_committee_url'] ) . '" class="button" onclick="return confirm(\'' . esc_js( __( 'This will send the results link to all committee members. Continue?', 'photo-competition-manager' ) ) . '\');">';
	echo '<span class="dashicons dashicons-groups" style="margin-top: 4px;"></span> ';
	echo esc_html__( 'Send to Committee', 'photo-competition-manager' );
	echo '</a> ';
	echo '<a href="' . esc_url( $data['send_all_url'] ) . '" class="button" onclick="return confirm(\'' . esc_js( __( 'This will send the results link to ALL active members. Continue?', 'photo-competition-manager' ) ) . '\');">';
	echo '<span class="dashicons dashicons-email" style="margin-top: 4px;"></span> ';
	echo esc_html__( 'Send to All Members', 'photo-competition-manager' );
	echo '</a>';
	echo '</p>';
} elseif ( empty( $data['share_hash'] ) ) {
	echo '<p class="description">';
	printf(
		/* translators: %s: link to competitions page */
		esc_html__( 'No share hash exists. %s on the Competitions page first.', 'photo-competition-manager' ),
		'<a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager' ) ) . '">' . esc_html__( 'Generate a results link', 'photo-competition-manager' ) . '</a>'
	);
	echo '</p>';
} else {
	echo '<p class="description">';
	echo esc_html__( 'No results page URL configured. Set one in competition settings.', 'photo-competition-manager' );
	echo '</p>';
}

echo '</div>';

echo '</div>';
