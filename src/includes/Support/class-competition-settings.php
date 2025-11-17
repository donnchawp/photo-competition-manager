<?php
/**
 * Competition settings helper.
 *
 * @package PhotoCompetitionManager\Support
 */

namespace PhotoCompetitionManager\Support;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;

/**
 * Class Competition_Settings
 *
 * @package PhotoCompetitionManager\Support
 */
class Competition_Settings {

	/**
	 * Get global default settings from WordPress options.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_global_defaults(): array {
		$saved = get_option( 'photo_comp_default_settings', '' );

		if ( empty( $saved ) ) {
			return self::defaults();
		}

		$decoded = json_decode( $saved, true );

		if ( ! is_array( $decoded ) ) {
			return self::defaults();
		}

		// Merge with hard-coded defaults to ensure structure is complete.
		return array_replace_recursive( self::defaults(), $decoded );
	}

	/**
	 * Default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'categories'      => array(
				array(
					'slug'  => 'colour',
					'label' => __( 'Colour', 'photo-competition-manager' ),
					'quota' => 1,
				),
				array(
					'slug'  => 'black-white',
					'label' => __( 'Black & White', 'photo-competition-manager' ),
					'quota' => 1,
				),
			),
			'grades'          => array(
				array(
					'slug'  => 'beginner',
					'label' => __( 'Beginner', 'photo-competition-manager' ),
				),
				array(
					'slug'  => 'intermediate',
					'label' => __( 'Intermediate', 'photo-competition-manager' ),
				),
				array(
					'slug'  => 'advanced',
					'label' => __( 'Advanced', 'photo-competition-manager' ),
				),
			),
			'upload'          => array(
				'max_file_size_mb'     => 5,
				'max_width'            => 1920,
				'max_height'           => 1920,
				'allowed_formats'      => array( 'jpg', 'jpeg' ),
				'originals_max_width'  => 3840,
				'originals_max_height' => 3840,
				'originals_quality'    => 90,
			),
			'voting'          => array(
				'score_matrix'        => array( 9, 8, 7, 6, 5 ),
				'open_categories'     => array(), // Array of category slugs where voting is open.
				'auth_mode'           => 'password', // 'password' or 'token' (email magic links).
				'password'            => '',
				'click_image_to_zoom' => false, // Whether images are clickable to open full-size in voting form.
				'ui_type'             => 'default',
			),
			'slideshow'       => array(
				'duration_seconds' => 10,
			),
			'email_reminders' => array(
				'enabled'                => true,
				'days_before_open'       => 7,
				'days_before_close'      => 1,
				'include_qr_code_voting' => true,
			),
			'urls'            => array(
				'upload_page' => '',
				'voting_page' => '',
			),
			'results'         => array(
				'results_visible' => false, // Whether results are displayed on frontend.
			),
		);
	}

	/**
	 * Parse stored settings JSON.
	 *
	 * @param string|null $json Settings JSON string.
	 * @return array<string, mixed>
	 */
	public static function parse( ?string $json ): array {
		if ( empty( $json ) ) {
			return self::defaults();
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return self::defaults();
		}

		$merged = self::merge_with_defaults( $decoded );

		// Auto-detect page URLs if not set.
		$merged = self::auto_detect_page_urls( $merged );

		return $merged;
	}

	/**
	 * Merge parsed settings with defaults.
	 *
	 * @param array<string, mixed> $settings User settings.
	 * @return array<string, mixed>
	 */
	private static function merge_with_defaults( array $settings ): array {
		$defaults = self::defaults();

		// For arrays like categories and grades, replace entirely rather than merge.
		foreach ( array( 'categories', 'grades' ) as $key ) {
			if ( isset( $settings[ $key ] ) ) {
				$defaults[ $key ] = $settings[ $key ];
				unset( $settings[ $key ] );
			}
		}

		// For other nested arrays, merge recursively.
		return array_replace_recursive( $defaults, $settings );
	}

