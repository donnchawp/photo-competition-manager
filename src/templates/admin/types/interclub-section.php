<?php
/**
 * Interclub section partial for the competition type form.
 *
 * Reads $data['enabled'] (bool), $data['substitutes'] and
 * $data['photographer_limit'] (both int).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2>' . esc_html__( 'Interclub', 'photo-competition-manager' ) . '</h2>';

echo '<input type="hidden" name="interclub_section" value="1" />';

echo '<p>';
echo '<label><input type="checkbox" name="interclub_enabled" value="1"' . checked( $data['enabled'], true, false ) . ' /> ';
echo esc_html__( 'Entrants are clubs', 'photo-competition-manager' ) . '</label><br />';
echo '<span class="description">' . esc_html__( 'Each entrant is a club entering a panel of images in each category. A panel holds as many images as the category\'s quota, and every image has a photographer\'s name.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="interclub_substitutes">' . esc_html__( 'Substitutes per panel', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="number" id="interclub_substitutes" name="interclub_substitutes" min="0" step="1" value="' . esc_attr( $data['substitutes'] ) . '" class="small-text" /> ';
echo '<span class="description">' . esc_html__( 'Extra images a club can upload to replace a disqualified one. 0 for none.', 'photo-competition-manager' ) . '</span>';
echo '</p>';

echo '<p>';
echo '<label for="interclub_photographer_limit">' . esc_html__( 'Most images per photographer in a panel', 'photo-competition-manager' ) . '</label><br />';
echo '<input type="number" id="interclub_photographer_limit" name="interclub_photographer_limit" min="0" step="1" value="' . esc_attr( $data['photographer_limit'] ) . '" class="small-text" /> ';
echo '<span class="description">' . esc_html__( '0 for no limit.', 'photo-competition-manager' ) . '</span>';
echo '</p>';
