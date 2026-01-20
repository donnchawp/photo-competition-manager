<?php
/**
 * Email service for sending notifications.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Support\Email_Configuration;

/**
 * Class Email_Service
 *
 * @package PhotoCompetitionManager\Service
 */
class Email_Service {

	/**
	 * Event logger.
	 *
	 * @var Event_Logger|null
	 */
	private $event_logger;

	/**
	 * Constructor.
	 *
	 * @param Event_Logger|null $event_logger Optional event logger instance.
	 */
	public function __construct( ?Event_Logger $event_logger = null ) {
		$this->event_logger = $event_logger ?? new Event_Logger();
	}

	/**
	 * Send upload magic link email.
	 *
	 * @param string      $to_email        Recipient email address.
	 * @param string      $member_name     Member name.
	 * @param string      $competition_title Competition title.
	 * @param string      $magic_link      Magic link URL.
	 * @param int|null    $competition_id  Optional competition ID for logging.
	 * @param string|null $voting_page_url Optional voting page URL.
	 * @return bool Whether the email was sent successfully.
	 */
	public function send_upload_link( string $to_email, string $member_name, string $competition_title, string $magic_link, ?int $competition_id = null, ?string $voting_page_url = null ): bool {
		// Check if template is enabled and customized.
		$template = $this->get_template( 'upload_reminder' );

		if ( $template && $template['enabled'] ) {
			$merge_data = array(
				'{member_name}'       => $member_name,
				'{competition_title}' => $competition_title,
				'{upload_link}'       => $magic_link,
				'{voting_page}'       => $voting_page_url ?? '',
				'{site_name}'         => get_bloginfo( 'name' ),
			);

			$subject = $this->replace_merge_tags( $template['subject'], $merge_data );
			$message = $this->replace_merge_tags( $template['body'], $merge_data );
			$message = $this->wrap_html_email( $message );
		} else {
			// Fallback to default hardcoded email.
			$subject = sprintf(
				/* translators: %s: Competition title */
				__( 'Upload your images for %s', 'photo-competition-manager' ),
				$competition_title
			);
			$message = $this->get_upload_email_body( $member_name, $competition_title, $magic_link, $voting_page_url );
		}

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		$result = $this->send_mail( $to_email, $this->prefix_subject( $subject ), $message, $headers );
		// Log the email.
		if ( $result && $this->event_logger ) {
			$this->event_logger->log_email_sent(
				$competition_id,
				'upload_reminder',
				$member_name,
				array( 'email' => $to_email )
			);
		}

		return $result;
	}

	/**
	 * Send voting magic link email.
	 *
	 * @param string   $to_email        Recipient email address.
	 * @param string   $competition_title Competition title.
	 * @param string   $magic_link      Magic link URL.
	 * @param int|null $competition_id  Optional competition ID for logging.
	 * @return bool Whether the email was sent successfully.
	 */
	public function send_voting_link( string $to_email, string $competition_title, string $magic_link, ?int $competition_id = null ): bool {
		// Check if template is enabled and customized.
		$template = $this->get_template( 'voting_opened' );

		if ( $template && $template['enabled'] ) {
			$merge_data = array(
				'{competition_title}' => $competition_title,
				'{voting_page}'       => $magic_link,
				'{site_name}'         => get_bloginfo( 'name' ),
			);

			$subject = $this->replace_merge_tags( $template['subject'], $merge_data );
			$message = $this->replace_merge_tags( $template['body'], $merge_data );
			$message = $this->wrap_html_email( $message );
		} else {
			// Fallback to default hardcoded email.
			$subject = sprintf(
				/* translators: %s: Competition title */
				__( 'Vote in %s', 'photo-competition-manager' ),
				$competition_title
			);
			$message = $this->get_voting_email_body( $competition_title, $magic_link );
		}

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		$result = $this->send_mail( $to_email, $this->prefix_subject( $subject ), $message, $headers );

		// Log the email.
		if ( $result && $this->event_logger ) {
			$this->event_logger->log_email_sent(
				$competition_id,
				'voting_opened',
				$to_email,
				array( 'email' => $to_email )
			);
		}

		return $result;
	}

