<?php
/**
 * Summary card partial for the admin results dashboard page.
 *
 * @package PhotoCompetitionManager
 *
 * $data['label'] string Label text.
 * $data['value'] mixed  Value to display.
 * $data['icon']  string Dashicon class.
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="photo-comp-summary-card" style="background: white; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">';
echo '<div style="display: flex; align-items: center; margin-bottom: 10px;">';
echo '<span class="dashicons ' . esc_attr( $data['icon'] ) . '" style="font-size: 32px; color: #2271b1; margin-right: 10px;"></span>';
echo '<div>';
echo '<div style="font-size: 28px; font-weight: bold; color: #1d2327;">' . esc_html( $data['value'] ) . '</div>';
echo '<div style="font-size: 13px; color: #646970;">' . esc_html( $data['label'] ) . '</div>';
echo '</div>';
echo '</div>';
echo '</div>';
