<?php
/**
 * Who sees the club's published results.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * The club's one choice of who sees published results: private, public, or
 * public with the past competitions page. While private, the results and
 * top three pages show a competition's results only with its share link,
 * and there are no past competitions, since they show published results.
 *
 * @since 0.5.1
 */
class Public_Results {

	/**
	 * Option holding the choice.
	 */
	const OPTION = 'photo_comp_results_visibility';

	/**
	 * Results need the share link, and there are no past competitions.
	 */
	const PRIVATE = 'private';

	/**
	 * Anyone can see published results on the results and top three pages.
	 */
	const PUBLIC = 'public';

	/**
	 * Public, and the past competitions page lists them too.
	 */
	const WITH_ARCHIVE = 'archive';

	/**
	 * The club's choice. Private until the club chooses otherwise.
	 *
	 * @return string One of the class constants.
	 */
	public static function visibility(): string {
		$saved = (string) get_option( self::OPTION, self::PRIVATE );

		return in_array( $saved, array( self::PUBLIC, self::WITH_ARCHIVE ), true ) ? $saved : self::PRIVATE;
	}

	/**
	 * Whether published results show to everyone.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return self::PRIVATE !== self::visibility();
	}

	/**
	 * Whether the past competitions page is on.
	 *
	 * @return bool
	 */
	public static function archive_enabled(): bool {
		return self::WITH_ARCHIVE === self::visibility();
	}

	/**
	 * Save the club's choice. Anything else counts as private.
	 *
	 * @param string $visibility One of the class constants.
	 * @return void
	 */
	public static function save( string $visibility ): void {
		update_option( self::OPTION, in_array( $visibility, array( self::PUBLIC, self::WITH_ARCHIVE ), true ) ? $visibility : self::PRIVATE );
	}
}
