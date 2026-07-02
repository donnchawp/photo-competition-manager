<?php
/**
 * Regenerate random numbers form partial for the admin submissions page.
 *
 * Reads $data['competition_id'] (int).
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<form method="post" style="margin-bottom: 15px; display: inline-block; margin-right: 10px;">';
wp_nonce_field( 'photo_competition_regenerate_numbers_' . $data['competition_id'], '_wpnonce' );
echo '<input type="hidden" name="action" value="regenerate_numbers" />';
echo '<input type="hidden" name="competition_id" value="' . esc_attr( $data['competition_id'] ) . '" />';
echo '<button type="submit" class="button photo-comp-regenerate" data-confirm="' . esc_attr( __( 'Are you sure you want to regenerate random numbers? Each member will still have the same number across all their images, but the numbers will be reassigned.', 'photo-competition-manager' ) ) . '">';
echo esc_html__( 'Regenerate Random Numbers', 'photo-competition-manager' );
echo '</button>';
echo ' <span class="description">' . esc_html__( 'Reassign random numbers to members in this competition (each member keeps one consistent number).', 'photo-competition-manager' ) . '</span>';
echo '</form>';
