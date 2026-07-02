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
	 * Competitions repository.
	 *
	 * @var \PhotoCompetitionManager\Repository\Competitions_Repository
	 */
	private $competitions_repository;

	/**
	 * Members repository.
	 *
	 * @var \PhotoCompetitionManager\Repository\Members_Repository
	 */
	private $members_repository;

	/**
	 * Constructor.
	 *
	 * @param \PhotoCompetitionManager\Repository\Competitions_Repository $competitions_repository Competitions repository.
	 * @param \PhotoCompetitionManager\Repository\Members_Repository      $members_repository Members repository.
	 */
	public function __construct( $competitions_repository = null, $members_repository = null ) {
		$this->competitions_repository = $competitions_repository;
		$this->members_repository      = $members_repository;
	}

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

		wp_enqueue_script(
			'photo-comp-admin-category-grade',
			PHOTO_COMPETITION_MANAGER_URL . 'assets/js/admin-category-grade.js',
			array(),
			PHOTO_COMPETITION_MANAGER_VERSION,
			true
		);

		wp_localize_script(
			'photo-comp-admin-category-grade',
			'photoCompCategoryGrade',
			array(
				'labelText'       => __( 'Label', 'photo-competition-manager' ),
				'slugText'        => __( 'Slug', 'photo-competition-manager' ),
				'uploadQuotaText' => __( 'Upload Quota', 'photo-competition-manager' ),
				'removeText'      => __( 'Remove', 'photo-competition-manager' ),
			)
		);
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
		$existing_settings        = Competition_Settings::global_settings();
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

		$progress_meter_type_input = sanitize_text_field( $this->get_post_string( 'progress_meter_type', 'bar' ) );
		if ( ! in_array( $progress_meter_type_input, array( 'bar', 'line', 'dots', 'radial' ), true ) ) {
			$progress_meter_type_input = 'bar';
		}

		$preview_duration  = isset( $_POST['preview_duration'] ) ? absint( wp_unslash( $_POST['preview_duration'] ) ) : 10;
		$voting_duration   = isset( $_POST['voting_duration'] ) ? absint( wp_unslash( $_POST['voting_duration'] ) ) : 15;
		$critique_duration = isset( $_POST['critique_duration'] ) ? absint( wp_unslash( $_POST['critique_duration'] ) ) : 0;

		// Clamp to 0-120 range.
		$preview_duration  = min( 120, $preview_duration );
		$voting_duration   = min( 120, $voting_duration );
		$critique_duration = min( 120, $critique_duration );

		$upload_page_url  = sanitize_url( $this->get_post_string( 'upload_page_url', '' ) );
		$voting_page_url  = sanitize_url( $this->get_post_string( 'voting_page_url', '' ) );
		$results_page_url = sanitize_url( $this->get_post_string( 'results_page_url', '' ) );
		$top3_page_url    = sanitize_url( $this->get_post_string( 'top3_page_url', '' ) );

		$email_from_name  = sanitize_text_field( $this->get_post_string( 'email_from_name', '' ) );
		$email_from_email = sanitize_email( $this->get_post_string( 'email_from_email', '' ) );

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
				'progress_meter_type' => $progress_meter_type_input,
				'preview_duration'    => $preview_duration,
				'voting_duration'     => $voting_duration,
				'critique_duration'   => $critique_duration,
			),
			'email_reminders' => array(
				'enabled'                => true,
				'days_before_open'       => 7,
				'days_before_close'      => 1,
				'include_qr_code_voting' => true,
			),
			'email'           => array(
				'from_name'  => $email_from_name,
				'from_email' => $email_from_email,
			),
			'urls'            => array(
				'upload_page'  => $upload_page_url,
				'voting_page'  => $voting_page_url,
				'results_page' => $results_page_url,
				'top3_page'    => $top3_page_url,
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
			// Get old grades before saving for mapping purposes.
			$old_grades = Competition_Settings::get_grades( $existing_settings );

			$this->save_global_settings( $settings );
			update_option( 'photo_comp_voting_ui_type', $voting_ui_type_input );

			// Sync grades to all existing competitions.
			$this->sync_grades_to_competitions( $sanitized_grades );

			// Sync grades to all members.
			$this->sync_grades_to_members( $old_grades, $sanitized_grades );

			add_settings_error(
				'photo_competition_settings',
				'settings_saved',
				__( 'Default settings saved successfully.', 'photo-competition-manager' ),
				'updated'
			);
		}

		$this->redirect_with_settings_errors(
			add_query_arg(
				array(
					'page' => 'photo-competition-manager-settings',
				),
				admin_url( 'admin.php' )
			)
		);
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

		$settings            = Competition_Settings::global_settings();
		$categories          = Competition_Settings::get_categories( $settings );
		$grades              = Competition_Settings::get_grades( $settings );
		$upload              = Competition_Settings::get_upload_constraints( $settings );
		$voting              = Competition_Settings::get_voting_config( $settings );
		$slideshow           = $settings['slideshow'] ?? array();
		$progress_meter_type = $slideshow['progress_meter_type'] ?? 'bar';
		$preview_duration    = $slideshow['preview_duration'] ?? 10;
		$voting_duration     = $slideshow['voting_duration'] ?? 15;
		$critique_duration   = $slideshow['critique_duration'] ?? 0;
		$voting_ui_type      = get_option( 'photo_comp_voting_ui_type', 'buttons' );
		$urls                = $settings['urls'] ?? array(
			'upload_page' => '',
			'voting_page' => '',
		);
		$email_config        = $settings['email'] ?? array(
			'from_name'  => '',
			'from_email' => '',
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Default Competition Settings', 'photo-competition-manager' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'These settings will be used as defaults when creating new competitions. Individual competitions can override these settings.', 'photo-competition-manager' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="card" style="max-width: 720px; padding: 16px; margin-top: 20px;">';
		wp_nonce_field( 'photo_competition_global_settings', 'photo_competition_nonce' );
		echo '<input type="hidden" name="photo_competition_action" value="update_global_settings" />';
		echo '<input type="hidden" name="page" value="photo-competition-manager-settings" />';

		echo $this->render_categories_section( $categories ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_grades_section( $grades ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_upload_constraints_section( $upload ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_voting_section( $voting, $voting_ui_type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_slideshow_section( $progress_meter_type, $preview_duration, $voting_duration, $critique_duration ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_email_section( $email_config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_urls_section( $urls ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.

		submit_button( __( 'Save Default Settings', 'photo-competition-manager' ) );

		echo '</form>';
		echo '</div>';
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
			$rows_html .= $this->render_category_field( $index, $category );
		}

		return $this->render_template(
			'admin/settings/categories-section.php',
			array(
				'category_rows_html' => $rows_html,
			)
		);
	}

	/**
	 * Render a single category field row.
	 *
	 * @param  int   $index    Category index.
	 * @param  array $category Category data.
	 * @return string
	 */
	private function render_category_field( int $index, array $category ): string {
		return $this->render_template(
			'admin/shared/category-field.php',
			array(
				'index' => $index,
				'label' => $category['label'],
				'slug'  => $category['slug'],
				'quota' => $category['quota'],
			)
		);
	}

	/**
	 * Render the grades section (heading, rows, add-grade button).
	 *
	 * @param  array<int, array{label: string, slug: string}> $grades Configured grades.
	 * @return string
	 */
	private function render_grades_section( array $grades ): string {
		$rows_html = '';

		foreach ( $grades as $index => $grade ) {
			$rows_html .= $this->render_grade_field( $index, $grade );
		}

		return $this->render_template(
			'admin/settings/grades-section.php',
			array(
				'grade_rows_html' => $rows_html,
			)
		);
	}

	/**
	 * Render a single grade field row.
	 *
	 * @param  int   $index Grade index.
	 * @param  array $grade Grade data.
	 * @return string
	 */
	private function render_grade_field( int $index, array $grade ): string {
		return $this->render_template(
			'admin/shared/grade-field.php',
			array(
				'index' => $index,
				'label' => $grade['label'],
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
			'admin/settings/upload-constraints-section.php',
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
	 * @param  array<string, mixed> $voting         Voting configuration.
	 * @param  string               $voting_ui_type Resolved voting UI type option.
	 * @return string
	 */
	private function render_voting_section( array $voting, string $voting_ui_type ): string {
		$auth_mode     = $voting['auth_mode'] ?? 'password';
		$click_to_zoom = isset( $voting['click_image_to_zoom'] ) ? (bool) $voting['click_image_to_zoom'] : false;

		return $this->render_template(
			'admin/settings/voting-section.php',
			array(
				'auth_mode'        => $auth_mode,
				'voting_ui_type'   => $voting_ui_type,
				'password'         => $voting['password'],
				'click_to_zoom'    => $click_to_zoom,
				'score_matrix_str' => implode( ', ', $voting['score_matrix'] ),
			)
		);
	}

	/**
	 * Render the slideshow section.
	 *
	 * @param  string $progress_meter_type Selected progress meter type.
	 * @param  int    $preview_duration    Preview duration in seconds.
	 * @param  int    $voting_duration     Voting duration in seconds.
	 * @param  int    $critique_duration   Critique duration in seconds.
	 * @return string
	 */
	private function render_slideshow_section( string $progress_meter_type, int $preview_duration, int $voting_duration, int $critique_duration ): string {
		$meter_types = array(
			'bar'    => __( 'Bar', 'photo-competition-manager' ),
			'line'   => __( 'Thin Line', 'photo-competition-manager' ),
			'dots'   => __( 'Dots', 'photo-competition-manager' ),
			'radial' => __( 'Radial', 'photo-competition-manager' ),
		);

		return $this->render_template(
			'admin/settings/slideshow-section.php',
			array(
				'meter_types'         => $meter_types,
				'progress_meter_type' => $progress_meter_type,
				'preview_duration'    => $preview_duration,
				'voting_duration'     => $voting_duration,
				'critique_duration'   => $critique_duration,
			)
		);
	}

	/**
	 * Render the email configuration section.
	 *
	 * @param  array<string, string> $email_config Email sender configuration.
	 * @return string
	 */
	private function render_email_section( array $email_config ): string {
		return $this->render_template(
			'admin/settings/email-section.php',
			array(
				'from_name'  => $email_config['from_name'],
				'from_email' => $email_config['from_email'],
			)
		);
	}

	/**
	 * Render the URLs section.
	 *
	 * @param  array<string, string> $urls Default page URLs.
	 * @return string
	 */
	private function render_urls_section( array $urls ): string {
		return $this->render_template(
			'admin/settings/urls-section.php',
			array(
				'upload_page_url'  => $urls['upload_page'] ?? '',
				'voting_page_url'  => $urls['voting_page'] ?? '',
				'results_page_url' => $urls['results_page'] ?? '',
				'top3_page_url'    => $urls['top3_page'] ?? '',
			)
		);
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

	/**
	 * Sync grades from global settings to all existing competitions.
	 *
	 * @param  array<int, array{label: string, slug: string}> $new_grades New grades array.
	 * @return void
	 */
	private function sync_grades_to_competitions( array $new_grades ): void {
		if ( ! $this->competitions_repository ) {
			return;
		}

		// Get all competitions (including archived).
		$competitions = $this->competitions_repository->all( 1000, true, false );

		foreach ( $competitions as $competition ) {
			// Parse existing settings.
			$settings = Competition_Settings::parse( $competition->settings );

			// Update grades.
			$settings['grades'] = $new_grades;

			// Save updated settings.
			$this->competitions_repository->update(
				$competition->id,
				array(
					'settings' => $settings,
				)
			);
		}
	}

	/**
	 * Sync member grades when global grades change.
	 *
	 * Maps old grade slugs to new grade slugs based on position/order.
	 * If a member's grade no longer exists, it's updated to the first available grade.
	 *
	 * @param  array<int, array{label: string, slug: string}> $old_grades Old grades array.
	 * @param  array<int, array{label: string, slug: string}> $new_grades New grades array.
	 * @return void
	 */
	private function sync_grades_to_members( array $old_grades, array $new_grades ): void {
		if ( ! $this->members_repository ) {
			return;
		}

		// Build a mapping from old grade slugs to new grade slugs based on position.
		$grade_mapping = array();
		foreach ( $old_grades as $index => $old_grade ) {
			$old_slug = $old_grade['slug'] ?? '';
			// Map to new grade at same position, or first grade if position doesn't exist.
			$new_slug = isset( $new_grades[ $index ]['slug'] ) ? $new_grades[ $index ]['slug'] : ( $new_grades[0]['slug'] ?? '' );
			if ( $old_slug && $new_slug ) {
				$grade_mapping[ $old_slug ] = $new_slug;
			}
		}

		// If no mapping exists (e.g., no old grades), default to first new grade.
		$default_grade = $new_grades[0]['slug'] ?? '';

		// Get all members (including inactive).
		$members = $this->members_repository->all( 10000, false );

		foreach ( $members as $member ) {
			$current_grade = $member->grade ?? '';

			// Determine new grade based on mapping or default.
			$new_grade = $grade_mapping[ $current_grade ] ?? $default_grade;

			// Only update if grade has changed.
			if ( $new_grade && $new_grade !== $current_grade ) {
				$this->members_repository->update(
					$member->id,
					array(
						'grade' => $new_grade,
					)
				);
			}
		}
	}
}
