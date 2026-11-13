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
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Log_Retention;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Manage the club's settings page.
 *
 * @since 0.1.0
 */
class Settings_Controller {

	use Date_Formatting;
	use Form_Rendering;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members_repository;

	/**
	 * Constructor.
	 *
	 * @param Members_Repository $members_repository Members repository.
	 */
	public function __construct( Members_Repository $members_repository ) {
		$this->members_repository = $members_repository;
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

		$this->enqueue_category_grade_script();
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

		$sanitized_grades = Competition_Settings::sanitize_grades( $this->get_post_array( 'grades' ) );

		$voting_ui_type_input = sanitize_text_field( $this->get_post_string( 'voting_ui_type', 'buttons' ) );
		if ( ! in_array( $voting_ui_type_input, array( 'buttons', 'dropdown' ), true ) ) {
			$voting_ui_type_input = 'buttons';
		}

		// Competition defaults (categories, upload, voting, slideshow) belong
		// to competition types now. Their old copies stay in the option untouched.
		$settings           = $this->stored_club_settings();
		$settings['grades'] = $sanitized_grades;
		$settings['email']  = array(
			'from_name'  => sanitize_text_field( $this->get_post_string( 'email_from_name', '' ) ),
			'from_email' => sanitize_email( $this->get_post_string( 'email_from_email', '' ) ),
		);
		$settings['urls']   = array(
			'upload_page'  => sanitize_url( $this->get_post_string( 'upload_page_url', '' ) ),
			'voting_page'  => sanitize_url( $this->get_post_string( 'voting_page_url', '' ) ),
			'results_page' => sanitize_url( $this->get_post_string( 'results_page_url', '' ) ),
			'top3_page'    => sanitize_url( $this->get_post_string( 'top3_page_url', '' ) ),
		);
		$validation         = Competition_Settings::validate_grades( $settings );

		$log_retention_months = Log_Retention::parse(
			sanitize_key( $this->get_post_string( 'log_retention', 'forever' ) ),
			sanitize_text_field( $this->get_post_string( 'log_retention_months' ) )
		);

		if ( true === $validation && is_wp_error( $log_retention_months ) ) {
			$validation = $log_retention_months;
		}

		if ( true === $validation ) {
			$validation = $this->check_removed_grades_unheld( Competition_Settings::club_grades(), $sanitized_grades );
		}

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
			update_option( Log_Retention::OPTION, $log_retention_months );

			add_settings_error(
				'photo_competition_settings',
				'settings_saved',
				__( 'Settings saved.', 'photo-competition-manager' ),
				'updated'
			);
		}

		$this->redirect_with_settings_errors( $this->settings_url() );
	}

	/**
	 * Render the club's settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( 'photo_competition_settings' );

		$settings       = Competition_Settings::global_settings();
		$grades         = Competition_Settings::club_grades();
		$voting_ui_type = get_option( 'photo_comp_voting_ui_type', 'buttons' );
		$urls           = $settings['urls'] ?? array(
			'upload_page' => '',
			'voting_page' => '',
		);
		$email_config   = $settings['email'] ?? array(
			'from_name'  => '',
			'from_email' => '',
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Settings', 'photo-competition-manager' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Grades, the voting form, email, page URLs and logs apply to the whole club.', 'photo-competition-manager' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="card" style="max-width: 720px; padding: 16px; margin-top: 20px;">';
		wp_nonce_field( 'photo_competition_global_settings', 'photo_competition_nonce' );
		echo '<input type="hidden" name="photo_competition_action" value="update_global_settings" />';
		echo '<input type="hidden" name="page" value="photo-competition-manager-settings" />';

		echo $this->render_template( 'admin/settings/types-notice.php', array( 'types_url' => $this->types_url() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_grades_section( $grades ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_template( 'admin/settings/voting-ui-section.php', array( 'voting_ui_type' => $voting_ui_type ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_email_section( $email_config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_urls_section( $urls ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_template( 'admin/settings/logs-section.php', array( 'months' => Log_Retention::months() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.

		submit_button( __( 'Save Settings', 'photo-competition-manager' ) );

		echo '</form>';
		echo '</div>';
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
				'slug'  => $grade['slug'],
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
	 * Save the club's settings.
	 *
	 * @param  array<string, mixed> $settings Settings to save.
	 * @return void
	 */
	private function save_global_settings( array $settings ): void {
		update_option( 'photo_comp_default_settings', Competition_Settings::encode( $settings ) );
	}

	/**
	 * The club option as stored, without defaults filled in, so a save keeps
	 * every key it doesn't set.
	 *
	 * @since 0.5.0
	 * @return array<string, mixed>
	 */
	private function stored_club_settings(): array {
		$stored = json_decode( (string) get_option( 'photo_comp_default_settings', '' ), true );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Refuse to remove a grade that any member, active or inactive, holds.
	 *
	 * @since 0.4.0
	 * @param  array<int, array{label: string, slug: string}> $old_grades Grades before the save.
	 * @param  array<int, array{label: string, slug: string}> $new_grades Grades being saved.
	 * @return true|\WP_Error
	 */
	private function check_removed_grades_unheld( array $old_grades, array $new_grades ) {
		$kept_slugs = wp_list_pluck( $new_grades, 'slug' );
		$held       = array();

		foreach ( $old_grades as $grade ) {
			if ( in_array( $grade['slug'], $kept_slugs, true ) ) {
				continue;
			}

			$members = $this->members_repository->find_by_grade( $grade['slug'] );
			if ( $members ) {
				$held[] = sprintf(
					/* translators: 1: grade label, 2: comma-separated member names */
					__( '%1$s (held by %2$s)', 'photo-competition-manager' ),
					$grade['label'],
					implode( ', ', wp_list_pluck( $members, 'name' ) )
				);
			}
		}

		if ( ! $held ) {
			return true;
		}

		return new \WP_Error(
			'grade_in_use',
			sprintf(
				/* translators: %s: semicolon-separated grades and the members holding them */
				__( 'Settings not saved. These grades can\'t be removed while members hold them: %s. Change those members\' grades first.', 'photo-competition-manager' ),
				implode( '; ', $held )
			)
		);
	}
}
