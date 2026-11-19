<?php
/**
 * Tests for Voting_Link_Service: emailing a member their voting link.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Ballots;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Voting_Link_Service;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

/**
 * An admin emails a member a link that lets them vote in the open category.
 */
class Voting_Link_Service_Test extends WP_UnitTestCase {

	/**
	 * @var Voting_Link_Service
	 */
	private $links;

	/**
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * @var object
	 */
	private $competition;

	/**
	 * @var int
	 */
	private $entry_id;

	/**
	 * Every email sent, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function setUp(): void {
		parent::setUp();

		$this->links   = new Voting_Link_Service();
		$this->members = new Members_Repository();

		$competitions   = new Competitions_Repository();
		$competition_id = (int) $competitions->create(
			array(
				'type_id'   => Type_Fixtures::id(),
				'title'     => 'Links Comp',
				'slug'      => 'links-comp',
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
						'auth_mode'    => 'token',
						'score_matrix' => array( 9, 8, 7 ),
					),
					'urls'       => array( 'voting_page' => 'https://example.com/vote/' ),
				),
			)
		);

		$this->entry_id = Entry_Fixtures::insert_entry( $competition_id, 'colour', $this->member( 'entrant@example.com' ), array() );
		Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_VOTING );
		$this->competition = $competitions->find( $competition_id );

		add_filter(
			'wp_mail',
			function ( $atts ) {
				$this->mails[] = $atts;
				return $atts;
			}
		);
	}

	/**
	 * Create a member.
	 *
	 * @param string $email  Email.
	 * @param bool   $active Whether they're active.
	 * @return int
	 */
	private function member( string $email, bool $active = true ): int {
		return (int) $this->members->create(
			array(
				'name'   => 'Voter',
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => $active ? 1 : 0,
			)
		);
	}

	/**
	 * The raw token in the last email's voting link.
	 *
	 * @return string
	 */
	private function token_from_last_mail(): string {
		$mail = end( $this->mails );
		$this->assertSame( 1, preg_match( '#https://example\.com/vote/\?token=([0-9a-f]{64})#', $mail['message'], $matches ) );
		return $matches[1];
	}

	/**
	 * Send from the admin screens.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $category  Category slug.
	 * @return true|\WP_Error
	 */
	private function send( int $member_id, string $category = 'colour' ) {
		return $this->links->send_from_admin( $this->competition, $this->members->find( $member_id ), $category );
	}

	public function test_an_admin_sent_link_lets_the_member_vote_in_that_category() {
		$this->assertTrue( $this->send( $this->member( 'ann@example.com' ) ) );

		$ballots = new Ballots();
		$voter   = $ballots->link_voter( $this->competition, $this->token_from_last_mail() );

		$this->assertTrue( $ballots->cast( $this->competition, 'colour', $voter, array( $this->entry_id => 9 ) ) );
	}

	public function test_an_admin_send_replaces_the_earlier_link_without_waiting_five_minutes() {
		$member_id = $this->member( 'ann@example.com' );
		$this->send( $member_id );
		$first = $this->token_from_last_mail();

		$this->assertTrue( $this->send( $member_id ) );

		$this->assertCount( 2, $this->mails );
		$ballots = new Ballots();
		$this->assertWPError( $ballots->link_voter( $this->competition, $first ) );
		$this->assertNotWPError( $ballots->link_voter( $this->competition, $this->token_from_last_mail() ) );
	}

	public function test_a_member_who_has_voted_in_the_category_is_not_sent_a_link() {
		$member_id = $this->member( 'ann@example.com' );
		$this->send( $member_id );
		$ballots = new Ballots();
		$ballots->cast( $this->competition, 'colour', $ballots->link_voter( $this->competition, $this->token_from_last_mail() ), array( $this->entry_id => 9 ) );

		$result = $this->send( $member_id );

		$this->assertWPError( $result );
		$this->assertSame( 'already_voted', $result->get_error_code() );
		$this->assertCount( 1, $this->mails );
	}

	public function test_a_member_who_may_not_vote_is_not_sent_a_link() {
		$result = $this->send( $this->member( 'gone@example.com', false ) );

		$this->assertWPError( $result );
		$this->assertCount( 0, $this->mails );
	}

	public function test_no_link_is_sent_for_a_category_not_taking_votes() {
		$result = $this->send( $this->member( 'ann@example.com' ), 'mono' );

		$this->assertWPError( $result );
		$this->assertCount( 0, $this->mails );
	}
}
