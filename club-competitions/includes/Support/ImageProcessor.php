<?php
/**
 * Image processing and storage handler.
 *
 * @package ClubCompetitions\Support
 */

namespace ClubCompetitions\Support;

use WP_Error;

class ImageProcessor {

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
			return new WP_Error( 'upload_error', __( 'File upload failed.', 'club-competitions' ) );
		}

		if ( empty( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'No file was uploaded.', 'club-competitions' ) );
		}

		// Check if file exists (works for both real uploads and test files).
		if ( ! file_exists( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'No file was uploaded.', 'club-competitions' ) );
		}

		// Check file size.
		$max_size_bytes = ( $constraints['max_file_size_mb'] ?? 5 ) * 1024 * 1024;
		if ( $file['size'] > $max_size_bytes ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %d: maximum file size in MB */
					__( 'File size exceeds maximum of %d MB.', 'club-competitions' ),
					$constraints['max_file_size_mb'] ?? 5
				)
			);
		}

		// Check file type.
		$allowed_formats = $constraints['allowed_formats'] ?? array( 'jpg', 'jpeg' );
		$filetype        = wp_check_filetype( $file['name'] );
		$extension       = strtolower( $filetype['ext'] ?? '' );

		if ( ! in_array( $extension, $allowed_formats, true ) ) {
			return new WP_Error(
				'invalid_format',
				sprintf(
					/* translators: %s: comma-separated list of allowed formats */
					__( 'Invalid file format. Allowed formats: %s', 'club-competitions' ),
					implode( ', ', $allowed_formats )
				)
			);
		}

		// Verify actual image.
		$image_info = @getimagesize( $file['tmp_name'] );
		if ( false === $image_info ) {
			return new WP_Error( 'invalid_image', __( 'File is not a valid image.', 'club-competitions' ) );
		}

		// Note: Dimension validation removed - images will be automatically resized to max dimensions during processing.

		return true;
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
	 * @return string|WP_Error Stored filename on success, WP_Error on failure.
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

		// Load image and resize to max dimensions.
		$image = wp_get_image_editor( $file['tmp_name'] );
		if ( is_wp_error( $image ) ) {
			return new WP_Error( 'image_processing_failed', __( 'Could not process image.', 'club-competitions' ) );
		}

		// Always resize to max dimensions to ensure file size compliance.
		$max_width  = $constraints['max_width'] ?? 1920;
		$max_height = $constraints['max_height'] ?? 1920;
		$image->resize( $max_width, $max_height, false );

		// Save image.
		$target_path = trailingslashit( $upload_dir['path'] ) . $filename;
		$saved       = $image->save( $target_path );

		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'save_failed', __( 'Could not save image.', 'club-competitions' ) );
		}

		// Generate thumbnail.
		$this->generate_thumbnail( $target_path, $upload_dir['path'] );

		return $filename;
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
				return new WP_Error( 'mkdir_failed', __( 'Could not create upload directory.', 'club-competitions' ) );
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

		return sprintf( '%s-%s-%d.jpg', $safe_username, $safe_category, $counter );
	}

	/**
	 * Generate thumbnail for image.
	 *
	 * @param string $source_path  Full path to source image.
	 * @param string $target_dir   Directory to save thumbnail.
	 * @param int    $thumb_width  Thumbnail width (default 300).
	 * @param int    $thumb_height Thumbnail height (default 300).
	 * @return bool|WP_Error
	 */
	public function generate_thumbnail( string $source_path, string $target_dir, int $thumb_width = 300, int $thumb_height = 300 ) {
		$image = wp_get_image_editor( $source_path );
		if ( is_wp_error( $image ) ) {
			return $image;
		}

		$image->resize( $thumb_width, $thumb_height, true );

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
	 * @return bool
	 */
	public function delete_files( string $competition_slug, string $category_slug, string $filename ): bool {
		$upload_dir = $this->get_upload_directory( $competition_slug, $category_slug );
		if ( is_wp_error( $upload_dir ) ) {
			return false;
		}

		$image_path = trailingslashit( $upload_dir['path'] ) . $filename;
		$thumb_name = $this->generate_thumbnail_filename( $filename );
		$thumb_path = trailingslashit( $upload_dir['path'] ) . $thumb_name;

		$deleted = true;

		if ( file_exists( $image_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			$deleted = $deleted && unlink( $image_path );
		}

		if ( file_exists( $thumb_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink
			$deleted = $deleted && unlink( $thumb_path );
		}

		return $deleted;
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
		return $this->get_image_url( $competition_slug, $category_slug, $thumb_filename );
	}
}
