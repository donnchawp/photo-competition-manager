<?php
/**
 * Shared helper functions.
 *
 * @package PhotoCompetitionManager\Support
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


namespace PhotoCompetitionManager\Support;

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
