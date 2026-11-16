<?php
/**
 * The link a judge scores from.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Builds a judge's link: the competition's voting page with the judge's token.
 *
 * @since 0.4.0
 */
final class Judge_Link {

	/**
	 * A judge's link to the voting page.
	 *
	 * @param object $competition Competition row.
	 * @param string $token       The judge's token for the competition.
	 * @return string The link, or '' when the competition has no voting page.
	 */
	public static function url( object $competition, string $token ): string {
		$page = Competition_Settings::page_url( 'voting_page', $competition );

		return '' === $page ? '' : add_query_arg( 'judge', rawurlencode( $token ), $page );
	}
}
