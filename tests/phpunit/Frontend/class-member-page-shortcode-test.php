<?php
/**
 * Tests for Member_Page_Shortcode: the page wiring around the member page link and entries.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Member_Page_Shortcode;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Competition_Archive;
use PhotoCompetitionManager\Service\Member_Page_Link;
use PhotoCompetitionManager\Service\Member_Visibility;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WPDieException;
use WP_UnitTestCase;

/**
 * The page asks for an email address, and a member's link opens their entries.
 * The rules behind both are tested on Member_Page_Link and Member_Entries.
 */
class Member_Page_Shortcode_Test extends WP_UnitTestCase {

	/**
	 * The member page.
	 *
	 * @var int
	 */
	private $page_id;

	/**
	 * A member.
	 *
	 * @var object
	 */
	private $ann;

	/**
	 * Every email sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	/**
	 * Create the page and a member, and record emails.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->page_id   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '[competition_my_entries]',
			)
		);
		$GLOBALS['post'] = get_post( $this->page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The shortcode links back to the page it's on.

		$members   = new Members_Repository();
		$this->ann = $members->find(
			(int) $members->create(
				array(
					'name'  => 'Ann',
					'email' => 'ann@example.com',
					'grade' => 'beginner',
				)
			)
		);

		add_filter(
			'wp_mail',
			function ( $atts ) {
				$this->mails[] = $atts;
				return $atts;
			}
		);

		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Clear the request.
	 */
	public function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	/**
	 * Render the shortcode.
	 *
	 * @return string
	 */
	private function render(): string {
		return ( new Member_Page_Shortcode() )->render( array() );
	}

	/**
	 * Post the form with an email address.
	 *
	 * @param string $email Address.
	 * @param string $nonce Nonce, or '' for a valid one.
	 * @return string
	 */
	private function post( string $email, string $nonce = '' ): string {
		$_POST    = array(
			'photo_comp_member_link'       => '1',
			'member_email'                 => $email,
			'photo_comp_member_link_nonce' => '' !== $nonce ? $nonce : wp_create_nonce( 'photo_comp_member_link' ),
		);
		$_REQUEST = $_POST;

		return $this->render();
	}

	/**
	 * Open the page with a member's link.
	 *
	 * @param string $key  Key.
	 * @param string $sort Sort, or ''.
	 * @return string
	 */
	private function open( string $key, string $sort = '' ): string {
		$_GET = array( Member_Page_Link::QUERY_ARG => $key );
		if ( '' !== $sort ) {
			$_GET['sort'] = $sort;
		}

		return $this->render();
	}

	/**
	 * A valid key for Ann.
	 *
	 * @return string
	 */
	private function ann_key(): string {
		parse_str( (string) wp_parse_url( ( new Member_Page_Link() )->url( $this->ann, get_permalink( $this->page_id ) ), PHP_URL_QUERY ), $args );

		return (string) $args[ Member_Page_Link::QUERY_ARG ];
	}

