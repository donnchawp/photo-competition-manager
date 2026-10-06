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
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
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
	}

	public function tearDown(): void {
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
	 * Submit a ballot with a token.
	 *
	 * @param string             $token_string Voting token.
	 * @param int|array<int,int> $votes        Image ID to score 9, or image ID => score.
	 * @return string Rendered output.
	 */
	private function submit_vote( string $token_string, $votes ): string {
		$nonce = wp_create_nonce( 'photo_competition_vote_with_token' );

		$_GET['token']                            = $token_string;
		$_POST['photo_competition_vote']          = '1';
		$_POST['photo_competition_vote_nonce']    = $nonce;
		$_REQUEST['photo_competition_vote_nonce'] = $nonce;
		$_POST['votes']                           = is_array( $votes ) ? array_map( 'strval', $votes ) : array( $votes => '9' );

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

	public function test_voting_link_falls_back_to_the_built_in_email_when_its_template_is_off(): void {
		update_option(
			'photo_comp_email_templates',
			array(
				'voting_link'   => array(
					'enabled' => false,
					'subject' => 'Ignored subject',
					'body'    => 'Ignored body',
				),
				'voting_opened' => array(
					'enabled' => true,
					'subject' => 'Voting is now open for {competition_title}',
					'body'    => '<p>Hi {member_name}, voting closes on {close_date}.</p>',
				),
			)
		);
		$this->make_member( 'mary@example.com', true, 'Mary Murphy' );

		$this->request_token( 'mary@example.com' );

		$this->assertSame( 1, $this->mail_count );
		$this->assertStringContainsString( 'Vote in Token Comp', $this->last_mail['subject'] );
		$this->assertStringContainsString( 'Hi Mary Murphy,', $this->last_mail['message'] );
		$this->assertStringContainsString( 'This link will expire in 1 hour and can only be used once.', $this->last_mail['message'] );
		$this->assertStringNotContainsString( 'Ignored', $this->last_mail['message'] );
	}

	public function test_voting_link_is_logged_under_the_members_name(): void {
		$this->make_member( 'mary@example.com', true, 'Mary Murphy' );

		$this->request_token( 'mary@example.com' );

		$logs = ( new Logs_Repository() )->find_by_competition( (int) $this->competition->id, 50, 0, array( 'event_type' => 'voting_link' ) );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'Sent voting link email to Mary Murphy', $logs[0]->description );
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

	public function test_active_member_token_records_vote(): void {
		$image_id = $this->make_image();

		$this->submit_vote( $this->issue_token( $this->make_member( 'active@example.com', true ) ), $image_id );

		$this->assertSame( 1, $this->vote_count() );
	}

	public function test_token_vote_ignores_images_from_another_category(): void {
		$colour_id = $this->make_image( 'colour' );
		$mono_id   = $this->make_image( 'mono' );

		$this->submit_vote(
			$this->issue_token( $this->make_member( 'active@example.com', true ) ),
			array(
				$colour_id => 9,
				$mono_id   => 8,
			)
		);

		$votes = ( new Votes_Repository() )->find_by_competition( (int) $this->competition->id );
		$this->assertSame( array( $colour_id ), array_map( 'intval', array_column( $votes, 'image_id' ) ) );
	}

	public function test_token_ballot_for_every_image_is_accepted(): void {
		$first  = $this->make_image( 'colour' );
		$second = $this->add_entry( 'colour' );

		$output = $this->submit_vote(
			$this->issue_token( $this->make_member( 'active@example.com', true ) ),
			array(
				$first  => 9,
				$second => 8,
			)
		);

		$this->assertStringContainsString( 'Thank you for voting!', $output );
		$this->assertSame( 2, $this->vote_count() );
	}

	public function test_token_ballot_padded_with_another_category_is_rejected(): void {
		$this->add_entry( 'colour' );

		$output = $this->submit_vote(
			$this->issue_token( $this->make_member( 'active@example.com', true ) ),
			array(
				$this->make_image( 'colour' ) => 9,
				$this->make_image( 'mono' )   => 8,
			)
		);

		$this->assertStringContainsString( 'You have voted for 1 of 2 images.', $output );
		$this->assertSame( 0, $this->vote_count() );
	}

	public function test_second_token_ballot_is_reported_as_already_voted(): void {
		$token = $this->issue_token( $this->make_member( 'active@example.com', true ) );
		$this->submit_vote( $token, $this->make_image() );

		$output = $this->submit_vote( $token, $this->make_image() );

		$this->assertStringContainsString( 'Your votes for this category have already been recorded.', $output );
		$this->assertStringNotContainsString( 'Thank you for voting!', $output );
		$this->assertStringNotContainsString( 'class="error"', $output );
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
