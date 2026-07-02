<?php
/**
 * Upload-status notice bar partial for the admin members page.
 *
 * Reads $data['notice_class'] (string, 'notice-warning' or 'notice-info'),
 * $data['title'] (string, active competition title), $data['status_text']
 * (string), $data['toggle_url'] (string), $data['button_text'] (string).
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<div class="notice ' . esc_attr( $data['notice_class'] ) . '" style="display:flex;align-items:center;justify-content:space-between;padding:8px 12px;">';
echo '<p style="margin:0;"><strong>' . esc_html( $data['title'] ) . ':</strong> ' . esc_html( $data['status_text'] ) . '</p>';
echo '<a href="' . esc_url( $data['toggle_url'] ) . '" class="button">' . esc_html( $data['button_text'] ) . '</a>';
echo '</div>';
