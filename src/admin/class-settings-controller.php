<?php
/**
 * Settings controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Date_Formatting;
use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Manage global default settings page.
 *
 * @since 0.1.0
 */
class Settings_Controller {

	use Date_Formatting;
	use Form_Rendering;

	/**
	 * Register hooks for this controller.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue inline scripts for settings page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'competitions_page_photo-competition-manager-settings' !== $hook ) {
			return;
		}

		// Same JavaScript as competitions controller for category/grade management.
		$inline_js = '
		document.addEventListener(\'DOMContentLoaded\', function() {
		(function() {
			let categoryIndex = document.querySelectorAll(\'.category-row\').length;
			let gradeIndex = document.querySelectorAll(\'.grade-row\').length;

			document.getElementById(\'add-category\')?.addEventListener(\'click\', function() {
				const container = document.getElementById(\'categories-container\');
				const row = document.createElement(\'div\');
				row.className = \'category-row\';
				row.style.cssText = \'margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;\';
				row.innerHTML = `
					<p style="margin: 5px 0;">
						<label>' . esc_js( __( 'Label', 'photo-competition-manager' ) ) . '</label><br />
						<input type="text" name="categories[${categoryIndex}][label]" class="regular-text" required />
					</p>
					<p style="margin: 5px 0;">
						<label>' . esc_js( __( 'Slug', 'photo-competition-manager' ) ) . '</label><br />
						<input type="text" name="categories[${categoryIndex}][slug]" class="regular-text" required />
					</p>
					<p style="margin: 5px 0;">
						<label>' . esc_js( __( 'Upload Quota', 'photo-competition-manager' ) ) . '</label><br />
						<input type="number" name="categories[${categoryIndex}][quota]" value="1" min="1" max="10" class="small-text" required />
					</p>
					<button type="button" class="button remove-category" style="color: #b32d2e;">' . esc_js( __( 'Remove', 'photo-competition-manager' ) ) . '</button>
				`;
				container.appendChild(row);
				categoryIndex++;
			});

			document.getElementById(\'add-grade\')?.addEventListener(\'click\', function() {
				const container = document.getElementById(\'grades-container\');
				const row = document.createElement(\'div\');
				row.className = \'grade-row\';
				row.style.cssText = \'margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;\';
				row.innerHTML = `
					<p style="margin: 5px 0;">
						<label>' . esc_js( __( 'Label', 'photo-competition-manager' ) ) . '</label><br />
						<input type="text" name="grades[${gradeIndex}][label]" class="regular-text" required />
					</p>
					<button type="button" class="button remove-grade" style="color: #b32d2e;">' . esc_js( __( 'Remove', 'photo-competition-manager' ) ) . '</button>
				`;
				container.appendChild(row);
				gradeIndex++;
			});

			document.addEventListener(\'click\', function(e) {
				if (e.target.classList.contains(\'remove-category\')) {
					e.target.closest(\'.category-row\').remove();
				}
				if (e.target.classList.contains(\'remove-grade\')) {
					e.target.closest(\'.grade-row\').remove();
				}
			});
		})();
		});
		';

		wp_register_script( 'photo-competition-manager-settings-js', false, array(), PHOTO_COMPETITION_MANAGER_VERSION, true );
		wp_enqueue_script( 'photo-competition-manager-settings-js' );
		wp_add_inline_script( 'photo-competition-manager-settings-js', $inline_js );
	}

	/**
	 * Handle admin post actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			return;
		}

		$action = '';

		if ( isset( $_POST['photo_competition_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['photo_competition_action'] ) );
		} elseif ( isset( $_POST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; mutations require explicit nonce checks below.
			$action = sanitize_key( wp_unslash( $_POST['action'] ) );
		}

		if ( 'update_global_settings' !== $action ) {
			return;
		}

		check_admin_referer( 'photo_competition_global_settings', 'photo_competition_nonce' );

		$categories = $this->get_post_array( 'categories' );
		$grades     = $this->get_post_array( 'grades' );

		$sanitized_categories = array();
		foreach ( $categories as $category ) {
			if ( ! isset( $category['label'], $category['slug'], $category['quota'] ) ) {
				continue;
			}

			$sanitized_categories[] = array(
				'label' => sanitize_text_field( $category['label'] ),
				'slug'  => sanitize_title( $category['slug'] ),
				'quota' => absint( $category['quota'] ),
			);
		}

		$sanitized_grades = array();
		foreach ( $grades as $grade ) {
			if ( ! isset( $grade['label'] ) ) {
				continue;
			}

			$sanitized_grades[] = array(
				'label' => sanitize_text_field( $grade['label'] ),
				'slug'  => sanitize_title( $grade['label'] ),
			);
		}

		$score_matrix_raw = sanitize_text_field( $this->get_post_string( 'score_matrix' ) );
		$score_matrix     = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $score_matrix_raw ) ), 'is_numeric' ) );

		if ( empty( $score_matrix ) ) {
			$score_matrix = array( 9, 8, 7, 6, 5 );
		}

		// Get existing settings to preserve open_categories (controlled via Voting Controls page).
		$existing_settings        = $this->get_global_settings();
		$existing_open_categories = $existing_settings['voting']['open_categories'] ?? array();

		$auth_mode_input = sanitize_text_field( $this->get_post_string( 'voting_auth_mode', 'password' ) );
		if ( ! in_array( $auth_mode_input, array( 'password', 'token' ), true ) ) {
			$auth_mode_input = 'password';
		}

		$voting_ui_type_input = sanitize_text_field( $this->get_post_string( 'voting_ui_type', 'buttons' ) );
		if ( ! in_array( $voting_ui_type_input, array( 'buttons', 'dropdown' ), true ) ) {
			$voting_ui_type_input = 'buttons';
		}

		$voting_password     = sanitize_text_field( $this->get_post_string( 'voting_password' ) );
		$click_image_to_zoom = isset( $_POST['click_image_to_zoom'] ) && '1' === $_POST['click_image_to_zoom'];

		$upload_page_url = sanitize_url( $this->get_post_string( 'upload_page_url', '' ) );
		$voting_page_url = sanitize_url( $this->get_post_string( 'voting_page_url', '' ) );

		$settings   = array(
			'categories'      => $sanitized_categories,
			'grades'          => $sanitized_grades,
			'upload'          => array(
				'max_file_size_mb' => absint( $this->get_post_string( 'max_file_size_mb', '5' ) ),
				'max_width'        => absint( $this->get_post_string( 'max_width', '1920' ) ),
				'max_height'       => absint( $this->get_post_string( 'max_height', '1920' ) ),
				'allowed_formats'  => array( 'jpg', 'jpeg' ),
			),
			'voting'          => array(
				'score_matrix'        => $score_matrix,
				'open_categories'     => $existing_open_categories,
				'auth_mode'           => $auth_mode_input,
				'password'            => $voting_password,
				'click_image_to_zoom' => $click_image_to_zoom,
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
				'upload_page' => $upload_page_url,
				'voting_page' => $voting_page_url,
			),
		);
		$validation = Competition_Settings::validate( $settings );

		if ( is_wp_error( $validation ) ) {
			add_settings_error(
				'photo_competition_settings',
				$validation->get_error_code(),
				$validation->get_error_message(),
				'error'
			);
		} else {
			$this->save_global_settings( $settings );
			update_option( 'photo_comp_voting_ui_type', $voting_ui_type_input );

			add_settings_error(
				'photo_competition_settings',
				'settings_saved',
				__( 'Default settings saved successfully.', 'photo-competition-manager' ),
				'updated'
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'photo-competition-manager-settings',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render global settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( 'photo_competition_settings' );

		$settings       = $this->get_global_settings();
		$categories     = Competition_Settings::get_categories( $settings );
		$grades         = Competition_Settings::get_grades( $settings );
		$upload         = Competition_Settings::get_upload_constraints( $settings );
		$voting         = Competition_Settings::get_voting_config( $settings );
		$voting_ui_type = get_option( 'photo_comp_voting_ui_type', 'buttons' );
		$urls           = $settings['urls'] ?? array(
			'upload_page' => '',
			'voting_page' => '',
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Default Competition Settings', 'photo-competition-manager' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'These settings will be used as defaults when creating new competitions. Individual competitions can override these settings.', 'photo-competition-manager' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="card" style="max-width: 720px; padding: 16px; margin-top: 20px;">';
		wp_nonce_field( 'photo_competition_global_settings', 'photo_competition_nonce' );
		echo '<input type="hidden" name="photo_competition_action" value="update_global_settings" />';
		echo '<input type="hidden" name="page" value="photo-competition-manager-settings" />';

		echo '<h2>' . esc_html__( 'Categories', 'photo-competition-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Define default categories and upload quotas.', 'photo-competition-manager' ) . '</p>';

		echo '<div id="categories-container">';
		foreach ( $categories as $index => $category ) {
			$this->render_category_field( $index, $category );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-category" class="button">' . esc_html__( 'Add Category', 'photo-competition-manager' ) . '</button>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Grades', 'photo-competition-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Define default member grade levels.', 'photo-competition-manager' ) . '</p>';

		echo '<div id="grades-container">';
		foreach ( $grades as $index => $grade ) {
			$this->render_grade_field( $index, $grade );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-grade" class="button">' . esc_html__( 'Add Grade', 'photo-competition-manager' ) . '</button>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Upload Constraints', 'photo-competition-manager' ) . '</h2>';

		echo '<p>';
		echo '<label for="max_file_size_mb">' . esc_html__( 'Max File Size (MB)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="number" id="max_file_size_mb" name="max_file_size_mb" min="1" max="50" value="' . esc_attr( $upload['max_file_size_mb'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_width">' . esc_html__( 'Max Width (pixels)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="number" id="max_width" name="max_width" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_width'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_height">' . esc_html__( 'Max Height (pixels)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="number" id="max_height" name="max_height" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_height'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Voting Configuration', 'photo-competition-manager' ) . '</h2>';

		$auth_mode = $voting['auth_mode'] ?? 'password';

		echo '<p>';
		echo '<label for="voting_auth_mode">' . esc_html__( 'Voting Authentication Mode', 'photo-competition-manager' ) . '</label><br />';
		echo '<select id="voting_auth_mode" name="voting_auth_mode">';
		echo '<option value="password"' . selected( $auth_mode, 'password', false ) . '>' . esc_html__( 'Password-based (traditional)', 'photo-competition-manager' ) . '</option>';
		echo '<option value="token"' . selected( $auth_mode, 'token', false ) . '>' . esc_html__( 'Email Magic Links (anonymous)', 'photo-competition-manager' ) . '</option>';
		echo '</select><br />';
		echo '<span class="description">' . esc_html__( 'Choose how voters authenticate. Password mode allows voters to enter their name and optional password. Token mode sends secure one-time voting links via email for anonymous voting.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_ui_type">' . esc_html__( 'Voting UI Type', 'photo-competition-manager' ) . '</label><br />';
		echo '<select id="voting_ui_type" name="voting_ui_type">';
		echo '<option value="buttons"' . selected( $voting_ui_type, 'buttons', false ) . '>' . esc_html__( 'Horizontal Score Buttons', 'photo-competition-manager' ) . '</option>';
		echo '<option value="dropdown"' . selected( $voting_ui_type, 'dropdown', false ) . '>' . esc_html__( 'Dropdown', 'photo-competition-manager' ) . '</option>';
		echo '</select><br />';
		echo '<span class="description">' . esc_html__( 'Choose how voters select scores. Buttons offer a quick, one-click experience, while dropdowns conserve vertical space.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_password">' . esc_html__( 'Voting Password (for password mode)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="voting_password" name="voting_password" value="' . esc_attr( $voting['password'] ) . '" class="regular-text" />';
		echo '<span class="description">' . esc_html__( 'Voters must enter this password before submitting votes. Leave blank to disable by default. Only used when auth mode is "Password-based".', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		$click_to_zoom = isset( $voting['click_image_to_zoom'] ) ? (bool) $voting['click_image_to_zoom'] : false;
		echo '<label for="click_image_to_zoom">';
		echo '<input type="checkbox" id="click_image_to_zoom" name="click_image_to_zoom" value="1"' . checked( $click_to_zoom, true, false ) . ' />';
		echo ' ' . esc_html__( 'Click image to zoom on voting form', 'photo-competition-manager' );
		echo '</label><br />';
		echo '<span class="description">' . esc_html__( 'When enabled, images in the voting form can be clicked to open full-size in a new tab. When disabled, images are not clickable to prevent accidental navigation. Recommended: off for touch devices.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="score_matrix">' . esc_html__( 'Score Matrix (comma-separated)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="score_matrix" name="score_matrix" value="' . esc_attr( implode( ', ', $voting['score_matrix'] ) ) . '" class="regular-text" />';
		echo '<span class="description">' . esc_html__( 'E.g., 9, 8, 7, 6, 5', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'URLs', 'photo-competition-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Default pages used in upload and voting notifications.', 'photo-competition-manager' ) . '</p>';

		echo '<p>';
		echo '<label for="upload_page_url">' . esc_html__( 'Upload Page URL', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="url" id="upload_page_url" name="upload_page_url" value="' . esc_attr( $urls['upload_page'] ?? '' ) . '" class="regular-text" placeholder="https://example.com/upload" />';
		echo '<br /><span class="description">' . esc_html__( 'Members receive this link when requesting upload tokens.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_page_url">' . esc_html__( 'Voting Page URL', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="url" id="voting_page_url" name="voting_page_url" value="' . esc_attr( $urls['voting_page'] ?? '' ) . '" class="regular-text" placeholder="https://example.com/vote" />';
		echo '<br /><span class="description">' . esc_html__( 'Voters receive this link in voting invitation emails.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		submit_button( __( 'Save Default Settings', 'photo-competition-manager' ) );

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render category field row.
	 *
	 * @param  int   $index    Category index.
	 * @param  array $category Category data.
	 * @return void
	 */
	private function render_category_field( int $index, array $category ): void {
		echo '<div class="category-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Label', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" name="categories[' . esc_attr( $index ) . '][label]" value="' . esc_attr( $category['label'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Slug', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" name="categories[' . esc_attr( $index ) . '][slug]" value="' . esc_attr( $category['slug'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Upload Quota', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="number" name="categories[' . esc_attr( $index ) . '][quota]" value="' . esc_attr( $category['quota'] ) . '" min="1" max="10" class="small-text" required />';
		echo '</p>';

		echo '<button type="button" class="button remove-category" style="color: #b32d2e;">' . esc_html__( 'Remove', 'photo-competition-manager' ) . '</button>';

		echo '</div>';
	}

	/**
	 * Render grade field row.
	 *
	 * @param  int   $index Grade index.
	 * @param  array $grade Grade data.
	 * @return void
	 */
	private function render_grade_field( int $index, array $grade ): void {
		echo '<div class="grade-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Label', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" name="grades[' . esc_attr( $index ) . '][label]" value="' . esc_attr( $grade['label'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<button type="button" class="button remove-grade" style="color: #b32d2e;">' . esc_html__( 'Remove', 'photo-competition-manager' ) . '</button>';

		echo '</div>';
	}


	/**
	 * Get global default settings.
	 *
	 * @return array<string, mixed>
	 */
	private function get_global_settings(): array {
		$saved = get_option( 'photo_comp_default_settings', '' );
		return Competition_Settings::parse( $saved );
	}

	/**
	 * Save global default settings.
	 *
	 * @param  array<string, mixed> $settings Settings to save.
	 * @return void
	 */
	private function save_global_settings( array $settings ): void {
		update_option( 'photo_comp_default_settings', Competition_Settings::encode( $settings ) );
	}
}
