<?php
/**
 * Past competitions section partial for the admin settings page.
 *
 * Reads $data['enabled'] (bool: the archive is on) and $data['show_winners']
 * (bool: it shows positions and scores).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2 id="past-competitions">' . esc_html__( 'Past competitions', 'photo-competition-manager' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'A public page listing competitions whose results are published, each with its own link showing the images entered. Put the [competition_archive] shortcode on a page; its address is the start of every link. Members can hide their own images. Names are hidden unless the shortcode has show_names="true".', 'photo-competition-manager' ) . '</p>';

echo '<p><label><input type="checkbox" name="archive_enabled" value="1"' . checked( $data['enabled'], true, false ) . ' /> ';
echo esc_html__( 'Show past competitions to the public', 'photo-competition-manager' ) . '</label></p>';

echo '<p><label><input type="checkbox" name="archive_show_winners" value="1"' . checked( $data['show_winners'], true, false ) . ' /> ';
echo esc_html__( 'Show positions and scores', 'photo-competition-manager' ) . '</label></p>';
