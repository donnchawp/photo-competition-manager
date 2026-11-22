<?php
/**
 * A member's choices about the past competitions archive.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * A member shows or hides all their images in the archive, and hides or
 * shows single images. The two are separate: hiding everything keeps the
 * single choices, so showing everything again brings back all but those.
 * Which images the archive shows is Competition_Archive's rule.
 *
 * @since 0.5.0
 */
class Member_Visibility {

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * Constructor.
	 *
	 * @param Members_Repository|null $members Members repository.
	 * @param Images_Repository|null  $images  Images repository.
	 */
	public function __construct( ?Members_Repository $members = null, ?Images_Repository $images = null ) {
		$this->members = $members ?? new Members_Repository();
		$this->images  = $images ?? new Images_Repository();
	}

	/**
	 * Show or hide all of a member's images.
	 *
	 * @since 0.5.0
	 *
	 * @param object $member Member row.
	 * @param bool   $shown  Whether their images may show.
	 * @return bool False if it couldn't be saved.
	 */
	public function set_all_shown( object $member, bool $shown ): bool {
		return $this->members->set_images_public( (int) $member->id, $shown );
	}

	/**
	 * Show or hide one of a member's images.
	 *
	 * @since 0.5.0
	 *
	 * @param object $member   Member row.
	 * @param int    $entry_id Entry ID.
	 * @param bool   $shown    Whether it may show.
	 * @return bool False if it isn't the member's entry or couldn't be saved.
	 */
	public function set_entry_shown( object $member, int $entry_id, bool $shown ): bool {
		$entry = $this->images->find( $entry_id );
		if ( ! $entry || (int) $entry->member_id !== (int) $member->id ) {
			return false;
		}

		return $this->images->set_archive_hidden( $entry_id, ! $shown );
	}

	/**
	 * A member's choices: whether their images may show, and how many they've hidden one by one.
	 *
	 * @since 0.5.0
	 *
	 * @param object $member Member row.
	 * @return array{shown: bool, hidden: int}
	 */
	public function status( object $member ): array {
		$hidden = 0;
		foreach ( $this->images->find_by_member( (int) $member->id ) as $entry ) {
			$hidden += (int) $entry->archive_hidden;
		}

		return array(
			'shown'  => (bool) (int) $member->images_public,
			'hidden' => $hidden,
		);
	}

	/**
	 * The upload email's paragraph about the archive: whether the member's
	 * images show there, and a link to their entries page to change it.
	 *
	 * @since 0.5.0
	 *
	 * @param object $member Member row.
	 * @return string HTML, or '' while the archive is off or has no page.
	 */
	public function archive_paragraph( object $member ): string {
		$archive_url = Competition_Settings::find_page_url_with_shortcode( 'competition_archive' );
		if ( ! Competition_Archive::settings()['enabled'] || '' === $archive_url ) {
			return '';
		}

		$status = $this->status( $member );
		$link   = '<a href="' . esc_url( $archive_url ) . '">' . esc_html( $archive_url ) . '</a>';

		if ( ! $status['shown'] ) {
			/* translators: %s: link to the past competitions page */
			$text = sprintf( esc_html__( "Your images are hidden from the club's past competitions at %s.", 'photo-competition-manager' ), $link );
		} elseif ( $status['hidden'] > 0 ) {
			$text = sprintf(
				/* translators: 1: link to the past competitions page, 2: number of images the member has hidden */
				esc_html( _n( "Your images are shown in the club's past competitions at %1\$s, except %2\$d you've hidden.", "Your images are shown in the club's past competitions at %1\$s, except the %2\$d you've hidden.", $status['hidden'], 'photo-competition-manager' ) ),
				$link,
				$status['hidden']
			);
		} else {
			/* translators: %s: link to the past competitions page */
			$text = sprintf( esc_html__( "Your images are shown in the club's past competitions at %s.", 'photo-competition-manager' ), $link );
		}

		$entries_url = Competition_Settings::find_page_url_with_shortcode( 'competition_my_entries' );
		if ( '' !== $entries_url ) {
			$text .= ' ' . sprintf(
				/* translators: %s: link to the member's entries page */
				esc_html__( 'To show or hide them, all at once or one at a time, open %s. The link works for 7 days.', 'photo-competition-manager' ),
				'<a href="' . esc_url( ( new Member_Page_Link() )->url( $member, $entries_url ) ) . '">' . esc_html__( 'your entries page', 'photo-competition-manager' ) . '</a>'
			);
		}

		return '<p>' . $text . '</p>';
	}
}
