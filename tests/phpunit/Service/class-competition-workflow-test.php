<?php
/**
 * Tests for Competition_Workflow.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

use function PhotoCompetitionManager\Support\utc_time;

/**
 * @covers \PhotoCompetitionManager\Service\Competition_Workflow
 */
class Competition_Workflow_Test extends WP_UnitTestCase {

	/**
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * @var Competition_Workflow
	 */
	private $workflow;

	public function set_up(): void {
		parent::set_up();

		$this->competitions = new Competitions_Repository();
		$this->workflow     = new Competition_Workflow( $this->competitions );
	}

	/**
	 * Create a competition open now, with Colour and Mono categories.
	 *
	 * @param array<string, mixed> $data Overrides for the competition row.
	 * @return int Competition ID.
	 */
	private function create_competition( array $data = array() ): int {
		static $n = 0;
		++$n;

		return (int) $this->competitions->create(
			array_merge(
				array(
					'title'      => 'Workflow ' . $n,
					'slug'       => 'workflow-' . $n,
					'open_date'  => utc_time( -DAY_IN_SECONDS ),
					'close_date' => utc_time( DAY_IN_SECONDS ),
					'settings'   => array(
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
					),
				),
				$data
			)
		);
	}

	/**
	 * Fetch a competition row fresh from the database.
	 *
	 * @param int $id Competition ID.
	 * @return object
	 */
	private function row( int $id ): object {
		return $this->competitions->find( $id, true );
	}

	public function test_new_competition_accepts_uploads_and_has_not_started_voting(): void {
		$competition = $this->row( $this->create_competition() );

		$this->assertSame( Competition_Workflow::PHASE_ACCEPTING_UPLOADS, $this->workflow->phase( $competition ) );
		$this->assertTrue( $this->workflow->is_accepting_uploads( $competition ) );
		$this->assertSame( Competition_Workflow::STAGE_NOT_STARTED, $this->workflow->stage( $competition, 'colour' ) );
		$this->assertFalse( $this->workflow->is_accepting_votes( $competition, 'colour' ) );
	}

	public function test_closing_uploads_is_saved(): void {
		$id = $this->create_competition();

		$this->assertTrue( $this->workflow->close_uploads( $id ) );

		$competition = $this->row( $id );
		$this->assertSame( Competition_Workflow::PHASE_UPLOADS_CLOSED, $this->workflow->phase( $competition ) );
		$this->assertFalse( $this->workflow->is_accepting_uploads( $competition ) );
		$this->assertTrue( $this->workflow->uploads_closed( $competition ) );
	}

	public function test_advancing_from_not_started_marks_the_category_previewed(): void {
		$id = $this->create_competition();

		$this->assertTrue( $this->workflow->advance( $id, 'colour', Competition_Workflow::STAGE_PREVIEWED ) );

		$competition = $this->row( $id );
		$this->assertSame( Competition_Workflow::STAGE_PREVIEWED, $this->workflow->stage( $competition, 'colour' ) );
		$this->assertSame( Competition_Workflow::STAGE_NOT_STARTED, $this->workflow->stage( $competition, 'mono' ) );
	}

	public function test_advance_refuses_to_skip_opening_voting(): void {
		$id = $this->create_competition();
		$this->workflow->advance( $id, 'colour', Competition_Workflow::STAGE_PREVIEWED );

		$result = $this->workflow->advance( $id, 'colour', Competition_Workflow::STAGE_VOTING );

		$this->assertWPError( $result );
		$this->assertSame( 'wrong_stage', $result->get_error_code() );
		$this->assertSame( Competition_Workflow::STAGE_PREVIEWED, $this->workflow->stage( $this->row( $id ), 'colour' ) );
	}

	public function test_advance_twice_from_a_stale_page_is_refused(): void {
		$id = $this->create_competition();
		$this->workflow->advance( $id, 'colour', Competition_Workflow::STAGE_PREVIEWED );

		$result = $this->workflow->advance( $id, 'colour', Competition_Workflow::STAGE_PREVIEWED );

		$this->assertSame( 'wrong_stage', $result->get_error_code() );
	}

