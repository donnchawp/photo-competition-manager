<?php
/**
 * Tests for Member_Visibility_API: the member page saving a choice in the background.
 *
 * @package PhotoCompetitionManager\Tests\API
 */

namespace PhotoCompetitionManager\Tests\API;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Competition_Archive;
use PhotoCompetitionManager\Service\Member_Page_Link;
use PhotoCompetitionManager\Service\Member_Visibility;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A member's key authorises the change. Which images a member may change
 * is tested on Member_Visibility.
 */
class Member_Visibility_API_Test extends WP_UnitTestCase {

	/**
	 * Ann's member ID.
	 *
	 * @var int
	 */
	private $ann;

	/**
	 * One of Ann's entries.
	 *
	 * @var int
	 */
	private $entry;

	/**
	 * Turn past competitions on, and give Ann an entry.
	 */
	public function setUp(): void {
		parent::setUp();

		Competition_Archive::save_settings( true, false );
		do_action( 'rest_api_init' );

		$this->ann   = Member_Fixtures::insert_with_grade( 'Ann', 'ann@example.com', 'beginner' );
		$competition = (int) ( new Competitions_Repository() )->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'      => 'Spring',
				'slug'       => 'spring',
				'open_date'  => '2026-03-01 00:00:00',
				'close_date' => '2026-03-02 00:00:00',
			)
		);
		$this->entry = Entry_Fixtures::insert_entry( $competition, 'colour', $this->ann, array() );
	}

	/**
	 * Ann's key.
	 *
	 * @return string
	 */
	private function key(): string {
		parse_str( (string) wp_parse_url( ( new Member_Page_Link() )->url( $this->ann(), home_url( '/' ) ), PHP_URL_QUERY ), $args );

		return (string) $args[ Member_Page_Link::QUERY_ARG ];
	}

	/**
	 * Ann, as stored now.
	 *
	 * @return object
	 */
	private function ann(): object {
		return ( new Members_Repository() )->find( $this->ann );
	}

	/**
	 * Post a choice.
	 *
	 * @param array<string, mixed> $params Body.
	 * @return \WP_REST_Response
	 */
	private function post( array $params ) {
		$request = new WP_REST_Request( 'POST', '/photo-comp/v1/member-visibility' );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}

	public function test_a_members_key_saves_their_choice_for_all_images_or_one(): void {
		$this->assertSame( 200, $this->post( array( 'key' => $this->key(), 'entry' => $this->entry, 'shown' => 'false' ) )->get_status() );
		$this->assertSame( '1', ( new \PhotoCompetitionManager\Repository\Images_Repository() )->find( $this->entry )->archive_hidden, 'one image' );

		$this->assertSame( 200, $this->post( array( 'key' => $this->key(), 'entry' => 0, 'shown' => 'false' ) )->get_status() );
		$this->assertFalse( ( new Member_Visibility() )->status( $this->ann() )['shown'], 'all images' );
	}

	public function test_without_a_valid_key_nothing_is_saved(): void {
		$response = $this->post( array( 'key' => '1.2.' . str_repeat( 'a', 64 ), 'entry' => 0, 'shown' => 'false' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertTrue( ( new Member_Visibility() )->status( $this->ann() )['shown'] );
	}

	public function test_a_choice_the_member_may_not_make_is_refused(): void {
		$bobs = Entry_Fixtures::insert_scored_entry( (int) ( new \PhotoCompetitionManager\Repository\Images_Repository() )->find( $this->entry )->competition_id, 'colour', 'Bob', 'beginner', array() );

		$this->assertSame( 400, $this->post( array( 'key' => $this->key(), 'entry' => $bobs, 'shown' => 'false' ) )->get_status() );
	}

	public function test_while_past_competitions_are_off_nothing_is_saved(): void {
		Competition_Archive::save_settings( false, false );

		$this->assertSame( 403, $this->post( array( 'key' => $this->key(), 'entry' => 0, 'shown' => 'false' ) )->get_status() );
		$this->assertTrue( ( new Member_Visibility() )->status( $this->ann() )['shown'] );
	}
}
