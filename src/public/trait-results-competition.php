<?php
/**
 * How a results page picks its competition and whether it may show results.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;

/**
 * Competition lookup and the results visibility check, shared by the
 * Results and Top 3 shortcodes so the two pages always agree.
 *
 * The using class must hold a Competitions_Repository in
 * `$this->competitions_repo`.
 *
 * @since 0.4.0
 */
trait Results_Competition {

	/**
	 * The `?share=` hash from the request, or '' when there isn't one.
	 *
	 * @return string
	 */
	private function requested_share_hash(): string {
		return isset( $_GET['share'] ) ? sanitize_text_field( wp_unslash( $_GET['share'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only parameter for share link access.
	}

	/**
	 * Find the competition a results page should show.
	 *
	 * A named competition is looked up by slug. Without one, a share hash
	 * picks its competition, and otherwise the page shows the latest
	 * competition with results out.
	 *
	 * @param string $slug       Competition slug from the shortcode, or ''.
	 * @param string $share_hash Share hash from the request, or ''.
	 * @return object|WP_Error The competition, or an error to show instead.
	 */
	private function resolve_competition( string $slug, string $share_hash ) {
		// empty() so competition="0" falls back too, as it always has.
		if ( ! empty( $slug ) ) {
			$competition = $this->competitions_repo->find_by_slug( $slug );

			return $competition ? $competition : new WP_Error( 'competition_not_found', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$competition = $this->competitions_repo->find_by_share_hash( $share_hash );

		if ( ! $competition ) {
			$competition = $this->workflow->find_for_results();
		}

		return $competition ? $competition : new WP_Error( 'no_competitions', __( 'No competitions found.', 'photo-competition-manager' ) );
	}

	/**
	 * Whether a competition's results may be shown: they've been
	 * published, or the request carries the competition's share hash.
	 *
	 * @param object $competition Competition.
	 * @param string $share_hash  Share hash from the request, or ''.
	 * @return bool
	 */
	private function results_viewable( object $competition, string $share_hash ): bool {
		if ( $this->workflow->results_published( $competition ) ) {
			return true;
		}

		$stored_hash = (string) ( $competition->share_hash ?? '' );

		return '' !== $stored_hash && hash_equals( $stored_hash, $share_hash );
	}
}
