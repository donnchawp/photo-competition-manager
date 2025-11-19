<?php
/**
 * Email configuration handler for global email settings.
 *
 * @package PhotoCompetitionManager\Support
 */

namespace PhotoCompetitionManager\Support;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Class Email_Configuration
 *
 * Handles global email sender configuration for all plugin emails.
 *
 * @package PhotoCompetitionManager\Support
 */
class Email_Configuration {

	/**
	 * Initialize email configuration hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'wp_mail_from', array( __CLASS__, 'set_from_email' ), 10, 1 );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'set_from_name' ), 10, 1 );
	}

	/**
	 * Set the "From" email address for plugin emails.
	 *
	 * @param string $from_email Default from email address.
	 * @return string Modified from email address.
	 */
	public static function set_from_email( string $from_email ): string {
		// Only modify email if we're in a plugin context.
		if ( ! self::is_plugin_email() ) {
			return $from_email;
		}

		$settings     = self::get_email_settings();
		$custom_email = $settings['from_email'] ?? '';

		// Return custom email if set and valid, otherwise use default.
		if ( ! empty( $custom_email ) && is_email( $custom_email ) ) {
			return $custom_email;
		}

		return $from_email;
	}

	/**
	 * Set the "From" name for plugin emails.
	 *
	 * @param string $from_name Default from name.
	 * @return string Modified from name.
	 */
	public static function set_from_name( string $from_name ): string {
		// Only modify name if we're in a plugin context.
		if ( ! self::is_plugin_email() ) {
			return $from_name;
		}

		$settings    = self::get_email_settings();
		$custom_name = $settings['from_name'] ?? '';

		// Return custom name if set, otherwise use default.
		if ( ! empty( $custom_name ) ) {
			return $custom_name;
		}

		return $from_name;
	}

	/**
	 * Check if the current email is being sent by this plugin.
	 *
	 * @return bool True if this is a plugin email, false otherwise.
	 */
	private static function is_plugin_email(): bool {
		// Get the call stack to determine if Email_Service is in the chain.
		$backtrace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace

		foreach ( $backtrace as $trace ) {
			if ( isset( $trace['class'] ) && 'PhotoCompetitionManager\Service\Email_Service' === $trace['class'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get email configuration settings from global plugin settings.
	 *
	 * @return array<string, string> Email settings array with 'from_name' and 'from_email'.
	 */
	private static function get_email_settings(): array {
		$saved = get_option( 'photo_comp_default_settings', '' );

		if ( empty( $saved ) ) {
			return array(
				'from_name'  => '',
				'from_email' => '',
			);
		}

		$settings = Competition_Settings::parse( $saved );

		return $settings['email'] ?? array(
			'from_name'  => '',
			'from_email' => '',
		);
	}
}
