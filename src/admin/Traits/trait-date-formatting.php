<?php
/**
 * Date formatting utilities for admin screens.
 *
 * @package PhotoCompetitionManager\Admin\Traits
 */

namespace PhotoCompetitionManager\Admin\Traits;

/**
 * Provides date formatting and parsing methods with locale support.
 *
 * @since 0.1.0
 */
trait Date_Formatting {

	/**
	 * Format datetime for display using site locale.
	 *
	 * @param  string|null $datetime Datetime value.
	 * @return string
	 */
	private function format_datetime( ?string $datetime ): string {
		if ( empty( $datetime ) ) {
			return '—';
		}

		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return '—';
		}

		$date_format = $this->get_display_date_format();
		$time_format = get_option( 'time_format' );
		$format      = $date_format . ( ! empty( $time_format ) ? ' ' . $time_format : '' );

		return wp_date( $format, $timestamp );
	}

	/**
	 * Determine the date format to display, accounting for locale defaults.
	 *
	 * @return string
	 */
	private function get_display_date_format(): string {
		$locale = get_locale();

		if ( in_array( $locale, array( 'en_GB', 'en_AU', 'en_NZ', 'en_IE', 'en_ZA' ), true ) ) {
			return 'd/m/Y';
		}

		$format = get_option( 'date_format' );

		if ( empty( $format ) ) {
			$format = 'F j, Y';
		}

		return $format;
	}

	/**
	 * UI label format (human readable).
	 *
	 * @return string
	 */
	private function get_ui_date_label(): string {
		$locale = get_locale();

		if ( in_array( $locale, array( 'en_GB', 'en_AU', 'en_NZ', 'en_IE', 'en_ZA' ), true ) ) {
			return 'dd/mm/yyyy';
		}

		return 'yyyy-mm-dd';
	}

	/**
	 * Format stored datetime for HTML date inputs.
	 *
	 * @param  string|null $datetime Datetime value.
	 * @return string
	 */
	private function format_date_for_input( ?string $datetime ): string {
		if ( empty( $datetime ) ) {
			return '';
		}

		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Parse user input to normalized Y-m-d format.
	 *
	 * @param  string $raw Raw input.
	 * @return string|null
	 */
	private function parse_date_input( string $raw ): ?string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return null;
		}

		$tz = wp_timezone();

		$dt = \DateTime::createFromFormat( 'Y-m-d', $raw, $tz );

		if ( $dt instanceof \DateTimeInterface ) {
			return $dt->format( 'Y-m-d' );
		}

		$format = $this->get_display_date_format();
		$dt     = \DateTime::createFromFormat( $format, $raw, $tz );

		if ( $dt instanceof \DateTimeInterface ) {
			return gmdate( 'Y-m-d', $dt->getTimestamp() );
		}

		$timestamp = strtotime( $raw );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d', $timestamp );
	}
}
