<?php
/**
 * The Ballots module: one owner for casting a ballot.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use WP_Error;

/**
 * Establishes who is voting and casts their ballot, applying the ballot rules
 * the same way for a link voter and a named voter.
 *
 * @since 0.4.0
 */
final class Ballots {

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes;

	/**
	 * Voting token repository.
	 *
	 * @var Voting_Token_Repository
	 */
	private $voting_tokens;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * Constructor.
	 *
	 * @param Competition_Workflow|null    $workflow      Competition workflow.
	 * @param Votes_Repository|null        $votes         Votes repository.
	 * @param Voting_Token_Repository|null $voting_tokens Voting token repository.
	 * @param Members_Repository|null      $members       Members repository.
	 * @param Images_Repository|null       $images        Images repository.
	 */
	public function __construct(
		?Competition_Workflow $workflow = null,
		?Votes_Repository $votes = null,
		?Voting_Token_Repository $voting_tokens = null,
		?Members_Repository $members = null,
		?Images_Repository $images = null
	) {
		$this->workflow      = $workflow ?? new Competition_Workflow();
		$this->images        = $images ?? new Images_Repository();
		$this->votes         = $votes ?? new Votes_Repository();
		$this->voting_tokens = $voting_tokens ?? new Voting_Token_Repository();
		$this->members       = $members ?? new Members_Repository();
	}

	/**
	 * A link voter, once their voting link checks out: not expired, an active
	 * member's, and for this competition.
	 *
	 * @param object $competition Competition row.
	 * @param string $token       The token from the voting link.
	 * @return Voter|WP_Error
	 */
	public function link_voter( object $competition, string $token ) {
		$record = '' === $token ? null : $this->voting_tokens->find_valid_token( hash( 'sha256', $token ) );
		$member = $record && (int) $record->competition_id === (int) $competition->id ? $this->members->find( (int) $record->member_id ) : null;

		if ( ! $member ) {
			return new WP_Error( 'invalid_link', __( 'This voting link has expired or isn\'t valid. Ask for a new one below.', 'photo-competition-manager' ) );
		}

		return new Link_Voter( $record, $member );
	}

	/**
	 * A named voter, once their voting password checks out.
	 *
	 * @param object $competition Competition row.
	 * @param string $name        The name they gave.
	 * @param string $password    The voting password they gave.
	 * @return Voter|WP_Error
	 */
	public function named_voter( object $competition, string $name, string $password ) {
		$voter = new Named_Voter( $name );
		if ( '' === $voter->name() ) {
			return new WP_Error( 'missing_name', __( 'Please enter your name.', 'photo-competition-manager' ) );
		}

		$voting   = Competition_Settings::get_voting_config( Competition_Settings::parse( $competition->settings ) );
		$expected = (string) ( $voting['password'] ?? '' );
		$password = strtolower( sanitize_text_field( $password ) );

		if ( '' === $expected ) {
			return $voter;
		}

		if ( '' === $password ) {
			return new WP_Error( 'missing_password', __( 'Please enter the voting password.', 'photo-competition-manager' ) );
		}

		// Plaintext and case-insensitive, or a legacy hash of the lowercased password.
		if ( strtolower( $expected ) !== $password && ! wp_check_password( $password, $expected ) ) {
			return new WP_Error( 'wrong_password', __( 'The voting password is incorrect.', 'photo-competition-manager' ) );
		}

		return $voter;
	}

	/**
	 * Cast a voter's ballot. The rules apply in this order, for either voter:
	 * the voter may vote in the category, voting is open, only the category's
	 * entries count, there's at least one score, every entry is scored, and
	 * each score is a whole number in the competition's score matrix, and not
	 * negative. An unanswered entry (an empty score) isn't scored.
	 *
	 * Error codes: not_your_category, voting_closed, empty_ballot,
	 * incomplete_ballot (data: voted, total), invalid_score, already_cast and
	 * insert_failed. The message is for the voter.
	 *
	 * @param object                   $competition Competition row.
	 * @param string                   $category    Category slug.
	 * @param Voter                    $voter       Who is voting.
	 * @param array<int|string, mixed> $scores      Image ID => score, as the form posted them.
	 * @return true|WP_Error
	 */
	public function cast( object $competition, string $category, Voter $voter, array $scores ) {
		if ( ! $voter->may_vote_in( $category ) ) {
			return new WP_Error( 'not_your_category', __( 'Your voting link is for another category.', 'photo-competition-manager' ) );
		}

		if ( ! $this->workflow->is_accepting_votes( $competition, $category ) ) {
			return new WP_Error( 'voting_closed', __( 'Voting is not open for this category.', 'photo-competition-manager' ) );
		}

		$entries = array_column( $this->images->find_by_competition( (int) $competition->id, $category ), 'id' );
		$scores  = array_filter(
			array_intersect_key( $scores, array_flip( array_map( 'intval', $entries ) ) ),
			fn( $score ) => ! is_scalar( $score ) || '' !== trim( (string) $score )
		);
		if ( empty( $scores ) ) {
			return new WP_Error( 'empty_ballot', __( 'Please select at least one image to vote for.', 'photo-competition-manager' ) );
		}

		if ( count( $scores ) < count( $entries ) ) {
			return new WP_Error(
				'incomplete_ballot',
				sprintf(
					/* translators: %1$d: number of images voted for, %2$d: total number of images */
					_n(
						'You must vote for all images. You have voted for %1$d of %2$d image.',
						'You must vote for all images. You have voted for %1$d of %2$d images.',
						count( $entries ),
						'photo-competition-manager'
					),
					count( $scores ),
					count( $entries )
				),
				array(
					'voted' => count( $scores ),
					'total' => count( $entries ),
				)
			);
		}

		$voting  = Competition_Settings::get_voting_config( Competition_Settings::parse( $competition->settings ) );
		$allowed = array_map( 'intval', $voting['score_matrix'] );
		foreach ( $scores as $image_id => $score ) {
			// Votes are never negative, whatever the matrix says.
			if ( ! is_scalar( $score ) || ! preg_match( '/^\d+$/', trim( (string) $score ) ) || ! in_array( (int) $score, $allowed, true ) ) {
				return new WP_Error( 'invalid_score', __( 'Please choose a score from the list for every image.', 'photo-competition-manager' ) );
			}
			$scores[ $image_id ] = (int) $score;
		}

		$result = $voter->record( $this->votes, (int) $competition->id, $category, $scores );

		// A category with votes takes no new entries, so a second ballot is always a full duplicate.
		if ( is_wp_error( $result ) && 'duplicate_vote' === $result->get_error_code() ) {
			return new WP_Error( 'already_cast', __( 'Thank you! Your votes for this category have already been recorded.', 'photo-competition-manager' ) );
		}

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'insert_failed', __( 'Failed to record votes. Please try again.', 'photo-competition-manager' ), $result->get_error_data() );
		}

		return true;
	}

	/**
	 * Whether the voter has cast a ballot in the category.
	 *
	 * @param object $competition Competition row.
	 * @param string $category    Category slug.
	 * @param Voter  $voter       Who is voting.
	 * @return bool
	 */
	public function has_cast( object $competition, string $category, Voter $voter ): bool {
		return $voter->has_ballot( $this->votes, (int) $competition->id, $category );
	}
}
