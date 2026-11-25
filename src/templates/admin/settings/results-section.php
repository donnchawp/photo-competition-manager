<?php
/**
 * Results section partial for the admin settings page.
 *
 * Reads $data['public'] (bool: published results show to everyone).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2 id="results">' . esc_html__( 'Results', 'photo-competition-manager' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'While this is off, the results and top three pages show a competition\'s results only with its results share link, which members get in the results email, and past competitions aren\'t shown.', 'photo-competition-manager' ) . '</p>';

echo '<p><label><input type="checkbox" name="results_public" value="1"' . checked( $data['public'], true, false ) . ' /> ';
echo esc_html__( 'Show published results to the public', 'photo-competition-manager' ) . '</label></p>';
