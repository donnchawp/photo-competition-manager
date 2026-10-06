<?php
/**
 * Image processing and storage handler.
 *
 * @package PhotoCompetitionManager\Support
 */

namespace PhotoCompetitionManager\Support;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;

/**
 * Image Processor class.
 *
 * @since 0.1.0
 */
class Image_Processor {

	/**
	 * Validate uploaded file against competition settings.
	 *
	 * @param array<string, mixed> $file         Uploaded file array from $_FILES.
	 * @param array<string, mixed> $constraints  Upload constraints from settings.
	 * @return true|WP_Error
	 */
	public function validate( array $file, array $constraints ) {
		// Check upload error first. A file over PHP's own limit never reaches the size check below.
		if ( in_array( $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %d: maximum file size in MB */
					__( 'File size exceeds maximum of %d MB.', 'photo-competition-manager' ),
					(int) floor( wp_max_upload_size() / MB_IN_BYTES )
				)
			);
		}

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'upload_error', __( 'File upload failed.', 'photo-competition-manager' ) );
		}

		if ( empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'No file was uploaded.', 'photo-competition-manager' ) );
		}

		// Security: Verify file was uploaded via HTTP POST (not a local file reference).
		// Skip this check in test environments to allow mock uploads.
		if ( ! defined( 'WP_TESTS_DOMAIN' ) && ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'security_check_failed', __( 'Security check failed.', 'photo-competition-manager' ) );
		}

		// Check if file exists (works for both real uploads and test files).
		if ( ! file_exists( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'No file was uploaded.', 'photo-competition-manager' ) );
		}

		// Check file size.
		$max_size_bytes = ( $constraints['max_file_size_mb'] ?? 5 ) * 1024 * 1024;
		if ( $file['size'] > $max_size_bytes ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %d: maximum file size in MB */
					__( 'File size exceeds maximum of %d MB.', 'photo-competition-manager' ),
					$constraints['max_file_size_mb'] ?? 5
				)
			);
		}

		// Security: Use WordPress robust file type validation.
		$wp_filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		if ( ! $wp_filetype['ext'] || ! $wp_filetype['type'] ) {
			return new WP_Error( 'invalid_file', __( 'Invalid file type.', 'photo-competition-manager' ) );
		}

		// Check file extension matches allowed formats.
		$allowed_formats = $constraints['allowed_formats'] ?? array( 'jpg', 'jpeg' );
		$extension       = strtolower( $wp_filetype['ext'] );

		if ( ! in_array( $extension, $allowed_formats, true ) ) {
			return new WP_Error(
				'invalid_format',
				sprintf(
					/* translators: %s: comma-separated list of allowed formats */
					__( 'Invalid file format. Allowed formats: %s', 'photo-competition-manager' ),
					implode( ', ', $allowed_formats )
				)
			);
		}

		// Build allowed MIME types from allowed formats.
		$allowed_mimes = $this->get_allowed_mimes( $allowed_formats );

		// Verify MIME type matches one of the allowed types.
		if ( ! in_array( $wp_filetype['type'], $allowed_mimes, true ) ) {
			return new WP_Error(
				'invalid_mime',
				sprintf(
					/* translators: %s: detected MIME type */
					__( 'Invalid image type. Detected type: %s', 'photo-competition-manager' ),
					$wp_filetype['type']
				)
			);
		}

		// Verify actual image content.
		$image_info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $image_info ) {
			return new WP_Error( 'invalid_image', __( 'File is not a valid image.', 'photo-competition-manager' ) );
		}

		// Note: Dimension validation removed - images will be automatically resized to max dimensions during processing.

		return true;
	}

	/**
	 * Get allowed MIME types from file extensions.
	 *
	 * @param array<string> $formats Array of file extensions (e.g., ['jpg', 'jpeg', 'png']).
	 * @return array<string> Array of MIME types.
	 */
	private function get_allowed_mimes( array $formats ): array {
		$mime_map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
		);

		$allowed_mimes = array();
		foreach ( $formats as $format ) {
			$format = strtolower( $format );
			if ( isset( $mime_map[ $format ] ) ) {
				$allowed_mimes[] = $mime_map[ $format ];
			}
		}

		// Remove duplicates (e.g., jpg and jpeg both map to image/jpeg).
		return array_unique( $allowed_mimes );
	}

	/**
	 * Process and store uploaded image.
	 *
	 * Saves the original to the media library, then a resized copy and its thumbnail in the directory given.
	 *
	 * This doesn't validate the file. Call validate() first, before creating the directory: the
	 * upload's MIME type, extension and size checks depend on it. The Entries module does both.
	 *
	 * @internal Use Entries::add(), which validates the upload first.
	 *
	 * @since 0.4.0 Takes the directory, filename and original's title and meta instead of working them out, and no longer validates.
	 *
	 * @param array<string, mixed> $file        Uploaded file array from $_FILES.
	 * @param string               $directory   Existing directory to save the resized image and thumbnail in.
	 * @param string               $filename    Name to give the resized image, suffixed if the directory has it already.
	 * @param string               $title       Title for the original's media library attachment.
	 * @param array<string, mixed> $meta        Post meta for the original's attachment, written as soon as it exists.
	 * @param array<string, mixed> $constraints Upload constraints from settings.
	 * @return array<string, mixed>|WP_Error Array with 'filename' and 'attachment_id' on success, WP_Error on failure.
	 */
	public function process( array $file, string $directory, string $filename, string $title, array $meta, array $constraints ) {
		// Save original to media library first.
		$attachment_id = $this->save_original_to_media_library( $file, $filename, $title, $meta, $constraints );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Load image and resize to max dimensions for slideshow.
		$image = wp_get_image_editor( $file['tmp_name'] );
		if ( is_wp_error( $image ) ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'image_processing_failed', __( 'Could not process image.', 'photo-competition-manager' ) );
		}

		// Always resize to max dimensions to ensure file size compliance.
		$max_width  = $constraints['max_width'] ?? 1920;
		$max_height = $constraints['max_height'] ?? 1920;
		$image->resize( $max_width, $max_height, false );

		// Suffix the name when another entry has it. The name is picked here, just before
		// the write, to keep the gap for a concurrent upload small.
		$filename    = wp_unique_filename( $directory, $filename );
		$target_path = trailingslashit( $directory ) . $filename;
		$saved       = $image->save( $target_path );

		if ( is_wp_error( $saved ) ) {
			wp_delete_attachment( $attachment_id, true );
			return new WP_Error( 'save_failed', __( 'Could not save image.', 'photo-competition-manager' ) );
		}

		// Generate thumbnail.
		$this->generate_thumbnail( $target_path, $directory );

		return array(
			'filename'      => $filename,
			'attachment_id' => $attachment_id,
		);
	}

	/**
	 * Save original image to WordPress media library.
	 *
	 * @param array<string, mixed> $file        Uploaded file array from $_FILES.
	 * @param string               $filename    Name of the resized image; the original is named after it.
	 * @param string               $title       Attachment title.
	 * @param array<string, mixed> $meta        Post meta for the attachment.
	 * @param array<string, mixed> $constraints Upload constraints from settings.
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	private function save_original_to_media_library( array $file, string $filename, string $title, array $meta, array $constraints ) {
		if ( ! function_exists( 'wp_crop_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		// Get original constraints.
		$max_width  = $constraints['originals_max_width'] ?? 3840;
		$max_height = $constraints['originals_max_height'] ?? 3840;
		$quality    = $constraints['originals_quality'] ?? 90;

		// Load and potentially resize the original.
		$image = wp_get_image_editor( $file['tmp_name'] );
		if ( is_wp_error( $image ) ) {
			return new WP_Error( 'original_processing_failed', __( 'Could not process original image.', 'photo-competition-manager' ) );
		}

		// Get current dimensions.
		$size           = $image->get_size();
		$current_width  = $size['width'];
		$current_height = $size['height'];

		// Only resize if larger than max dimensions.
		if ( $current_width > $max_width || $current_height > $max_height ) {
			$image->resize( $max_width, $max_height, false );
		}

		// Set quality.
		$image->set_quality( $quality );

		$original_filename = pathinfo( $filename, PATHINFO_FILENAME ) . '-original.jpg';

		// The month folder is shared by every competition, so take a name no other original has.
		$upload_dir = wp_upload_dir();
		$temp_file  = trailingslashit( $upload_dir['path'] ) . wp_unique_filename( $upload_dir['path'], $original_filename );

		// Save the processed original to temp location.
		$saved = $image->save( $temp_file );
		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'original_save_failed', __( 'Could not save original image.', 'photo-competition-manager' ) );
		}

		$attachment = array(
			'guid'           => $upload_dir['url'] . '/' . basename( $temp_file ),
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'meta_input'     => $meta,
		);

		// Insert the attachment.
		$attachment_id = wp_insert_attachment( $attachment, $temp_file );

		if ( is_wp_error( $attachment_id ) || 0 === $attachment_id ) {
			// Clean up temp file.
			wp_delete_file( $temp_file );
			return new WP_Error( 'attachment_insert_failed', __( 'Could not create media library attachment.', 'photo-competition-manager' ) );
		}

		// Generate attachment metadata.
		// Buffer output to prevent exif_read_data() warnings from corrupting REST API JSON responses.
		ob_start();
		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $temp_file );
		ob_end_clean();
		wp_update_attachment_metadata( $attachment_id, $attachment_data );

		return $attachment_id;
	}

	/**
	 * Generate thumbnail for image.
	 *
	 * @param string $source_path  Full path to source image.
	 * @param string $target_dir   Directory to save thumbnail.
	 * @param int    $thumb_width  Thumbnail width (default 400).
	 * @param int    $thumb_height Thumbnail height (default 400).
	 * @return bool|WP_Error
	 */
	public function generate_thumbnail( string $source_path, string $target_dir, int $thumb_width = 400, int $thumb_height = 400 ) {
		$image = wp_get_image_editor( $source_path );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$image->resize( $thumb_width, $thumb_height, false );

		$filename       = basename( $source_path );
		$thumb_filename = self::get_thumbnail_filename( $filename );
		$thumb_path     = trailingslashit( $target_dir ) . $thumb_filename;

		$saved = $image->save( $thumb_path );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return true;
	}

	/**
	 * Determine the thumbnail filename for a stored image filename.
	 *
	 * Appends a `-thumb` suffix before the lowercased file extension
	 * (e.g. `photo.jpg` becomes `photo-thumb.jpg`). This is the single naming
	 * rule used both when thumbnails are written and when they are looked up.
	 *
	 * @param string $filename Base filename.
	 * @return string
	 */
	public static function get_thumbnail_filename( string $filename ): string {
		$info = pathinfo( $filename );
		$base = $info['filename'] ?? $filename;
		$ext  = isset( $info['extension'] ) && '' !== $info['extension'] ? '.' . strtolower( $info['extension'] ) : '';

		return $base . '-thumb' . $ext;
	}
}
