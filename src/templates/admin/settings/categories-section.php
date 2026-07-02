<?php
/**
 * Categories section partial for the admin settings page.
 *
 * Reads $data['category_rows_html'] (string): pre-rendered category row
 * markup, one row per configured category, produced by
 * Settings_Controller::render_category_field().
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<h2>' . esc_html__( 'Categories', 'photo-competition-manager' ) . '</h2>';
echo '<p class="description">' . esc_html__( 'Define default categories and upload quotas.', 'photo-competition-manager' ) . '</p>';

echo '<div id="categories-container">';
echo $data['category_rows_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
echo '</div>';

echo '<p>';
echo '<button type="button" id="add-category" class="button">' . esc_html__( 'Add Category', 'photo-competition-manager' ) . '</button>';
echo '</p>';
