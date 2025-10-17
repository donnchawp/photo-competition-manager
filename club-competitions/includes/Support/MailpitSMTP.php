<?php
/**
 * Configure WordPress to use Mailpit SMTP for development.
 *
 * @package ClubCompetitions\Support
 */

namespace ClubCompetitions\Support;

/**
 * Mailpit SMTP configuration helper.
 *
 * @package ClubCompetitions\Support
 */
class MailpitSMTP {

	/**
	 * Initialize SMTP configuration if in development environment.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Only enable in development (WP_DEBUG mode).
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// Only configure if SMTP_HOST is defined (set via docker-compose.override.yml).
		if ( ! defined( 'SMTP_HOST' ) ) {
			return;
		}

		add_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ) );
	}

	/**
	 * Configure PHPMailer to use Mailpit SMTP.
	 *
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance.
	 * @return void
	 */
	public static function configure_phpmailer( $phpmailer ): void {
		$phpmailer->isSMTP();
		$phpmailer->Host        = defined( 'SMTP_HOST' ) ? SMTP_HOST : 'club-competitions-mailpit';
		$phpmailer->Port        = defined( 'SMTP_PORT' ) ? SMTP_PORT : 1025;
		$phpmailer->SMTPAuth    = false;
		$phpmailer->SMTPSecure  = '';
		$phpmailer->SMTPAutoTLS = false;

		// Set From address if defined, or use default.
		$from_email = defined( 'SMTP_FROM' ) ? SMTP_FROM : 'wordpress@club-competitions.local';
		$from_name  = defined( 'SMTP_NAME' ) ? SMTP_NAME : 'Club Competitions';

		$phpmailer->setFrom( $from_email, $from_name );
	}
}
