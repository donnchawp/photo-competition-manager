<?php
/**
 * @package PhotoCompetitionManager\Tests\Repository
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use WP_UnitTestCase;
use function PhotoCompetitionManager\Support\utc_time;

class Competitions_Repository_Test extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();

		Activator::activate();
	}

	/**
	 * Repository returns prefixed table name.
	 *
	 * @return void
	 */
	public function test_table_name_is_prefixed(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$this->assertSame(
			$GLOBALS['wpdb']->prefix . 'photocomp_competitions',
			$repository->table()
		);
	}

	/**
	 * Repository creates competitions and normalizes slug.
	 *
	 * @return void
	 */
	public function test_create_persists_competition(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$result = $repository->create(
			array(
				'title'     => 'October 2024 Competition',
				'open_date' => '2024-10-01',
			)
		);

		$this->assertIsInt( $result );

		$row = $GLOBALS['wpdb']->get_row(
			$GLOBALS['wpdb']->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$repository->table(),
				$result
			)
		);

		$this->assertSame( 'october-2024-competition', $row->slug );
	}

	/**
	 * Duplicate slugs return an error.
	 *
	 * @return void
	 */
	public function test_duplicate_slug_returns_error(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$first = $repository->create(
			array(
				'title' => 'Monthly Challenge',
				'slug'  => 'monthly-challenge',
			)
		);

		$this->assertIsInt( $first );

		$second = $repository->create(
			array(
				'title' => 'Another Challenge',
				'slug'  => 'monthly-challenge',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $second );
		$this->assertSame( 'duplicate_slug', $second->get_error_code() );
	}

	/**
	 * Update modifies existing rows.
	 *
	 * @return void
	 */
	public function test_update_persists_changes(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition_id = $repository->create(
			array(
				'title' => 'Winter Showcase',
				'status'=> 'draft',
			)
		);

		$result = $repository->update(
			$competition_id,
			array(
				'title'      => 'Winter Showcase Updated',
				'open_date'  => '2024-12-01',
				'close_date' => '2024-12-31',
			)
		);

		$this->assertTrue( $result );

		$row = $repository->find( $competition_id );

		$this->assertSame( 'Winter Showcase Updated', $row->title );
		$this->assertSame( '2024-12-01 00:00:00', $row->open_date );
		$this->assertSame( '2024-12-31 00:00:00', $row->close_date );
	}

	/**
	 * Update with existing slug on same record succeeds.
	 *
	 * @return void
	 */
	public function test_update_allows_same_slug(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition_id = $repository->create(
			array(
				'title' => 'Spring Gala',
				'slug'  => 'spring-gala',
			)
		);

		$result = $repository->update(
			$competition_id,
			array(
				'title' => 'Spring Gala 2025',
				'slug'  => 'spring-gala',
			)
		);

		$this->assertTrue( $result );
		$row = $repository->find( $competition_id );
		$this->assertSame( 'spring-gala', $row->slug );
		$this->assertSame( 'Spring Gala 2025', $row->title );
	}

	/**
	 * Archive hides competitions from default listings.
	 *
	 * @return void
	 */
	public function test_archive_marks_competition_as_deleted(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition_id = $repository->create(
			array(
				'title' => 'Archive Me',
			)
		);

		$this->assertTrue( $repository->archive( $competition_id ) );

		$this->assertCount( 0, $repository->all() );
		$this->assertCount( 1, $repository->all( 10, true, true ) );
		$this->assertSame( 0, $repository->count( false ) );
		$this->assertSame( 1, $repository->count( true ) );

		$archived = $repository->find( $competition_id, true );

		$this->assertNotNull( $archived );
		$this->assertNotEmpty( $archived->deleted_at );
	}

	/**
	 * Restore brings archived items back to active list.
	 *
	 * @return void
	 */
	public function test_restore_reactivates_competition(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition_id = $repository->create(
			array(
				'title' => 'Restore Me',
			)
		);

		$repository->archive( $competition_id );
		$this->assertTrue( $repository->restore( $competition_id ) );

		$active = $repository->find( $competition_id );

		$this->assertNotNull( $active );
		$this->assertNull( $active->deleted_at );
		$this->assertSame( 1, $repository->count( false ) );
		$this->assertSame( 0, $repository->count( true ) );
	}

	/**
	 * Find by slug retrieves competition.
	 *
	 * @return void
	 */
	public function test_find_by_slug_retrieves_competition(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition_id = $repository->create(
			array(
				'title' => 'October 2025',
				'slug'  => 'october-2025',
			)
		);

		$competition = $repository->find_by_slug( 'october-2025' );

		$this->assertNotNull( $competition );
		$this->assertSame( $competition_id, (int) $competition->id );
		$this->assertSame( 'October 2025', $competition->title );
		$this->assertSame( 'october-2025', $competition->slug );
	}

	/**
	 * Find by slug excludes archived competitions by default.
	 *
	 * @return void
	 */
	public function test_find_by_slug_excludes_archived(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition_id = $repository->create(
			array(
				'title' => 'Archived Competition',
				'slug'  => 'archived-comp',
			)
		);

		$repository->archive( $competition_id );

		$competition = $repository->find_by_slug( 'archived-comp' );

		$this->assertNull( $competition );

		$competition_archived = $repository->find_by_slug( 'archived-comp', true );

		$this->assertNotNull( $competition_archived );
		$this->assertSame( $competition_id, (int) $competition_archived->id );
	}

	/**
	 * Find by slug returns null for non-existent slug.
	 *
	 * @return void
	 */
	public function test_find_by_slug_returns_null_for_missing(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$competition = $repository->find_by_slug( 'does-not-exist' );

		$this->assertNull( $competition );
	}

	// ---------------------------------------------------------------
	// is_open()
	// ---------------------------------------------------------------

	public function test_is_open_returns_true_for_current_dates(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id          = $repository->create(
			array(
				'title'      => 'Open Comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2099-12-31 23:59:59',
			)
		);
		$competition = $repository->find( $id );

		$this->assertTrue( $repository->is_open( $competition ) );
	}

	public function test_is_open_returns_false_when_not_yet_opened(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id          = $repository->create(
			array(
				'title'     => 'Future Comp',
				'open_date' => '2099-01-01 00:00:00',
			)
		);
		$competition = $repository->find( $id );

		$this->assertFalse( $repository->is_open( $competition ) );
	}

	public function test_is_open_returns_false_when_past_close_date(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id          = $repository->create(
			array(
				'title'      => 'Closed Comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-02-01 00:00:00',
			)
		);
		$competition = $repository->find( $id );

		$this->assertFalse( $repository->is_open( $competition ) );
	}

	public function test_is_open_returns_false_when_archived(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id = $repository->create(
			array(
				'title'      => 'Archived Comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2099-12-31 23:59:59',
			)
		);

		$repository->archive( $id );
		$competition = $repository->find( $id, true );

		$this->assertFalse( $repository->is_open( $competition ) );
	}

	public function test_is_open_returns_true_when_dates_are_null(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id          = $repository->create( array( 'title' => 'No Dates' ) );
		$competition = $repository->find( $id );

		$this->assertTrue( $repository->is_open( $competition ) );
	}

	// ---------------------------------------------------------------
	// all_open()
	// ---------------------------------------------------------------

	public function test_all_open_returns_only_open_competitions(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$open_id     = $repository->create( array( 'title' => 'Open Comp' ) );
		$closed_id   = $repository->create(
			array(
				'title'      => 'Closed Comp',
				'close_date' => '2020-02-01 00:00:00',
			)
		);
		$future_id   = $repository->create(
			array(
				'title'     => 'Future Comp',
				'open_date' => '2099-01-01 00:00:00',
			)
		);
		$archived_id = $repository->create( array( 'title' => 'Archived Comp' ) );
		$repository->archive( $archived_id );

		$ids = array_map( 'intval', wp_list_pluck( $repository->all_open(), 'id' ) );

		$this->assertSame( array( $open_id ), $ids );
		$this->assertNotContains( $closed_id, $ids );
		$this->assertNotContains( $future_id, $ids );
	}

	public function test_all_open_returns_empty_array_when_none_open(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$repository->create(
			array(
				'title'      => 'Closed Comp',
				'close_date' => '2020-02-01 00:00:00',
			)
		);

		$this->assertSame( array(), $repository->all_open() );
	}

	// ---------------------------------------------------------------
	// find_overlapping()
	// ---------------------------------------------------------------

	/**
	 * A range that starts before another competition closes overlaps it.
	 */
	public function test_find_overlapping_returns_competition_whose_dates_overlap(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$current_id = $repository->create(
			array(
				'title'      => 'Current',
				'open_date'  => utc_time( -10 * DAY_IN_SECONDS ),
				'close_date' => utc_time( 20 * DAY_IN_SECONDS ),
			)
		);

		$overlap = $repository->find_overlapping( utc_time( 10 * DAY_IN_SECONDS ), utc_time( 40 * DAY_IN_SECONDS ) );

		$this->assertNotNull( $overlap );
		$this->assertSame( $current_id, (int) $overlap->id );
	}

	/**
	 * A competition that closes before the range opens does not overlap it.
	 */
	public function test_find_overlapping_ignores_competition_that_ends_before_range(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$repository->create(
			array(
				'title'      => 'Current',
				'open_date'  => utc_time( -10 * DAY_IN_SECONDS ),
				'close_date' => utc_time( 20 * DAY_IN_SECONDS ),
			)
		);

		$this->assertNull( $repository->find_overlapping( utc_time( 21 * DAY_IN_SECONDS ), utc_time( 50 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * One competition closing on the day the next opens is the normal
	 * month-to-month hand-over, not an overlap.
	 */
	public function test_find_overlapping_allows_range_starting_when_other_closes(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );
		$hand_over  = utc_time( 20 * DAY_IN_SECONDS );

		$repository->create(
			array(
				'title'      => 'Current',
				'open_date'  => utc_time( -10 * DAY_IN_SECONDS ),
				'close_date' => $hand_over,
			)
		);

		$this->assertNull( $repository->find_overlapping( $hand_over, utc_time( 50 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * A competition with no close date stays open for ever, so it overlaps
	 * anything that opens after it.
	 */
	public function test_find_overlapping_treats_missing_close_date_as_open_ended(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$stale_id = $repository->create(
			array(
				'title'     => 'Stale',
				'open_date' => '2020-01-01 00:00:00',
			)
		);

		$overlap = $repository->find_overlapping( utc_time( 10 * DAY_IN_SECONDS ), utc_time( 40 * DAY_IN_SECONDS ) );

		$this->assertNotNull( $overlap );
		$this->assertSame( $stale_id, (int) $overlap->id );
	}

	/**
	 * A range with no open date is open from now, so it doesn't clash with
	 * competitions that have already closed.
	 */
	public function test_find_overlapping_treats_missing_open_date_as_now(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$repository->create(
			array(
				'title'      => 'Last Year',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-02-01 00:00:00',
			)
		);

		$this->assertNull( $repository->find_overlapping( null, null ) );
	}

	/**
	 * Only one competition may be open at a time from now on. Two past
	 * competitions whose dates overlapped don't block each other, so
	 * either can still be edited.
	 */
	public function test_find_overlapping_ignores_overlap_in_the_past(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$repository->create(
			array(
				'title'      => 'Last Year',
				'open_date'  => '2025-01-01 00:00:00',
				'close_date' => '2025-02-05 00:00:00',
			)
		);

		$this->assertNull( $repository->find_overlapping( '2025-02-01 00:00:00', '2025-03-01 00:00:00' ) );
	}

	/**
	 * Closing a competition sets its close date to the current time. The
	 * next competition opening today is stored as midnight, which is
	 * before that time, but it only clashes if both are open from now on.
	 */
	public function test_find_overlapping_allows_range_opening_today_after_close_competition(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$repository->create(
			array(
				'title'      => 'Closed Today',
				'open_date'  => utc_time( -30 * DAY_IN_SECONDS ),
				'close_date' => utc_time( -1 ),
			)
		);

		$this->assertNull( $repository->find_overlapping( gmdate( 'Y-m-d 00:00:00' ), utc_time( 30 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * A closed competition with no open date covers no time from now on,
	 * so it overlaps nothing.
	 */
	public function test_find_overlapping_ignores_closed_range_with_missing_open_date(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$repository->create(
			array(
				'title'     => 'Stale',
				'open_date' => '2020-01-01 00:00:00',
			)
		);

		$this->assertNull( $repository->find_overlapping( null, utc_time( -10 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * The competition being edited does not overlap itself.
	 */
	public function test_find_overlapping_excludes_given_competition(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id = $repository->create(
			array(
				'title'      => 'Current',
				'open_date'  => utc_time( -10 * DAY_IN_SECONDS ),
				'close_date' => utc_time( 20 * DAY_IN_SECONDS ),
			)
		);

		$this->assertNull( $repository->find_overlapping( utc_time( -10 * DAY_IN_SECONDS ), utc_time( 40 * DAY_IN_SECONDS ), $id ) );
	}

	/**
	 * Archived competitions are never open, so they never overlap.
	 */
	public function test_find_overlapping_ignores_archived_competitions(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id = $repository->create( array( 'title' => 'Archived' ) );
		$repository->archive( $id );

		$this->assertNull( $repository->find_overlapping( utc_time( 10 * DAY_IN_SECONDS ), utc_time( 40 * DAY_IN_SECONDS ) ) );
	}

	// ---------------------------------------------------------------
	// is_accepting_uploads()
	// ---------------------------------------------------------------

	public function test_is_accepting_uploads_when_open(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id          = $repository->create(
			array(
				'title'      => 'Upload Comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2099-12-31 23:59:59',
			)
		);
		$competition = $repository->find( $id );

		$this->assertTrue( $repository->is_accepting_uploads( $competition ) );
	}

	public function test_is_accepting_uploads_false_when_closed(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id          = $repository->create(
			array(
				'title'      => 'Closed Comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-02-01 00:00:00',
			)
		);
		$competition = $repository->find( $id );

		$this->assertFalse( $repository->is_accepting_uploads( $competition ) );
	}

	public function test_is_accepting_uploads_false_when_uploads_closed_setting(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id = $repository->create(
			array(
				'title'      => 'Uploads Closed',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2099-12-31 23:59:59',
				'settings'   => array( 'upload' => array( 'uploads_closed' => true ) ),
			)
		);

		$competition = $repository->find( $id );

		$this->assertFalse( $repository->is_accepting_uploads( $competition ) );
	}

	// ---------------------------------------------------------------
	// is_accepting_votes()
	// ---------------------------------------------------------------

	public function test_is_accepting_votes_when_open(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id          = $repository->create(
			array(
				'title'      => 'Voting Comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2099-12-31 23:59:59',
			)
		);
		$competition = $repository->find( $id );

		$this->assertTrue( $repository->is_accepting_votes( $competition ) );
	}

	public function test_is_accepting_votes_false_when_closed(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id          = $repository->create(
			array(
				'title'      => 'Closed Comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-02-01 00:00:00',
			)
		);
		$competition = $repository->find( $id );

		$this->assertFalse( $repository->is_accepting_votes( $competition ) );
	}

	public function test_is_accepting_votes_respects_open_categories(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id = $repository->create(
			array(
				'title'      => 'Category Voting',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2099-12-31 23:59:59',
				'settings'   => array( 'open_categories' => array( 'colour' ) ),
			)
		);

		$competition = $repository->find( $id );

		$this->assertTrue( $repository->is_accepting_votes( $competition, 'colour' ) );
		$this->assertFalse( $repository->is_accepting_votes( $competition, 'black-white' ) );
	}

	// ---------------------------------------------------------------
	// find_current_active()
	// ---------------------------------------------------------------

	public function test_find_current_active_returns_open_competition(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id = $repository->create(
			array(
				'title'      => 'Active Comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2099-12-31 23:59:59',
			)
		);

		$active = $repository->find_current_active();

		$this->assertNotNull( $active );
		$this->assertEquals( $id, (int) $active->id );
	}

	public function test_find_current_active_returns_null_when_none_open(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$repository->create(
			array(
				'title'      => 'Past Comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-02-01 00:00:00',
			)
		);

		$this->assertNull( $repository->find_current_active() );
	}

	public function test_find_current_active_excludes_archived(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id = $repository->create(
			array(
				'title'      => 'Archived Active',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2099-12-31 23:59:59',
			)
		);

		$repository->archive( $id );

		$this->assertNull( $repository->find_current_active() );
	}

	// ---------------------------------------------------------------
	// find_by_share_hash() / update_share_hash()
	// ---------------------------------------------------------------

	public function test_find_by_share_hash_returns_competition(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id = $repository->create(
			array(
				'title'      => 'Shared Comp',
				'share_hash' => 'abc123',
			)
		);

		$found = $repository->find_by_share_hash( 'abc123' );

		$this->assertNotNull( $found );
		$this->assertEquals( $id, (int) $found->id );
	}

	public function test_find_by_share_hash_returns_null_for_empty(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$this->assertNull( $repository->find_by_share_hash( '' ) );
	}

	public function test_find_by_share_hash_excludes_archived(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id = $repository->create(
			array(
				'title'      => 'Archived Shared',
				'share_hash' => 'xyz789',
			)
		);

		$repository->archive( $id );

		$this->assertNull( $repository->find_by_share_hash( 'xyz789' ) );
	}

	public function test_update_share_hash_persists(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id = $repository->create( array( 'title' => 'Hash Comp' ) );

		$result = $repository->update_share_hash( $id, 'newhash' );
		$this->assertTrue( $result );

		$found = $repository->find_by_share_hash( 'newhash' );
		$this->assertNotNull( $found );
		$this->assertEquals( $id, (int) $found->id );
	}

	public function test_update_share_hash_rejects_invalid_id(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$result = $repository->update_share_hash( 0, 'hash' );
		$this->assertWPError( $result );
	}

	// ---------------------------------------------------------------
	// delete()
	// ---------------------------------------------------------------

	public function test_delete_removes_competition(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$id = $repository->create( array( 'title' => 'Delete Me' ) );

		$result = $repository->delete( $id );
		$this->assertTrue( $result );

		$this->assertNull( $repository->find( $id ) );
		$this->assertNull( $repository->find( $id, true ) );
	}

	public function test_delete_rejects_invalid_id(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$result = $repository->delete( 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
	}

	public function test_delete_rejects_missing_competition(): void {
		$repository = new Competitions_Repository( $GLOBALS['wpdb'] );

		$result = $repository->delete( 9999 );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_competition', $result->get_error_code() );
	}
}
