<?php
/**
 * Shared helper functions.
 *
 * @package ClubCompetitions\Support
 */

namespace ClubCompetitions\Support;

/**
 * Format a competition slug.
 *
 * @param string $label Raw competition label.
 * @return string
 */
function format_slug( string $label ): string {
	$slug = strtolower( trim( preg_replace( '/[^a-zA-Z0-9]+/', '-', $label ), '-' ) );

	return $slug ?: 'competition';
}
