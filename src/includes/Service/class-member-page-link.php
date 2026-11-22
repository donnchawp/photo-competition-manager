<?php
/**
 * The emailed link to a member's own page.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Members_Repository;

/**
 * A member page link carries a key of the member's ID, when it expires, and a
 * signature over both made with the site's salts. Nothing is stored: the key
 * can't be guessed or edited without the salts, and it stops working after
 * seven days or when the member is deleted.
 *
 * @since 0.5.0
 */
class Member_Page_Link {

	/**
	 * Query argument the key travels in.
	 */
	const QUERY_ARG = 'member_key';

	/**
	 * How long a link works.
	 */
	const LIFETIME = 7 * DAY_IN_SECONDS;

	/**
	 * How long an address waits before it can ask again.
	 */
	const ADDRESS_WAIT = 5 * MINUTE_IN_SECONDS;

	/**
	 * How many times an hour one IP address can ask.
	 */
	const REQUESTS_PER_IP = 5;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Email service.
	 *
	 * @var Email_Service
	 */
	private $email;

	/**
	 * Current Unix time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Constructor.
	 *
	 * @param callable|null           $clock   Returns the current Unix time. Defaults to time().
	 * @param Members_Repository|null $members Members repository.
	 * @param Email_Service|null      $email   Email service.
	 */
	public function __construct( ?callable $clock = null, ?Members_Repository $members = null, ?Email_Service $email = null ) {
		$this->clock   = $clock ? $clock : 'time';
		$this->members = $members ? $members : new Members_Repository();
		$this->email   = $email ? $email : new Email_Service();
	}

	/**
	 * A link to a member's page that works for seven days.
	 *
	 * @since 0.5.0
	 *
	 * @param object $member   Member row.
	 * @param string $page_url URL of the page with the member page shortcode.
	 * @return string
	 */
	public function url( object $member, string $page_url ): string {
		$payload = (int) $member->id . '.' . ( ( $this->clock )() + self::LIFETIME );

		return add_query_arg( self::QUERY_ARG, $payload . '.' . $this->sign( $payload ), $page_url );
	}

	/**
	 * The member a key belongs to, if it's genuine and hasn't expired.
	 *
	 * @since 0.5.0
	 *
	 * @param string $key Key from the link.
	 * @return object|null Member row, or null.
	 */
	public function member( string $key ): ?object {
		if ( ! preg_match( '/^(\d+)\.(\d+)\.([a-f0-9]{64})$/D', $key, $parts ) ) {
			return null;
		}

		list( , $member_id, $expires, $signature ) = $parts;

		if ( ! hash_equals( $this->sign( $member_id . '.' . $expires ), $signature ) || (int) $expires <= ( $this->clock )() ) {
			return null;
		}

		return $this->members->find( (int) $member_id );
	}

	/**
	 * Email a member a link to their page, if the address is a member's.
	 *
	 * The caller learns nothing either way, so the form can't tell anyone who
	 * is a member. Each address can ask once every few minutes and each IP
	 * address a few times an hour, members or not, so the form can't be used
	 * to flood an inbox.
	 *
	 * @since 0.5.0
	 *
	 * @param string $email    Address typed into the form.
	 * @param string $ip       Requester's IP address.
	 * @param string $page_url URL of the page with the member page shortcode.
	 * @return void
	 */
	public function request( string $email, string $ip, string $page_url ): void {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return;
		}

		$ip_key      = 'photo_comp_member_link_ip_' . md5( $ip );
		$address_key = 'photo_comp_member_link_email_' . md5( $email );
		$ip_requests = (int) get_transient( $ip_key );

		if ( $ip_requests >= self::REQUESTS_PER_IP || get_transient( $address_key ) ) {
			return;
		}

		set_transient( $ip_key, $ip_requests + 1, HOUR_IN_SECONDS );
		set_transient( $address_key, 1, self::ADDRESS_WAIT );

		$member = $this->members->find_by_email( $email );
		if ( ! $member ) {
			return;
		}

		// Send to the address typed in: a deactivated member's stored address is marked so mail to it bounces.
		$recipient        = clone $member;
		$recipient->email = $email;

		$this->email->send( 'member_page_link', $recipient, null, array( '{member_page_link}' => $this->url( $member, $page_url ) ) );
	}

	/**
	 * Sign a key's payload.
	 *
	 * @param string $payload Member ID and expiry.
	 * @return string
	 */
	private function sign( string $payload ): string {
		return hash_hmac( 'sha256', 'photocomp-member-page|' . $payload, wp_salt( 'auth' ) );
	}
}
