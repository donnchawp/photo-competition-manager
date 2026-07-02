<?php
/**
 * "Missing pages" notice partial for the admin voting controls page.
 *
 * Rendering continues after this notice; no wrapper div is closed here.
 *
 * @package PhotoCompetitionManager
 *
 * @var array $data {
 *     @type string[] $missing List of missing page type labels (e.g. 'Voting', 'Results').
 * }
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="notice notice-warning">';
echo '<p>';
printf(
	/* translators: %s: comma-separated list of missing page types */
	esc_html__( 'Missing pages: %s. Voting controls require these pages to be created.', 'photo-competition-manager' ),
	'<strong>' . esc_html( implode( ', ', $data['missing'] ) ) . '</strong>'
);
echo ' <a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager-setup' ) ) . '">' . esc_html__( 'Run Setup Wizard', 'photo-competition-manager' ) . '</a>';
echo ' | <a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager-settings' ) ) . '">' . esc_html__( 'Configure in Settings', 'photo-competition-manager' ) . '</a>';
echo '</p>';
echo '</div>';