	public function test_advance_refuses_a_category_the_competition_does_not_have(): void {
		$id = $this->create_competition();

		$result = $this->workflow->advance( $id, 'landscape', Competition_Workflow::STAGE_PREVIEWED );

		$this->assertSame( 'unknown_category', $result->get_error_code() );
	}

	public function test_opening_voting_on_a_previewed_category_accepts_votes(): void {
		$id = $this->ready_to_open( 'colour' );

		$this->assertTrue( $this->workflow->open_voting( $id, 'colour' ) );

		$competition = $this->row( $id );
		$this->assertSame( Competition_Workflow::STAGE_VOTING, $this->workflow->stage( $competition, 'colour' ) );
		$this->assertTrue( $this->workflow->is_accepting_votes( $competition, 'colour' ) );
		$this->assertFalse( $this->workflow->is_accepting_votes( $competition, 'mono' ) );
	}

	public function test_opening_voting_while_uploads_are_open_is_refused(): void {
		$id = $this->create_competition();
		Entry_Fixtures::insert_entry( $id, 'colour', 1, array() );
		$this->workflow->advance( $id, 'colour', Competition_Workflow::STAGE_PREVIEWED );

		$this->assertSame( 'uploads_open', $this->workflow->open_voting( $id, 'colour' )->get_error_code() );
	}

	public function test_opening_voting_on_a_category_without_images_is_refused(): void {
		$id = $this->create_competition();
		$this->workflow->close_uploads( $id );
		$this->workflow->advance( $id, 'colour', Competition_Workflow::STAGE_PREVIEWED );

		$this->assertSame( 'no_images', $this->workflow->open_voting( $id, 'colour' )->get_error_code() );
	}

	public function test_opening_voting_after_the_close_date_is_refused(): void {
		$id = $this->ready_to_open( 'colour' );
		$this->set_dates( $id, '-2 days', '-1 hour' );

		$this->assertSame( 'competition_closed', $this->workflow->open_voting( $id, 'colour' )->get_error_code() );
	}

	public function test_opening_voting_on_an_archived_competition_is_refused(): void {
		$id = $this->ready_to_open( 'colour' );
		$this->competitions->archive( $id );

		$this->assertSame( 'competition_closed', $this->workflow->open_voting( $id, 'colour' )->get_error_code() );
	}

	public function test_opening_voting_while_another_category_is_voting_is_refused(): void {
		$id = $this->ready_to_open( 'colour' );
		$this->workflow->advance( $id, 'mono', Competition_Workflow::STAGE_PREVIEWED );
		$this->workflow->open_voting( $id, 'colour' );

		$this->assertSame( 'another_category_voting', $this->workflow->open_voting( $id, 'mono' )->get_error_code() );
	}

	public function test_opening_voting_while_another_competition_is_voting_is_refused(): void {
		$other = $this->ready_to_open( 'colour' );
		$this->workflow->open_voting( $other, 'colour' );

		// Competitions saved before only one could be open may still overlap.
		$id = $this->ready_to_open(
			'colour',
			array(
				'open_date'  => utc_time( 10 * DAY_IN_SECONDS ),
				'close_date' => utc_time( 11 * DAY_IN_SECONDS ),
			)
		);
		$this->set_dates( $id, '-1 day', '+1 day' );

		$this->assertSame( 'another_category_voting', $this->workflow->open_voting( $id, 'colour' )->get_error_code() );
	}

	public function test_a_closed_competition_still_voting_does_not_block_another(): void {
		$other = $this->ready_to_open( 'colour' );
		$this->workflow->open_voting( $other, 'colour' );
		$this->set_dates( $other, '-2 days', '-1 hour' );

		$id = $this->ready_to_open( 'colour' );

		$this->assertTrue( $this->workflow->open_voting( $id, 'colour' ) );
	}

