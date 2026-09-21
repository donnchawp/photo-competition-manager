<?php
/**
 * Tests for Voting_Shortcode token-based voting access.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Voting_Shortcode;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use ReflectionMethod;
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
					),
					'voting'     => array(
						'auth_mode'       => 'token',
						'open_categories' => array( 'colour' ),
					),
				),
			)
		);
		$this->competition = $competitions->find( (int) $competition_id );

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
		unset( $_GET['token'] );
		parent::tearDown();
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
		$method = new ReflectionMethod( Voting_Shortcode::class, 'handle_token_request' );
		$method->setAccessible( true );

		return $method->invoke(
			$this->shortcode,
			$this->competition,
			\PhotoCompetitionManager\Support\Competition_Settings::parse( $this->competition->settings ),
			array(
				'member_email' => $email,
				'category'     => 'colour',
			)
		);
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
		$_GET['token'] = $this->issue_token( $this->make_member( 'active@example.com', true ) );

		$this->assertStringNotContainsString( 'token-request-section', $this->shortcode->render() );
	}

	public function test_inactive_member_token_falls_back_to_request_form(): void {
		$_GET['token'] = $this->issue_token( $this->make_member( 'inactive@example.com', false ) );

		$this->assertStringContainsString( 'token-request-section', $this->shortcode->render() );
	}
}