	public function test_without_a_link_the_page_asks_for_an_email_address(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'name="member_email"', $html );
		$this->assertStringNotContainsString( 'Your entries', $html );
	}

	public function test_the_reply_is_the_same_for_a_member_and_a_stranger_and_only_the_member_is_emailed(): void {
		$member_reply   = $this->post( 'ann@example.com' );
		$stranger_reply = $this->post( 'stranger@example.com' );

		$this->assertSame( $member_reply, $stranger_reply );
		$this->assertStringContainsString( "If that address is on our list", $member_reply );
		$this->assertCount( 1, $this->mails );
		$this->assertSame( 'ann@example.com', $this->mails[0]['to'] );
		$this->assertStringContainsString( esc_url( get_permalink( $this->page_id ) ), (string) $this->mails[0]['message'] );
	}

	public function test_a_form_posted_without_a_valid_nonce_sends_nothing(): void {
		try {
			$this->post( 'ann@example.com', 'forged' );
			$this->fail( 'A forged form should be refused.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( array(), $this->mails );
		}
	}

	public function test_a_bad_link_says_so_and_offers_the_form(): void {
		$html = $this->open( '1.2.' . str_repeat( 'a', 64 ) );

		$this->assertStringContainsString( "This link has expired or isn't valid", html_entity_decode( $html, ENT_QUOTES ) );
		$this->assertStringContainsString( 'name="member_email"', $html );
	}

	public function test_a_members_link_lists_their_entries_in_the_order_asked_for(): void {
		$competitions = new Competitions_Repository();
		$ids          = array();
		foreach ( array( 'Spring' => '2026-03-01', 'Autumn' => '2026-09-01' ) as $title => $date ) {
			$ids[ $title ] = (int) $competitions->create(
				array(
					'type_id'    => Type_Fixtures::id(),
					'title'      => $title,
					'slug'       => strtolower( $title ),
					'open_date'  => $date . ' 00:00:00',
					'close_date' => $date . ' 23:00:00',
				)
			);
		}
		$category = 'colour';
		Entry_Fixtures::insert_entry( $ids['Spring'], $category, (int) $this->ann->id, array( 9 ) );
		Entry_Fixtures::insert_entry( $ids['Autumn'], $category, (int) $this->ann->id, array( 1 ) );
		Entry_Fixtures::insert_scored_entry( $ids['Autumn'], $category, 'Bob', 'beginner', array( 9 ) );
		Workflow_Fixtures::publish_results( $ids['Spring'] );
		Workflow_Fixtures::publish_results( $ids['Autumn'] );

		$by_date     = $this->open( $this->ann_key() );
		$by_position = $this->open( $this->ann_key(), 'position' );

		$this->assertStringContainsString( 'Your entries', $by_date );
		$this->assertLessThan( strpos( $by_date, 'Spring' ), strpos( $by_date, 'Autumn' ), 'newest first by default' );
		$this->assertLessThan( strpos( $by_position, 'Autumn' ), strpos( $by_position, 'Spring' ), 'best position first when asked' );
	}

	/**
	 * A competition with one entry of Ann's.
	 *
	 * @return int Entry ID.
	 */
	private function ann_entry(): int {
		$competition = (int) ( new Competitions_Repository() )->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'      => 'Spring',
				'slug'       => 'spring',
				'open_date'  => '2026-03-01 00:00:00',
				'close_date' => '2026-03-02 00:00:00',
			)
		);

		return Entry_Fixtures::insert_entry( $competition, 'colour', (int) $this->ann->id, array() );
	}

	public function test_while_past_competitions_are_on_the_page_offers_show_and_hide_for_all_images_and_each_one(): void {
		$entry = $this->ann_entry();

		$off = $this->open( $this->ann_key() );
		Competition_Archive::save_settings( true, false );
		$on = $this->open( $this->ann_key() );

		$this->assertStringNotContainsString( 'name="visibility_all"', $off );
		$this->assertStringContainsString( 'name="visibility_all"', $on );
		$this->assertStringContainsString( 'name="visibility[' . $entry . ']"', $on );
	}

	public function test_saving_the_form_with_a_members_link_saves_their_choices(): void {
		$entry = $this->ann_entry();
		Competition_Archive::save_settings( true, false );
		$_POST    = array(
			'photo_comp_visibility' => '1',
			'visibility_all'        => 'hide',
			'visibility'            => array( $entry => 'hide' ),
		);
		$_REQUEST = $_POST;

		$this->open( $this->ann_key() );

		$this->assertSame(
			array(
				'shown'  => false,
				'hidden' => 1,
			),
			( new Member_Visibility() )->status( ( new Members_Repository() )->find( (int) $this->ann->id ) )
		);
	}

	public function test_opening_a_members_link_changes_nothing(): void {
		$entry = $this->ann_entry();
		Competition_Archive::save_settings( true, false );
		$_GET = array(
			Member_Page_Link::QUERY_ARG => $this->ann_key(),
			'visibility_all'            => 'hide',
			'visibility'                => array( $entry => 'hide' ),
		);

		$this->render();

		$this->assertSame(
			array(
				'shown'  => true,
				'hidden' => 0,
			),
			( new Member_Visibility() )->status( ( new Members_Repository() )->find( (int) $this->ann->id ) )
		);
	}
}
