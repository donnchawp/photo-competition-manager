<?php
/**
 * What a member voter is told about their ballot.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * The Voter messages for members, who vote rather than score. Shared by the
 * link voter and the named voter.
 *
 * @since 0.4.0
 */
trait Member_Ballot_Messages {

	/**
	 * Why a ballot with no votes was refused.
	 *
	 * @return string
	 */
	public static function empty_ballot_message(): string {
		return __( 'Please select at least one image to vote for.', 'photo-competition-manager' );
	}

	/**
	 * Why a ballot that left images without a vote was refused.
	 *
	 * @param int $voted Images voted for.
	 * @param int $total Images in the category.
	 * @return string
	 */
	public static function incomplete_ballot_message( int $voted, int $total ): string {
		return sprintf(
			/* translators: %1$d: number of images voted for, %2$d: total number of images */
			_n(
				'You must vote for all images. You have voted for %1$d of %2$d image.',
				'You must vote for all images. You have voted for %1$d of %2$d images.',
				$total,
				'photo-competition-manager'
			),
			$voted,
			$total
		);
	}

	/**
	 * That the member's votes for the category are already saved.
	 *
	 * @return string
	 */
	public static function already_cast_message(): string {
		return __( 'Thank you! Your votes for this category have already been recorded.', 'photo-competition-manager' );
	}
}
