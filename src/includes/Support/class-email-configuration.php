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
	 * Flag indicating if the current email is being sent by this plugin.
	 *
	 * @var bool
	 */
	private static $is_sending_plugin_email = false;

	/**
	 * Mark the start of sending a plugin email.
	 *
	 * Call this before wp_mail() in Email_Service.
	 *
	 * @return void
	 */
	public static function begin_plugin_email(): void {
		self::$is_sending_plugin_email = true;
	}

	/**
	 * Mark the end of sending a plugin email.
	 *
	 * Call this after wp_mail() in Email_Service.
	 *
	 * @return void
	 */
	public static function end_plugin_email(): void {
		self::$is_sending_plugin_email = false;
	}

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
	 * @param string|null $from_email Default from email address.
	 * @return string Modified from email address.
	 */
	public static function set_from_email( $from_email ): string {
		// Only modify email if we're in a plugin context.
		if ( ! self::is_plugin_email() ) {
			return $from_email ?? '';
		}

		$settings     = self::get_email_settings();
		$custom_email = $settings['from_email'] ?? '';

		// Return custom email if set and valid, otherwise use default.
		if ( ! empty( $custom_email ) && is_email( $custom_email ) ) {
			return $custom_email;
		}

		return $from_email ?? '';
	}

	/**
	 * Set the "From" name for plugin emails.
	 *
	 * @param string|null $from_name Default from name.
	 * @return string Modified from name.
	 */
	public static function set_from_name( $from_name ): string {
		// Only modify name if we're in a plugin context.
		if ( ! self::is_plugin_email() ) {
			return $from_name ?? '';
		}

		$settings    = self::get_email_settings();
		$custom_name = $settings['from_name'] ?? '';

		// Return custom name if set, otherwise use default.
		if ( ! empty( $custom_name ) ) {
			return $custom_name;
		}

		return $from_name ?? '';
	}

	/**
	 * Check if the current email is being sent by this plugin.
	 *
	 * @return bool True if this is a plugin email, false otherwise.
	 */
	private static function is_plugin_email(): bool {
		return self::$is_sending_plugin_email;
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
