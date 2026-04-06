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
		// Check upload error first.
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
	 * @param array<string, mixed> $file            Uploaded file array from $_FILES.
	 * @param string               $competition_slug Competition slug for directory.
	 * @param string               $category_slug   Category slug for directory.
	 * @param string               $username        Member username for filename.
	 * @param int                  $counter         Counter for filename uniqueness.
	 * @param array<string, mixed> $constraints     Upload constraints from settings.
	 * @return array<string, mixed>|WP_Error Array with 'filename' and 'attachment_id' on success, WP_Error on failure.
	 */
	public function process( array $file, string $competition_slug, string $category_slug, string $username, int $counter, array $constraints ) {
		$validation = $this->validate( $file, $constraints );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Create target directory.
		$upload_dir = $this->get_upload_directory( $competition_slug, $category_slug );
		if ( is_wp_error( $upload_dir ) ) {
			return $upload_dir;
		}

		// Generate filename: username-categoryslug-[counter].jpg.
		$filename = $this->generate_filename( $username, $category_slug, $counter );

		// Save original to media library first.
		$attachment_id = $this->save_original_to_media_library( $file, $competition_slug, $category_slug, $username, $counter, $constraints );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Load image and resize to max dimensions for slideshow.
		$image = wp_get_image_editor( $file['tmp_name'] );
		if ( is_wp_error( $image ) ) {
			return new WP_Error( 'image_processing_failed', __( 'Could not process image.', 'photo-competition-manager' ) );
		}

		// Always resize to max dimensions to ensure file size compliance.
		$max_width  = $constraints['max_width'] ?? 1920;
		$max_height = $constraints['max_height'] ?? 1920;
		$image->resize( $max_width, $max_height, false );

		// Save slideshow image.
		$target_path = trailingslashit( $upload_dir['path'] ) . $filename;
		$saved       = $image->save( $target_path );

		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'save_failed', __( 'Could not save image.', 'photo-competition-manager' ) );
		}

		// Generate thumbnail.
		$this->generate_thumbnail( $target_path, $upload_dir['path'] );

		return array(
			'filename'      => $filename,
			'attachment_id' => $attachment_id,
		);
	}

	/**
	 * Save original image to WordPress media library.
	 *
	 * @param array<string, mixed> $file            Uploaded file array from $_FILES.
	 * @param string               $competition_slug Competition slug.
	 * @param string               $category_slug   Category slug.
	 * @param string               $username        Member username.
	 * @param int                  $counter         Counter for filename uniqueness.
	 * @param array<string, mixed> $constraints     Upload constraints from settings.
	 * @return int|WP_Error Attachment ID on success, WP_Error on failure.
	 */
	private function save_original_to_media_library( array $file, string $competition_slug, string $category_slug, string $username, int $counter, array $constraints ) {
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

		// Generate filename for original.
		$original_filename = $counter > 0
			? sprintf( '%s-%s-%d-original.jpg', sanitize_title( $username ), sanitize_title( $category_slug ), $counter )
			: sprintf( '%s-%s-original.jpg', sanitize_title( $username ), sanitize_title( $category_slug ) );

		// Create a temporary file.
		$upload_dir = wp_upload_dir();
		$temp_file  = trailingslashit( $upload_dir['path'] ) . $original_filename;

		// Save the processed original to temp location.
		$saved = $image->save( $temp_file );
		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'original_save_failed', __( 'Could not save original image.', 'photo-competition-manager' ) );
		}

		// Prepare attachment data.
		$attachment_title = $counter > 0
			? sprintf( '%s - %s - %s #%d', $competition_slug, $category_slug, $username, $counter )
			: sprintf( '%s - %s - %s', $competition_slug, $category_slug, $username );

		$attachment = array(
			'guid'           => $upload_dir['url'] . '/' . basename( $temp_file ),
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $attachment_title,
			'post_content'   => '',
			'post_status'    => 'inherit',
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

		// Add competition and category metadata.
		update_post_meta( $attachment_id, '_photo_comp_slug', $competition_slug );
		update_post_meta( $attachment_id, '_photo_comp_category', $category_slug );
		update_post_meta( $attachment_id, '_photo_comp_member', $username );

		return $attachment_id;
	}

	/**
	 * Get or create upload directory for competition and category.
	 *
	 * @param string $competition_slug Competition slug.
	 * @param string $category_slug    Category slug.
	 * @return array<string, string>|WP_Error Array with 'path' and 'url' keys, or WP_Error.
	 */
	public function get_upload_directory( string $competition_slug, string $category_slug ) {
		$wp_upload_dir = wp_upload_dir();
		if ( $wp_upload_dir['error'] ) {
			return new WP_Error( 'upload_dir_error', $wp_upload_dir['error'] );
		}

		// Security: Explicitly check for path traversal sequences.
		if ( strpos( $competition_slug, '..' ) !== false || strpos( $category_slug, '..' ) !== false ) {
			return new WP_Error( 'invalid_path', __( 'Invalid directory name.', 'photo-competition-manager' ) );
		}

		$base_path = trailingslashit( $wp_upload_dir['basedir'] ) . 'competitions';
		$base_url  = trailingslashit( $wp_upload_dir['baseurl'] ) . 'competitions';

		$competition_path = trailingslashit( $base_path ) . sanitize_file_name( $competition_slug );
		$competition_url  = trailingslashit( $base_url ) . sanitize_file_name( $competition_slug );

		$category_path = trailingslashit( $competition_path ) . sanitize_file_name( $category_slug );
		$category_url  = trailingslashit( $competition_url ) . sanitize_file_name( $category_slug );

		// Create directories if they don't exist.
		if ( ! file_exists( $category_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			if ( ! wp_mkdir_p( $category_path ) ) {
				return new WP_Error( 'mkdir_failed', __( 'Could not create upload directory.', 'photo-competition-manager' ) );
			}

			// Add index.php to prevent directory browsing.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( trailingslashit( $category_path ) . 'index.php', '<?php // Silence is golden.' );
		}

		return array(
			'path' => $category_path,
			'url'  => $category_url,
		);
	}

	/**
	 * Generate filename following PRD spec: username-categoryslug-[counter].jpg.
	 *
	 * @param string $username      Member username.
	 * @param string $category_slug Category slug.
	 * @param int    $counter       Counter for uniqueness.
	 * @return string
	 */
	public function generate_filename( string $username, string $category_slug, int $counter ): string {
		// Sanitize and make lowercase with dashes instead of spaces.
		$safe_username = sanitize_title( $username );
		$safe_category = sanitize_title( $category_slug );

		// Security: Explicitly check for path traversal sequences after sanitization.
		if ( strpos( $safe_username, '..' ) !== false || strpos( $safe_category, '..' ) !== false ) {
			$safe_username = str_replace( '..', '', $safe_username );
			$safe_category = str_replace( '..', '', $safe_category );
		}

		if ( $counter > 0 ) {
			return sprintf( '%s-%s-%d.jpg', $safe_username, $safe_category, $counter );
		}

		return sprintf( '%s-%s.jpg', $safe_username, $safe_category );
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
		$thumb_filename = $this->generate_thumbnail_filename( $filename );
		$thumb_path     = trailingslashit( $target_dir ) . $thumb_filename;

		$saved = $image->save( $thumb_path );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return true;
	}

	/**
	 * Derive thumbnail filename for an image.
	 *
	 * @param string $filename Original filename.
	 * @return string
	 */
	public function generate_thumbnail_filename( string $filename ): string {
		$info = pathinfo( $filename );
		$base = $info['filename'] ?? $filename;
		$ext  = isset( $info['extension'] ) && '' !== $info['extension'] ? '.' . strtolower( $info['extension'] ) : '';

		return $base . '-thumb' . $ext;
	}

	/**
	 * Delete image and thumbnail files.
	 *
	 * @param string $competition_slug Competition slug.
	 * @param string $category_slug    Category slug.
	 * @param string $filename         Filename to delete.
	 * @param int    $attachment_id    Optional attachment ID to delete from media library.
	 * @return bool
	 */
	public function delete_files( string $competition_slug, string $category_slug, string $filename, int $attachment_id = 0 ): bool {
		$upload_dir = $this->get_upload_directory( $competition_slug, $category_slug );
		if ( is_wp_error( $upload_dir ) ) {
			return false;
		}

		$image_path = trailingslashit( $upload_dir['path'] ) . $filename;
		$thumb_name = $this->generate_thumbnail_filename( $filename );
		$thumb_path = trailingslashit( $upload_dir['path'] ) . $thumb_name;

		$deleted = true;

		if ( file_exists( $image_path ) ) {
			$deleted = $deleted && wp_delete_file( $image_path );
		}

		if ( file_exists( $thumb_path ) ) {
			$deleted = $deleted && wp_delete_file( $thumb_path );
		}

		// Delete original from media library if provided.
		if ( $attachment_id > 0 ) {
			$deleted = $deleted && ( false !== wp_delete_attachment( $attachment_id, true ) );
		}

		return $deleted;
	}

	/**
	 * Delete only the original attachment from media library.
	 *
	 * Keeps the slideshow image and thumbnail intact.
	 *
	 * @param int $attachment_id Attachment ID to delete.
	 * @return bool True on success, false on failure.
	 */
	public function delete_original_attachment( int $attachment_id ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}

		return false !== wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * Get URL for image.
	 *
	 * @param string $competition_slug Competition slug.
	 * @param string $category_slug    Category slug.
	 * @param string $filename         Filename.
	 * @return string|WP_Error
	 */
	public function get_image_url( string $competition_slug, string $category_slug, string $filename ) {
		$upload_dir = $this->get_upload_directory( $competition_slug, $category_slug );
		if ( is_wp_error( $upload_dir ) ) {
			return $upload_dir;
		}

		return trailingslashit( $upload_dir['url'] ) . $filename;
	}

	/**
	 * Get URL for thumbnail.
	 *
	 * @param string $competition_slug Competition slug.
	 * @param string $category_slug    Category slug.
	 * @param string $filename         Filename.
	 * @return string|WP_Error
	 */
	public function get_thumbnail_url( string $competition_slug, string $category_slug, string $filename ) {
		$thumb_filename = str_replace( '.jpg', '-thumb.jpg', $filename );
		$url            = $this->get_image_url( $competition_slug, $category_slug, $thumb_filename );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		// Add cache-busting parameter based on file modification time.
		$upload_dir = $this->get_upload_directory( $competition_slug, $category_slug );
		if ( ! is_wp_error( $upload_dir ) ) {
			$file_path = trailingslashit( $upload_dir['path'] ) . $thumb_filename;
			if ( file_exists( $file_path ) ) {
				$mtime = filemtime( $file_path );
				$url   = add_query_arg( 'v', $mtime, $url );
			}
		}

		return $url;
	}

	/**
	 * Move image files between categories.
	 *
	 * Moves both the main image and thumbnail from one category folder to another.
	 *
	 * @param string $competition_slug Competition slug.
	 * @param string $old_category     Old category slug.
	 * @param string $new_category     New category slug.
	 * @param string $filename         Image filename.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function move_image_between_categories( string $competition_slug, string $old_category, string $new_category, string $filename ) {
		// Get source directory.
		$source_dir = $this->get_upload_directory( $competition_slug, $old_category );
		if ( is_wp_error( $source_dir ) ) {
			return $source_dir;
		}

		// Get destination directory.
		$dest_dir = $this->get_upload_directory( $competition_slug, $new_category );
		if ( is_wp_error( $dest_dir ) ) {
			return $dest_dir;
		}

		$source_path = trailingslashit( $source_dir['path'] );
		$dest_path   = trailingslashit( $dest_dir['path'] );

		// Move main image.
		$source_file = $source_path . $filename;
		$dest_file   = $dest_path . $filename;

		if ( file_exists( $source_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! rename( $source_file, $dest_file ) ) {
				return new WP_Error( 'move_failed', __( 'Failed to move image file.', 'photo-competition-manager' ) );
			}
		}

		// Move thumbnail.
		$thumb_filename = str_replace( '.jpg', '-thumb.jpg', $filename );
		$source_thumb   = $source_path . $thumb_filename;
		$dest_thumb     = $dest_path . $thumb_filename;

		if ( file_exists( $source_thumb ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! rename( $source_thumb, $dest_thumb ) ) {
				// Rollback: Move main image back if thumbnail move fails.
				if ( file_exists( $dest_file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
					rename( $dest_file, $source_file );
				}
				return new WP_Error( 'move_thumb_failed', __( 'Failed to move thumbnail file.', 'photo-competition-manager' ) );
			}
		}

		return true;
	}
}