	public function test_a_category_runs_through_competition_night(): void {
		$id = $this->ready_to_open( 'colour' );

		$this->assertTrue( $this->workflow->open_voting( $id, 'colour' ) );
		$this->assertTrue( $this->workflow->advance( $id, 'colour', Competition_Workflow::STAGE_SLIDESHOW_SHOWN ) );
		$this->assertTrue( $this->workflow->is_accepting_votes( $this->row( $id ), 'colour' ) );
		$this->assertTrue( $this->workflow->close_voting( $id, 'colour' ) );
		$this->assertSame( Competition_Workflow::STAGE_CRITIQUE, $this->workflow->stage( $this->row( $id ), 'colour' ) );
		$this->assertFalse( $this->workflow->is_accepting_votes( $this->row( $id ), 'colour' ) );
		$this->assertTrue( $this->workflow->advance( $id, 'colour', Competition_Workflow::STAGE_DONE ) );

		$this->assertSame( Competition_Workflow::STAGE_DONE, $this->workflow->stage( $this->row( $id ), 'colour' ) );
	}

	public function test_closing_voting_that_is_not_open_is_refused(): void {
		$id = $this->ready_to_open( 'colour' );

		$this->assertSame( 'wrong_stage', $this->workflow->close_voting( $id, 'colour' )->get_error_code() );
	}

	public function test_voting_can_be_closed_and_finished_after_the_close_date(): void {
		$id = $this->ready_to_open( 'colour' );
		$this->workflow->open_voting( $id, 'colour' );
		$this->set_dates( $id, '-2 days', '-1 hour' );

		$this->assertFalse( $this->workflow->is_accepting_votes( $this->row( $id ), 'colour' ) );
		$this->assertTrue( $this->workflow->close_voting( $id, 'colour' ) );
		$this->assertTrue( $this->workflow->advance( $id, 'colour', Competition_Workflow::STAGE_DONE ) );
	}

	public function test_reopening_uploads_accepts_uploads_again(): void {
		$id = $this->create_competition();
		$this->workflow->close_uploads( $id );

		$this->assertTrue( $this->workflow->reopen_uploads( $id ) );
		$this->assertTrue( $this->workflow->is_accepting_uploads( $this->row( $id ) ) );
	}

	public function test_reopening_uploads_while_voting_is_open_is_refused(): void {
		$id = $this->ready_to_open( 'colour' );
		$this->workflow->open_voting( $id, 'colour' );

		$this->assertSame( 'voting_open', $this->workflow->reopen_uploads( $id )->get_error_code() );
	}

	public function test_reopening_uploads_once_votes_are_cast_is_refused(): void {
		$id = $this->create_competition();
		Entry_Fixtures::insert_entry( $id, 'colour', 1, array( 9 ) );
		$this->workflow->close_uploads( $id );

		$this->assertSame( 'votes_exist', $this->workflow->reopen_uploads( $id )->get_error_code() );
		$this->assertTrue( $this->workflow->uploads_closed( $this->row( $id ) ) );
	}

	public function test_publishing_results_is_saved_and_can_be_undone(): void {
		$id = $this->create_competition();
		$this->workflow->close_uploads( $id );

		$this->assertTrue( $this->workflow->publish_results( $id ) );
		$this->assertSame( Competition_Workflow::PHASE_RESULTS_PUBLISHED, $this->workflow->phase( $this->row( $id ) ) );
		$this->assertTrue( $this->workflow->results_published( $this->row( $id ) ) );

		$this->assertTrue( $this->workflow->unpublish_results( $id ) );
		$this->assertFalse( $this->workflow->results_published( $this->row( $id ) ) );
	}

	public function test_publishing_results_while_uploads_are_open_is_refused(): void {
		$id = $this->create_competition();

		$this->assertSame( 'uploads_open', $this->workflow->publish_results( $id )->get_error_code() );
	}

	public function test_publishing_results_while_voting_is_open_is_refused(): void {
		$id = $this->ready_to_open( 'colour' );
		$this->workflow->open_voting( $id, 'colour' );

		$this->assertSame( 'voting_open', $this->workflow->publish_results( $id )->get_error_code() );
	}

	public function test_opening_voting_while_results_are_published_is_refused(): void {
		$id = $this->ready_to_open( 'colour' );
		$this->workflow->publish_results( $id );

		$this->assertSame( 'results_published', $this->workflow->open_voting( $id, 'colour' )->get_error_code() );
	}

