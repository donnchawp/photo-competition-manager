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
	 * Shortcode tag of each competition page, keyed by its settings URL key.
	 *
	 * @var array<string, string>
	 */
	private const PAGE_SHORTCODES = array(
		'upload_page'  => 'competition_upload',
		'voting_page'  => 'competition_voting',
		'results_page' => 'competition_results',
		'top3_page'    => 'competition_top3',
	);

	/**
	 * Competition page URLs found this request, keyed by the posts "last
	 * changed" time they were found at, then by shortcode tag.
	 *
	 * @var array<string, array<string, string>>
	 */
	private static $page_urls = array();

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
				'category_steps'      => array(),
				'voted_categories'    => array(),
			),
			'slideshow'       => array(
				'duration_seconds'    => 10,
				'progress_meter_type' => 'bar',
				'preview_duration'    => 10,
				'voting_duration'     => 15,
				'critique_duration'   => 0,
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
	 * Generate a 32-character hex share hash.
	 *
	 * @return string
	 */
	public static function generate_share_hash(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Parse stored settings JSON.
	 *
	 * Empty or invalid JSON is treated the same as an empty settings object
	 * (`{}`): both flow through merge_with_defaults() and auto-detection of
	 * page URLs, so "no settings" and "empty settings" behave identically.
	 *
	 * @param string|null $json Settings JSON string.
	 * @return array<string, mixed>
	 */
	public static function parse( ?string $json ): array {
		$decoded = ( ! empty( $json ) ) ? json_decode( $json, true ) : array();

		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}

		$merged = self::merge_with_defaults( $decoded );

		// Auto-detect page URLs if not set.
		$merged = self::auto_detect_page_urls( $merged );

		return $merged;
	}

	/**
	 * Get the global default settings stored in the site options.
	 *
	 * Reads the `photo_comp_default_settings` option and returns the parsed
	 * settings array, falling back to the hard-coded defaults when the option
	 * is empty or invalid.
	 *
	 * @return array<string, mixed>
	 */
	public static function global_settings(): array {
		$saved = get_option( 'photo_comp_default_settings', '' );

		return self::parse( is_string( $saved ) ? $saved : '' );
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

		$seen_labels = array();
		$seen_slugs  = array();

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

			// Ignores case and accents, so "Ógánach" and "oganach" clash.
			$label_key = sanitize_title( $grade['label'] );
			if ( isset( $seen_labels[ $label_key ] ) || isset( $seen_slugs[ $grade['slug'] ] ) ) {
				return new WP_Error(
					'duplicate_grade',
					sprintf(
						/* translators: %s: grade label */
						__( 'The grade "%s" clashes with another grade. Each grade needs its own name.', 'photo-competition-manager' ),
						$grade['label']
					)
				);
			}

			$seen_labels[ $label_key ]    = true;
			$seen_slugs[ $grade['slug'] ] = true;
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

		if ( isset( $settings['slideshow']['progress_meter_type'] ) ) {
			$valid_meter_types = array( 'bar', 'line', 'dots', 'radial' );
			if ( ! in_array( $settings['slideshow']['progress_meter_type'], $valid_meter_types, true ) ) {
				return new WP_Error( 'invalid_meter_type', __( 'Progress meter type must be "bar", "line", "dots", or "radial".', 'photo-competition-manager' ) );
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
	 * Find a category's configuration by slug.
	 *
	 * @param array<string, mixed> $settings Parsed settings.
	 * @param string               $slug     Category slug.
	 * @return array<string, mixed>|null Category config, or null if the slug is not configured.
	 */
	public static function find_category( array $settings, string $slug ): ?array {
		foreach ( self::get_categories( $settings ) as $category ) {
			if ( $category['slug'] === $slug ) {
				return $category;
			}
		}

		return null;
	}

	/**
	 * Sanitize grade rows posted from a settings form.
	 *
	 * An existing grade posts its slug, which stays put when the label is
	 * renamed so its members stay in it. A grade added in the browser has no
	 * slug yet, and gets one made from its label, with a number added if a
	 * renamed grade still holds that slug.
	 *
	 * @since 0.4.0
	 * @param array<int, mixed> $rows Posted grade rows.
	 * @return array<int, array{label: string, slug: string}>
	 */
	public static function sanitize_grades( array $rows ): array {
		$rows = array_filter(
			$rows,
			static function ( $row ) {
				return isset( $row['label'] );
			}
		);

		$grades = array();
		foreach ( $rows as $row ) {
			$grades[] = array(
				'label' => sanitize_text_field( $row['label'] ),
				'slug'  => sanitize_title( $row['slug'] ?? '' ),
			);
		}

		$taken = array_filter( wp_list_pluck( $grades, 'slug' ) );

		foreach ( $grades as $index => $grade ) {
			if ( '' !== $grade['slug'] ) {
				continue;
			}

			$base   = sanitize_title( $grade['label'] );
			$slug   = $base;
			$suffix = 1;
			while ( in_array( $slug, $taken, true ) ) {
				++$suffix;
				$slug = $base . '-' . $suffix;
			}

			$grades[ $index ]['slug'] = $slug;
			$taken[]                  = $slug;
		}

		return $grades;
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
	 * Close voting for a category.
	 *
	 * Clears the open category, advances it to step 5, and records it as voted.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $settings       Parsed settings.
	 * @param int                  $competition_id Competition ID.
	 * @param string               $category_slug  Category slug.
	 * @return array<string, mixed> Updated settings.
	 */
	public static function close_category_voting( array $settings, int $competition_id, string $category_slug ): array {
		$settings['voting']['open_categories']                  = array();
		$settings['voting']['category_steps'][ $category_slug ] = 5;

		return self::mark_category_voted( $settings, $competition_id, $category_slug );
	}

	/**
	 * Record a category as voted, once.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $settings       Parsed settings.
	 * @param int                  $competition_id Competition ID.
	 * @param string               $category_slug  Category slug.
	 * @return array<string, mixed> Updated settings.
	 */
	public static function mark_category_voted( array $settings, int $competition_id, string $category_slug ): array {
		$category_key = $competition_id . '_' . $category_slug;
		if ( ! in_array( $category_key, $settings['voting']['voted_categories'] ?? array(), true ) ) {
			$settings['voting']['voted_categories'][] = $category_key;
		}

		return $settings;
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

		foreach ( self::PAGE_SHORTCODES as $url_key => $shortcode_tag ) {
			if ( empty( $settings['urls'][ $url_key ] ) ) {
				$url = self::find_page_url_with_shortcode( $shortcode_tag );
				if ( ! empty( $url ) ) {
					$settings['urls'][ $url_key ] = $url;
				}
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

		if ( in_array( $shortcode_tag, self::PAGE_SHORTCODES, true ) ) {
			return self::competition_page_urls()[ $shortcode_tag ];
		}

		return self::find_page_urls( array( $shortcode_tag ) )[ $shortcode_tag ];
	}

	/**
	 * Get the URLs of the competition shortcode pages.
	 *
	 * Every parse() looks these up, so they are found in one pass over the
	 * pages and kept for the rest of the request. Any post change bumps the
	 * posts "last changed" time, which makes the next call look again.
	 *
	 * @return array<string, string> Page URLs keyed by shortcode tag.
	 */
	private static function competition_page_urls(): array {
		$last_changed = wp_cache_get_last_changed( 'posts' );

		if ( ! isset( self::$page_urls[ $last_changed ] ) ) {
			self::$page_urls = array( $last_changed => self::find_page_urls( array_values( self::PAGE_SHORTCODES ) ) );
		}

		return self::$page_urls[ $last_changed ];
	}

	/**
	 * Find the first page containing each shortcode, in one pass over the pages.
	 *
	 * @param array<int, string> $shortcode_tags Shortcode tags to search for.
	 * @return array<string, string> Page URLs keyed by shortcode tag, '' when not found.
	 */
	private static function find_page_urls( array $shortcode_tags ): array {
		$urls  = array();
		$pages = get_pages( array( 'number' => 100 ) );

		foreach ( is_array( $pages ) ? $pages : array() as $page ) {
			if ( empty( $page->post_content ) ) {
				continue;
			}

			foreach ( $shortcode_tags as $shortcode_tag ) {
				if ( ! isset( $urls[ $shortcode_tag ] ) && has_shortcode( $page->post_content, $shortcode_tag ) ) {
					$url                    = get_permalink( $page->ID );
					$urls[ $shortcode_tag ] = $url ? $url : '';
				}
			}
		}

		return $urls + array_fill_keys( $shortcode_tags, '' );
	}
}
