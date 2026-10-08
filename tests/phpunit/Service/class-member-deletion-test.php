<?php
/**
 * Tests for deleting a member.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Recorded_Results_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Event_Logger;
use PhotoCompetitionManager\Service\Member_Deletion;
use PhotoCompetitionManager\Service\Named_Voter;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

/**
 * Deleting a member removes everything that names them and keeps the votes they cast.
 *
 * @covers \PhotoCompetitionManager\Service\Member_Deletion
 */
class Member_Deletion_Test extends WP_UnitTestCase {

	/**
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * @var Votes_Repository
	 */
	private $votes;

	/**
	 * @var Member_Deletion
	 */
	private $deletion;

	public function setUp(): void {
		parent::setUp();

		$this->members  = new Members_Repository();
		$this->votes    = new Votes_Repository();
		$this->deletion = new Member_Deletion();
	}

	public function test_a_deleted_members_voting_link_votes_still_count_under_their_token(): void {
		$competition_id = $this->create_competition( 'link-votes' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$johns_entry    = Entry_Fixtures::insert_entry( $competition_id, 'colour', $john_id, array( 3 ) );
		$token_id       = $this->voting_token( $jane_id, $competition_id );
		$this->votes->create_anonymous_ballot( $competition_id, 'colour', $token_id, array( $johns_entry => 5 ) );
		$before = $this->votes->calculate_averages( $competition_id );

		$this->assertSame( 1, $this->deletion->delete( $jane_id ) );

		$this->assertNull( $this->members->find( $jane_id ) );
		$this->assertSame( 0, ( new Voting_Token_Repository() )->member_token_id( $jane_id, $competition_id, 'colour' ) );
		$this->assertSame( array( $johns_entry => 5.0 ), $this->votes->get_votes_by_token( $token_id ) );
		$this->assertSame( $before, $this->votes->calculate_averages( $competition_id ) );
	}

	public function test_a_deleted_members_upload_links_go_and_other_members_stay(): void {
		$competition_id = $this->create_competition( 'upload-links' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$uploads        = new Upload_Token_Repository();
		$uploads->find_or_create( $jane_id, $competition_id );
		$uploads->find_or_create( $john_id, $competition_id );
		$this->voting_token( $john_id, $competition_id );

		$this->assertSame( 0, $this->deletion->delete( $jane_id ) );

		$this->assertSame( array( $john_id ), array_keys( $uploads->get_tracking_by_competition( $competition_id ) ) );
		$this->assertSame( array( $john_id ), array_keys( ( new Voting_Token_Repository() )->get_tracking_by_competition( $competition_id ) ) );
	}

	public function test_a_deleted_members_password_votes_are_cast_by_a_former_member(): void {
		$competition_id = $this->create_competition( 'password-votes' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$johns_entry    = Entry_Fixtures::insert_entry( $competition_id, 'colour', $john_id, array( 3 ) );
		$johns_mono     = Entry_Fixtures::insert_entry( $competition_id, 'mono', $john_id, array() );
		// The name she typed differs in case, accents and spaces, as named voting allows.
		$this->votes->create_ballot( $competition_id, 'colour', ( new Named_Voter( ' JANE DOÉ ' ) )->name(), array( $johns_entry => 4 ) );
		$this->votes->create_ballot( $competition_id, 'mono', 'Jane Doe', array( $johns_mono => 2 ) );
		$this->votes->create_ballot( $competition_id, 'colour', 'Bob Smith', array( $johns_entry => 1 ) );
		$before = $this->votes->calculate_averages( $competition_id );

		$this->assertSame( 2, $this->deletion->delete( $jane_id ) );

		$former = 'Former member #' . $jane_id;
		$this->assertSame( array( $johns_entry => 4.0 ), $this->votes->get_votes_by_voter( $competition_id, 'colour', $former ) );
		$this->assertSame( array( $johns_mono => 2.0 ), $this->votes->get_votes_by_voter( $competition_id, 'mono', $former ) );
		$this->assertSame( array(), $this->votes->get_votes_by_voter( $competition_id, 'colour', 'Jane Doe' ) );
		$this->assertSame( array( $johns_entry => 1.0 ), $this->votes->get_votes_by_voter( $competition_id, 'colour', 'Bob Smith' ) );
		$this->assertSame( $before, $this->votes->calculate_averages( $competition_id ) );
	}

	public function test_two_deleted_members_who_voted_for_one_entry_stay_two_voters(): void {
		$competition_id = $this->create_competition( 'two-voters' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$mary_id        = $this->create_member( 'Mary Byrne', 'mary@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$johns_entry    = Entry_Fixtures::insert_entry( $competition_id, 'colour', $john_id, array() );
		$this->votes->create_ballot( $competition_id, 'colour', 'Jane Doe', array( $johns_entry => 4 ) );
		$this->votes->create_ballot( $competition_id, 'colour', 'Mary Byrne', array( $johns_entry => 2 ) );

		$this->assertSame( 1, $this->deletion->delete( $jane_id ) );
		$this->assertSame( 1, $this->deletion->delete( $mary_id ) );

		$this->assertSame( array( $johns_entry => 4.0 ), $this->votes->get_votes_by_voter( $competition_id, 'colour', 'Former member #' . $jane_id ) );
		$this->assertSame( array( $johns_entry => 2.0 ), $this->votes->get_votes_by_voter( $competition_id, 'colour', 'Former member #' . $mary_id ) );
		$this->assertSame( 2, $this->votes->calculate_averages( $competition_id )[ $johns_entry ]['vote_count'] );
	}

	public function test_deleting_one_of_two_members_who_share_a_name_renames_the_names_votes(): void {
		$competition_id = $this->create_competition( 'shared-name' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$other_jane_id  = $this->create_member( 'Jane Doe', 'jane.doe@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$johns_entry    = Entry_Fixtures::insert_entry( $competition_id, 'colour', $john_id, array() );
		$this->votes->create_ballot( $competition_id, 'colour', 'Jane Doe', array( $johns_entry => 4 ) );
		$before = $this->votes->calculate_averages( $competition_id );

		$this->assertSame( 1, $this->deletion->delete( $jane_id ) );

		$this->assertNotNull( $this->members->find( $other_jane_id ) );
		$this->assertSame( array( $johns_entry => 4.0 ), $this->votes->get_votes_by_voter( $competition_id, 'colour', 'Former member #' . $jane_id ) );
		$this->assertSame( $before, $this->votes->calculate_averages( $competition_id ) );
	}

	public function test_log_rows_about_a_deleted_member_go_and_others_stay(): void {
		$competition_id = $this->create_competition( 'logs' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$jane_15_id     = $this->create_member( 'Jane Murphy', 'jane15@example.com' );
		$logger         = new Event_Logger();
		$logger->log_email_sent( $competition_id, 'voting_link', 'Jane Doe', array( 'email' => 'jane@example.com' ) );
		$logger->log_email_sent( $competition_id, 'voting_link', 'Jane Doe', array( 'email' => 'JANE@example.com' ) );
		$logger->log_email_sent( $competition_id, 'voting_link', 'Jane Doe', array( 'email' => Members_Repository::mark_deactivated_email( 'jane@example.com' ) ) );
		$logger->log( $competition_id, 'category_change_failed', 'upload', 'A category change failed.', array( 'member_id' => $jane_id ) );
		$logger->log( $competition_id, 'category_change_failed', 'upload', 'A category change failed.', array( 'member_id' => (string) $jane_id ) );
		$logger->log_email_sent( $competition_id, 'voting_link', 'Jane Murphy', array( 'email' => 'jane15@example.com' ) );
		$logger->log( $competition_id, 'category_change_failed', 'upload', 'A category change failed.', array( 'member_id' => $jane_15_id ) );
		$logger->log( $competition_id, 'category_change_failed', 'upload', 'A category change failed.', array( 'member_id' => (int) ( $jane_id . '5' ) ) );
		$logger->log( $competition_id, 'voting_opened', 'voting', 'Voting opened.' );

		$this->deletion->delete( $jane_id );

		$left = array_map(
			static function ( $row ) {
				return $row->metadata;
			},
			( new Logs_Repository() )->paginate( 50, 0, array( 'competition_id' => $competition_id ) )
		);
		sort( $left );
		$expected = array( '[]', '{"email":"jane15@example.com"}', '{"member_id":' . $jane_15_id . '}', '{"member_id":' . $jane_id . '5}' );
		sort( $expected );
		$this->assertSame( $expected, $left );
	}

	public function test_recorded_results_dont_change_when_a_member_who_entered_and_voted_is_deleted(): void {
		$competition_id = $this->create_competition( 'recorded' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$mary_id        = $this->create_member( 'Mary Byrne', 'mary@example.com' );
		$janes_entry    = Entry_Fixtures::insert_entry( $competition_id, 'colour', $jane_id, array( 5, 5 ) );
		$johns_entry    = Entry_Fixtures::insert_entry( $competition_id, 'colour', $john_id, array( 3 ) );
		$marys_entry    = Entry_Fixtures::insert_entry( $competition_id, 'colour', $mary_id, array( 2 ) );
		$this->votes->create_anonymous_ballot( $competition_id, 'colour', $this->voting_token( $jane_id, $competition_id ), array( $johns_entry => 4 ) );
		$this->votes->create_ballot( $competition_id, 'colour', 'Jane Doe', array( $marys_entry => 1 ) );
		Workflow_Fixtures::publish_results( $competition_id );
		$recorded = new Recorded_Results_Repository();
		$before   = $this->scores( $recorded->find_by_category( $competition_id, 'colour' ) );

		$this->assertSame( 2, $this->deletion->delete( $jane_id ) );

		$this->assertNull( ( new Images_Repository() )->find( $janes_entry ) );
		$this->assertSame( $before, $this->scores( $recorded->find_by_category( $competition_id, 'colour' ) ) );
		$this->assertSame( array( 1, 2, 3 ), array_values( array_map( 'intval', array_column( $before, 'position' ) ) ) );
	}

	public function test_a_member_is_deleted_while_their_category_is_being_voted_on(): void {
		$competition_id = $this->create_competition( 'voting-now' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$attachment_id  = self::factory()->attachment->create();
		$janes_entry    = ( new Images_Repository() )->create(
			array(
				'competition_id'         => $competition_id,
				'member_id'              => $jane_id,
				'category'               => 'colour',
				'filename'               => 'jane-doe-colour.jpg',
				'original_attachment_id' => $attachment_id,
			)
		);
		Entry_Fixtures::insert_entry( $competition_id, 'colour', $john_id, array() );
		Workflow_Fixtures::set_stage( $competition_id, 'colour', Competition_Workflow::STAGE_VOTING );

		$this->assertSame( 0, $this->deletion->delete( $jane_id ) );

		$this->assertNull( ( new Images_Repository() )->find( (int) $janes_entry ) );
		$this->assertNull( get_post( $attachment_id ) );
		$this->assertNull( $this->members->find( $jane_id ) );
	}

	public function test_erasing_a_members_email_deletes_them_and_reports_their_votes_kept(): void {
		$competition_id = $this->create_competition( 'erase' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$janes_entry    = Entry_Fixtures::insert_entry( $competition_id, 'colour', $jane_id, array( 4 ) );
		$johns_entry    = Entry_Fixtures::insert_entry( $competition_id, 'colour', $john_id, array() );
		$this->votes->create_anonymous_ballot( $competition_id, 'colour', $this->voting_token( $jane_id, $competition_id ), array( $johns_entry => 4 ) );
		( new Upload_Token_Repository() )->find_or_create( $jane_id, $competition_id );
		( new Event_Logger() )->log_email_sent( $competition_id, 'voting_link', 'Jane Doe', array( 'email' => 'jane@example.com' ) );

		$response = $this->erase( 'jane@example.com' );

		$this->assertTrue( $response['items_removed'] );
		$this->assertTrue( $response['items_retained'] );
		$this->assertCount( 1, $response['messages'] );
		$this->assertTrue( $response['done'] );
		$this->assertNull( $this->members->find( $jane_id ) );
		$this->assertNull( ( new Images_Repository() )->find( $janes_entry ) );
		$this->assertSame( array(), ( new Upload_Token_Repository() )->get_tracking_by_competition( $competition_id ) );
		$this->assertSame( 0, ( new Logs_Repository() )->count( array( 'competition_id' => $competition_id ) ) );
		$this->assertSame( 1, $this->votes->calculate_averages( $competition_id )[ $johns_entry ]['vote_count'] );
	}

	public function test_erasing_a_member_who_cast_no_votes_reports_nothing_kept(): void {
		$jane_id = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$response = $this->erase( 'jane@example.com' );

		$this->assertTrue( $response['items_removed'] );
		$this->assertFalse( $response['items_retained'] );
		$this->assertSame( array(), $response['messages'] );
		$this->assertNull( $this->members->find( $jane_id ) );
	}

	public function test_erasing_a_deactivated_members_real_address_finds_them(): void {
		$jane_id = $this->create_member( 'Jane Doe', 'jane@example.com', 0 );

		$response = $this->erase( 'jane@example.com' );

		$this->assertTrue( $response['items_removed'] );
		$this->assertTrue( $response['done'] );
		$this->assertNull( $this->members->find( $jane_id ) );
	}

	public function test_erasing_an_address_held_by_two_records_pages_through_both(): void {
		$jane_id     = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$old_jane_id = Member_Fixtures::insert_with_grade( 'Jane Doe', 'jane@example.com', 'beginner', false );

		$first = $this->erase( 'jane@example.com', 1 );

		$this->assertTrue( $first['items_removed'] );
		$this->assertFalse( $first['done'] );

		$second = $this->erase( 'jane@example.com', 2 );

		$this->assertTrue( $second['items_removed'] );
		$this->assertTrue( $second['done'] );
		$this->assertNull( $this->members->find( $jane_id ) );
		$this->assertNull( $this->members->find( $old_jane_id ) );
	}

	public function test_erasing_an_address_that_isnt_a_members_removes_nothing(): void {
		$jane_id = $this->create_member( 'Jane Doe', 'jane@example.com' );

		$response = $this->erase( 'nobody@example.com' );

		$this->assertSame(
			array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			),
			$response
		);
		$this->assertNotNull( $this->members->find( $jane_id ) );
	}

	/**
	 * Run the plugin's personal data eraser, as Tools > Erase Personal Data does.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page.
	 * @return array<string, mixed>
	 */
	private function erase( string $email, int $page = 1 ): array {
		$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$this->assertArrayHasKey( 'photo-competition-manager', $erasers );

		return call_user_func( $erasers['photo-competition-manager']['callback'], $email, $page );
	}

	/**
	 * Each recorded row's total score, vote count and position.
	 *
	 * @param array<int, object> $rows Recorded rows.
	 * @return array<int, array<string, string>>
	 */
	private function scores( array $rows ): array {
		$scores = array();
		foreach ( $rows as $row ) {
			$scores[ (int) $row->id ] = array(
				'total_score' => (string) $row->total_score,
				'vote_count'  => (string) $row->vote_count,
				'position'    => (string) $row->position,
			);
		}

		return $scores;
	}

	/**
	 * Create a competition with Colour and Mono categories.
	 *
	 * @param string $slug Slug.
	 * @return int Competition ID.
	 */
	private function create_competition( string $slug ): int {
		return (int) ( new Competitions_Repository() )->create(
			array(
				'title'      => $slug,
				'slug'       => $slug,
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => null,
				'settings'   => array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
							'quota' => 2,
						),
						array(
							'slug'  => 'mono',
							'label' => 'Mono',
							'quota' => 2,
						),
					),
				),
			)
		);
	}

	/**
	 * Create a member.
	 *
	 * @param string $name   Name.
	 * @param string $email  Email.
	 * @param int    $active Whether they're active.
	 * @return int Member ID.
	 */
	private function create_member( string $name, string $email, int $active = 1 ): int {
		return (int) $this->members->create(
			array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => $active,
			)
		);
	}

	/**
	 * Give a member a voting link for a category.
	 *
	 * @param int    $member_id      Member ID.
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return int Voting token ID.
	 */
	private function voting_token( int $member_id, int $competition_id, string $category = 'colour' ): int {
		return (int) ( new Voting_Token_Repository() )->renew( $member_id, $competition_id, $category, wp_hash( uniqid( '', true ) ), '2099-01-01 00:00:00' );
	}
}
