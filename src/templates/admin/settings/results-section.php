<?php
/**
 * Results section partial for the admin settings page: who sees published
 * results, and the past competitions page that shows them too.
 *
 * Reads $data['visibility'] ('private', 'public' or 'archive': public with
 * past competitions) and $data['show_winners'] (bool: past competitions
 * show positions and scores).
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

$choices = array(
	\PhotoCompetitionManager\Service\Public_Results::PRIVATE      => __( 'Private: the results and top three pages need the results share link, which members get in the results email', 'photo-competition-manager' ),
	\PhotoCompetitionManager\Service\Public_Results::PUBLIC       => __( 'Public: anyone can see published results on the results and top three pages', 'photo-competition-manager' ),
	\PhotoCompetitionManager\Service\Public_Results::WITH_ARCHIVE => __( 'Public, with past competitions: a page listing every competition whose results are published, too', 'photo-competition-manager' ),
);

echo '<h2 id="results">' . esc_html__( 'Results', 'photo-competition-manager' ) . '</h2>';
echo '<fieldset>';
echo '<legend class="screen-reader-text">' . esc_html__( 'Who sees published results', 'photo-competition-manager' ) . '</legend>';
foreach ( $choices as $value => $label ) {
	echo '<p><label><input type="radio" name="results_visibility" value="' . esc_attr( $value ) . '"' . checked( $data['visibility'], $value, false ) . ' /> ' . esc_html( $label ) . '</label></p>';
}
echo '</fieldset>';

echo '<h3 id="past-competitions">' . esc_html__( 'Past competitions', 'photo-competition-manager' ) . '</h3>';
echo '<p class="description">' . esc_html__( 'Each competition on the page has its own link showing the images entered. Put the [competition_archive] shortcode on one page; its address is the start of every link. Members can hide their own images. Names are hidden unless the shortcode has show_names="true".', 'photo-competition-manager' ) . '</p>';

echo '<p><label><input type="checkbox" name="archive_show_winners" value="1"' . checked( $data['show_winners'], true, false ) . ' /> ';
echo esc_html__( 'Show positions and scores', 'photo-competition-manager' ) . '</label></p>';
