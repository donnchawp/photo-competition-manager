<?php
/**
 * The competition-default sections of a form: categories, upload limits, voting, slideshow and interclub.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Render the competition-default sections and read them back from a post.
 *
 * @since 0.4.0
 */
class Type_Settings_Form {

	use Form_Rendering;

	/**
	 * Read the posted sections over a type's or competition's current settings.
	 *
	 * Settings the form doesn't post, such as email reminders, the originals'
	 * size, or the slideshow durations a competition's form leaves out, keep
	 * their current values. The caller verifies the nonce.
	 *
	 * @since 0.4.0
	 * @param array<string, mixed> $current Current settings, e.g. from Competition_Settings::type_settings().
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
				'quota' => Competition_Settings::number_in_range( 'categories', 'quota', $category['quota'] ),
			);
		}

		$score_matrix_raw = sanitize_text_field( $this->get_post_string( 'score_matrix' ) );
		$score_matrix     = array_values( array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $score_matrix_raw ) ), 'is_numeric' ) ) );
		if ( empty( $score_matrix ) ) {
			$score_matrix = array( 9, 8, 7, 6, 5 );
		}

		$auth_mode = sanitize_text_field( $this->get_post_string( 'voting_auth_mode', 'password' ) );
		if ( ! in_array( $auth_mode, Competition_Settings::AUTH_MODES, true ) ) {
			$auth_mode = 'password';
		}

		$progress_meter_type = sanitize_text_field( $this->get_post_string( 'progress_meter_type', 'bar' ) );
		if ( ! in_array( $progress_meter_type, Competition_Settings::METER_TYPES, true ) ) {
			$progress_meter_type = 'bar';
		}

		$settings               = $current;
		$settings['categories'] = $categories;
		$settings['upload']     = array_merge(
			$current['upload'] ?? array(),
			array(
				'max_file_size_mb' => Competition_Settings::number_in_range( 'upload', 'max_file_size_mb', $this->get_post_string( 'max_file_size_mb' ) ),
				'max_width'        => Competition_Settings::number_in_range( 'upload', 'max_width', $this->get_post_string( 'max_width' ) ),
				'max_height'       => Competition_Settings::number_in_range( 'upload', 'max_height', $this->get_post_string( 'max_height' ) ),
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
				'preview_duration'    => $this->posted_duration( 'preview_duration', $current ),
				'voting_duration'     => $this->posted_duration( 'voting_duration', $current ),
				'critique_duration'   => $this->posted_duration( 'critique_duration', $current ),
			)
		);

		// Only the type form has this section. The competition form leaves it
		// out, and its unticked checkbox must not turn interclub off.
		if ( '1' === $this->get_post_string( 'interclub_section' ) ) {
			$settings['interclub'] = array(
				'enabled'            => '1' === $this->get_post_string( 'interclub_enabled' ),
				'substitutes'        => Competition_Settings::number_in_range( 'interclub', 'substitutes', $this->get_post_string( 'interclub_substitutes' ) ),
				'photographer_limit' => Competition_Settings::number_in_range( 'interclub', 'photographer_limit', $this->get_post_string( 'interclub_photographer_limit' ) ),
			);
		}

		return $settings;
	}

	/**
	 * Render the categories, upload limits, voting, slideshow and interclub sections.
	 *
	 * @since 0.4.0
	 * @param array<string, mixed> $settings The type's settings, from Competition_Settings::type_settings().
	 * @return string
	 */
	public function render_sections( array $settings ): string {
		return $this->render_categories_section( $settings['categories'] )
			. $this->render_upload_constraints_section( $settings['upload'] )
			. $this->render_voting_section( $settings['voting'] )
			. $this->render_slideshow_section( $settings['slideshow'] )
			. $this->render_template( 'admin/types/interclub-section.php', $settings['interclub'] + array( 'attributes' => Competition_Settings::number_attributes( 'interclub' ) ) );
	}

	/**
	 * A posted slideshow duration in seconds. The competition form doesn't
	 * post the durations, so a missing one keeps its current value.
	 *
	 * @param string               $key     POST key, the same as the setting's.
	 * @param array<string, mixed> $current Current settings.
	 * @return int
	 */
	private function posted_duration( string $key, array $current ): int {
		$fallback = (string) ( $current['slideshow'][ $key ] ?? Competition_Settings::defaults()['slideshow'][ $key ] );

		return Competition_Settings::number_in_range( 'slideshow', $key, $this->get_post_string( $key, $fallback ) );
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
					'index'            => $index,
					'label'            => $category['label'],
					'slug'             => $category['slug'],
					'quota'            => $category['quota'],
					'quota_attributes' => Competition_Settings::number_attributes( 'categories' )['quota'],
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
				'fields_html' => $this->render_template( 'admin/shared/upload-constraint-fields.php', $upload + array( 'attributes' => Competition_Settings::number_attributes( 'upload' ) ) ),
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
				'attributes'          => Competition_Settings::number_attributes( 'slideshow' ),
			)
		);
	}
}
