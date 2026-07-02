<?php
/**
 * Delete original images form partial for the admin submissions page.
 *
 * Reads $data['competition_id'] (int).
 *
 * Pure function of $data — reaches into no controller/trait state.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;

echo '<form method="post" style="margin-bottom: 15px; display: inline-block;">';
wp_nonce_field( 'photo_competition_delete_originals_' . $data['competition_id'], '_wpnonce' );
echo '<input type="hidden" name="action" value="delete_original_images" />';
echo '<input type="hidden" name="competition_id" value="' . esc_attr( $data['competition_id'] ) . '" />';
echo '<button type="submit" class="button photo-comp-delete-originals" data-confirm="' . esc_attr( __( 'Are you sure you want to delete all original images from the media library for this competition? This will keep thumbnails and slideshow images, but remove the high-resolution originals to save space. This action cannot be undone.', 'photo-competition-manager' ) ) . '">';
echo esc_html__( 'Delete Original Images', 'photo-competition-manager' );
echo '</button>';
echo ' <span class="description">' . esc_html__( 'Remove high-resolution originals from media library (keeps thumbnails and slideshow images).', 'photo-competition-manager' ) . '</span>';
echo '</form>';
