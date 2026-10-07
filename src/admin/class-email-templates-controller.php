<?php
/**
 * Email Templates Controller
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Service\Email_Kinds;
use PhotoCompetitionManager\Service\Email_Service;

/**
 * Manage email template settings.
 *
 * @package PhotoCompetitionManager\Admin
 */
class Email_Templates_Controller {

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
	 * Enqueue inline styles for email templates page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'competitions_page_photo-competition-manager-email-templates' !== $hook ) {
			return;
		}

		// Register and enqueue a dummy style handle to attach inline styles to.
		wp_register_style( 'photo-comp-email-templates-style', '', array(), PHOTO_COMPETITION_MANAGER_VERSION );
		wp_enqueue_style( 'photo-comp-email-templates-style' );

		$inline_css = '.photo-comp-email-templates .card{max-width:none;} .photo-comp-email-templates .form-table{max-width:none;} .photo-comp-email-templates .form-table th{width:180px;} .photo-comp-email-templates .form-table td{padding-right:0;}';
		wp_add_inline_style( 'photo-comp-email-templates-style', $inline_css );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Safe read of action for routing; actual data processing requires nonce check below.
		if ( isset( $_POST['photo_competition_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['photo_competition_action'] ) );
		}

		if ( 'save_email_templates' === $action ) {
			check_admin_referer( 'photo_competition_email_templates' );

			$templates = array();

			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below per field.
			$raw_templates = isset( $_POST['templates'] ) && is_array( $_POST['templates'] ) ? wp_unslash( $_POST['templates'] ) : array();

			foreach ( $raw_templates as $template_key => $template_data ) {
				$key = sanitize_key( $template_key );

				$templates[ $key ] = array(
					'enabled' => isset( $template_data['enabled'] ) && '1' === $template_data['enabled'],
					'subject' => isset( $template_data['subject'] ) ? sanitize_text_field( $template_data['subject'] ) : '',
					'body'    => isset( $template_data['body'] ) ? wp_kses_post( $template_data['body'] ) : '',
				);
			}

			update_option( 'photo_comp_email_templates', $templates );

			add_settings_error(
				'photo_competition_email_templates',
				'templates_saved',
				__( 'Email templates saved successfully.', 'photo-competition-manager' ),
				'updated'
			);

			$this->redirect_with_settings_errors( admin_url( 'admin.php?page=photo-competition-manager-email-templates' ) );
		}
	}

	/**
	 * Render email templates page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( 'photo_competition_email_templates' );

		$email_service = new Email_Service();
		$templates     = array();
		foreach ( Email_Kinds::all() as $kind => $definition ) {
			$templates[ $kind ] = array(
				'name'         => $definition['label'],
				'description'  => $definition['description'],
				'notification' => $definition['notification'],
				'enabled'      => $email_service->is_template_enabled( $kind ),
				'merge_tags'   => array_map(
					function ( $tag ) {
						return $tag['description'];
					},
					Email_Kinds::shared_tags() + $definition['tags']
				),
			) + $email_service->get_template( $kind );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Email Templates', 'photo-competition-manager' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Customize the email templates sent to members. Use merge tags to personalize messages.', 'photo-competition-manager' ) . '</p>';

		echo '<form method="post" class="photo-comp-email-templates">';
		wp_nonce_field( 'photo_competition_email_templates' );
		echo '<input type="hidden" name="photo_competition_action" value="save_email_templates" />';

		foreach ( $templates as $template_key => $template ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_template_section( $template_key, $template );
		}

		submit_button( __( 'Save Email Templates', 'photo-competition-manager' ) );

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render a single template section.
	 *
	 * @param string               $template_key Template key.
	 * @param array<string, mixed> $template     Template data.
	 * @return string
	 */
	private function render_template_section( string $template_key, array $template ): string {
		return $this->render_template(
			'admin/email-templates/template-card.php',
			array(
				'template_key' => $template_key,
				'template'     => $template,
			)
		);
	}
}
