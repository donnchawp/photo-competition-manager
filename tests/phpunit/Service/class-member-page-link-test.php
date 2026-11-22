<?php
/**
 * Tests for Member_Page_Link: the emailed link to a member's own page.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Member_Page_Link;
use WP_UnitTestCase;

/**
 * A member asks for a link by email and the link opens their page for 7 days.
 */
class Member_Page_Link_Test extends WP_UnitTestCase {

	const PAGE = 'https://example.com/my-entries/';

	/**
	 * @var Members_Repository
	 */
	private $members;

	public function setUp(): void {
		parent::setUp();

		$this->members = new Members_Repository();
	}

	/**
	 * Create a member.
	 *
	 * @param string $email Email.
	 * @return object
	 */
	private function member( string $email ): object {
		$id = (int) $this->members->create(
			array(
				'name'  => 'Ann',
				'email' => $email,
				'grade' => 'beginner',
			)
		);

		return $this->members->find( $id );
	}

	/**
	 * The key a link carries.
	 *
	 * @param string $url Link.
	 * @return string
	 */
	private function key_of( string $url ): string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $args );

		return (string) ( $args[ Member_Page_Link::QUERY_ARG ] ?? '' );
	}

	public function test_a_link_opens_the_page_of_the_member_it_was_made_for(): void {
		$ann = $this->member( 'ann@example.com' );
		$this->member( 'bob@example.com' );
		$link = new Member_Page_Link();

		$url = $link->url( $ann, self::PAGE );

		$this->assertStringStartsWith( self::PAGE, $url );
		$this->assertSame( (int) $ann->id, (int) $link->member( $this->key_of( $url ) )->id );
	}

	public function test_a_link_stops_working_after_seven_days(): void {
		$ann       = $this->member( 'ann@example.com' );
		$issued_at = time() - 7 * DAY_IN_SECONDS - 60;
		$old_link  = new Member_Page_Link(
			static function () use ( $issued_at ): int {
				return $issued_at;
			}
		);

		$key = $this->key_of( $old_link->url( $ann, self::PAGE ) );

		$this->assertNull( ( new Member_Page_Link() )->member( $key ) );
	}

	public function test_a_link_still_works_just_inside_seven_days(): void {
		$ann       = $this->member( 'ann@example.com' );
		$issued_at = time() - 7 * DAY_IN_SECONDS + 60;
		$old_link  = new Member_Page_Link(
			static function () use ( $issued_at ): int {
				return $issued_at;
			}
		);

		$key = $this->key_of( $old_link->url( $ann, self::PAGE ) );

		$this->assertSame( (int) $ann->id, (int) ( new Member_Page_Link() )->member( $key )->id );
	}

	public function test_a_link_edited_to_point_at_another_member_or_a_later_expiry_is_refused(): void {
		$ann                              = $this->member( 'ann@example.com' );
		$bob                              = $this->member( 'bob@example.com' );
		$link                             = new Member_Page_Link();
		list( $id, $expires, $signature ) = explode( '.', $this->key_of( $link->url( $ann, self::PAGE ) ) );

		$this->assertNull( $link->member( $bob->id . '.' . $expires . '.' . $signature ), 'another member' );
		$this->assertNull( $link->member( $id . '.' . ( $expires + DAY_IN_SECONDS ) . '.' . $signature ), 'later expiry' );
		$this->assertNull( $link->member( 'nonsense' ), 'malformed' );
		$this->assertNull( $link->member( '' ), 'empty' );
	}

	/**
	 * Ask for a link, recording every email sent.
	 *
	 * @param string $email Address typed into the form.
	 * @return array<int, array<string, mixed>> Emails sent by this request.
	 */
	private function request( string $email ): array {
		$mails   = array();
		$capture = function ( $atts ) use ( &$mails ) {
			$mails[] = $atts;
			return $atts;
		};
		add_filter( 'wp_mail', $capture );
		( new Member_Page_Link() )->request( $email, self::PAGE );
		remove_filter( 'wp_mail', $capture );

		return $mails;
	}

	/**
	 * The member page link in an email.
	 *
	 * @param array<string, mixed> $mail Email.
	 * @return string
	 */
	private function link_in( array $mail ): string {
		preg_match( '#' . preg_quote( self::PAGE, '#' ) . '[^"\s<]*#', (string) $mail['message'], $match );

		return html_entity_decode( $match[0] ?? '' );
	}

	public function test_a_member_who_asks_is_emailed_a_link_to_their_page(): void {
		$ann = $this->member( 'ann@example.com' );

		$mails = $this->request( 'Ann@Example.com ' );

		$this->assertCount( 1, $mails );
		$this->assertSame( 'ann@example.com', $mails[0]['to'] );
		$this->assertSame( (int) $ann->id, (int) ( new Member_Page_Link() )->member( $this->key_of( $this->link_in( $mails[0] ) ) )->id );
	}

	public function test_nobody_is_emailed_when_the_address_is_not_a_member(): void {
		$this->member( 'ann@example.com' );

		$this->assertSame( array(), $this->request( 'stranger@example.com' ) );
		$this->assertSame( array(), $this->request( 'not an address' ) );
	}

	public function test_a_deactivated_member_is_emailed_at_their_real_address(): void {
		$ann = $this->member( 'ann@example.com' );
		$this->members->update(
			(int) $ann->id,
			array(
				'active' => 0,
				'email'  => Members_Repository::mark_deactivated_email( 'ann@example.com' ),
			)
		);

		$mails = $this->request( 'ann@example.com' );

		$this->assertCount( 1, $mails );
		$this->assertSame( 'ann@example.com', $mails[0]['to'] );
		$this->assertSame( (int) $ann->id, (int) ( new Member_Page_Link() )->member( $this->key_of( $this->link_in( $mails[0] ) ) )->id );
	}

	public function test_asking_again_for_the_same_address_soon_after_sends_nothing(): void {
		$this->member( 'ann@example.com' );
		$this->request( 'ann@example.com' );

		$this->assertSame( array(), $this->request( 'ANN@example.com' ) );
	}

	public function test_a_link_for_a_deleted_member_is_refused(): void {
		$ann  = $this->member( 'ann@example.com' );
		$link = new Member_Page_Link();
		$key  = $this->key_of( $link->url( $ann, self::PAGE ) );

		$this->members->delete( (int) $ann->id );

		$this->assertNull( $link->member( $key ) );
	}
}