	public function test_resetting_a_category_keeps_its_votes_by_default(): void {
		$id = $this->ready_to_open( 'colour' );
		Entry_Fixtures::insert_entry( $id, 'colour', 3, array( 9, 8 ) );
		$this->workflow->open_voting( $id, 'colour' );

		$this->assertTrue( $this->workflow->reset_category( $id, 'colour', false ) );

		$this->assertSame( Competition_Workflow::STAGE_NOT_STARTED, $this->workflow->stage( $this->row( $id ), 'colour' ) );
		$this->assertNull( $this->workflow->category_accepting_votes() );
		$this->assertCount( 2, ( new Votes_Repository() )->find_by_competition( $id, 'colour' ) );
	}

	public function test_resetting_a_category_can_clear_its_votes_and_voting_tokens(): void {
		$id = $this->ready_to_open( 'colour' );
		Entry_Fixtures::insert_entry( $id, 'colour', 3, array( 9 ) );
		Entry_Fixtures::insert_entry( $id, 'mono', 4, array( 7 ) );
		$voter  = Member_Fixtures::insert_with_grade( 'Voter', 'voter@example.com', 'advanced' );
		$tokens = new Voting_Token_Repository();
		$tokens->create( $voter, $id, 'colour', hash( 'sha256', 'colour-token' ), utc_time( DAY_IN_SECONDS ) );
		$tokens->create( $voter, $id, 'mono', hash( 'sha256', 'mono-token' ), utc_time( DAY_IN_SECONDS ) );

		$this->assertTrue( $this->workflow->reset_category( $id, 'colour', true ) );

		$votes = new Votes_Repository();
		$this->assertSame( array(), $votes->find_by_competition( $id, 'colour' ) );
		$this->assertCount( 1, $votes->find_by_competition( $id, 'mono' ) );
		$this->assertNull( $tokens->find_valid_token( hash( 'sha256', 'colour-token' ) ) );
		$this->assertNotNull( $tokens->find_valid_token( hash( 'sha256', 'mono-token' ) ) );
	}

	public function test_resetting_the_competition_starts_voting_over(): void {
		$id = $this->ready_to_open( 'colour' );
		Entry_Fixtures::insert_entry( $id, 'mono', 4, array( 7 ) );
		$this->workflow->open_voting( $id, 'colour' );

		$this->assertTrue( $this->workflow->reset_competition( $id ) );

		$competition = $this->row( $id );
		$this->assertSame( Competition_Workflow::STAGE_NOT_STARTED, $this->workflow->stage( $competition, 'colour' ) );
		$this->assertSame( Competition_Workflow::STAGE_NOT_STARTED, $this->workflow->stage( $competition, 'mono' ) );
		$this->assertSame( array(), ( new Votes_Repository() )->find_by_competition( $id ) );
		$this->assertTrue( $this->workflow->uploads_closed( $competition ), 'A reset leaves uploads closed.' );
	}

	public function test_closing_the_competition_ends_it_now_and_closes_voting(): void {
		$id = $this->ready_to_open( 'colour' );
		$this->workflow->open_voting( $id, 'colour' );

		$this->assertTrue( $this->workflow->close_competition( $id ) );

		$competition = $this->row( $id );
		$this->assertSame( Competition_Workflow::PHASE_CLOSED, $this->workflow->phase( $competition ) );
		$this->assertLessThanOrEqual( utc_time(), $competition->close_date );
		$this->assertSame( Competition_Workflow::STAGE_CRITIQUE, $this->workflow->stage( $competition, 'colour' ) );
	}

	public function test_closing_a_closed_competition_again_is_refused(): void {
		$id = $this->create_competition();
		$this->set_dates( $id, '-2 days', '-1 day' );
		$close_date = $this->row( $id )->close_date;

		$this->assertSame( 'competition_already_closed', $this->workflow->close_competition( $id )->get_error_code() );
		$this->assertSame( $close_date, $this->row( $id )->close_date, 'A replayed link must not move the close date.' );
	}

	public function test_a_competition_not_yet_open_is_scheduled(): void {
		$competition = $this->row( $this->create_competition( array( 'open_date' => utc_time( DAY_IN_SECONDS ), 'close_date' => utc_time( 2 * DAY_IN_SECONDS ) ) ) );

		$this->assertSame( Competition_Workflow::PHASE_SCHEDULED, $this->workflow->phase( $competition ) );
		$this->assertFalse( $this->workflow->is_open( $competition ) );
		$this->assertFalse( $this->workflow->is_accepting_uploads( $competition ) );
	}

