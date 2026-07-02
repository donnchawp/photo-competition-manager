<?php
/**
 * No-members-found notice partial for the admin members page.
 *
 * Reads $data['has_filters'] (bool, whether search/status/grade filters are
 * active).
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

if ( $data['has_filters'] ) {
	echo '<p>' . esc_html__( 'No members found matching the selected filters.', 'photo-competition-manager' ) . '</p>';
} else {
	echo '<p>' . esc_html__( 'No members recorded yet. Import or create members to get started.', 'photo-competition-manager' ) . '</p>';
}
