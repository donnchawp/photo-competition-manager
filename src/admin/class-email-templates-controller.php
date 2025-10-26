<?php
/**
 * Email Templates Controller
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

/**
 * Manage email template settings.
 *
 * @package PhotoCompetitionManager\Admin
 */
class Email_Templates_Controller {

	/**
	 * Register hooks for this controller.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Handle admin post actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = '';

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

			wp_safe_redirect( admin_url( 'admin.php?page=photo-competition-manager-email-templates' ) );
			exit;
		}
	}

	/**
	 * Render email templates page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( 'photo_competition_email_templates' );

		$templates = $this->get_default_templates();
		$saved     = get_option( 'photo_comp_email_templates', array() );

		// Merge saved templates with defaults.
		foreach ( $saved as $key => $saved_template ) {
			if ( isset( $templates[ $key ] ) ) {
				$templates[ $key ]['subject'] = $saved_template['subject'];
				$templates[ $key ]['body']    = $saved_template['body'];
				$templates[ $key ]['enabled'] = $saved_template['enabled'];
			}
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Email Templates', 'photo-competition-manager' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Customize the email templates sent to members. Use merge tags to personalize messages.', 'photo-competition-manager' ) . '</p>';

		// Add custom CSS to widen the form.
		echo '<style>
			.photo-comp-email-templates .card {
				max-width: none;
			}
			.photo-comp-email-templates .form-table {
				max-width: none;
			}
			.photo-comp-email-templates .form-table th {
				width: 180px;
			}
			.photo-comp-email-templates .form-table td {
				padding-right: 0;
			}
		</style>';

		echo '<form method="post" class="photo-comp-email-templates">';
		wp_nonce_field( 'photo_competition_email_templates' );
		echo '<input type="hidden" name="photo_competition_action" value="save_email_templates" />';

		foreach ( $templates as $template_key => $template ) {
			$this->render_template_section( $template_key, $template );
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
	 * @return void
	 */
	private function render_template_section( string $template_key, array $template ): void {
		echo '<div class="card photo-comp-template-card" style="margin-bottom: 20px; padding: 20px; max-width: none;">';

		echo '<h2 style="margin-top: 0;">' . esc_html( $template['name'] ) . '</h2>';
		echo '<p class="description">' . esc_html( $template['description'] ) . '</p>';

		// Enabled toggle.
		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="templates[' . esc_attr( $template_key ) . '][enabled]" value="1" ' . checked( $template['enabled'], true, false ) . ' />';
		echo ' <strong>' . esc_html__( 'Enable this email notification', 'photo-competition-manager' ) . '</strong>';
		echo '</label>';
		echo '</p>';

		// Subject field.
		echo '<table class="form-table"><tbody>';
		echo '<tr>';
		echo '<th scope="row"><label for="template-' . esc_attr( $template_key ) . '-subject">' . esc_html__( 'Subject Line', 'photo-competition-manager' ) . '</label></th>';
		echo '<td>';
		echo '<input type="text" id="template-' . esc_attr( $template_key ) . '-subject" name="templates[' . esc_attr( $template_key ) . '][subject]" value="' . esc_attr( $template['subject'] ) . '" class="large-text" />';
		echo '</td>';
		echo '</tr>';

		// Body field.
		echo '<tr>';
		echo '<th scope="row"><label for="template-' . esc_attr( $template_key ) . '-body">' . esc_html__( 'Email Body', 'photo-competition-manager' ) . '</label></th>';
		echo '<td>';

		wp_editor(
			$template['body'],
			'template_' . $template_key . '_body',
			array(
				'textarea_name' => 'templates[' . $template_key . '][body]',
				'textarea_rows' => 12,
				'media_buttons' => false,
				'teeny'         => true,
			)
		);

		echo '<p class="description">' . esc_html__( 'Available merge tags:', 'photo-competition-manager' ) . ' ';
		$merge_tags_html = array_map(
			function ( $tag ) {
				return '<code>' . esc_html( $tag ) . '</code>';
			},
			$template['merge_tags']
		);
		echo implode( ', ', $merge_tags_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above in array_map.
		echo '</p>';
		echo '</td>';
		echo '</tr>';

		echo '</tbody></table>';

		echo '</div>';
	}

	/**
	 * Get default email templates.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_default_templates(): array {
		return array(
			'upload_reminder'      => array(
				'name'        => __( 'Upload Reminder', 'photo-competition-manager' ),
				'description' => __( 'Sent to members with a link to upload their images.', 'photo-competition-manager' ),
				'enabled'     => true,
				'subject'     => __( 'Upload your images for {competition_title}', 'photo-competition-manager' ),
				'body'        => __( "<p>Hi {member_name},</p>\n\n<p>Here is your link to upload images for {competition_title}.</p>\n\n<p><a href=\"{upload_link}\" style=\"background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block;\">Upload Images</a></p>\n\n<p>This link will remain active for 14 days.</p>\n\n<p>If you have any questions, please contact your club competitions officer.</p>", 'photo-competition-manager' ),
				'merge_tags'  => array( '{member_name}', '{competition_title}', '{upload_link}', '{site_name}' ),
			),
			'voting_opened'        => array(
				'name'        => __( 'Voting Opened', 'photo-competition-manager' ),
				'description' => __( 'Sent when voting opens for a competition.', 'photo-competition-manager' ),
				'enabled'     => false,
				'subject'     => __( 'Voting is now open for {competition_title}', 'photo-competition-manager' ),
				'body'        => __( "<p>Hi {member_name},</p>\n\n<p>Voting is now open for {competition_title}!</p>\n\n<p>Visit the voting page to see all submitted images and cast your votes.</p>\n\n<p><a href=\"{voting_page}\" style=\"background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block;\">Go to Voting Page</a></p>\n\n<p>Voting closes on {close_date}.</p>", 'photo-competition-manager' ),
				'merge_tags'  => array( '{member_name}', '{competition_title}', '{voting_page}', '{close_date}', '{site_name}' ),
			),
			'competition_closed'   => array(
				'name'        => __( 'Competition Closed', 'photo-competition-manager' ),
				'description' => __( 'Sent when a competition closes (voting ends).', 'photo-competition-manager' ),
				'enabled'     => false,
				'subject'     => __( '{competition_title} has closed', 'photo-competition-manager' ),
				'body'        => __( "<p>Hi {member_name},</p>\n\n<p>{competition_title} has now closed. Thank you for participating!</p>\n\n<p>Results will be announced soon.</p>", 'photo-competition-manager' ),
				'merge_tags'  => array( '{member_name}', '{competition_title}', '{site_name}' ),
			),
			'results_published'    => array(
				'name'        => __( 'Results Published', 'photo-competition-manager' ),
				'description' => __( 'Sent to members when competition results are available.', 'photo-competition-manager' ),
				'enabled'     => false,
				'subject'     => __( 'Results for {competition_title}', 'photo-competition-manager' ),
				'body'        => __( "<p>Hi {member_name},</p>\n\n<p>The results for {competition_title} are now available!</p>\n\n<p><a href=\"{results_page}\" style=\"background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block;\">View Results</a></p>\n\n<p>Thanks to everyone who participated.</p>", 'photo-competition-manager' ),
				'merge_tags'  => array( '{member_name}', '{competition_title}', '{results_page}', '{site_name}' ),
			),
			'submission_confirmed' => array(
				'name'        => __( 'Submission Confirmed', 'photo-competition-manager' ),
				'description' => __( 'Sent when a member successfully uploads an image.', 'photo-competition-manager' ),
				'enabled'     => false,
				'subject'     => __( 'Image uploaded successfully for {competition_title}', 'photo-competition-manager' ),
				'body'        => __( "<p>Hi {member_name},</p>\n\n<p>Your image has been successfully uploaded for {competition_title} in the {category_name} category.</p>\n\n<p>You have uploaded {current_count} of {quota} images for this category.</p>\n\n<p>Thank you for your submission!</p>", 'photo-competition-manager' ),
				'merge_tags'  => array( '{member_name}', '{competition_title}', '{category_name}', '{current_count}', '{quota}', '{site_name}' ),
			),
		);
	}
}