	/**
	 * A competition is open while open_date <= now < close_date, so it is
	 * already closed at the moment its close date arrives.
	 */
	public function test_a_competition_is_closed_from_its_close_date(): void {
		$id = $this->create_competition();
		$this->set_dates( $id, '-1 day', 'now' );

		$competition = $this->row( $id );
		$this->assertSame( Competition_Workflow::PHASE_CLOSED, $this->workflow->phase( $competition ) );
		$this->assertFalse( $this->workflow->is_accepting_uploads( $competition ) );
	}

	public function test_an_archived_competition_is_archived_whatever_its_dates(): void {
		$id = $this->create_competition();
		$this->workflow->close_uploads( $id );
		$this->workflow->publish_results( $id );
		$this->competitions->archive( $id );

		$this->assertSame( Competition_Workflow::PHASE_ARCHIVED, $this->workflow->phase( $this->row( $id ) ) );
	}

	public function test_a_competition_without_dates_is_open(): void {
		$competition = $this->row( $this->create_competition( array( 'open_date' => null, 'close_date' => null ) ) );

		$this->assertTrue( $this->workflow->is_open( $competition ) );
	}

	/**
	 * find_current_active() selects in SQL with the same rule is_open()
	 * applies in PHP. This pins them together.
	 */
	public function test_the_current_competition_is_the_one_the_workflow_calls_open(): void {
		$cases = array(
			'scheduled' => array( '+1 day', '+2 days' ),
			'closed'    => array( '-2 days', '-1 day' ),
			'closing'   => array( '-1 day', 'now' ),
			'archived'  => array( '-1 day', '+1 day' ),
			'open'      => array( '-1 day', '+1 day' ),
		);

		foreach ( $cases as $name => $dates ) {
			$id = $this->create_competition( array( 'open_date' => utc_time( 100 * DAY_IN_SECONDS ), 'close_date' => utc_time( 101 * DAY_IN_SECONDS ) ) );
			$this->set_dates( $id, $dates[0], $dates[1] );

			if ( 'archived' === $name ) {
				$this->competitions->archive( $id );
			}

			$current = $this->competitions->find_current_active();
			$this->assertSame(
				$this->workflow->is_open( $this->row( $id ) ),
				null !== $current && (int) $current->id === $id,
				$name
			);

			// Leave the next case alone in the window.
			$this->set_dates( $id, '-10 days', '-9 days' );
		}
	}

	/**
	 * Results night when next month's competition was created early: the
	 * results pages keep showing the competition whose results are out.
	 */
	public function test_results_pages_show_the_latest_competition_with_results_published(): void {
		$published = $this->create_competition( array( 'open_date' => utc_time( -30 * DAY_IN_SECONDS ), 'close_date' => utc_time( DAY_IN_SECONDS ) ) );
		Workflow_Fixtures::publish_results( $published );
		$this->create_competition( array( 'open_date' => utc_time( DAY_IN_SECONDS ), 'close_date' => utc_time( 31 * DAY_IN_SECONDS ) ) );

		$this->assertSame( $published, (int) $this->workflow->find_for_results()->id );
	}

	public function test_results_pages_pick_the_latest_opened_of_several_published(): void {
		$august = $this->create_competition( array( 'open_date' => '2020-08-01 00:00:00', 'close_date' => '2020-09-01 00:00:00' ) );
		$july   = $this->create_competition( array( 'open_date' => '2020-07-01 00:00:00', 'close_date' => '2020-08-01 00:00:00' ) );
		Workflow_Fixtures::publish_results( $august );
		Workflow_Fixtures::publish_results( $july );

		$this->assertSame( $august, (int) $this->workflow->find_for_results()->id );
	}

