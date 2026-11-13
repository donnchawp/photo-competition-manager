<?php
/**
 * The competition-default sections of a form: categories, upload limits, voting and slideshow.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Form_Rendering;

/**
 * Render the competition-default sections and read them back from a post.
 *
 * @since 0.5.0
 */
class Type_Settings_Form {

	use Form_Rendering;

	/**
	 * Read the posted sections over a type's current settings.
	 *
	 * Settings the form doesn't show, such as email reminders and the
	 * originals' size, keep their current values. The caller verifies the nonce.
	 *
	 * @since 0.5.0
	 * @param array<string, mixed> $current The type's settings, from Competition_Settings::type_settings().
	 * @return array<string, mixed>
	 */
	public function from_post( array $current ): array {
		$categories = array();
		foreach ( $this->get_post_array( 'categories' ) as $category ) {
			if ( ! isset( $category['label'], $category['slug'], $category['quota'] ) ) {
				continue;
			}

			$categories[] = array(
				'label' => sanitize_text_field( $category['label'] ),
				'slug'  => sanitize_title( $category['slug'] ),
				'quota' => absint( $category['quota'] ),
			);
		}

		$score_matrix_raw = sanitize_text_field( $this->get_post_string( 'score_matrix' ) );
		$score_matrix     = array_values( array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $score_matrix_raw ) ), 'is_numeric' ) ) );
		if ( empty( $score_matrix ) ) {
			$score_matrix = array( 9, 8, 7, 6, 5 );
		}

		$auth_mode = sanitize_text_field( $this->get_post_string( 'voting_auth_mode', 'password' ) );
		if ( ! in_array( $auth_mode, array( 'password', 'token', 'judges' ), true ) ) {
			$auth_mode = 'password';
		}

		$progress_meter_type = sanitize_text_field( $this->get_post_string( 'progress_meter_type', 'bar' ) );
		if ( ! in_array( $progress_meter_type, array( 'bar', 'line', 'dots', 'radial' ), true ) ) {
			$progress_meter_type = 'bar';
		}

		$settings               = $current;
		$settings['categories'] = $categories;
		$settings['upload']     = array_merge(
			$current['upload'] ?? array(),
			array(
				'max_file_size_mb' => absint( $this->get_post_string( 'max_file_size_mb', '5' ) ),
				'max_width'        => absint( $this->get_post_string( 'max_width', '1920' ) ),
				'max_height'       => absint( $this->get_post_string( 'max_height', '1920' ) ),
			)
		);
		$settings['voting']     = array_merge(
			$current['voting'] ?? array(),
			array(
				'score_matrix'        => $score_matrix,
				'auth_mode'           => $auth_mode,
				'password'            => sanitize_text_field( $this->get_post_string( 'voting_password' ) ),
				'click_image_to_zoom' => '1' === $this->get_post_string( 'click_image_to_zoom' ),
			)
		);
		$settings['slideshow']  = array_merge(
			$current['slideshow'] ?? array(),
			array(
				'progress_meter_type' => $progress_meter_type,
				'preview_duration'    => $this->posted_duration( 'preview_duration', 10 ),
				'voting_duration'     => $this->posted_duration( 'voting_duration', 15 ),
				'critique_duration'   => $this->posted_duration( 'critique_duration', 0 ),
			)
		);

		return $settings;
	}

	/**
	 * Render the categories, upload limits, voting and slideshow sections.
	 *
	 * @since 0.5.0
	 * @param array<string, mixed> $settings The type's settings, from Competition_Settings::type_settings().
	 * @return string
	 */
	public function render_sections( array $settings ): string {
		return $this->render_categories_section( $settings['categories'] )
			. $this->render_upload_constraints_section( $settings['upload'] )
			. $this->render_voting_section( $settings['voting'] )
			. $this->render_slideshow_section( $settings['slideshow'] );
	}

	/**
	 * A posted slideshow duration in seconds, from 0 to 120.
	 *
	 * @param string $key      POST key.
	 * @param int    $fallback Value when nothing is posted.
	 * @return int
	 */
	private function posted_duration( string $key, int $fallback ): int {
		return min( 120, absint( $this->get_post_string( $key, (string) $fallback ) ) );
	}

	/**
	 * Render the categories section (heading, rows, add-category button).
	 *
	 * @param  array<int, array{label: string, slug: string, quota: int}> $categories Configured categories.
	 * @return string
	 */
	private function render_categories_section( array $categories ): string {
		$rows_html = '';

		foreach ( $categories as $index => $category ) {
			$rows_html .= $this->render_template(
				'admin/shared/category-field.php',
				array(
					'index' => $index,
					'label' => $category['label'],
					'slug'  => $category['slug'],
					'quota' => $category['quota'],
				)
			);
		}

		return $this->render_template(
			'admin/types/categories-section.php',
			array(
				'category_rows_html' => $rows_html,
			)
		);
	}

	/**
	 * Render the upload constraints section.
	 *
	 * @param  array<string, mixed> $upload Upload constraints.
	 * @return string
	 */
	private function render_upload_constraints_section( array $upload ): string {
		return $this->render_template(
			'admin/types/upload-constraints-section.php',
			array(
				'max_file_size_mb' => $upload['max_file_size_mb'],
				'max_width'        => $upload['max_width'],
				'max_height'       => $upload['max_height'],
			)
		);
	}

	/**
	 * Render the voting configuration section.
	 *
	 * @param  array<string, mixed> $voting Voting configuration.
	 * @return string
	 */
	private function render_voting_section( array $voting ): string {
		return $this->render_template(
			'admin/types/voting-section.php',
			array(
				'auth_mode'        => $voting['auth_mode'] ?? 'password',
				'password'         => $voting['password'],
				'click_to_zoom'    => (bool) ( $voting['click_image_to_zoom'] ?? false ),
				'score_matrix_str' => implode( ', ', $voting['score_matrix'] ),
			)
		);
	}

	/**
	 * Render the slideshow section.
	 *
	 * @param  array<string, mixed> $slideshow Slideshow settings.
	 * @return string
	 */
	private function render_slideshow_section( array $slideshow ): string {
		$meter_types = array(
			'bar'    => __( 'Bar', 'photo-competition-manager' ),
			'line'   => __( 'Thin Line', 'photo-competition-manager' ),
			'dots'   => __( 'Dots', 'photo-competition-manager' ),
			'radial' => __( 'Radial', 'photo-competition-manager' ),
		);

		return $this->render_template(
			'admin/types/slideshow-section.php',
			array(
				'meter_types'         => $meter_types,
				'progress_meter_type' => $slideshow['progress_meter_type'],
				'preview_duration'    => $slideshow['preview_duration'],
				'voting_duration'     => $slideshow['voting_duration'],
				'critique_duration'   => $slideshow['critique_duration'],
			)
		);
	}
}