	/**
	 * Validate settings array.
	 *
	 * @param array<string, mixed> $settings Settings to validate.
	 * @param bool                 $require_categories_grades Whether to require at least one category/grade.
	 * @return true|WP_Error
	 */
	public static function validate( array $settings, bool $require_categories_grades = true ) {
		if ( ! isset( $settings['categories'] ) || ! is_array( $settings['categories'] ) ) {
			return new WP_Error( 'invalid_categories', __( 'Categories must be an array.', 'photo-competition-manager' ) );
		}

		if ( $require_categories_grades && empty( $settings['categories'] ) ) {
			return new WP_Error( 'missing_categories', __( 'At least one category is required.', 'photo-competition-manager' ) );
		}

		foreach ( $settings['categories'] as $index => $category ) {
			if ( ! is_array( $category ) ) {
				return new WP_Error(
					'invalid_category',
					sprintf(
						/* translators: %d: category index */
						__( 'Category %d must be an array.', 'photo-competition-manager' ),
						$index
					)
				);
			}

			if ( empty( $category['slug'] ) || empty( $category['label'] ) ) {
				return new WP_Error(
					'missing_category_fields',
					sprintf(
						/* translators: %d: category index */
						__( 'Category %d must have slug and label.', 'photo-competition-manager' ),
						$index
					)
				);
			}

			if ( ! isset( $category['quota'] ) || ! is_numeric( $category['quota'] ) || $category['quota'] < 1 ) {
				return new WP_Error(
					'invalid_quota',
					sprintf(
						/* translators: %s: category label */
						__( 'Category "%s" must have a quota of at least 1.', 'photo-competition-manager' ),
						$category['label']
					)
				);
			}
		}

		if ( ! isset( $settings['grades'] ) || ! is_array( $settings['grades'] ) ) {
			return new WP_Error( 'invalid_grades', __( 'Grades must be an array.', 'photo-competition-manager' ) );
		}

		if ( $require_categories_grades && empty( $settings['grades'] ) ) {
			return new WP_Error( 'missing_grades', __( 'At least one grade is required.', 'photo-competition-manager' ) );
		}

		foreach ( $settings['grades'] as $index => $grade ) {
			if ( ! is_array( $grade ) ) {
				return new WP_Error(
					'invalid_grade',
					sprintf(
						/* translators: %d: grade index */
						__( 'Grade %d must be an array.', 'photo-competition-manager' ),
						$index
					)
				);
			}

			if ( empty( $grade['slug'] ) || empty( $grade['label'] ) ) {
				return new WP_Error(
					'missing_grade_fields',
					sprintf(
						/* translators: %d: grade index */
						__( 'Grade %d must have slug and label.', 'photo-competition-manager' ),
						$index
					)
				);
			}
		}

		if ( isset( $settings['upload']['max_file_size_mb'] ) ) {
			if ( ! is_numeric( $settings['upload']['max_file_size_mb'] ) || $settings['upload']['max_file_size_mb'] < 1 ) {
				return new WP_Error( 'invalid_file_size', __( 'Max file size must be at least 1 MB.', 'photo-competition-manager' ) );
			}
		}

		if ( isset( $settings['voting']['score_matrix'] ) ) {
			if ( ! is_array( $settings['voting']['score_matrix'] ) || empty( $settings['voting']['score_matrix'] ) ) {
				return new WP_Error( 'invalid_score_matrix', __( 'Score matrix must be a non-empty array.', 'photo-competition-manager' ) );
			}
		}

		if ( isset( $settings['voting']['password'] ) && ! is_string( $settings['voting']['password'] ) ) {
			return new WP_Error( 'invalid_voting_password', __( 'Voting password must be a string.', 'photo-competition-manager' ) );
		}

		if ( isset( $settings['voting']['auth_mode'] ) ) {
			$valid_modes = array( 'password', 'token' );
			if ( ! in_array( $settings['voting']['auth_mode'], $valid_modes, true ) ) {
				return new WP_Error( 'invalid_auth_mode', __( 'Voting auth mode must be either "password" or "token".', 'photo-competition-manager' ) );
			}
		}

		if ( isset( $settings['voting']['ui_type'] ) ) {
			$valid_ui_types = array( 'default', 'buttons', 'dropdown' );
			if ( ! in_array( $settings['voting']['ui_type'], $valid_ui_types, true ) ) {
				return new WP_Error( 'invalid_voting_ui_type', __( 'Voting UI type must be "default", "buttons", or "dropdown".', 'photo-competition-manager' ) );
			}
		}

		return true;
	}

