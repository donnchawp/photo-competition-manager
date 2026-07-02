<?php
/**
 * "Pages already configured" notice partial for the admin setup wizard page.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="notice notice-info inline"><p>';
echo esc_html__( 'You have already configured some pages in Settings. Creating new pages will update those URLs.', 'photo-competition-manager' );
echo '</p></div>';
