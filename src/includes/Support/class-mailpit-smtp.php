<?php
/**
 * Configure WordPress to use Mailpit SMTP for development.
 *
 * @package PhotoCompetitionManager\Support
 */

namespace PhotoCompetitionManager\Support;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Mailpit SMTP configuration helper.
 *
 * @package PhotoCompetitionManager\Support
 */
class Mailpit_SMTP {

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
		// phpcs:ignore
		$phpmailer->Host        = defined( 'SMTP_HOST' ) ? SMTP_HOST : 'photo-competition-manager-mailpit';
		// phpcs:ignore
		$phpmailer->Port        = defined( 'SMTP_PORT' ) ? SMTP_PORT : 1025;
		// phpcs:ignore
		$phpmailer->SMTPAuth    = false;
		// phpcs:ignore
		$phpmailer->SMTPSecure  = '';
		// phpcs:ignore
		$phpmailer->SMTPAutoTLS = false;

		// Only set From address if not already customized by plugin email configuration.
		// WordPress applies wp_mail_from and wp_mail_from_name filters before phpmailer_init.
		// Check if the From address looks like a default WordPress address.
		$default_addresses = array(
			'wordpress@localhost',
			'wordpress@example.com',
		);

		// Add SERVER_NAME variant if available.
		if ( isset( $_SERVER['SERVER_NAME'] ) ) {
			$default_addresses[] = 'wordpress@' . sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) );
		}

		// Check if From is empty OR matches a default WordPress address (but NOT the SMTP_FROM constant).
		// If SMTP_FROM is defined, don't treat its value as "default" since it's already been customized.
		$smtp_from_constant = defined( 'SMTP_FROM' ) ? SMTP_FROM : '';
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		$is_default = empty( $phpmailer->From ) ||
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
			( in_array( $phpmailer->From, $default_addresses, true ) && $phpmailer->From !== $smtp_from_constant );

		if ( $is_default ) {
			$from_email = defined( 'SMTP_FROM' ) ? SMTP_FROM : 'wordpress@photo-competition-manager.local';
			$from_name  = defined( 'SMTP_NAME' ) ? SMTP_NAME : 'Photo Competition Manager';

			$phpmailer->setFrom( $from_email, $from_name );
		}
	}
}
