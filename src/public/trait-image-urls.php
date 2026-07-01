<?php
/**
 * Shared image URL helpers for public shortcodes.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Support\Image_Processor;

/**
 * Provides image URL resolution for competition entries.
 *
 * Shared by the Top 3 and Results shortcodes, which both need to resolve
 * the public URLs for an entry's full-size image and thumbnail.
 *
 * @since 0.3.0
 */
trait Image_Urls {

	/**
	 * Get image URLs for display.
	 *
	 * @param object $competition Competition object.
	 * @param object $image       Image record.
	 * @return array{full: string, thumb: string}
	 */
	private function get_image_urls( object $competition, object $image ): array {
		if ( empty( $competition->slug ) || empty( $image->filename ) ) {
			return array(
				'full'  => '',
				'thumb' => '',
			);
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return array(
				'full'  => '',
				'thumb' => '',
			);
		}

		$base = trailingslashit( $uploads['baseurl'] ) . 'competitions/';
		$slug = sanitize_file_name( (string) $competition->slug );
		$cat  = sanitize_file_name( (string) $image->category );

		$folder_url  = trailingslashit( $base . rawurlencode( $slug ) . '/' . rawurlencode( $cat ) );
		$folder_path = trailingslashit( trailingslashit( $uploads['basedir'] ) . 'competitions/' . $slug . '/' . $cat );

		$filename   = $image->filename;
		$thumb_name = Image_Processor::get_thumbnail_filename( $filename );

		$full_path  = $folder_path . $filename;
		$thumb_path = $folder_path . $thumb_name;

		$full_url  = file_exists( $full_path ) ? $folder_url . rawurlencode( $filename ) : '';
		$thumb_url = file_exists( $thumb_path ) ? $folder_url . rawurlencode( $thumb_name ) : '';

		return array(
			'full'  => $full_url,
			'thumb' => $thumb_url,
		);
	}
}
