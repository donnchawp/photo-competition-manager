<?php
/**
 * A member voting through their voting link.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Votes_Repository;

/**
 * A link voter: a member, proved by their voting link. The link is for one
 * category, and the ballot is stored against its token, not the member.
 * Built by Ballots::link_voter(), which checks the link.
 *
 * @since 0.4.0
 */
final class Link_Voter implements Voter {

	use Member_Ballot_Messages;

	/**
	 * The link's token row.
	 *
	 * @var object
	 */
	private $token;

	/**
	 * The member the link belongs to.
	 *
	 * @var object
	 */
	private $member;

	/**
	 * Constructor.
	 *
	 * @param object $token  The link's token row.
	 * @param object $member The member the link belongs to.
	 */
	public function __construct( object $token, object $member ) {
		$this->token  = $token;
		$this->member = $member;
	}

	/**
	 * The member the link belongs to.
	 *
	 * @return object
	 */
	public function member(): object {
		return $this->member;
	}

	/**
	 * The category the link is for.
	 *
	 * @return string
	 */
	public function category(): string {
		return (string) $this->token->category;
	}

	/**
	 * A link voter may vote only in their link's category.
	 *
	 * @param string $category Category slug.
	 * @return bool
	 */
	public function may_vote_in( string $category ): bool {
		return $this->category() === $category;
	}

	/**
	 * Store the voter's ballot against their link's token.
	 *
	 * @param Votes_Repository $votes          Votes repository.
	 * @param int              $competition_id Competition ID.
	 * @param string           $category       Category slug.
	 * @param array<int,int>   $scores         Image ID => score.
	 * @return int|\WP_Error Number of votes recorded, or error.
	 */
	public function record( Votes_Repository $votes, int $competition_id, string $category, array $scores ) {
		return $votes->create_anonymous_ballot( $competition_id, $category, (int) $this->token->id, $scores );
	}

	/**
	 * Whether the voter has a ballot. Their link is for one category, so the token says.
	 *
	 * @param Votes_Repository $votes          Votes repository.
	 * @param int              $competition_id Competition ID.
	 * @param string           $category       Category slug.
	 * @return bool
	 */
	public function has_ballot( Votes_Repository $votes, int $competition_id, string $category ): bool {
		return $votes->has_voted_with_token( (int) $this->token->id );
	}

	/**
	 * The cookie that remembers a competition's voting password on this device.
	 *
	 * @since 0.5.0
	 *
	 * @param int $competition_id Competition ID.
	 * @return string
	 */
	private static function password_cookie( int $competition_id ): string {
		return 'photo_competition_voting_password_' . $competition_id;
	}

	/**
	 * Remember the competition's voting password on this device for the
	 * night, so a link voter gives it once, not in every category.
	 *
	 * @since 0.5.0
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $password       The voting password they gave.
	 * @return void
	 */
	public static function remember_password( int $competition_id, string $password ): void {
		$cookie = self::password_cookie( $competition_id );

		// A real request gets here on template_redirect, before any output. PHPUnit has already printed, and setcookie() would warn.
		if ( ! headers_sent() ) {
			setcookie(
				$cookie,
				$password,
				array(
					'expires'  => time() + 12 * HOUR_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'samesite' => 'Lax',
					'httponly' => true,
				)
			);
		}

		// A redirect follows, so this copy is how tests see the cookie. Slashed, as WordPress slashes $_COOKIE.
		$_COOKIE[ $cookie ] = wp_slash( $password );
	}

	/**
	 * The voting password this device remembers for a competition, or ''.
	 *
	 * @since 0.5.0
	 *
	 * @param int $competition_id Competition ID.
	 * @return string
	 */
	public static function remembered_password( int $competition_id ): string {
		$cookie = self::password_cookie( $competition_id );

		return isset( $_COOKIE[ $cookie ] ) && is_string( $_COOKIE[ $cookie ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie ] ) ) : '';
	}
}
