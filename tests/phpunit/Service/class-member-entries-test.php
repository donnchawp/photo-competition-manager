<?php
/**
 * Tests for Member_Entries: every image a member has entered.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Member_Entries;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

/**
 * A member's page lists their entries from every competition, with positions
 * where results are published.
 */
class Member_Entries_Test extends WP_UnitTestCase {

	/**
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * @var int
	 */
	private $ann;

	/**
	 * @var int
	 */
	private $bob;

	public function setUp(): void {
		parent::setUp();

		$this->competitions = new Competitions_Repository();
		$this->ann          = Member_Fixtures::insert_with_grade( 'Ann', 'ann@example.com', 'beginner' );
		$this->bob          = Member_Fixtures::insert_with_grade( 'Bob', 'bob@example.com', 'beginner' );
	}

	/**
	 * Create a competition with a Colour and a Mono category.
	 *
	 * @param string $slug      Slug.
	 * @param string $open_date When it opened.
	 * @return int
	 */
	private function competition( string $slug, string $open_date ): int {
		return (int) $this->competitions->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'      => ucfirst( $slug ),
				'slug'       => $slug,
				'open_date'  => $open_date,
				'close_date' => gmdate( 'Y-m-d H:i:s', strtotime( $open_date ) + 5 * DAY_IN_SECONDS ),
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
	 * A member's listed entries.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $sort      Sort.
	 * @return array<int, object>
	 */
	private function entries( int $member_id, string $sort = 'date' ): array {
		return ( new Member_Entries() )->for_member( ( new Members_Repository() )->find( $member_id ), $sort );
	}

	/**
	 * Entry IDs of a member's listed entries, in order.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $sort      Sort.
	 * @return array<int, int>
	 */
	private function listed( int $member_id, string $sort = 'date' ): array {
		return array_map(
			static function ( $entry ): int {
				return (int) $entry->id;
			},
			$this->entries( $member_id, $sort )
		);
	}

	public function test_a_member_sees_their_own_entries_from_every_competition_newest_first(): void {
		$january  = $this->competition( 'january', '2026-01-01 00:00:00' );
		$february = $this->competition( 'february', '2026-02-01 00:00:00' );
		$old      = Entry_Fixtures::insert_entry( $january, 'colour', $this->ann, array() );
		$new      = Entry_Fixtures::insert_entry( $february, 'mono', $this->ann, array() );
		Entry_Fixtures::insert_entry( $february, 'colour', $this->bob, array() );

		$this->assertSame( array( $new, $old ), $this->listed( $this->ann ) );
	}

	public function test_total_scores_and_positions_show_only_while_results_are_published(): void {
		$published = $this->competition( 'published', '2026-01-01 00:00:00' );
		$hidden    = $this->competition( 'hidden', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$scored    = Entry_Fixtures::insert_entry( $published, 'colour', $this->ann, array( 9, 8 ) );
		Entry_Fixtures::insert_entry( $published, 'colour', $this->bob, array( 9, 9 ) );
		$pending = Entry_Fixtures::insert_entry( $hidden, 'colour', $this->ann, array( 9, 9 ) );
		Workflow_Fixtures::publish_results( $published );

		// Published and then hidden again while the competition is still open: its results are recorded but not out.
		Workflow_Fixtures::publish_results( $hidden );
		$this->assertTrue( true === ( new Competition_Workflow() )->unpublish_results( $hidden ) );

		$entries = array_column( $this->entries( $this->ann ), null, 'id' );

		$this->assertSame( 17, $entries[ $scored ]->score );
		$this->assertSame( 2, $entries[ $scored ]->position );
		$this->assertNull( $entries[ $pending ]->score );
		$this->assertNull( $entries[ $pending ]->position );
	}

	public function test_sorting_by_position_puts_the_best_positions_first_and_unpositioned_entries_last(): void {
		$january  = $this->competition( 'january', '2026-01-01 00:00:00' );
		$february = $this->competition( 'february', '2026-02-01 00:00:00' );
		$march    = $this->competition( 'march', '2026-03-01 00:00:00' );

		$january_first  = Entry_Fixtures::insert_entry( $january, 'colour', $this->ann, array( 9 ) );
		$january_second = Entry_Fixtures::insert_entry( $january, 'mono', $this->ann, array( 5 ) );
		Entry_Fixtures::insert_entry( $january, 'mono', $this->bob, array( 7 ) );
		$february_first     = Entry_Fixtures::insert_entry( $february, 'colour', $this->ann, array( 6 ) );
		$march_unpositioned = Entry_Fixtures::insert_entry( $march, 'colour', $this->ann, array( 9 ) );
		Workflow_Fixtures::publish_results( $january );
		Workflow_Fixtures::publish_results( $february );

		$this->assertSame(
			array( $february_first, $january_first, $january_second, $march_unpositioned ),
			$this->listed( $this->ann, 'position' )
		);
	}

	public function test_an_entry_ranked_without_a_grade_is_shown_as_ungraded(): void {
		$competition = $this->competition( 'january', '2026-01-01 00:00:00' );
		$no_grade    = Member_Fixtures::insert_with_grade( 'Cal', 'cal@example.com', 'no-such-grade' );
		$entry       = Entry_Fixtures::insert_entry( $competition, 'colour', $no_grade, array( 9 ) );
		Workflow_Fixtures::publish_results( $competition );

		$listed = $this->entries( $no_grade );

		$this->assertSame( $entry, $listed[0]->id );
		$this->assertSame( 'Ungraded', $listed[0]->grade );
	}

	public function test_entries_in_an_archived_competition_are_listed_without_their_image(): void {
		$archived = $this->competition( 'archived', '2026-01-01 00:00:00' );
		$entry    = Entry_Fixtures::insert_entry( $archived, 'colour', $this->ann, array( 9 ) );
		Workflow_Fixtures::publish_results( $archived );
		$this->competitions->archive( $archived );

		$entries = $this->entries( $this->ann );

		$this->assertSame( array( $entry ), $this->listed( $this->ann ) );
		$this->assertSame( 1, $entries[0]->position );
		$this->assertSame( '', $entries[0]->thumb );
	}
}
