<?php
/**
 * Grades section partial for the admin settings page.
 *
 * Reads $data['grade_rows_html'] (string): pre-rendered grade row markup,
 * one row per configured grade, produced by
 * Settings_Controller::render_grade_field().
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2>' . esc_html__( 'Grades', 'photo-competition-manager' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'Define default member grade levels.', 'photo-competition-manager' ) . '</p>';

echo '<div id="grades-container">';
echo $data['grade_rows_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
echo '</div>';

echo '<p>';
echo '<button type="button" id="add-grade" class="button">' . esc_html__( 'Add Grade', 'photo-competition-manager' ) . '</button>';
echo '</p>';
