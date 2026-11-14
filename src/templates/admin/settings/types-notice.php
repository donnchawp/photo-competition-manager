<?php
/**
 * Pointer from the admin settings page to the Competition Types screen.
 *
 * Reads $data['types_url'] (string).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<p class="notice notice-info inline" style="padding: 8px 12px;">';
echo esc_html__( 'Categories, upload limits, voting and slideshow settings are now set for each competition type.', 'photo-competition-manager' );
printf( ' <a href="%s">%s</a>', esc_url( $data['types_url'] ), esc_html__( 'Edit competition types', 'photo-competition-manager' ) );
echo '</p>';
