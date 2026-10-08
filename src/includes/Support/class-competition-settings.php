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
				'auth_mode'           => 'password', // 'password' or 'token' (email magic links).
				'password'            => '',
				'click_image_to_zoom' => false, // Whether images are clickable to open full-size in voting form.
				'ui_type'             => 'default',
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
		$merged = self::merge_with_defaults( self::decode( $json ) );

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
		return self::parse( self::stored_club_settings() );
	}

	/**
	 * The club's settings JSON as stored in the site options.
	 *
	 * @return string
	 */
	private static function stored_club_settings(): string {
		$saved = get_option( 'photo_comp_default_settings', '' );

		return is_string( $saved ) ? $saved : '';
	}

	/**
	 * Decode settings JSON, treating empty or invalid JSON as no settings.
	 *
	 * @param string|null $json Settings JSON.
	 * @return array<string, mixed>
	 */
	private static function decode( ?string $json ): array {
		$decoded = ( ! empty( $json ) ) ? json_decode( $json, true ) : array();

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Merge parsed settings with defaults.
	 *
	 * @param array<string, mixed> $settings User settings.
	 * @return array<string, mixed>
	 */
	private static function merge_with_defaults( array $settings ): array {
		$defaults = self::defaults();

		// Grades and categories are read through club_grades() and
		// get_categories(), which fall back to the club's lists, so the
		// built-in lists aren't filled in.
		unset( $defaults['grades'], $defaults['categories'] );

		return self::merge_recursive( $defaults, $settings );
	}

	/**
	 * Merge stored settings over defaults, key by key.
	 *
	 * A default that's a list, such as the score matrix, is replaced whole.
	 * array_replace_recursive() would merge it by position, so a stored
	 * [10, 8, 6] would keep the default's trailing 6 and 5. A section such
	 * as `voting` is merged key by key. An empty stored array keeps its
	 * default, so a section stored as [] by wp_json_encode() is filled in,
	 * and an empty score matrix can't leave voting with no scores. An empty
	 * default counts as a list, so a map that must merge with its default
	 * needs at least one default key.
	 *
	 * @since 0.4.0
	 * @param array<string, mixed> $defaults Default values.
	 * @param array<string, mixed> $stored   Stored values.
	 * @return array<string, mixed>
	 */
	private static function merge_recursive( array $defaults, array $stored ): array {
		foreach ( $stored as $key => $value ) {
			if ( array() === $value && is_array( $defaults[ $key ] ?? null ) ) {
				continue;
			}

			if ( is_array( $value ) && is_array( $defaults[ $key ] ?? null ) && ! array_is_list( $defaults[ $key ] ) ) {
				$value = self::merge_recursive( $defaults[ $key ], $value );
			}

			$defaults[ $key ] = $value;
		}

		return $defaults;
	}

	/**
	 * Validate settings array.
	 *
	 * Expects settings built from a form, which always carry a `categories`
	 * key. Parsed settings may not have one; read those with get_categories().
	 *
	 * @param array<string, mixed> $settings      Settings to validate.
	 * @param bool                 $club_settings True for the club's settings, which need at
	 *                                            least one category and a valid grade list;
	 *                                            false for a competition's.
	 * @return true|WP_Error
	 */
	public static function validate( array $settings, bool $club_settings = true ) {
		if ( ! isset( $settings['categories'] ) || ! is_array( $settings['categories'] ) ) {
			return new WP_Error( 'invalid_categories', __( 'Categories must be an array.', 'photo-competition-manager' ) );
		}

		if ( $club_settings && empty( $settings['categories'] ) ) {
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

		// Grades belong to the club, so only the club's settings carry them.
		if ( $club_settings ) {
			$grades_valid = self::validate_grades( $settings );
			if ( true !== $grades_valid ) {
				return $grades_valid;
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

		if ( isset( $settings['slideshow']['progress_meter_type'] ) ) {
			$valid_meter_types = array( 'bar', 'line', 'dots', 'radial' );
			if ( ! in_array( $settings['slideshow']['progress_meter_type'], $valid_meter_types, true ) ) {
				return new WP_Error( 'invalid_meter_type', __( 'Progress meter type must be "bar", "line", "dots", or "radial".', 'photo-competition-manager' ) );
			}
		}

		return true;
	}

	/**
	 * Validate the club's grade list.
	 *
	 * @since 0.4.0
	 * @param array<string, mixed> $settings Club settings to validate.
	 * @return true|WP_Error
	 */
	private static function validate_grades( array $settings ) {
		if ( ! isset( $settings['grades'] ) || ! is_array( $settings['grades'] ) ) {
			return new WP_Error( 'invalid_grades', __( 'Grades must be an array.', 'photo-competition-manager' ) );
		}

		if ( empty( $settings['grades'] ) ) {
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
	 * A competition with no categories of its own uses the club's.
	 *
	 * @param array<string, mixed> $settings Parsed settings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_categories( array $settings ): array {
		return ! empty( $settings['categories'] ) ? $settings['categories'] : self::club_categories();
	}

	/**
	 * The club's category list, or the built-in one if the club has none saved.
	 *
	 * @since 0.4.0
	 * @return array<int, array<string, mixed>>
	 */
	public static function club_categories(): array {
		$categories = self::global_settings()['categories'] ?? array();

		return ! empty( $categories ) ? $categories : self::defaults()['categories'];
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
	 * The club's grade list.
	 *
	 * @since 0.4.0
	 * @return array<int, array{label: string, slug: string}>
	 */
	public static function club_grades(): array {
		return self::global_settings()['grades'] ?? self::defaults()['grades'];
	}

	/**
	 * The club's grades as slug => label.
	 *
	 * @since 0.4.0
	 * @return array<string, string>
	 */
	public static function grade_labels(): array {
		return array_column( self::club_grades(), 'label', 'slug' );
	}

	/**
	 * Whether a slug is one of the club's grades.
	 *
	 * @since 0.4.0
	 * @param string $slug Grade slug.
	 * @return bool
	 */
	public static function is_club_grade( string $slug ): bool {
		return in_array( $slug, wp_list_pluck( self::club_grades(), 'slug' ), true );
	}

	/**
	 * Find the club grade a label or slug names, ignoring case and accents.
	 *
	 * Labels are checked before slugs. A renamed grade keeps its old slug, so
	 * a new grade given the old name must still win on its label.
	 *
	 * @since 0.4.0
	 * @param string $label_or_slug Grade label or slug, as typed into a CSV file.
	 * @return string|null The grade's slug, or null if no club grade matches.
	 */
	public static function find_club_grade_slug( string $label_or_slug ): ?string {
		$wanted = sanitize_title( $label_or_slug );
		$grades = self::club_grades();

		if ( '' === $wanted ) {
			return null;
		}

		foreach ( $grades as $grade ) {
			if ( sanitize_title( $grade['label'] ) === $wanted ) {
				return $grade['slug'];
			}
		}

		foreach ( $grades as $grade ) {
			if ( $grade['slug'] === $wanted ) {
				return $grade['slug'];
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
	 * Get upload constraints from settings.
	 *
	 * @param array<string, mixed> $settings Parsed settings.
	 * @return array<string, mixed>
	 */
	public static function get_upload_constraints( array $settings ): array {
		return $settings['upload'] ?? self::defaults()['upload'];
	}

	/**
	 * The per-file upload limit that's really enforced: the lowest of the
	 * competition's limit, upload_max_filesize and post_max_size. PHP
	 * refuses a bigger file before WordPress sees it.
	 *
	 * Reads PHP's settings directly, not wp_max_upload_size(), which
	 * multisite caps at the network's upload limit and the site's space
	 * quota. The plugin applies neither.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string, mixed> $upload_constraints Upload constraints from get_upload_constraints().
	 * @return int The limit, in bytes.
	 */
	public static function enforced_max_file_size( array $upload_constraints ): int {
		$competition_limit = (int) ( $upload_constraints['max_file_size_mb'] ?? self::defaults()['upload']['max_file_size_mb'] ) * MB_IN_BYTES;
		$php_limit         = self::php_upload_limit( (string) ini_get( 'upload_max_filesize' ), (string) ini_get( 'post_max_size' ) );

		/**
		 * Filters the server's per-file upload limit. Lower it when something in
		 * front of PHP, such as a proxy, refuses smaller files than PHP would.
		 * Raising it above PHP's limit makes the upload page promise a size
		 * PHP will refuse.
		 *
		 * @since 0.4.0
		 *
		 * @param int $server_limit The lower of PHP's upload_max_filesize and post_max_size, in bytes. 0 or less is no limit.
		 */
		$server_limit = (int) apply_filters( 'photo_competition_manager_server_upload_limit', $php_limit );

		return self::lowest_limit( $competition_limit, $server_limit );
	}

	/**
	 * The largest file PHP accepts in an upload: the lower of upload_max_filesize
	 * and post_max_size, ignoring either when it's 0 or less (no limit).
	 *
	 * @since 0.4.0
	 *
	 * @param string $upload_max_filesize PHP's upload_max_filesize, such as "8M".
	 * @param string $post_max_size       PHP's post_max_size, such as "8M".
	 * @return int The limit, in bytes, or 0 when there is none.
	 */
	public static function php_upload_limit( string $upload_max_filesize, string $post_max_size ): int {
		return self::lowest_limit( wp_convert_hr_to_bytes( $upload_max_filesize ), wp_convert_hr_to_bytes( $post_max_size ) );
	}

	/**
	 * The lowest of some size limits, ignoring any of 0 or less, which PHP reads as no limit.
	 *
	 * @since 0.4.0
	 *
	 * @param int ...$limits Limits, in bytes.
	 * @return int The lowest limit, or 0 when there is none.
	 */
	private static function lowest_limit( int ...$limits ): int {
		$limits = array_filter(
			$limits,
			static function ( int $limit ): bool {
				return $limit > 0;
			}
		);

		return $limits ? min( $limits ) : 0;
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
	 * The URL of a competition's voting, upload, results or top 3 page.
	 *
	 * Every email with a page link, the Members screen and the reminder job
	 * use this, so a competition always gets the same link. It takes the
	 * competition's own setting, then the club's, then a published page
	 * containing the page's shortcode. It reads the stored settings rather
	 * than parse(), which fills in a shortcode page before the club's
	 * setting gets a say.
	 *
	 * @since 0.4.0
	 * @param string      $page        'voting_page', 'upload_page', 'results_page' or 'top3_page'.
	 * @param object|null $competition Competition row, or null for the club's page.
	 * @return string The page URL, or '' when no page can be found.
	 */
	public static function page_url( string $page, ?object $competition = null ): string {
		$url = $competition ? self::stored_page_url( $competition->settings ?? '', $page ) : '';

		if ( '' === $url ) {
			$url = self::stored_page_url( self::stored_club_settings(), $page );
		}

		if ( '' === $url && isset( self::PAGE_SHORTCODES[ $page ] ) ) {
			$url = self::find_page_url_with_shortcode( self::PAGE_SHORTCODES[ $page ] );
		}

		if ( 'upload_page' === $page ) {
			/**
			 * Filters the upload page URL that members' upload links are built on.
			 *
			 * @since 0.3.0
			 * @since 0.4.0 Runs on every upload link, and may be passed ''.
			 *
			 * @param string      $url         Upload page URL, or '' when none was found.
			 * @param object|null $competition Competition row.
			 */
			$url = (string) apply_filters( 'photo_competition_manager_upload_page_url', $url, $competition );
		}

		return $url;
	}

	/**
	 * A page URL as stored in settings JSON, before any shortcode page is filled in.
	 *
	 * @param string|null $json Settings JSON.
	 * @param string      $page Settings URL key.
	 * @return string The stored URL, or ''.
	 */
	private static function stored_page_url( ?string $json, string $page ): string {
		$url = self::decode( $json )['urls'][ $page ] ?? '';

		return is_string( $url ) ? $url : '';
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
