<?php
/**
 * Error partial shown in place of the grades panel when a closed
 * competition's results couldn't be recorded.
 *
 * @package PhotoCompetitionManager
 *
 * $data['message'] string Why recording failed.
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside a method, so these are its locals.

echo '<div class="notice notice-error inline">';
echo '<p>' . esc_html__( 'Grades in this competition can\'t be corrected until its results are recorded. Reload this screen to try again.', 'photo-competition-manager' ) . '</p>';
echo '<p>' . esc_html( $data['message'] ) . '</p>';
echo '</div>';
