<?php
/**
 * Email service for sending notifications.
 *
 * @package ClubCompetitions\Service
 */

namespace ClubCompetitions\Service;

/**
 * Class Email_Service
 *
 * @package ClubCompetitions\Service
 */
class Email_Service {

	/**
	 * Send upload magic link email.
	 *
	 * @param string $to_email        Recipient email address.
	 * @param string $member_name     Member name.
	 * @param string $competition_title Competition title.
	 * @param string $magic_link      Magic link URL.
	 * @return bool Whether the email was sent successfully.
	 */
	public function send_upload_link( string $to_email, string $member_name, string $competition_title, string $magic_link ): bool {
		$subject = sprintf(
			/* translators: %s: Competition title */
			__( 'Upload your images for %s', 'club-competitions' ),
			$competition_title
		);

		$message = $this->get_upload_email_body( $member_name, $competition_title, $magic_link );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		return wp_mail( $to_email, $subject, $message, $headers );
	}

	/**
	 * Send voting magic link email.
	 *
	 * @param string $to_email        Recipient email address.
	 * @param string $competition_title Competition title.
	 * @param string $magic_link      Magic link URL.
	 * @return bool Whether the email was sent successfully.
	 */
	public function send_voting_link( string $to_email, string $competition_title, string $magic_link ): bool {
		$subject = sprintf(
			/* translators: %s: Competition title */
			__( 'Vote in %s', 'club-competitions' ),
			$competition_title
		);

		$message = $this->get_voting_email_body( $competition_title, $magic_link );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		return wp_mail( $to_email, $subject, $message, $headers );
	}

	/**
	 * Get upload email body.
	 *
	 * @param string $member_name     Member name.
	 * @param string $competition_title Competition title.
	 * @param string $magic_link      Magic link URL.
	 * @return string
	 */
	private function get_upload_email_body( string $member_name, string $competition_title, string $magic_link ): string {
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
		</head>
		<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
			<div style="max-width: 600px; margin: 0 auto; padding: 20px;">
				<h2 style="color: #0073aa;"><?php echo esc_html( $competition_title ); ?></h2>

				<p>
				<?php
					printf(
						/* translators: %s: Member name */
						esc_html__( 'Hi %s,', 'club-competitions' ),
						esc_html( $member_name )
					);
				?>
				</p>

				<p><?php esc_html_e( 'Here is your link to upload images for this competition. Click the button below to access the upload page:', 'club-competitions' ); ?></p>

				<p style="margin: 30px 0;">
					<a href="<?php echo esc_url( $magic_link ); ?>"
						style="background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block;">
						<?php esc_html_e( 'Upload Images', 'club-competitions' ); ?>
					</a>
				</p>

				<p style="color: #666; font-size: 14px;">
					<?php esc_html_e( 'This link will remain active for 14 days so you can return and continue uploading during that window.', 'club-competitions' ); ?>
				</p>

				<p style="color: #666; font-size: 14px;">
					<?php esc_html_e( 'If you did not request this email, it may have been sent to you by your club competitions officer. You can safely ignore this email if you do not want to take part in this competition.', 'club-competitions' ); ?>
				</p>
				<p style="color: #666; font-size: 14px;">
					<?php esc_html_e( 'If you have any questions, please contact your club competitions officer.', 'club-competitions' ); ?>
				</p>

				<hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">

				<p style="color: #999; font-size: 12px;">
					<?php
					printf(
						/* translators: %s: Site name */
						esc_html__( 'This email was sent by %s', 'club-competitions' ),
						esc_html( get_bloginfo( 'name' ) )
					);
					?>
				</p>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get voting email body.
	 *
	 * @param string $competition_title Competition title.
	 * @param string $magic_link      Magic link URL.
	 * @return string
	 */
	private function get_voting_email_body( string $competition_title, string $magic_link ): string {
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
		</head>
		<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
			<div style="max-width: 600px; margin: 0 auto; padding: 20px;">
				<h2 style="color: #0073aa;"><?php echo esc_html( $competition_title ); ?></h2>

				<p><?php esc_html_e( 'You requested to vote in this competition. Click the link below to access the voting form:', 'club-competitions' ); ?></p>

				<p style="margin: 30px 0;">
					<a href="<?php echo esc_url( $magic_link ); ?>"
						style="background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block;">
						<?php esc_html_e( 'Vote Now', 'club-competitions' ); ?>
					</a>
				</p>

				<p style="color: #666; font-size: 14px;">
					<?php esc_html_e( 'This link will expire in 1 hour and can only be used once.', 'club-competitions' ); ?>
				</p>

				<p style="color: #666; font-size: 14px;">
					<?php esc_html_e( 'If you did not request this link, you can safely ignore this email.', 'club-competitions' ); ?>
				</p>

				<hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">

				<p style="color: #999; font-size: 12px;">
					<?php
					printf(
						/* translators: %s: Site name */
						esc_html__( 'This email was sent by %s', 'club-competitions' ),
						esc_html( get_bloginfo( 'name' ) )
					);
					?>
				</p>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}
}
