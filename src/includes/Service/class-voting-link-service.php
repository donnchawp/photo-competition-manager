<?php
/**
 * Email a member their voting link.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use WP_Error;
use function PhotoCompetitionManager\Support\format_site_date;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Sends voting links: one category's link, for a member who may vote in a
 * competition whose members vote with emailed links. A new link replaces the
 * member's earlier one for that category.
 *
 * @since 0.5.0
 */
class Voting_Link_Service {

	/**
	 * Voting token repository.
	 *
	 * @var Voting_Token_Repository
	 */
	private $tokens;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes;

	/**
	 * Email service.
	 *
	 * @var Email_Service
	 */
	private $email_service;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Who may enter, and so vote.
	 *
	 * @var Competition_Entry
	 */
	private $entry;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 *
	 * @param Competitions_Repository|null $competitions  Competitions repository.
	 * @param Members_Repository|null      $members       Members repository.
	 * @param Voting_Token_Repository|null $tokens        Voting token repository.
	 * @param Email_Service|null           $email_service Email service.
	 */
	public function __construct(
		?Competitions_Repository $competitions = null,
		?Members_Repository $members = null,
		?Voting_Token_Repository $tokens = null,
		?Email_Service $email_service = null
	) {
		$competitions        = $competitions ?? new Competitions_Repository();
		$members             = $members ?? new Members_Repository();
		$this->tokens        = $tokens ?? new Voting_Token_Repository();
		$this->votes         = new Votes_Repository();
		$this->email_service = $email_service ?? new Email_Service();
		$this->workflow      = new Competition_Workflow( $competitions );
		$this->entry         = new Competition_Entry( $competitions, $members );
	}

	/**
	 * Email a member the link they asked for on the voting page.
	 *
	 * A member who asked within the last 5 minutes isn't sent another, so
	 * anyone who knows their email can't flood them. A member who has voted
	 * is still sent one: it tells them their ballot is in.
	 *
	 * @since 0.5.0
	 *
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @param string $category    Category slug.
	 * @param string $page_url    The voting page the member asked on.
	 * @return true|WP_Error 'rate_limited' when they asked too recently, or why no link was sent.
	 */
	public function send_requested( object $competition, object $member, string $category, string $page_url ) {
		$refused = $this->refusal( $competition, $member, $category );
		if ( $refused ) {
			return $refused;
		}

		if ( $this->tokens->has_recent_token( (int) $member->id, (int) $competition->id, $category ) ) {
			return new WP_Error( 'rate_limited', __( 'A voting link was sent to this member a few minutes ago.', 'photo-competition-manager' ) );
		}

		return $this->send( $competition, $member, $category, $page_url );
	}

	/**
	 * Email a member a link from the admin screens, to the competition's
	 * voting page. A member who has voted in the category isn't sent one.
	 *
	 * @since 0.5.0
	 *
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @param string $category    Category slug.
	 * @return true|WP_Error 'already_voted', 'no_voting_page', or why no link was sent.
	 */
	public function send_from_admin( object $competition, object $member, string $category ) {
		$refused = $this->refusal( $competition, $member, $category );
		if ( $refused ) {
			return $refused;
		}

		if ( $this->has_voted( $competition, $member, $category ) ) {
			return new WP_Error( 'already_voted', __( 'This member has already voted in this category.', 'photo-competition-manager' ) );
		}

		$page_url = Competition_Settings::page_url( 'voting_page', $competition );
		if ( '' === $page_url ) {
			return new WP_Error( 'no_voting_page', __( 'No voting page is set, so there is no link to send.', 'photo-competition-manager' ) );
		}

		return $this->send( $competition, $member, $category, $page_url );
	}

	/**
	 * Whether a member may be sent a link from the admin screens now: they may
	 * vote, and haven't, in the category.
	 *
	 * @since 0.5.0
	 *
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @param string $category    Category slug.
	 * @return bool
	 */
	public function can_send_from_admin( object $competition, object $member, string $category ): bool {
		return null === $this->refusal( $competition, $member, $category ) && ! $this->has_voted( $competition, $member, $category );
	}

	/**
	 * Why a member can't be sent a link for a category, if they can't.
	 *
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @param string $category    Category slug.
	 * @return WP_Error|null
	 */
	private function refusal( object $competition, object $member, string $category ): ?WP_Error {
		$voting = Competition_Settings::get_voting_config( Competition_Settings::parse( $competition->settings ) );
		if ( 'token' !== ( $voting['auth_mode'] ?? 'password' ) ) {
			return new WP_Error( 'not_link_voting', __( 'Members don\'t vote with emailed links in this competition.', 'photo-competition-manager' ) );
		}

		if ( ! $this->workflow->is_accepting_votes( $competition, $category ) ) {
			return new WP_Error( 'not_accepting_votes', __( 'Voting is not open for this category.', 'photo-competition-manager' ) );
		}

		if ( empty( $member->email ) ) {
			return new WP_Error( 'missing_email', __( 'Member does not have an email address.', 'photo-competition-manager' ) );
		}

		if ( ! $this->entry->may_enter( $competition, $member ) ) {
			return new WP_Error( 'not_a_voter', __( 'This member may not vote in this competition.', 'photo-competition-manager' ) );
		}

		return null;
	}

	/**
	 * Whether the member has cast their ballot in the category.
	 *
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @param string $category    Category slug.
	 * @return bool
	 */
	private function has_voted( object $competition, object $member, string $category ): bool {
		$token_id = $this->tokens->member_token_id( (int) $member->id, (int) $competition->id, $category );

		return $token_id > 0 && $this->votes->has_voted_with_token( $token_id );
	}

	/**
	 * Give the member a new link for the category and email it.
	 *
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @param string $category    Category slug.
	 * @param string $page_url    Voting page URL.
	 * @return true|WP_Error
	 */
	private function send( object $competition, object $member, string $category, string $page_url ) {
		$token = bin2hex( random_bytes( 32 ) );

		// Renew the member's one token for the category, so an earlier link stops working.
		$token_id = $this->tokens->renew( (int) $member->id, (int) $competition->id, $category, hash( 'sha256', $token ), utc_time( HOUR_IN_SECONDS ) );
		if ( is_wp_error( $token_id ) ) {
			return $token_id;
		}

		$sent = $this->email_service->send(
			'voting_link',
			$member,
			$competition,
			array(
				'{voting_link}' => add_query_arg(
					array(
						'token'       => $token,
						'competition' => $competition->slug,
					),
					$page_url
				),
				'{close_date}'  => format_site_date( $competition->close_date ),
			)
		);

		return is_wp_error( $sent ) ? $sent : true;
	}
}
