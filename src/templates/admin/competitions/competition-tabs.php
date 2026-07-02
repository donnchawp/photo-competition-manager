<?php
/**
 * Tab-strip partial for the competition edit screen.
 *
 * Reads $data['competition_id'] (int), $data['current_tab'] (string), and
 * $data['tabs'] (array<string, string>): tab slug => label.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2 class="nav-tab-wrapper">';

foreach ( $data['tabs'] as $slug => $label ) {
	$url = add_query_arg(
		array(
			'page'        => 'photo-competition-manager',
			'action'      => 'edit',
			'competition' => $data['competition_id'],
			'tab'         => $slug,
		),
		admin_url( 'admin.php' )
	);

	$active_class = $slug === $data['current_tab'] ? 'nav-tab-active' : '';

	printf(
		'<a href="%s" class="nav-tab %s">%s</a>',
		esc_url( $url ),
		esc_attr( $active_class ),
		esc_html( $label )
	);
}

echo '</h2>';
