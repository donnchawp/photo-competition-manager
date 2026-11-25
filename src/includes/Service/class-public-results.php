<?php
/**
 * Whether the club's results pages show published results to everyone.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * The club's public results setting. While it's off, the results and top
 * three pages show a competition's results only with its share link.
 *
 * @since 0.5.1
 */
class Public_Results {

	/**
	 * Option holding the setting: '1' for public, '0' for private.
	 */
	const OPTION = 'photo_comp_results_public';

	/**
	 * Whether published results show to everyone. Off until the club turns it on.
	 *
	 * @return bool
	 */
	public static function enabled(): bool {
		return '1' === (string) get_option( self::OPTION, '0' );
	}

	/**
	 * Save the setting.
	 *
	 * @param bool $enabled Whether published results show to everyone.
	 * @return void
	 */
	public static function save( bool $enabled ): void {
		update_option( self::OPTION, $enabled ? '1' : '0' );
	}
}
