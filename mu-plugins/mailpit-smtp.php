<?php
/**
 * Plugin Name: Mailpit SMTP Configuration
 * Description: Configures WordPress to use Mailpit for email testing (development only)
 * Version: 1.0.0
 * Author: Photo Competition Manager
 *
 * This MU plugin configures WordPress to send all emails to Mailpit when running in development mode.
 * It only activates when WP_DEBUG is true.
 */

// Only run in development.
if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
	return;
}

// Configure SMTP constants for PHPMailer.
if ( ! defined( 'SMTP_HOST' ) ) {
	define( 'SMTP_HOST', 'photo-competition-manager-mailpit' );
}

if ( ! defined( 'SMTP_PORT' ) ) {
	define( 'SMTP_PORT', 1025 );
}

if ( ! defined( 'SMTP_FROM' ) ) {
	define( 'SMTP_FROM', 'noreply@example.com' );
}

if ( ! defined( 'SMTP_FROM_NAME' ) ) {
	define( 'SMTP_FROM_NAME', 'WordPress' );
}

/**
 * Override WordPress default from email.
 */
add_filter(
	'wp_mail_from',
	function ( $from_email ) {
		// Ensure we return a valid email address.
		if ( empty( $from_email ) || false === strpos( $from_email, '@' ) || strpos( $from_email, '@localhost' ) !== false ) {
			return SMTP_FROM;
		}
		return $from_email;
	}
);

/**
 * Override WordPress default from name.
 */
add_filter(
	'wp_mail_from_name',
	function ( $from_name ) {
		if ( empty( $from_name ) || 'WordPress' === $from_name ) {
			return SMTP_FROM_NAME;
		}
		return $from_name;
	}
);

/**
 * Configure PHPMailer to use Mailpit SMTP server.
 *
 * @param PHPMailer $phpmailer The PHPMailer instance.
 */
add_action(
	'phpmailer_init',
	function ( $phpmailer ) {
		$phpmailer->isSMTP();
		$phpmailer->Host     = SMTP_HOST;
		$phpmailer->Port     = SMTP_PORT;
		$phpmailer->SMTPAuth = false;
		$phpmailer->From     = SMTP_FROM;
		$phpmailer->FromName = SMTP_FROM_NAME;

		// Enable debug output if needed (uncomment for troubleshooting).
		// $phpmailer->SMTPDebug = 2;
		// $phpmailer->Debugoutput = 'error_log';
	}
);

/**
 * Log wp_mail failures.
 */
add_action(
	'wp_mail_failed',
	function ( $error ) {
		error_log( '[Mailpit SMTP] wp_mail failed: ' . $error->get_error_message() );
	}
);

// Log when Mailpit SMTP is active.
error_log( '[Mailpit SMTP] Configured to use ' . SMTP_HOST . ':' . SMTP_PORT );
