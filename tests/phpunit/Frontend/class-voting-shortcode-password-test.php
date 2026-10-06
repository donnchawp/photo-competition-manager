<?php
/**
 * Tests for Voting_Shortcode password-based ballots.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Voting_Shortcode;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Tests\Admin\Redirect_Exception;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

require_once dirname( __DIR__ ) . '/Admin/class-redirect-exception.php';

/**
 * Ballots cast with the club's voting password.
 */
class Voting_Shortcode_Password_Test extends WP_UnitTestCase {

	const PASSWORD = 'blarney';

	/**
	 * @var int
	 */
	private $competition_id;

	/**
	 * Entries keyed by category slug, then in the order they were added.
	 *
	 * @var array<string, int[]>
	 */
	private $images = array();

	public function setUp(): void {
		parent::setUp();

		$this->competition_id = (int) ( new Competitions_Repository() )->create(
			array(
				'title'     => 'Password Comp',
				'slug'      => 'password-comp',
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
						'auth_mode' => 'password',
						'password'  => self::PASSWORD,
					),
				),
			)
		);

		foreach ( array( 'colour', 'colour', 'mono' ) as $i => $category ) {
			$member_id                  = Member_Fixtures::insert_with_grade( 'Entrant ' . $i, 'entrant-' . $i . '@example.com', 'beginner' );
			$this->images[ $category ][] = Entry_Fixtures::insert_entry( $this->competition_id, $category, $member_id, array() );
		}

		Workflow_Fixtures::set_stage( $this->competition_id, 'colour', Competition_Workflow::STAGE_VOTING );

		add_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );
	}

	public function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );
		$_POST    = array();
		$_REQUEST = array();
		unset( $_COOKIE['photo_competition_voter'] );
		parent::tearDown();
	}

	/**
	 * Redirect interceptor: stop before the exit that follows a saved ballot.
	 *
	 * @param string $location Redirect target.
	 * @throws Redirect_Exception Always, carrying the location.
	 */
	public function throw_on_redirect( $location ) {
		throw new Redirect_Exception( (string) $location ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test harness; location captured, not output.
	}

	/**
	 * Post a colour ballot, the way template_redirect sees it.
	 *
	 * @param array<int,int> $votes    Image ID => score.
	 * @param string         $voter    Voter name.
	 * @param string         $password Voting password.
	 * @return string The redirect target when the ballot is cast or already cast, otherwise the rendered page.
	 */
	private function submit_ballot( array $votes, string $voter = 'Ann Voter', string $password = self::PASSWORD ): string {
		$nonce     = wp_create_nonce( 'photo_competition_vote' );
		$shortcode = new Voting_Shortcode();

		$_POST['photo_competition_vote']          = '1';
		$_POST['photo_competition_vote_nonce']    = $nonce;
		$_REQUEST['photo_competition_vote_nonce'] = $nonce;
		$_POST['voter_name']                      = $voter;
		$_POST['voting_password']                 = $password;
		$_POST['category']                        = 'colour';
		$_POST['votes']                           = array_map( 'strval', $votes );

		try {
			$shortcode->handle_ballot();
		} catch ( Redirect_Exception $e ) {
			return $e->getMessage();
		}

		return $shortcode->render();
	}

	/**
	 * The page as a voter sees it after a redirect, with no form posted.
	 *
	 * @return string
	 */
	private function view(): string {
		$_POST    = array();
		$_REQUEST = array();

		return ( new Voting_Shortcode() )->render();
	}

	private function vote_count(): int {
		return count( ( new Votes_Repository() )->find_by_competition( $this->competition_id ) );
	}

	/**
	 * A colour ballot scoring every entry.
	 *
	 * @return array<int,int>
	 */
	private function full_ballot(): array {
		return array(
			$this->images['colour'][0] => 9,
			$this->images['colour'][1] => 8,
		);
	}

	public function test_a_cast_ballot_redirects_to_the_thank_you_page_and_remembers_the_voter(): void {
		$location = $this->submit_ballot( $this->full_ballot() );

		$this->assertStringEndsWith( 'ballot=cast', $location );
		$this->assertSame( 2, $this->vote_count() );
		$this->assertSame(
			array(
				'name'     => 'Ann Voter',
				'password' => self::PASSWORD,
			),
			json_decode( wp_unslash( $_COOKIE['photo_competition_voter'] ), true )
		);

		$_GET['ballot'] = 'cast';
		$this->assertStringContainsString( 'Thank you for voting! Your votes have been recorded.', $this->view() );
		unset( $_GET['ballot'] );
	}

	public function test_a_refused_ballot_shows_why_and_keeps_the_voters_scores(): void {
		$second = $this->images['colour'][1];

		$page = $this->submit_ballot( array( $second => 8 ) );

		$this->assertSame( 1, substr_count( $page, 'You have voted for 1 of 2 images.' ) );
		$this->assertMatchesRegularExpression( '/name="votes\[' . $second . '\]"[^>]*value="8" checked/', $page );
		$this->assertStringContainsString( 'value="Ann Voter"', $page );
		$this->assertArrayNotHasKey( 'photo_competition_voter', $_COOKIE );
		$this->assertSame( 0, $this->vote_count() );
	}

	public function test_a_wrong_password_does_not_reveal_whether_a_name_has_voted(): void {
		$this->submit_ballot( $this->full_ballot() );
		unset( $_COOKIE['photo_competition_voter'] );

		$page = $this->submit_ballot( $this->full_ballot(), 'Ann Voter', 'shandon' );

		$this->assertStringContainsString( 'The voting password is incorrect.', $page );
		$this->assertStringNotContainsString( 'already been recorded', $page );
	}

	/**
	 * Names the cookie has to carry back intact.
	 *
	 * @return array<string, array{string}>
	 */
	public function remembered_names(): array {
		return array(
			'too long for the votes table' => array( str_repeat( 'Ann ', 50 ) . 'Voter' ),
			'with an accent'               => array( 'Seán Ó Sé' ),
		);
	}

	/**
	 * @dataProvider remembered_names
	 *
	 * @param string $name The name the voter gave.
	 */
	public function test_a_remembered_voter_who_has_cast_their_ballot_is_told_so_once( string $name ): void {
		$this->submit_ballot( $this->full_ballot(), $name );

		$page = $this->view();

		$this->assertSame( 1, substr_count( $page, 'Your votes for this category have already been recorded.' ) );
	}

	public function test_a_ballot_cast_again_redirects_to_already_cast(): void {
		$this->submit_ballot( $this->full_ballot() );

		$location = $this->submit_ballot( $this->full_ballot() );

		$this->assertStringEndsWith( 'ballot=already_cast', $location );
		$this->assertSame( 2, $this->vote_count() );

		$_GET['ballot'] = 'already_cast';
		$this->assertSame( 1, substr_count( $this->view(), 'Your votes for this category have already been recorded.' ) );
		unset( $_GET['ballot'] );
	}
}
