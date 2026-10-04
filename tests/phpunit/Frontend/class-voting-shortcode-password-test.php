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
	 * Submit a colour ballot.
	 *
	 * @param array<int,int> $votes Image ID => score.
	 * @param string         $voter Voter name.
	 * @return string The redirect target when the ballot is saved, otherwise the rendered page.
	 */
	private function submit_ballot( array $votes, string $voter = 'Ann Voter' ): string {
		$nonce = wp_create_nonce( 'photo_competition_vote' );

		$_POST['photo_competition_vote']          = '1';
		$_POST['photo_competition_vote_nonce']    = $nonce;
		$_REQUEST['photo_competition_vote_nonce'] = $nonce;
		$_POST['voter_name']                      = $voter;
		$_POST['voting_password']                 = self::PASSWORD;
		$_POST['category']                        = 'colour';
		$_POST['votes']                           = array_map( 'strval', $votes );

		try {
			return ( new Voting_Shortcode() )->render();
		} catch ( Redirect_Exception $e ) {
			return $e->getMessage();
		}
	}

	private function vote_count(): int {
		return count( ( new Votes_Repository() )->find_by_competition( $this->competition_id ) );
	}

	public function test_ballot_for_every_image_is_accepted(): void {
		$result = $this->submit_ballot(
			array(
				$this->images['colour'][0] => 9,
				$this->images['colour'][1] => 8,
			)
		);

		$this->assertStringContainsString( 'vote_status=success', $result );
		$this->assertSame( 2, $this->vote_count() );
	}

	public function test_second_ballot_is_reported_as_already_voted(): void {
		$ballot = array(
			$this->images['colour'][0] => 9,
			$this->images['colour'][1] => 8,
		);
		$this->submit_ballot( $ballot );

		$result = $this->submit_ballot( $ballot );

		$this->assertStringContainsString( 'Your votes for this category have already been recorded.', $result );
		$this->assertStringNotContainsString( 'class="error"', $result );
		$this->assertSame( 2, $this->vote_count() );
	}

	public function test_ballot_padded_with_another_category_is_rejected(): void {
		$result = $this->submit_ballot(
			array(
				$this->images['colour'][0] => 9,
				$this->images['mono'][0]   => 8,
			)
		);

		$this->assertStringContainsString( 'You have voted for 1 of 2 images.', $result );
		$this->assertSame( 0, $this->vote_count() );
	}
}
