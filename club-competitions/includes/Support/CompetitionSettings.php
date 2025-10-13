<?php
/**
 * Competition settings helper.
 *
 * @package ClubCompetitions\Support
 */

namespace ClubCompetitions\Support;

use WP_Error;

class CompetitionSettings {

	/**
	 * Default settings structure.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'categories'       => array(
				array(
					'slug'  => 'colour',
					'label' => __( 'Colour', 'club-competitions' ),
					'quota' => 2,
				),
				array(
					'slug'  => 'black-white',
					'label' => __( 'Black & White', 'club-competitions' ),
					'quota' => 2,
				),
			),
			'grades'           => array(
				array(
					'slug'  => 'beginner',
					'label' => __( 'Beginner', 'club-competitions' ),
				),
				array(
					'slug'  => 'intermediate',
					'label' => __( 'Intermediate', 'club-competitions' ),
				),
				array(
					'slug'  => 'advanced',
					'label' => __( 'Advanced', 'club-competitions' ),
				),
			),
			'upload'           => array(
				'max_file_size_mb' => 5,
				'max_width'        => 1920,
				'max_height'       => 1920,
				'allowed_formats'  => array( 'jpg', 'jpeg' ),
			),
			'voting'           => array(
				'score_matrix'    => array( 9, 8, 7, 6, 5 ),
				'auto_open'       => false,
				'open_categories' => array(), // Array of category slugs where voting is open.
			),
			'slideshow'        => array(
				'duration_seconds' => 10,
			),
			'email_reminders'  => array(
				'enabled'                => true,
				'days_before_open'       => 7,
				'days_before_close'      => 1,
				'include_qr_code_voting' => true,
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

		return self::merge_with_defaults( $decoded );
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
	 * @return true|WP_Error
	 */
	public static function validate( array $settings ) {
		if ( ! isset( $settings['categories'] ) || ! is_array( $settings['categories'] ) ) {
			return new WP_Error( 'invalid_categories', __( 'Categories must be an array.', 'club-competitions' ) );
		}

		if ( empty( $settings['categories'] ) ) {
			return new WP_Error( 'missing_categories', __( 'At least one category is required.', 'club-competitions' ) );
		}

		foreach ( $settings['categories'] as $index => $category ) {
			if ( ! is_array( $category ) ) {
				return new WP_Error(
					'invalid_category',
					sprintf(
						/* translators: %d: category index */
						__( 'Category %d must be an array.', 'club-competitions' ),
						$index
					)
				);
			}

			if ( empty( $category['slug'] ) || empty( $category['label'] ) ) {
				return new WP_Error(
					'missing_category_fields',
					sprintf(
						/* translators: %d: category index */
						__( 'Category %d must have slug and label.', 'club-competitions' ),
						$index
					)
				);
			}

			if ( ! isset( $category['quota'] ) || ! is_numeric( $category['quota'] ) || $category['quota'] < 1 ) {
				return new WP_Error(
					'invalid_quota',
					sprintf(
						/* translators: %s: category label */
						__( 'Category "%s" must have a quota of at least 1.', 'club-competitions' ),
						$category['label']
					)
				);
			}
		}

		if ( ! isset( $settings['grades'] ) || ! is_array( $settings['grades'] ) ) {
			return new WP_Error( 'invalid_grades', __( 'Grades must be an array.', 'club-competitions' ) );
		}

		if ( empty( $settings['grades'] ) ) {
			return new WP_Error( 'missing_grades', __( 'At least one grade is required.', 'club-competitions' ) );
		}

		foreach ( $settings['grades'] as $index => $grade ) {
			if ( ! is_array( $grade ) ) {
				return new WP_Error(
					'invalid_grade',
					sprintf(
						/* translators: %d: grade index */
						__( 'Grade %d must be an array.', 'club-competitions' ),
						$index
					)
				);
			}

			if ( empty( $grade['slug'] ) || empty( $grade['label'] ) ) {
				return new WP_Error(
					'missing_grade_fields',
					sprintf(
						/* translators: %d: grade index */
						__( 'Grade %d must have slug and label.', 'club-competitions' ),
						$index
					)
				);
			}
		}

		if ( isset( $settings['upload']['max_file_size_mb'] ) ) {
			if ( ! is_numeric( $settings['upload']['max_file_size_mb'] ) || $settings['upload']['max_file_size_mb'] < 1 ) {
				return new WP_Error( 'invalid_file_size', __( 'Max file size must be at least 1 MB.', 'club-competitions' ) );
			}
		}

		if ( isset( $settings['voting']['score_matrix'] ) ) {
			if ( ! is_array( $settings['voting']['score_matrix'] ) || empty( $settings['voting']['score_matrix'] ) ) {
				return new WP_Error( 'invalid_score_matrix', __( 'Score matrix must be a non-empty array.', 'club-competitions' ) );
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
		return $settings['categories'] ?? array();
	}

	/**
	 * Get grades from settings.
	 *
	 * @param array<string, mixed> $settings Parsed settings.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_grades( array $settings ): array {
		return $settings['grades'] ?? array();
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
	 * Check if voting is open for a specific category.
	 *
	 * @param array<string, mixed> $settings Parsed settings.
	 * @param string               $category Category slug.
	 * @return bool
	 */
	public static function is_voting_open_for_category( array $settings, string $category ): bool {
		$voting_config    = self::get_voting_config( $settings );
		$open_categories  = $voting_config['open_categories'] ?? array();

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
}
