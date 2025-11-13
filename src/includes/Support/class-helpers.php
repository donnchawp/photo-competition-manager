<?php
/**
 * Shared helper functions.
 *
 * @package PhotoCompetitionManager\Support
 */

namespace PhotoCompetitionManager\Support;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Format a competition slug.
 *
 * @param string $label Raw competition label.
 * @return string
 */
function format_slug( string $label ): string {
	$slug = strtolower( trim( preg_replace( '/[^a-zA-Z0-9]+/', '-', $label ), '-' ) );

	return ! empty( $slug ) ? $slug : 'competition';
}

/**
 * Sanitize a value for CSV output to prevent formula injection.
 *
 * Prevents CSV formula injection by prefixing values that start with
 * potentially dangerous characters (=, +, -, @, \t, \r) with a single quote.
 *
 * @param mixed $value The value to sanitize.
 * @return mixed The sanitized value.
 * @since 0.1.0
 */
function sanitize_csv_value( $value ) {
	// Handle non-string values.
	if ( ! is_string( $value ) ) {
		return $value;
	}

	// Check if value starts with a dangerous character.
	if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
		return "'" . $value;
	}

	return $value;
}

/**
 * Sanitize an array of values for CSV output.
 *
 * @param array<mixed> $row Array of values to sanitize.
 * @return array<mixed> Sanitized array.
 * @since 0.1.0
 */
function sanitize_csv_row( array $row ): array {
	return array_map( __NAMESPACE__ . '\sanitize_csv_value', $row );
}
