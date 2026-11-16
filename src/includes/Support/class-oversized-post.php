<?php
/**
 * Spot a POST that PHP emptied because it was over post_max_size.
 *
 * @package PhotoCompetitionManager\Support
 */

namespace PhotoCompetitionManager\Support;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * A POST over post_max_size reaches the plugin with $_POST and $_FILES empty,
 * so it looks like a request that sent nothing.
 *
 * @since 0.4.0
 */
class Oversized_Post {

	/**
	 * Whether this request is a POST whose body PHP dropped for being over post_max_size.
	 *
	 * @since 0.4.0
	 *
	 * @return bool
	 */
	public static function detected(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only checks whether the body is empty.
		if ( ! empty( $_POST ) || ! empty( $_FILES ) ) {
			return false;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			return false;
		}

		$post_max_size  = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( $_SERVER['CONTENT_LENGTH'] ) : 0;

		// PHP only applies post_max_size when it's above 0.
		return $post_max_size > 0 && $content_length > $post_max_size;
	}
}
