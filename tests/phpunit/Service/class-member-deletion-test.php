<?php
/**
 * Tests for deleting a member.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Service\Member_Deletion;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
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
