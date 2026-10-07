<?php
/**
 * Tests for Recorded_Results_Repository.
 *
 * @package PhotoCompetitionManager\Tests\Repository
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Recorded_Results_Repository;
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

class Recorded_Results_Repository_Test extends WP_UnitTestCase {

	/**
	 * @var Recorded_Results_Repository
	 */
	private $record;

	/**
	 * @var int
	 */
	private $competition_id;

	public function set_up(): void {
		parent::set_up();

		$this->record         = new Recorded_Results_Repository();
		$this->competition_id = (int) ( new Competitions_Repository() )->create(
			array(
				'title' => 'Recorded Comp',
				'slug'  => 'recorded-comp',
			)
		);
	}

	public function test_a_deleted_members_rows_stay_without_their_entry_or_member(): void {
		$ann     = Entry_Fixtures::insert_scored_entry( $this->competition_id, 'colour', 'Ann', 'beginner', array( 9 ) );
		$bob     = Entry_Fixtures::insert_scored_entry( $this->competition_id, 'colour', 'Bob', 'beginner', array( 5 ) );
		$ann_id  = (int) ( new Images_Repository() )->find( $ann )->member_id;
		$bob_id  = (int) ( new Images_Repository() )->find( $bob )->member_id;
		Workflow_Fixtures::publish_results( $this->competition_id );

		( new Entries() )->remove_member_entries( Actor::admin(), $ann_id );
		( new Members_Repository() )->delete( $ann_id );

		$this->assertSame(
			array( ':', $bob . ':' . $bob_id ),
			$this->ids( $this->record->find_by_category( $this->competition_id, 'colour' ) )
		);
	}

	public function test_a_removed_entrys_row_stays_with_its_member(): void {
		$ann    = Entry_Fixtures::insert_scored_entry( $this->competition_id, 'colour', 'Ann', 'beginner', array( 9 ) );
		$ann_id = (int) ( new Images_Repository() )->find( $ann )->member_id;
		Workflow_Fixtures::publish_results( $this->competition_id );

		( new Entries() )->remove( Actor::admin(), $this->competition_id, $ann );

		$this->assertSame( array( ':' . $ann_id ), $this->ids( $this->record->find_by_category( $this->competition_id, 'colour' ) ) );
	}

	public function test_deleting_a_competition_deletes_its_record(): void {
		Entry_Fixtures::insert_scored_entry( $this->competition_id, 'colour', 'Ann', 'beginner', array( 9 ) );
		Workflow_Fixtures::publish_results( $this->competition_id );

		( new Entries() )->remove_competition_entries( Actor::admin(), $this->competition_id );
		( new Competitions_Repository() )->delete( $this->competition_id );

		$this->assertNull( $this->record->recorded_at( $this->competition_id ) );
	}

	public function test_recording_the_same_entries_twice_leaves_one_record(): void {
		$row = array(
			'competition_id' => $this->competition_id,
			'category'       => 'colour',
			'entry_id'       => 41,
			'member_id'      => 7,
			'grade'          => 'beginner',
			'total_score'    => 9,
			'vote_count'     => 1,
			'position'       => 1,
		);

		// Two first reads at once both find no record and both record it.
		$this->assertSame( 1, $this->record->insert( array( $row ) ) );
		$this->assertSame( 0, $this->record->insert( array( $row ) ) );

		$this->assertSame( array( '41:7' ), $this->ids( $this->record->find_by_category( $this->competition_id, 'colour' ) ) );
	}

	/**
	 * Each row's "entry ID:member ID", blank where null.
	 *
	 * @param array<int, object> $rows Recorded rows.
	 * @return array<int, string>
	 */
	private function ids( array $rows ): array {
		return array_map(
			static fn( $row ): string => $row->entry_id . ':' . $row->member_id,
			$rows
		);
	}
}
