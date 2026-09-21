<?php
/**
 * Tests for Voting_Shortcode token-based voting access.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Voting_Shortcode;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use WP_UnitTestCase;

/**
 * Deactivated members must not receive voting links or reach the ballot.
 */
class Voting_Shortcode_Test extends WP_UnitTestCase {

	/**
	 * @var Voting_Shortcode
	 */
	private $shortcode;

	/**
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * @var Voting_Token_Repository
	 */
	private $tokens;

	/**
	 * @var object
	 */
	private $competition;

	/**
	 * @var int
	 */
	private $mail_count = 0;

	public function setUp(): void {
		parent::setUp();

		$this->shortcode = new Voting_Shortcode();
		$this->members   = new Members_Repository();
		$this->tokens    = new Voting_Token_Repository();

		$this->create_competition( array( 'colour' ) );

		$this->mail_count = 0;
		add_filter(
			'wp_mail',
			function ( $atts ) {
				++$this->mail_count;
				return $atts;
			}
		);
	}

	public function tearDown(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['post'] );
		parent::tearDown();
	}

	/**
	 * Create the open token-voting competition under test.
	 *
	 * @param array<string> $open_categories Category slugs open for voting.
	 */
	private function create_competition( array $open_categories ): void {
		$competitions      = new Competitions_Repository();
		$competition_id    = $competitions->create(
			array(
				'title'     => 'Token Comp',
				'slug'      => 'token-comp',
				'open_date' => '2020-01-01 00:00:00',
				'settings'  => array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
							'quota' => 1,
						),
						array(
							'slug'  => 'mono',
							'label' => 'Mono',
							'quota' => 1,
						),
					),
					'voting'     => array(
						'auth_mode'       => 'token',
						'open_categories' => $open_categories,
					),
				),
			)
		);
		$this->competition = $competitions->find( (int) $competition_id );
	}

	/**
	 * Replace the categories open for voting on the competition under test.
	 *
	 * @param array<string> $open_categories Category slugs open for voting.
	 */
	private function set_open_categories( array $open_categories ): void {
		$competitions                         = new Competitions_Repository();
		$settings                             = json_decode( $this->competition->settings, true );
		$settings['voting']['open_categories'] = $open_categories;
		$competitions->update( (int) $this->competition->id, array( 'settings' => $settings ) );
		$this->competition = $competitions->find( (int) $this->competition->id );
	}

	/**
	 * Make a page the current post so get_permalink() has something to return.
	 *
	 * @return string The page's permalink.
	 */
	private function view_page(): string {
		$GLOBALS['post'] = get_post( self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		return get_permalink();
	}

	/**
	 * Extract the "Check If Voting Is Open" redirect URL from rendered output.
	 *
	 * @param string $html Rendered shortcode output.
	 * @return string
	 */
	private function check_open_url( string $html ): string {
		$this->assertMatchesRegularExpression( '/photo-comp-redirect-btn" data-redirect-url="([^"]*)"/', $html );
		preg_match( '/photo-comp-redirect-btn" data-redirect-url="([^"]*)"/', $html, $matches );
		return html_entity_decode( $matches[1] );
	}

	private function make_member( string $email, bool $active ): int {
		return (int) $this->members->create(
			array(
				'name'   => 'Voter',
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => $active ? 1 : 0,
			)
		);
	}

	private function request_token( string $email ): string {
		$nonce = wp_create_nonce( 'photo_competition_request_voting_token' );

		$_POST['photo_competition_request_voting_token'] = '1';
		$_POST['photo_competition_voting_nonce']         = $nonce;
		$_REQUEST['photo_competition_voting_nonce']      = $nonce;
		$_POST['member_email']                           = $email;
		$_POST['category']                               = 'colour';

		return $this->shortcode->render();
	}

	private function issue_token( int $member_id ): string {
		$token_string = bin2hex( random_bytes( 32 ) );
		$this->tokens->create(
			$member_id,
			(int) $this->competition->id,
			'colour',
			hash( 'sha256', $token_string ),
			gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS )
		);
		return $token_string;
	}

	private function make_image(): int {
		return (int) ( new Images_Repository() )->create(
			array(
				'competition_id' => (int) $this->competition->id,
				'member_id'      => $this->make_member( 'entrant@example.com', true ),
				'category'       => 'colour',
				'filename'       => 'entry.jpg',
			)
		);
	}

	private function submit_vote( string $token_string, int $image_id ): void {
		$nonce = wp_create_nonce( 'photo_competition_vote_with_token' );

		$_GET['token']                            = $token_string;
		$_POST['photo_competition_vote']          = '1';
		$_POST['photo_competition_vote_nonce']    = $nonce;
		$_REQUEST['photo_competition_vote_nonce'] = $nonce;
		$_POST['votes']                           = array( $image_id => '9' );

		$this->shortcode->render();
	}

	private function vote_count(): int {
		return count( ( new Votes_Repository() )->find_by_competition( (int) $this->competition->id ) );
	}

	public function test_active_member_is_sent_voting_link(): void {
		$member_id = $this->make_member( 'active@example.com', true );

		$message = $this->request_token( 'active@example.com' );

		$this->assertStringContainsString( 'class="success"', $message );
		$this->assertSame( 1, $this->mail_count );
		$this->assertTrue( $this->tokens->has_recent_token( $member_id, (int) $this->competition->id, 'colour' ) );
	}

	public function test_inactive_member_gets_generic_success_but_no_link(): void {
		$member_id = $this->make_member( 'inactive@example.com', false );

		$message = $this->request_token( 'inactive@example.com' );

		$this->assertStringContainsString( 'class="success"', $message );
		$this->assertSame( 0, $this->mail_count );
		$this->assertFalse( $this->tokens->has_recent_token( $member_id, (int) $this->competition->id, 'colour' ) );
	}

	public function test_active_member_token_opens_ballot(): void {
		$this->make_image();
		$_GET['token'] = $this->issue_token( $this->make_member( 'active@example.com', true ) );

		$output = $this->shortcode->render();

		$this->assertStringContainsString( 'id="voting-form"', $output );
		$this->assertStringNotContainsString( 'token-request-section', $output );
	}

	public function test_inactive_member_token_falls_back_to_request_form(): void {
		$_GET['token'] = $this->issue_token( $this->make_member( 'inactive@example.com', false ) );

		$this->assertStringContainsString( 'token-request-section', $this->shortcode->render() );
	}

	public function test_active_member_token_records_vote(): void {
		$image_id = $this->make_image();

		$this->submit_vote( $this->issue_token( $this->make_member( 'active@example.com', true ) ), $image_id );

		$this->assertSame( 1, $this->vote_count() );
	}

	public function test_inactive_member_token_cannot_vote(): void {
		$image_id = $this->make_image();

		$this->submit_vote( $this->issue_token( $this->make_member( 'inactive@example.com', false ) ), $image_id );

		$this->assertSame( 0, $this->vote_count() );
	}

	public function test_check_open_button_keeps_token_when_no_category_open(): void {
		$this->set_open_categories( array() );
		$permalink     = $this->view_page();
		$token         = $this->issue_token( $this->make_member( 'active@example.com', true ) );
		$_GET['token'] = $token;

		$url = $this->check_open_url( $this->shortcode->render() );

		$this->assertSame( add_query_arg( 'token', $token, $permalink ), $url );
	}

	public function test_check_open_button_keeps_token_when_token_category_closed(): void {
		$this->set_open_categories( array( 'mono' ) );
		$permalink     = $this->view_page();
		$token         = $this->issue_token( $this->make_member( 'active@example.com', true ) );
		$_GET['token'] = $token;

		$html = $this->shortcode->render();

		$this->assertStringContainsString( 'Voting is no longer open for this category.', $html );
		$this->assertSame( add_query_arg( 'token', $token, $permalink ), $this->check_open_url( $html ) );
	}

	public function test_check_open_button_has_no_token_without_one(): void {
		$this->set_open_categories( array() );
		$permalink = $this->view_page();

		$url = $this->check_open_url( $this->shortcode->render() );

		$this->assertSame( $permalink, $url );
	}

	public function test_check_open_button_encodes_token(): void {
		$this->set_open_categories( array() );
		$permalink     = $this->view_page();
		$_GET['token'] = 'abc&foo=bar';

		$url = $this->check_open_url( $this->shortcode->render() );

		$this->assertSame( add_query_arg( 'token', 'abc%26foo%3Dbar', $permalink ), $url );
	}
}