	public function test_results_pages_fall_back_to_the_current_competition(): void {
		$this->create_competition( array( 'open_date' => utc_time( 10 * DAY_IN_SECONDS ), 'close_date' => utc_time( 40 * DAY_IN_SECONDS ) ) );
		$current = $this->create_competition( array( 'open_date' => utc_time( -5 * DAY_IN_SECONDS ), 'close_date' => utc_time( 5 * DAY_IN_SECONDS ) ) );

		$this->assertSame( $current, (int) $this->workflow->find_for_results()->id );
	}

	public function test_results_pages_fall_back_to_the_latest_opened_ignoring_archived(): void {
		$august = $this->create_competition( array( 'open_date' => '2020-08-01 00:00:00', 'close_date' => '2020-09-01 00:00:00' ) );
		$this->create_competition( array( 'open_date' => '2020-07-01 00:00:00', 'close_date' => '2020-08-01 00:00:00' ) );
		$archived = $this->create_competition( array( 'open_date' => '2020-10-01 00:00:00', 'close_date' => '2020-11-01 00:00:00' ) );
		Workflow_Fixtures::publish_results( $archived );
		$this->competitions->archive( $archived );

		$this->assertSame( $august, (int) $this->workflow->find_for_results()->id );
	}

	/**
	 * A competition saved without an open date opened when it was created,
	 * so it ranks by its creation time rather than below every dated one.
	 */
	public function test_results_pages_rank_a_missing_open_date_by_creation(): void {
		$august = $this->create_competition( array( 'open_date' => '2020-08-01 00:00:00', 'close_date' => '2020-09-01 00:00:00' ) );
		Workflow_Fixtures::publish_results( $august );
		$undated = $this->create_competition( array( 'open_date' => null, 'close_date' => utc_time( DAY_IN_SECONDS ) ) );
		Workflow_Fixtures::publish_results( $undated );

		$this->assertSame( $undated, (int) $this->workflow->find_for_results()->id );
	}

	public function test_results_pages_have_nothing_to_show_without_competitions(): void {
		$this->assertNull( $this->workflow->find_for_results() );
	}

	public function test_a_removed_category_loses_its_stage(): void {
		$id = $this->ready_to_open( 'mono' );
		$this->workflow->open_voting( $id, 'mono' );
		$colour_only = array(
			'categories' => array(
				array(
					'slug'  => 'colour',
					'label' => 'Colour',
					'quota' => 1,
				),
			),
		);
		$both        = json_decode( $this->row( $id )->settings, true );

		$this->competitions->update( $id, array( 'settings' => $colour_only ) );
		$this->assertNull( $this->workflow->category_accepting_votes(), 'A removed category is ignored.' );

		$this->workflow->advance( $id, 'colour', Competition_Workflow::STAGE_PREVIEWED );
		$this->competitions->update( $id, array( 'settings' => $both ) );
		$this->assertSame( Competition_Workflow::STAGE_NOT_STARTED, $this->workflow->stage( $this->row( $id ), 'mono' ), 'Its stage was dropped on the next write.' );
	}

	/**
	 * Set a competition's dates directly, skipping the overlap check.
	 *
	 * @param int         $id    Competition ID.
	 * @param string|null $open  Open date relative to now, e.g. '-1 day'.
	 * @param string|null $close Close date relative to now.
	 */
	private function set_dates( int $id, ?string $open, ?string $close ): void {
		global $wpdb;

		$wpdb->update(
			$this->competitions->table(),
			array(
				'open_date'  => $open ? gmdate( 'Y-m-d H:i:s', strtotime( $open ) ) : null,
				'close_date' => $close ? gmdate( 'Y-m-d H:i:s', strtotime( $close ) ) : null,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * A competition with uploads closed, an entry in each category, and the
	 * given category previewed, so voting can open on it.
	 *
	 * @param string               $category_slug Category to preview.
	 * @param array<string, mixed> $data          Overrides for the competition row.
	 * @return int Competition ID.
	 */
	private function ready_to_open( string $category_slug, array $data = array() ): int {
		$id = $this->create_competition( $data );

		Entry_Fixtures::insert_entry( $id, 'colour', 1, array() );
		Entry_Fixtures::insert_entry( $id, 'mono', 2, array() );
		$this->workflow->close_uploads( $id );
		$this->workflow->advance( $id, $category_slug, Competition_Workflow::STAGE_PREVIEWED );

		return $id;
	}
}
