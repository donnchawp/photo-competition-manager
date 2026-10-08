<?php
/**
 * The plugin's suggested privacy-policy text.
 *
 * @package PhotoCompetitionManager\Support
 */

namespace PhotoCompetitionManager\Support;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Suggests a section for the site's privacy policy, shown in Settings >
 * Privacy > Policy Guide: what the club holds about members, why, and what
 * deleting a member keeps.
 *
 * @since 0.4.0
 */
class Privacy_Policy {

	/**
	 * Add the plugin's text to WordPress's privacy policy guide. Runs on admin_init.
	 *
	 * @return void
	 */
	public static function suggest(): void {
		$paragraphs = array(
			__( 'For each club member, we hold their name, email address and grade. We also hold the images they enter in competitions, with the original file they uploaded, the votes they cast, and a log of the emails we send them.', 'photo-competition-manager' ),
			__( 'We use this to run the club\'s competitions: to send members links to upload their images and to vote, to show their entries for voting, and to publish the results.', 'photo-competition-manager' ),
			__( 'A member can ask for a copy of what we hold about them, or ask us to delete it. Deleting a member deletes their entries, with their images and originals, and anything else that names them. Two things are kept without their name, so the results of past competitions don\'t change: each of their entries\' score and position in published results, and the votes they cast.', 'photo-competition-manager' ),
		);

		wp_add_privacy_policy_content(
			__( 'Photo Competition Manager', 'photo-competition-manager' ),
			'<p>' . implode( '</p><p>', array_map( 'esc_html', $paragraphs ) ) . '</p>'
		);
	}
}
