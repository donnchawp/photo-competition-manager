<?php
/**
 * Tests for the Ballots module, through its interface.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Service\Ballots;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

/**
 * Casting a ballot, for a link voter and a named voter alike.
 */
class Ballots_Test extends WP_UnitTestCase {

	const PASSWORD = 'blarney';

	/**
	 * @var Ballots
	 */
	private $ballots;

	/**
	 * @var object
	 */
	private $competition;

	/**
	 * Entries keyed by category slug, then in the order they were added.
	 *
	 * @var array<string, int[]>
	 */
	private $images = array();

	public function setUp(): void {
		parent::setUp();

		$competitions   = new Competitions_Repository();
		$competition_id = (int) $competitions->create(
			array(
				'title'     => 'Ballot Comp',
				'slug'      => 'ballot-comp',
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
						'password'     => self::PASSWORD,
						'score_matrix' => array( 9, 8, 7 ),
					),
				),
			)
		);

		foreach ( array( 'colour', 'colour', 'mono' ) as $i => $category ) {
			$member_id                   = Member_Fixtures::insert_with_grade( 'Entrant ' . $i, 'entrant-' . $i . '@example.com', 'beginner' );
			$this->images[ $category ][] = Entry_Fixtures::insert_entry( $competition_id, $category, $member_id, array() );
		}

		Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_VOTING );

		$this->competition = $competitions->find( $competition_id );
		$this->ballots     = new Ballots();
	}

	/**
	 * A colour ballot scoring every entry, as the form posts it.
	 *
	 * @return array<int, string>
	 */
	private function full_ballot(): array {
		return array(
			$this->images['colour'][0] => '9',
			$this->images['colour'][1] => '8',
		);
	}

	/**
	 * Issue a voting link for a member.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $category  Category slug.
	 * @param int    $expires   Seconds until it expires.
	 * @return string The token in the link.
	 */
	private function issue_link( int $member_id, string $category = 'colour', int $expires = HOUR_IN_SECONDS ): string {
		$token = bin2hex( random_bytes( 32 ) );
		( new Voting_Token_Repository() )->create( $member_id, (int) $this->competition->id, $category, hash( 'sha256', $token ), gmdate( 'Y-m-d H:i:s', time() + $expires ) );
		return $token;
	}

	/**
	 * A voter of the given kind, for colour.
	 *
	 * @param string $kind link or named.
	 * @return \PhotoCompetitionManager\Service\Voter
	 */
	private function voter( string $kind ) {
		if ( 'link' === $kind ) {
			$member_id = Member_Fixtures::insert_with_grade( 'Ann Voter', 'ann@example.com', 'beginner' );
			$voter     = $this->ballots->link_voter( $this->competition, $this->issue_link( $member_id ) );
		} else {
			$voter = $this->ballots->named_voter( $this->competition, 'Ann Voter', self::PASSWORD );
		}

		$this->assertNotWPError( $voter );
		return $voter;
	}

	/**
	 * Both kinds of voter.
	 *
	 * @return array<string, array{string}>
	 */
	public function voters(): array {
		return array(
			'link voter'  => array( 'link' ),
			'named voter' => array( 'named' ),
		);
	}

	private function vote_count(): int {
		return count( ( new Votes_Repository() )->find_by_competition( (int) $this->competition->id ) );
	}

	/**
	 * @dataProvider voters
	 *
	 * @param string $kind link or named.
	 */
	public function test_voter_casts_a_ballot_for_every_entry( string $kind ): void {
		$voter = $this->voter( $kind );

		$this->assertTrue( $this->ballots->cast( $this->competition, 'colour', $voter, $this->full_ballot() ) );
		$this->assertTrue( $this->ballots->has_cast( $this->competition, 'colour', $voter ) );
		$this->assertSame( 2, $this->vote_count() );
	}

	public function test_an_expired_link_is_refused(): void {
		$member_id = Member_Fixtures::insert_with_grade( 'Ann Voter', 'ann@example.com', 'beginner' );

		$voter = $this->ballots->link_voter( $this->competition, $this->issue_link( $member_id, 'colour', -60 ) );

		$this->assertWPError( $voter );
		$this->assertSame( 'invalid_link', $voter->get_error_code() );
	}

	public function test_an_inactive_members_link_is_refused(): void {
		$member_id = Member_Fixtures::insert_with_grade( 'Ann Voter', 'ann@example.com', 'beginner', false );

		$voter = $this->ballots->link_voter( $this->competition, $this->issue_link( $member_id ) );

		$this->assertSame( 'invalid_link', $voter->get_error_code() );
	}

	public function test_a_link_for_another_competition_is_refused(): void {
		$member_id = Member_Fixtures::insert_with_grade( 'Ann Voter', 'ann@example.com', 'beginner' );
		$token     = $this->issue_link( $member_id );
		$other     = clone $this->competition;
		$other->id = (int) $this->competition->id + 1;

		$voter = $this->ballots->link_voter( $other, $token );

		$this->assertSame( 'invalid_link', $voter->get_error_code() );
	}

	public function test_an_unknown_link_is_refused(): void {
		$this->assertSame( 'invalid_link', $this->ballots->link_voter( $this->competition, 'no-such-token' )->get_error_code() );
		$this->assertSame( 'invalid_link', $this->ballots->link_voter( $this->competition, '' )->get_error_code() );
	}

	public function test_a_wrong_voting_password_is_refused(): void {
		$voter = $this->ballots->named_voter( $this->competition, 'Ann Voter', 'shandon' );

		$this->assertSame( 'wrong_password', $voter->get_error_code() );
	}

	public function test_a_missing_voting_password_is_refused(): void {
		$voter = $this->ballots->named_voter( $this->competition, 'Ann Voter', '' );

		$this->assertSame( 'missing_password', $voter->get_error_code() );
	}

	public function test_the_voting_password_is_not_case_sensitive(): void {
		$this->assertNotWPError( $this->ballots->named_voter( $this->competition, 'Ann Voter', 'BLARNEY' ) );
	}

	public function test_a_legacy_hashed_voting_password_still_works(): void {
		$this->competition->settings = wp_json_encode(
			array(
				'voting' => array( 'password' => wp_hash_password( self::PASSWORD ) ),
			)
		);

		$this->assertNotWPError( $this->ballots->named_voter( $this->competition, 'Ann Voter', 'Blarney' ) );
		$this->assertSame( 'wrong_password', $this->ballots->named_voter( $this->competition, 'Ann Voter', 'shandon' )->get_error_code() );
	}

	public function test_any_password_will_do_when_the_competition_has_none(): void {
		$this->competition->settings = wp_json_encode( array( 'voting' => array( 'password' => '' ) ) );

		$this->assertNotWPError( $this->ballots->named_voter( $this->competition, 'Ann Voter', '' ) );
	}

	public function test_a_named_voter_needs_a_name(): void {
		$voter = $this->ballots->named_voter( $this->competition, '   ', self::PASSWORD );

		$this->assertSame( 'missing_name', $voter->get_error_code() );
	}

	/**
	 * @dataProvider voters
	 *
	 * @param string $kind link or named.
	 */
	public function test_a_ballot_is_refused_once_voting_closes( string $kind ): void {
		$voter = $this->voter( $kind );
		( new Competition_Workflow() )->close_voting( (int) $this->competition->id, 'colour' );
		$this->competition = ( new Competitions_Repository() )->find( (int) $this->competition->id );

		$result = $this->ballots->cast( $this->competition, 'colour', $voter, $this->full_ballot() );

		$this->assertSame( 'voting_closed', $result->get_error_code() );
		$this->assertSame( 0, $this->vote_count() );
	}

	public function test_a_link_voter_cannot_vote_outside_their_links_category(): void {
		$voter = $this->voter( 'link' );

		$result = $this->ballots->cast( $this->competition, 'mono', $voter, array( $this->images['mono'][0] => '9' ) );

		$this->assertSame( 'not_your_category', $result->get_error_code() );
		$this->assertSame( 0, $this->vote_count() );
	}

	/**
	 * @dataProvider voters
	 *
	 * @param string $kind link or named.
	 */
	public function test_an_empty_ballot_is_refused( string $kind ): void {
		$result = $this->ballots->cast( $this->competition, 'colour', $this->voter( $kind ), array() );

		$this->assertSame( 'empty_ballot', $result->get_error_code() );
	}

	/**
	 * @dataProvider voters
	 *
	 * @param string $kind link or named.
	 */
	public function test_a_ballot_must_score_every_entry( string $kind ): void {
		$result = $this->ballots->cast( $this->competition, 'colour', $this->voter( $kind ), array( $this->images['colour'][0] => '9' ) );

		$this->assertSame( 'incomplete_ballot', $result->get_error_code() );
		$this->assertSame( 'You must vote for all images. You have voted for 1 of 2 images.', $result->get_error_message() );
		$this->assertSame( 0, $this->vote_count() );
	}

	/**
	 * @dataProvider voters
	 *
	 * @param string $kind link or named.
	 */
	public function test_another_categorys_entry_does_not_count_towards_a_ballot( string $kind ): void {
		$result = $this->ballots->cast(
			$this->competition,
			'colour',
			$this->voter( $kind ),
			array(
				$this->images['colour'][0] => '9',
				$this->images['mono'][0]   => '8',
			)
		);

		$this->assertSame( 'incomplete_ballot', $result->get_error_code() );
		$this->assertSame( 0, $this->vote_count() );
	}

	/**
	 * @dataProvider voters
	 *
	 * @param string $kind link or named.
	 */
	public function test_an_unanswered_entry_is_not_a_score_of_zero( string $kind ): void {
		$this->competition->settings = wp_json_encode(
			array_merge(
				json_decode( $this->competition->settings, true ),
				array( 'voting' => array( 'score_matrix' => array( 2, 1, 0 ) ) )
			)
		);

		$result = $this->ballots->cast(
			$this->competition,
			'colour',
			$this->voter( $kind ),
			array(
				$this->images['colour'][0] => '2',
				$this->images['colour'][1] => '',
			)
		);

		$this->assertSame( 'incomplete_ballot', $result->get_error_code() );
		$this->assertSame( 0, $this->vote_count() );
	}

	/**
	 * Scores the form can't send.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function bad_scores(): array {
		$scores = array(
			'not in the matrix' => '6',
			'a fraction'        => '9.5',
			'not a number'      => 'nine',
		);

		$cases = array();
		foreach ( $scores as $label => $score ) {
			foreach ( $this->voters() as $voter => $kind ) {
				$cases[ "$voter, $label" ] = array( $kind[0], $score );
			}
		}
		return $cases;
	}

	/**
	 * @dataProvider bad_scores
	 *
	 * @param string $kind  link or named.
	 * @param string $score The score posted for the second entry.
	 */
	public function test_a_score_outside_the_matrix_is_refused( string $kind, string $score ): void {
		$result = $this->ballots->cast(
			$this->competition,
			'colour',
			$this->voter( $kind ),
			array(
				$this->images['colour'][0] => '9',
				$this->images['colour'][1] => $score,
			)
		);

		$this->assertSame( 'invalid_score', $result->get_error_code() );
		$this->assertSame( 0, $this->vote_count() );
	}

	/**
	 * @dataProvider voters
	 *
	 * @param string $kind link or named.
	 */
	public function test_a_second_ballot_is_already_cast( string $kind ): void {
		$voter = $this->voter( $kind );
		$this->ballots->cast( $this->competition, 'colour', $voter, $this->full_ballot() );

		$result = $this->ballots->cast( $this->competition, 'colour', $voter, $this->full_ballot() );

		$this->assertSame( 'already_cast', $result->get_error_code() );
		$this->assertSame( 'Thank you! Your votes for this category have already been recorded.', $result->get_error_message() );
		$this->assertSame( 2, $this->vote_count() );
	}

	/**
	 * @dataProvider voters
	 *
	 * @param string $kind link or named.
	 */
	public function test_a_ballot_the_database_refuses_in_part_stores_nothing_and_can_be_cast_again( string $kind ): void {
		global $wpdb;
		$voter  = $this->voter( $kind );
		$second = $this->images['colour'][1];

		// Break any votes INSERT that carries the second entry's vote.
		$votes_table = $wpdb->prefix . 'photocomp_votes';
		$break_vote  = function ( $query ) use ( $votes_table, $second ) {
			$is_votes_insert = 0 === strpos( $query, "INSERT INTO `{$votes_table}`" );
			return $is_votes_insert && preg_match( "/, {$second}, 8, '/", $query ) ? 'INSERT INTO no_such_table VALUES (1)' : $query;
		};
		add_filter( 'query', $break_vote );
		$suppress = $wpdb->suppress_errors( true );
		$result   = $this->ballots->cast( $this->competition, 'colour', $voter, $this->full_ballot() );
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_vote );

		$this->assertSame( 'insert_failed', $result->get_error_code() );
		$this->assertSame( 'Failed to record votes. Please try again.', $result->get_error_message() );
		$this->assertSame( 0, $this->vote_count() );
		$this->assertTrue( $this->ballots->cast( $this->competition, 'colour', $voter, $this->full_ballot() ) );
	}

	/**
	 * @dataProvider voters
	 *
	 * @param string $kind link or named.
	 */
	public function test_the_rules_apply_in_the_same_order_for_both_voters( string $kind ): void {
		$voter = $this->voter( $kind );
		( new Competition_Workflow() )->close_voting( (int) $this->competition->id, 'colour' );
		$this->competition = ( new Competitions_Repository() )->find( (int) $this->competition->id );

		$result = $this->ballots->cast( $this->competition, 'colour', $voter, array( $this->images['colour'][0] => '6' ) );

		$this->assertSame( 'voting_closed', $result->get_error_code() );
	}

	/**
	 * @dataProvider voters
	 *
	 * @param string $kind link or named.
	 */
	public function test_a_ballot_still_counts_after_the_score_matrix_changes( string $kind ): void {
		$voter = $this->voter( $kind );
		$this->assertFalse( $this->ballots->has_cast( $this->competition, 'colour', $voter ) );
		$this->ballots->cast( $this->competition, 'colour', $voter, $this->full_ballot() );

		$this->competition->settings = wp_json_encode( array( 'voting' => array( 'score_matrix' => array( 10, 6, 2 ) ) ) );

		$this->assertTrue( $this->ballots->has_cast( $this->competition, 'colour', $voter ) );
	}

	public function test_names_differing_only_in_case_accents_or_spaces_are_one_voter(): void {
		$this->ballots->cast( $this->competition, 'colour', $this->ballots->named_voter( $this->competition, 'Seán Murphy', self::PASSWORD ), $this->full_ballot() );

		$voter = $this->ballots->named_voter( $this->competition, '  sean murphy ', self::PASSWORD );

		$this->assertTrue( $this->ballots->has_cast( $this->competition, 'colour', $voter ) );
		$this->assertSame( 'already_cast', $this->ballots->cast( $this->competition, 'colour', $voter, $this->full_ballot() )->get_error_code() );
	}

	public function test_a_long_name_cut_at_a_space_is_stored_without_it(): void {
		$voter = $this->ballots->named_voter( $this->competition, str_repeat( 'a', 190 ) . ' Voter', self::PASSWORD );

		$this->ballots->cast( $this->competition, 'colour', $voter, $this->full_ballot() );

		$votes = ( new Votes_Repository() )->find_by_competition( (int) $this->competition->id );
		$this->assertSame( array( str_repeat( 'a', 190 ) ), array_values( array_unique( array_column( $votes, 'voter_name' ) ) ) );
	}
}
