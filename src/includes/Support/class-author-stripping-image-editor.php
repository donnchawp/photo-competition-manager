<?php
/**
 * Imagick image editor that drops author metadata before it encodes.
 *
 * @package PhotoCompetitionManager\Support
 */

namespace PhotoCompetitionManager\Support;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';

/**
 * Class Author_Stripping_Image_Editor
 *
 * WordPress's Imagick editor keeps the EXIF, IPTC and XMP profiles when it saves, and they
 * can name the photographer. This editor removes them from the image just before it is
 * encoded, so the saved copy never has them and nothing is encoded twice. The colour
 * profile stays. GD drops all of it on save, so it needs no equivalent.
 *
 * @since 0.4.0
 */
class Author_Stripping_Image_Editor extends \WP_Image_Editor_Imagick {

	/**
	 * Profiles that can carry the photographer's name.
	 *
	 * @var string[]
	 */
	const AUTHOR_PROFILES = array( 'exif', 'iptc', 'xmp' );

	/**
	 * Strip the author profiles, then save as WordPress would.
	 *
	 * A failure to strip is logged and the save carries on, so one odd image can't fail an upload.
	 *
	 * @param \Imagick    $image     Image to save.
	 * @param string|null $filename  Destination path.
	 * @param string|null $mime_type Destination MIME type.
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function _save( $image, $filename = null, $mime_type = null ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Overrides the parent's method.
		try {
			foreach ( self::AUTHOR_PROFILES as $profile ) {
				// Ask each time: removing one profile can take another with it, and removing a missing one throws.
				if ( in_array( $profile, $image->getImageProfiles( '*', false ), true ) ) {
					$image->removeImageProfile( $profile );
				}
			}
		} catch ( \Exception $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Photo Competition Manager: could not strip author metadata from an image: ' . $e->getMessage() );
		}

		return parent::_save( $image, $filename, $mime_type );
	}
}