	/**
	 * Encode settings to JSON.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return string
	 */
	public static function encode( array $settings ): string {
		return wp_json_encode( $settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Get categories from settings.
	 *
	 * @param array<string, mixed> $settings Parsed settings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_categories( array $settings ): array {
		$categories = $settings['categories'] ?? array();

		// If empty, fall back to global defaults.
		if ( empty( $categories ) ) {
			$global_settings = self::get_global_defaults();
			$categories      = $global_settings['categories'] ?? self::defaults()['categories'];
		}

		return $categories;
	}

	/**
	 * Get grades from settings.
	 *
	 * @param array<string, mixed> $settings Parsed settings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_grades( array $settings ): array {
		$grades = $settings['grades'] ?? array();

		// If empty, fall back to global defaults.
		if ( empty( $grades ) ) {
			$global_settings = self::get_global_defaults();
			$grades          = $global_settings['grades'] ?? self::defaults()['grades'];
		}

		return $grades;
	}

	/**
	 * Get upload constraints from settings.
	 *
	 * @param array<string, mixed> $settings Parsed settings.
	 * @return array<string, mixed>
	 */
	public static function get_upload_constraints( array $settings ): array {
		return $settings['upload'] ?? self::defaults()['upload'];
	}

	/**
	 * Get voting configuration from settings.
	 *
	 * @param array<string, mixed> $settings Parsed settings.
	 * @return array<string, mixed>
	 */
	public static function get_voting_config( array $settings ): array {
		return $settings['voting'] ?? self::defaults()['voting'];
	}

	/**
	 * Get resolved voting UI type.
	 *
	 * @param array<string, mixed> $settings Competition settings array.
	 * @return string 'buttons' or 'dropdown'.
	 */
	public static function get_voting_ui_type( array $settings ): string {
		$voting_config = self::get_voting_config( $settings );
		$ui_type       = $voting_config['ui_type'] ?? 'default';

		if ( in_array( $ui_type, array( 'buttons', 'dropdown' ), true ) ) {
			return $ui_type;
		}

		$global_ui_type = get_option( 'photo_comp_voting_ui_type', 'buttons' );

		return in_array( $global_ui_type, array( 'buttons', 'dropdown' ), true ) ? $global_ui_type : 'buttons';
	}

	/**
	 * Check if voting is open for a specific category.
	 *
	 * @param array<string, mixed> $settings Parsed settings.
	 * @param string               $category Category slug.
	 * @return bool
	 */
	public static function is_voting_open_for_category( array $settings, string $category ): bool {
		$voting_config   = self::get_voting_config( $settings );
		$open_categories = $voting_config['open_categories'] ?? array();

		return in_array( $category, $open_categories, true );
	}

	/**
	 * Get categories where voting is currently open.
	 *
	 * @param array<string, mixed> $settings Parsed settings.
	 * @return array<string> Array of category slugs.
	 */
	public static function get_open_voting_categories( array $settings ): array {
		$voting_config = self::get_voting_config( $settings );
		return $voting_config['open_categories'] ?? array();
	}

	/**
	 * Set voting open status for a specific category.
	 *
	 * @param array<string, mixed> $settings Parsed settings (passed by reference).
	 * @param string               $category Category slug.
	 * @param bool                 $open     Whether voting should be open.
	 * @return true|WP_Error
	 */
	public static function set_voting_open_for_category( array &$settings, string $category, bool $open ) {
		// Verify category exists.
		$categories      = self::get_categories( $settings );
		$category_exists = false;

		foreach ( $categories as $cat ) {
			if ( $cat['slug'] === $category ) {
				$category_exists = true;
				break;
			}
		}

		if ( ! $category_exists ) {
			return new WP_Error( 'invalid_category', __( 'Category does not exist.', 'photo-competition-manager' ) );
		}

		// Initialize voting config if needed.
		if ( ! isset( $settings['voting'] ) ) {
			$settings['voting'] = self::defaults()['voting'];
		}

		if ( ! isset( $settings['voting']['open_categories'] ) ) {
			$settings['voting']['open_categories'] = array();
		}

		// Update open categories list.
		$open_categories = $settings['voting']['open_categories'];

		if ( $open ) {
			// Add category if not already present.
			if ( ! in_array( $category, $open_categories, true ) ) {
				$open_categories[] = $category;
			}
		} else {
			// Remove category if present.
			$open_categories = array_filter(
				$open_categories,
				function ( $cat ) use ( $category ) {
					return $cat !== $category;
				}
			);
			// Re-index array.
			$open_categories = array_values( $open_categories );
		}

		$settings['voting']['open_categories'] = $open_categories;

		return true;
	}

	/**
	 * Auto-detect page URLs by searching for shortcodes if URLs are not set.
	 *
	 * @param array<string, mixed> $settings Settings array.
	 * @return array<string, mixed> Settings with auto-detected URLs.
	 */
	private static function auto_detect_page_urls( array $settings ): array {
		if ( ! isset( $settings['urls'] ) ) {
			$settings['urls'] = array();
		}

		// Auto-detect upload page.
		if ( empty( $settings['urls']['upload_page'] ) ) {
			$url = self::find_page_url_with_shortcode( 'competition_upload' );
			if ( ! empty( $url ) ) {
				$settings['urls']['upload_page'] = $url;
			}
		}

		// Auto-detect voting page.
		if ( empty( $settings['urls']['voting_page'] ) ) {
			$url = self::find_page_url_with_shortcode( 'competition_voting' );
			if ( ! empty( $url ) ) {
				$settings['urls']['voting_page'] = $url;
			}
		}

		// Auto-detect results page.
		if ( empty( $settings['urls']['results_page'] ) ) {
			$url = self::find_page_url_with_shortcode( 'competition_results' );
			if ( ! empty( $url ) ) {
				$settings['urls']['results_page'] = $url;
			}
		}

		// Auto-detect top 3 page.
		if ( empty( $settings['urls']['top3_page'] ) ) {
			$url = self::find_page_url_with_shortcode( 'competition_top3' );
			if ( ! empty( $url ) ) {
				$settings['urls']['top3_page'] = $url;
			}
		}

		return $settings;
	}

	/**
	 * Find a page URL that contains a specific shortcode.
	 *
	 * @param string $shortcode_tag The shortcode tag to search for (without brackets).
	 * @return string The page URL if found, empty string otherwise.
	 */
	public static function find_page_url_with_shortcode( string $shortcode_tag ): string {
		if ( empty( $shortcode_tag ) ) {
			return '';
		}

		if ( ! function_exists( 'get_pages' ) || ! function_exists( 'has_shortcode' ) ) {
			return '';
		}

		$pages = get_pages( array( 'number' => 100 ) );
		if ( ! is_array( $pages ) ) {
			return '';
		}

		foreach ( $pages as $page ) {
			if ( ! empty( $page->post_content ) && has_shortcode( $page->post_content, $shortcode_tag ) ) {
				$url = get_permalink( $page->ID );
				return $url ? $url : '';
			}
		}

		return '';
	}
}
