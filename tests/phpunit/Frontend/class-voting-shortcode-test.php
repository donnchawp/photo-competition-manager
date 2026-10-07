<?php
/**
 * Tests for Voting_Shortcode token-based voting access.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Voting_Shortcode;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Tests\Admin\Redirect_Exception;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Admin/class-redirect-exception.php';

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

	/**
	 * Last captured wp_mail() arguments.
	 *
	 * @var array<string, mixed>|null
	 */
	private $last_mail = null;

	/**
	 * Each category's one entry, keyed by category slug.
	 *
	 * @var array<string, int>
	 */
	private $images = array();

	public function setUp(): void {
		parent::setUp();

		$this->shortcode = new Voting_Shortcode();
		$this->members   = new Members_Repository();
		$this->tokens    = new Voting_Token_Repository();

		$this->create_competition();

		$this->mail_count = 0;
		add_filter(
			'wp_mail',
			function ( $atts ) {
				++$this->mail_count;
				$this->last_mail = $atts;
				return $atts;
			}
		);
		add_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );
	}

	/**
	 * Redirect interceptor: stop before the exit that follows a cast ballot.
	 *
	 * @param string $location Redirect target.
	 * @throws Redirect_Exception Always, carrying the location.
	 */
	public function throw_on_redirect( $location ) {
		throw new Redirect_Exception( (string) $location ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test harness; location captured, not output.
	}

	public function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		unset( $GLOBALS['post'] );
		parent::tearDown();
	}

	/**
	 * Create the open token-voting competition under test, with voting open for colour.
	 */
	private function create_competition(): void {
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
						'auth_mode' => 'token',
					),
				),
			)
		);
		$this->competition = $competitions->find( (int) $competition_id );

		foreach ( array( 'colour', 'mono' ) as $category ) {
			$this->images[ $category ] = Entry_Fixtures::insert_entry( (int) $competition_id, $category, $this->make_member( $category . '-entrant@example.com', true ), array() );
		}

		$this->set_open_categories( array( 'colour' ) );
	}

	/**
	 * Replace the categories open for voting on the competition under test.
	 *
	 * @param array<string> $open_categories Category slugs open for voting.
	 */
	private function set_open_categories( array $open_categories ): void {
		$id       = (int) $this->competition->id;
		$workflow = new Competition_Workflow();

		foreach ( array( 'colour', 'mono' ) as $category ) {
			$workflow->reset_category( $id, $category, false );
		}

		foreach ( $open_categories as $category ) {
			Workflow_Fixtures::set_stage( $id, $category, Competition_Workflow::STAGE_VOTING );
		}

		$this->competition = ( new Competitions_Repository() )->find( $id );
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
		$this->assertSame( 1, preg_match( '/photo-comp-redirect-btn" data-redirect-url="([^"]*)"/', $html, $matches ) );
		return html_entity_decode( $matches[1] );
	}

	private function make_member( string $email, bool $active, string $name = 'Voter' ): int {
		return (int) $this->members->create(
			array(
				'name'   => $name,
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

	private function issue_token( int $member_id, int $expires = HOUR_IN_SECONDS ): string {
		$token_string = bin2hex( random_bytes( 32 ) );
		$this->tokens->create(
			$member_id,
			(int) $this->competition->id,
			'colour',
			hash( 'sha256', $token_string ),
			gmdate( 'Y-m-d H:i:s', time() + $expires )
		);
		return $token_string;
	}

	/**
	 * The category's one entry, created with the competition.
	 *
	 * @param string $category Category slug.
	 * @return int Image ID.
	 */
	private function make_image( string $category = 'colour' ): int {
		return $this->images[ $category ];
	}

	/**
	 * Add another entry to a category, from a new member.
	 *
	 * @param string $category Category slug.
	 * @return int Image ID.
	 */
	private function add_entry( string $category ): int {
		static $entrant = 0;
		++$entrant;

		return Entry_Fixtures::insert_entry( (int) $this->competition->id, $category, $this->make_member( 'entrant-' . $entrant . '@example.com', true ), array() );
	}

	/**
	 * Post a ballot with a token, the way template_redirect sees it.
	 *
	 * @param string             $token_string Voting token.
	 * @param int|array<int,int> $votes        Image ID to score 9, or image ID => score.
	 * @return string The redirect target when the ballot is cast or already cast, otherwise the rendered page.
	 */
	private function submit_vote( string $token_string, $votes ): string {
		$nonce = wp_create_nonce( 'photo_competition_vote_with_token' );

		$_GET['token']                            = $token_string;
		$_POST['photo_competition_vote']          = '1';
		$_POST['photo_competition_vote_nonce']    = $nonce;
		$_REQUEST['photo_competition_vote_nonce'] = $nonce;
		$_POST['votes']                           = is_array( $votes ) ? array_map( 'strval', $votes ) : array( $votes => '9' );

		try {
			$this->shortcode->handle_ballot();
		} catch ( Redirect_Exception $e ) {
			return $e->getMessage();
		}

		return $this->shortcode->render();
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

	public function test_voting_link_template_fills_in_every_merge_tag(): void {
		( new Competitions_Repository() )->update( (int) $this->competition->id, array( 'close_date' => '2099-12-31 18:00:00' ) );
		update_option(
			'photo_comp_email_templates',
			array(
				'voting_link' => array(
					'enabled' => true,
					'subject' => 'Your voting link for {competition_title}',
					'body'    => '<p>Hi {member_name}, vote in {competition_title} at {voting_link} before {close_date}. From {site_name}.</p>',
				),
			)
		);
		$this->make_member( 'mary@example.com', true, 'Mary Murphy' );

		$this->request_token( 'mary@example.com' );

		$this->assertStringContainsString( 'Your voting link for Token Comp', $this->last_mail['subject'] );
		$this->assertStringContainsString( 'Hi Mary Murphy,', $this->last_mail['message'] );
		$this->assertStringContainsString( 'December 31, 2099', $this->last_mail['message'] );
		$this->assertStringContainsString( 'token=', $this->last_mail['message'] );
		$this->assertDoesNotMatchRegularExpression( '/\{[a-z_]+\}/', $this->last_mail['subject'] . $this->last_mail['message'] );
	}

	public function test_voting_link_with_nothing_saved_sends_the_email_the_screen_shows(): void {
		$this->make_member( 'mary@example.com', true, 'Mary Murphy' );

		$this->request_token( 'mary@example.com' );

		$this->assertStringContainsString( 'Vote in Token Comp', $this->last_mail['subject'] );
		$this->assertStringContainsString( 'Hi Mary Murphy,', $this->last_mail['message'] );
		$this->assertStringContainsString( 'You asked to vote in Token Comp.', $this->last_mail['message'] );
		$this->assertStringContainsString( 'token=', $this->last_mail['message'] );
	}

	public function test_voting_link_sends_its_saved_template_even_when_saved_switched_off(): void {
		update_option(
			'photo_comp_email_templates',
			array(
				'voting_link' => array(
					'enabled' => false,
					'subject' => 'Saved subject',
					'body'    => 'Saved body for {member_name}',
				),
			)
		);
		$this->make_member( 'mary@example.com', true, 'Mary Murphy' );

		$this->request_token( 'mary@example.com' );

		$this->assertSame( 1, $this->mail_count );
		$this->assertStringContainsString( 'Saved subject', $this->last_mail['subject'] );
		$this->assertStringContainsString( 'Saved body for Mary Murphy', $this->last_mail['message'] );
	}

	public function test_voting_link_is_logged_under_the_members_name(): void {
		$this->make_member( 'mary@example.com', true, 'Mary Murphy' );

		$this->request_token( 'mary@example.com' );

		$logs = ( new Logs_Repository() )->find_by_competition( (int) $this->competition->id, 50, 0, array( 'event_type' => 'voting_link' ) );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'Email sent to Mary Murphy: Voting link', $logs[0]->description );
		$this->assertSame( 'mary@example.com', json_decode( $logs[0]->metadata, true )['email'] );
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

	public function test_an_entry_whose_image_is_missing_shows_as_unavailable_on_the_ballot(): void {
		$this->make_image();
		$_GET['token'] = $this->issue_token( $this->make_member( 'active@example.com', true ) );

		$output = $this->shortcode->render();

		$this->assertStringContainsString( 'Image unavailable', $output );
		$this->assertStringNotContainsString( '<img', $output );
	}

	public function test_inactive_member_token_falls_back_to_request_form(): void {
		$_GET['token'] = $this->issue_token( $this->make_member( 'inactive@example.com', false ) );

		$this->assertStringContainsString( 'token-request-section', $this->shortcode->render() );
	}

	public function test_a_cast_ballot_redirects_to_the_thank_you_page(): void {
		$permalink = $this->view_page();
		$token     = $this->issue_token( $this->make_member( 'active@example.com', true ) );

		$location = $this->submit_vote( $token, $this->make_image() );

		$this->assertSame( add_query_arg( 'ballot', 'cast', $permalink ), $location );
		$this->assertSame( 1, $this->vote_count() );

		$_POST          = array();
		$_GET['ballot'] = 'cast';
		$page           = $this->shortcode->render();
		$this->assertStringContainsString( 'Thank you for voting! Your votes have been recorded anonymously.', $page );
		$this->assertStringNotContainsString( 'already been recorded', $page );
	}

	public function test_a_refused_ballot_shows_why_and_keeps_the_voters_scores(): void {
		$first  = $this->make_image();
		$second = $this->add_entry( 'colour' );

		$page = $this->submit_vote( $this->issue_token( $this->make_member( 'active@example.com', true ) ), array( $second => 8 ) );

		$this->assertSame( 1, substr_count( $page, 'You have voted for 1 of 2 images.' ) );
		$this->assertMatchesRegularExpression( '/name="votes\[' . $second . '\]"[^>]*value="8" checked/', $page );
		$this->assertStringContainsString( 'name="votes[' . $first . ']"', $page );
		$this->assertSame( 0, $this->vote_count() );
	}

	public function test_a_voter_who_has_cast_their_ballot_is_told_so_once(): void {
		$token = $this->issue_token( $this->make_member( 'active@example.com', true ) );
		$this->submit_vote( $token, $this->make_image() );

		$_POST = array();
		$page  = $this->shortcode->render();

		$this->assertSame( 1, substr_count( $page, 'Your votes for this category have already been recorded.' ) );
		$this->assertStringNotContainsString( 'id="voting-form"', $page );
	}

	public function test_a_ballot_cast_again_redirects_to_already_cast(): void {
		$permalink = $this->view_page();
		$token     = $this->issue_token( $this->make_member( 'active@example.com', true ) );
		$this->submit_vote( $token, $this->make_image() );

		$location = $this->submit_vote( $token, $this->make_image() );

		$this->assertSame( add_query_arg( 'ballot', 'already_cast', $permalink ), $location );
		$this->assertSame( 1, $this->vote_count() );

		$_POST          = array();
		$_GET['ballot'] = 'already_cast';
		$this->assertSame( 1, substr_count( $this->shortcode->render(), 'Your votes for this category have already been recorded.' ) );
	}

	public function test_a_ballot_with_an_expired_link_says_so(): void {
		$token = $this->issue_token( $this->make_member( 'active@example.com', true ), -60 );

		$page = $this->submit_vote( $token, $this->make_image() );

		$this->assertStringContainsString( 'This voting link has expired or isn&#039;t valid.', $page );
		$this->assertStringContainsString( 'token-request-section', $page );
		$this->assertSame( 0, $this->vote_count() );
	}

	public function test_a_ballot_for_a_closed_category_says_so_once(): void {
		$token = $this->issue_token( $this->make_member( 'active@example.com', true ) );
		$this->set_open_categories( array( 'mono' ) );

		$page = $this->submit_vote( $token, $this->make_image() );

		$this->assertSame( 1, substr_count( $page, 'open for this category.' ) );
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

	public function test_check_open_button_drops_token_when_token_category_closed(): void {
		$this->set_open_categories( array( 'mono' ) );
		$permalink     = $this->view_page();
		$_GET['token'] = $this->issue_token( $this->make_member( 'active@example.com', true ) );

		$html = $this->shortcode->render();

		$this->assertStringContainsString( 'Voting is no longer open for this category.', $html );
		$this->assertSame( $permalink, $this->check_open_url( $html ) );

		unset( $_GET['token'] );
		$this->assertStringContainsString( '<option value="mono">', $this->shortcode->render() );
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