	/**
	 * Get upload email body.
	 *
	 * @param string      $member_name     Member name.
	 * @param string      $competition_title Competition title.
	 * @param string      $magic_link      Magic link URL.
	 * @param string|null $voting_page_url Optional voting page URL.
	 * @return string
	 */
	private function get_upload_email_body( string $member_name, string $competition_title, string $magic_link, ?string $voting_page_url = null ): string {
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
						esc_html__( 'Hi %s,', 'photo-competition-manager' ),
						esc_html( $member_name )
					);
				?>
				</p>

				<p><?php esc_html_e( 'Here is your link to upload images for this competition. Click the button below to access the upload page:', 'photo-competition-manager' ); ?></p>

				<p style="margin: 30px 0;">
					<a href="<?php echo esc_url( $magic_link ); ?>"
						style="background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block;">
						<?php esc_html_e( 'Upload Images', 'photo-competition-manager' ); ?>
					</a>
				</p>

				<p style="color: #666; font-size: 14px;">
					<?php esc_html_e( 'This link will remain active for 14 days so you can return and continue uploading during that window.', 'photo-competition-manager' ); ?>
				</p>

				<?php if ( ! empty( $voting_page_url ) ) : ?>
					<p style="color: #666; font-size: 14px;">
						<?php esc_html_e( 'Once voting opens, you can cast your votes at:', 'photo-competition-manager' ); ?>
						<br>
						<a href="<?php echo esc_url( $voting_page_url ); ?>"><?php echo esc_url( $voting_page_url ); ?></a>
					</p>
				<?php endif; ?>

				<p style="color: #666; font-size: 14px;">
					<?php esc_html_e( 'If you did not request this email, it may have been sent to you by your club competitions officer. You can safely ignore this email if you do not want to take part in this competition.', 'photo-competition-manager' ); ?>
				</p>
				<p style="color: #666; font-size: 14px;">
					<?php esc_html_e( 'If you have any questions, please contact your club competitions officer.', 'photo-competition-manager' ); ?>
				</p>

				<hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">

				<p style="color: #999; font-size: 12px;">
					<?php
					printf(
						/* translators: %s: Site name */
						esc_html__( 'This email was sent by %s', 'photo-competition-manager' ),
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

				<p><?php esc_html_e( 'You requested to vote in this competition. Click the link below to access the voting form:', 'photo-competition-manager' ); ?></p>

				<p style="margin: 30px 0;">
					<a href="<?php echo esc_url( $magic_link ); ?>"
						style="background-color: #0073aa; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block;">
						<?php esc_html_e( 'Vote Now', 'photo-competition-manager' ); ?>
					</a>
				</p>

				<p style="color: #666; font-size: 14px;">
					<?php esc_html_e( 'This link will expire in 1 hour and can only be used once.', 'photo-competition-manager' ); ?>
				</p>

				<p style="color: #666; font-size: 14px;">
					<?php esc_html_e( 'If you did not request this link, you can safely ignore this email.', 'photo-competition-manager' ); ?>
				</p>

				<hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">

				<p style="color: #999; font-size: 12px;">
					<?php
					printf(
						/* translators: %s: Site name */
						esc_html__( 'This email was sent by %s', 'photo-competition-manager' ),
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
	 * Send competition results email to a member.
	 *
	 * @param string               $to_email          Recipient email address.
	 * @param string               $member_name       Member name.
	 * @param string               $competition_title Competition title.
	 * @param array<string, mixed> $member_results    Member's results data.
	 * @param int|null             $competition_id    Optional competition ID for logging.
	 * @return bool Whether the email was sent successfully.
	 */
	public function send_results_email( string $to_email, string $member_name, string $competition_title, array $member_results, ?int $competition_id = null ): bool {
		// Note: This method sends detailed results data.
		// For a simple notification without detailed results, use send_results_published_notification() instead.
		$subject = sprintf(
			/* translators: %s: Competition title */
			__( 'Results for %s', 'photo-competition-manager' ),
			$competition_title
		);

		$message = $this->get_results_email_body( $member_name, $competition_title, $member_results );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);

		$result = $this->send_mail( $to_email, $this->prefix_subject( $subject ), $message, $headers );

		// Log the email.
		if ( $result && $this->event_logger ) {
			$this->event_logger->log_email_sent(
				$competition_id,
				'results_email',
				$member_name,
				array( 'email' => $to_email )
			);
		}

		return $result;
	}

	/**
	 * Send submission confirmed notification.
	 *
	 * @param string   $to_email          Recipient email.
	 * @param string   $member_name       Member name.
	 * @param string   $competition_title Competition title.
	 * @param string   $category_name     Category name.
	 * @param int      $current_count     Current submission count.
	 * @param int      $quota             Maximum allowed submissions.
	 * @param int|null $competition_id    Optional competition ID for logging.
	 * @return bool Whether email was sent successfully.
	 */
	public function send_submission_confirmed_notification(
		string $to_email,
		string $member_name,
		string $competition_title,
		string $category_name,
		int $current_count,
		int $quota,
		?int $competition_id = null
	): bool {
		$template = $this->get_template( 'submission_confirmed' );

		if ( ! $template || ! $template['enabled'] ) {
			return false; // Template disabled, skip sending.
		}

		$merge_data = array(
			'{member_name}'       => $member_name,
			'{competition_title}' => $competition_title,
			'{category_name}'     => $category_name,
			'{current_count}'     => (string) $current_count,
			'{quota}'             => (string) $quota,
			'{site_name}'         => get_bloginfo( 'name' ),
		);

		$subject = $this->replace_merge_tags( $template['subject'], $merge_data );
		$message = $this->replace_merge_tags( $template['body'], $merge_data );
		$message = $this->wrap_html_email( $message );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$result = $this->send_mail( $to_email, $this->prefix_subject( $subject ), $message, $headers );

		// Log the email.
		if ( $result && $this->event_logger ) {
			$this->event_logger->log_email_sent(
				$competition_id,
				'submission_confirmed',
				$member_name,
				array(
					'email'    => $to_email,
					'category' => $category_name,
				)
			);
		}

		return $result;
	}

	/**
	 * Send voting opened notification.
	 *
	 * @param string   $to_email          Recipient email.
	 * @param string   $member_name       Member name.
	 * @param string   $competition_title Competition title.
	 * @param string   $voting_page_url   Voting page URL.
	 * @param string   $close_date        Competition close date (formatted).
	 * @param int|null $competition_id    Optional competition ID for logging.
	 * @return bool Whether email was sent successfully.
	 */
	public function send_voting_opened_notification(
		string $to_email,
		string $member_name,
		string $competition_title,
		string $voting_page_url,
		string $close_date,
		?int $competition_id = null
	): bool {
		$template = $this->get_template( 'voting_opened' );

		if ( ! $template || ! $template['enabled'] ) {
			return false; // Template disabled, skip sending.
		}

		$merge_data = array(
			'{member_name}'       => $member_name,
			'{competition_title}' => $competition_title,
			'{voting_page}'       => $voting_page_url,
			'{close_date}'        => $close_date,
			'{site_name}'         => get_bloginfo( 'name' ),
		);

		$subject = $this->replace_merge_tags( $template['subject'], $merge_data );
		$message = $this->replace_merge_tags( $template['body'], $merge_data );
		$message = $this->wrap_html_email( $message );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$result = $this->send_mail( $to_email, $this->prefix_subject( $subject ), $message, $headers );

		// Log the email.
		if ( $result && $this->event_logger ) {
			$this->event_logger->log_email_sent(
				$competition_id,
				'voting_opened_notification',
				$member_name,
				array( 'email' => $to_email )
			);
		}

		return $result;
	}

	/**
	 * Send competition closed notification.
	 *
	 * @param string   $to_email          Recipient email.
	 * @param string   $member_name       Member name.
	 * @param string   $competition_title Competition title.
	 * @param int|null $competition_id    Optional competition ID for logging.
	 * @return bool Whether email was sent successfully.
	 */
	public function send_competition_closed_notification(
		string $to_email,
		string $member_name,
		string $competition_title,
		?int $competition_id = null
	): bool {
		$template = $this->get_template( 'competition_closed' );

		if ( ! $template || ! $template['enabled'] ) {
			return false; // Template disabled, skip sending.
		}

		$merge_data = array(
			'{member_name}'       => $member_name,
			'{competition_title}' => $competition_title,
			'{site_name}'         => get_bloginfo( 'name' ),
		);

		$subject = $this->replace_merge_tags( $template['subject'], $merge_data );
		$message = $this->replace_merge_tags( $template['body'], $merge_data );
		$message = $this->wrap_html_email( $message );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$result = $this->send_mail( $to_email, $this->prefix_subject( $subject ), $message, $headers );

		// Log the email.
		if ( $result && $this->event_logger ) {
			$this->event_logger->log_email_sent(
				$competition_id,
				'competition_closed',
				$member_name,
				array( 'email' => $to_email )
			);
		}

		return $result;
	}

	/**
	 * Send results published notification.
	 *
	 * @param string   $to_email          Recipient email.
	 * @param string   $member_name       Member name.
	 * @param string   $competition_title Competition title.
	 * @param string   $results_page_url  Results page URL.
	 * @param int|null $competition_id    Optional competition ID for logging.
	 * @return bool Whether email was sent successfully.
	 */
	public function send_results_published_notification(
		string $to_email,
		string $member_name,
		string $competition_title,
		string $results_page_url,
		?int $competition_id = null
	): bool {
		$template = $this->get_template( 'results_published' );

		if ( ! $template || ! $template['enabled'] ) {
			return false; // Template disabled, skip sending.
		}

		$merge_data = array(
			'{member_name}'       => $member_name,
			'{competition_title}' => $competition_title,
			'{results_page}'      => $results_page_url,
			'{site_name}'         => get_bloginfo( 'name' ),
		);

		$subject = $this->replace_merge_tags( $template['subject'], $merge_data );
		$message = $this->replace_merge_tags( $template['body'], $merge_data );
		$message = $this->wrap_html_email( $message );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$result = $this->send_mail( $to_email, $this->prefix_subject( $subject ), $message, $headers );

		// Log the email.
		if ( $result && $this->event_logger ) {
			$this->event_logger->log_email_sent(
				$competition_id,
				'results_published',
				$member_name,
				array( 'email' => $to_email )
			);
		}

		return $result;
	}

	/**
	 * Get results email body.
	 *
	 * @param string               $member_name       Member name.
	 * @param string               $competition_title Competition title.
	 * @param array<string, mixed> $member_results    Member's results data.
	 * @return string
	 */
	private function get_results_email_body( string $member_name, string $competition_title, array $member_results ): string {
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
		</head>
		<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
			<div style="max-width: 600px; margin: 0 auto; padding: 20px;">
				<h2 style="color: #0073aa;"><?php echo esc_html( $competition_title ); ?> - <?php esc_html_e( 'Results', 'photo-competition-manager' ); ?></h2>

				<p>
				<?php
					printf(
						/* translators: %s: Member name */
						esc_html__( 'Hi %s,', 'photo-competition-manager' ),
						esc_html( $member_name )
					);
				?>
				</p>

				<p><?php esc_html_e( 'The results for this competition are now available. Here are your results:', 'photo-competition-manager' ); ?></p>

				<?php if ( empty( $member_results['images'] ) ) : ?>
					<p><em><?php esc_html_e( 'You did not submit any images for this competition.', 'photo-competition-manager' ); ?></em></p>
				<?php else : ?>
					<?php foreach ( $member_results['images'] as $image_data ) : ?>
						<div style="margin: 30px 0; padding: 20px; background-color: #f9f9f9; border-left: 4px solid #0073aa;">
							<h3 style="margin-top: 0; color: #0073aa;">
								<?php echo esc_html( $image_data['category_label'] ); ?> -
								<?php
								printf(
									/* translators: %s: Image number */
									esc_html__( 'Image #%s', 'photo-competition-manager' ),
									esc_html( $image_data['image_number'] )
								);
								?>
							</h3>

							<?php if ( ! empty( $image_data['thumbnail_url'] ) ) : ?>
								<div style="margin-bottom: 15px;">
									<img src="<?php echo esc_url( $image_data['thumbnail_url'] ); ?>" alt="<?php esc_attr_e( 'Your submitted image', 'photo-competition-manager' ); ?>" style="max-width: 200px; height: auto; border: 1px solid #ddd; border-radius: 4px;">
								</div>
							<?php endif; ?>

							<table style="width: 100%; border-collapse: collapse;">
								<tr>
									<td style="padding: 8px 0; font-weight: bold; width: 40%;"><?php esc_html_e( 'Rank:', 'photo-competition-manager' ); ?></td>
									<td style="padding: 8px 0;">
										<?php
										$rank_display = $image_data['rank'];
										if ( ! empty( $image_data['total_in_grade'] ) ) {
											$rank_display .= ' ' . sprintf(
												/* translators: %d: Total number of images in the grade */
												__( 'of %d', 'photo-competition-manager' ),
												$image_data['total_in_grade']
											);
										}
										if ( ! empty( $image_data['grade'] ) ) {
											$rank_display .= ' (' . esc_html( $image_data['grade'] ) . ')';
										}
										echo esc_html( $rank_display );
										?>
									</td>
								</tr>
								<tr>
									<td style="padding: 8px 0; font-weight: bold;"><?php esc_html_e( 'Final Score:', 'photo-competition-manager' ); ?></td>
									<td style="padding: 8px 0;"><strong><?php echo esc_html( number_format( $image_data['statistics']['average'] * $image_data['statistics']['count'], 0 ) ); ?></strong></td>
								</tr>
								<tr>
									<td style="padding: 8px 0; font-weight: bold;"><?php esc_html_e( 'Total Votes:', 'photo-competition-manager' ); ?></td>
									<td style="padding: 8px 0;"><?php echo esc_html( $image_data['statistics']['count'] ); ?></td>
								</tr>
								<tr>
									<td style="padding: 8px 0; font-weight: bold;"><?php esc_html_e( 'Average Score:', 'photo-competition-manager' ); ?></td>
									<td style="padding: 8px 0;"><?php echo esc_html( number_format( $image_data['statistics']['average'], 2 ) ); ?></td>
								</tr>
								<tr>
									<td style="padding: 8px 0; font-weight: bold;"><?php esc_html_e( 'Median Score:', 'photo-competition-manager' ); ?></td>
									<td style="padding: 8px 0;"><?php echo esc_html( number_format( $image_data['statistics']['median'], 2 ) ); ?></td>
								</tr>
								<tr>
									<td style="padding: 8px 0; font-weight: bold;"><?php esc_html_e( 'Score Range:', 'photo-competition-manager' ); ?></td>
									<td style="padding: 8px 0;">
										<?php
										printf(
											'%s - %s',
											esc_html( number_format( $image_data['statistics']['min'], 0 ) ),
											esc_html( number_format( $image_data['statistics']['max'], 0 ) )
										);
										?>
									</td>
								</tr>
							</table>

							<h4 style="margin-top: 20px; margin-bottom: 10px;"><?php esc_html_e( 'Individual Votes:', 'photo-competition-manager' ); ?></h4>
							<table style="width: 100%; border-collapse: collapse; background-color: white;">
								<thead>
									<tr style="background-color: #0073aa; color: white;">
										<th style="padding: 10px; text-align: left;"><?php esc_html_e( 'Vote #', 'photo-competition-manager' ); ?></th>
										<th style="padding: 10px; text-align: left;"><?php esc_html_e( 'Score', 'photo-competition-manager' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$vote_number = 1;
									foreach ( $image_data['votes'] as $vote ) :
										?>
										<tr style="border-bottom: 1px solid #ddd;">
											<td style="padding: 10px;"><?php echo esc_html( $vote_number ); ?></td>
											<td style="padding: 10px;"><strong><?php echo esc_html( number_format( (float) $vote->score, 0 ) ); ?></strong></td>
										</tr>
										<?php
										++$vote_number;
									endforeach;
									?>
								</tbody>
							</table>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>

				<p style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 14px;">
					<?php esc_html_e( 'Thank you for participating in this competition!', 'photo-competition-manager' ); ?>
				</p>
			</div>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get a specific email template.
	 *
	 * @param string $template_key Template key.
	 * @return array<string, mixed>|null Template data or null if not found/enabled.
	 */
	private function get_template( string $template_key ): ?array {
		$templates = get_option( 'photo_comp_email_templates', array() );

		if ( ! isset( $templates[ $template_key ] ) ) {
			return null;
		}

		$template = $templates[ $template_key ];

		// Return null if template is disabled or has no content.
		if ( empty( $template['enabled'] ) || empty( $template['subject'] ) || empty( $template['body'] ) ) {
			return null;
		}

		return $template;
	}

	/**
	 * Replace merge tags in a string.
	 *
	 * @param string               $content    Content with merge tags.
	 * @param array<string, mixed> $merge_data Merge tag data.
	 * @return string Content with merge tags replaced.
	 */
	private function replace_merge_tags( string $content, array $merge_data ): string {
		return str_replace( array_keys( $merge_data ), array_values( $merge_data ), $content );
	}

	/**
	 * Prefix email subject with site title.
	 *
	 * @param string $subject The email subject.
	 * @return string Subject prefixed with [Site Title].
	 */
	private function prefix_subject( string $subject ): string {
		$site_title = get_bloginfo( 'name' );
		return sprintf( '[%s] %s', $site_title, $subject );
	}

	/**
	 * Wrap content in HTML email template.
	 *
	 * @param string $content Email body content.
	 * @return string Wrapped HTML email.
	 */
	private function wrap_html_email( string $content ): string {
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
		</head>
		<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
			<div style="max-width: 600px; margin: 0 auto; padding: 20px;">
				<?php echo wp_kses_post( wpautop( $content ) ); ?>

				<hr style="border: none; border-top: 1px solid #ddd; margin: 30px 0;">

				<p style="color: #999; font-size: 12px;">
					<?php
					printf(
						/* translators: %s: Site name */
						esc_html__( 'This email was sent by %s', 'photo-competition-manager' ),
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
	 * Send an email with plugin context flag set.
	 *
	 * Wraps wp_mail to ensure Email_Configuration knows this is a plugin email.
	 *
	 * @param string       $to      Recipient email address.
	 * @param string       $subject Email subject.
	 * @param string       $message Email body.
	 * @param array|string $headers Optional headers.
	 * @return bool Whether the email was sent successfully.
	 */
	private function send_mail( string $to, string $subject, string $message, $headers = array() ): bool {
		Email_Configuration::begin_plugin_email();
		$result = wp_mail( $to, $subject, $message, $headers );
		Email_Configuration::end_plugin_email();
		return $result;
	}
}
