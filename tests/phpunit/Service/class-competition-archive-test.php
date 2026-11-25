<?php
/**
 * Tests for Competition_Archive: the past competitions shown to the public.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Competition_Archive;
use PhotoCompetitionManager\Service\Public_Results;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

/**
 * The archive lists competitions whose results are published, and shows each
 * one's entered images by category, leaving out images their members hide.
 */
class Competition_Archive_Test extends WP_UnitTestCase {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Turn the archive on.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->competitions = new Competitions_Repository();
		Competition_Archive::save_settings( true, false );
		Public_Results::save( true );
	}

	/**
	 * Create a competition with a Colour and a Mono category.
	 *
	 * @param string               $slug      Slug.
	 * @param string               $open_date When it opened.
	 * @param array<string, mixed> $settings  Extra settings.
	 * @return int
	 */
	private function competition( string $slug, string $open_date, array $settings = array() ): int {
		return (int) $this->competitions->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'      => ucfirst( $slug ),
				'slug'       => $slug,
				'open_date'  => $open_date,
				'close_date' => gmdate( 'Y-m-d H:i:s', strtotime( $open_date ) + 5 * DAY_IN_SECONDS ),
				'settings'   => $settings + array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
							'quota' => 3,
						),
						array(
							'slug'  => 'mono',
							'label' => 'Mono',
							'quota' => 3,
						),
					),
				),
			)
		);
	}

	/**
	 * Slugs of the listed competitions, in order.
	 *
	 * @return array<int, string>
	 */
	private function listed(): array {
		return array_column( ( new Competition_Archive() )->competitions(), 'slug' );
	}

	public function test_only_competitions_with_published_results_are_listed_newest_first(): void {
		$january  = $this->competition( 'january', '2026-01-01 00:00:00' );
		$february = $this->competition( 'february', '2026-02-01 00:00:00' );
		$this->competition( 'unpublished', '2026-03-01 00:00:00' );
		$archived = $this->competition( 'archived', '2026-04-01 00:00:00' );
		foreach ( array( $january, $february, $archived ) as $id ) {
			Workflow_Fixtures::publish_results( $id );
		}
		$this->competitions->archive( $archived );

		$this->assertSame( array( 'february', 'january' ), $this->listed() );
	}

	public function test_while_the_archive_is_off_nothing_is_listed_or_found(): void {
		$january = $this->competition( 'january', '2026-01-01 00:00:00' );
		Workflow_Fixtures::publish_results( $january );
		Competition_Archive::save_settings( false, false );

		$this->assertSame( array(), $this->listed() );
		$this->assertNull( ( new Competition_Archive() )->find( 'january' ) );
	}

	public function test_while_results_are_private_nothing_is_listed_or_found(): void {
		$january = $this->competition( 'january', '2026-01-01 00:00:00' );
		Workflow_Fixtures::publish_results( $january );
		Public_Results::save( false );

		$this->assertSame( array(), $this->listed() );
		$this->assertNull( ( new Competition_Archive() )->find( 'january' ) );
		$this->assertTrue( Competition_Archive::saved_settings()['enabled'], 'the club\'s archive choice is kept for when results are public again' );
	}

	public function test_a_competition_is_found_by_slug_only_once_its_results_are_published(): void {
		$january = $this->competition( 'january', '2026-01-01 00:00:00' );
		$archive = new Competition_Archive();

		$this->assertNull( $archive->find( 'january' ), 'unpublished' );

		Workflow_Fixtures::publish_results( $january );

		$this->assertSame( $january, (int) $archive->find( 'january' )->id );
		$this->assertNull( $archive->find( 'no-such-competition' ), 'unknown' );
	}

	/**
	 * Entry IDs shown in each category, keyed by category label.
	 *
	 * @param int  $competition_id Competition ID.
	 * @param bool $show_names     Whether names are shown.
	 * @return array<string, array<int, int>>
	 */
	private function shown( int $competition_id, bool $show_names = false ): array {
		$shown = array();
		foreach ( ( new Competition_Archive() )->categories( $this->competitions->find( $competition_id ), $show_names ) as $category ) {
			$shown[ $category['label'] ] = array_column( $category['entries'], 'id' );
		}

		return $shown;
	}

	public function test_each_category_shows_its_entered_images_in_ballot_order(): void {
		$january = $this->competition( 'january', '2026-01-01 00:00:00' );
		$colour  = array();
		foreach ( array( 'Ann', 'Bob', 'Cat', 'Dan' ) as $name ) {
			$colour[] = Entry_Fixtures::insert_scored_entry( $january, 'colour', $name, 'beginner', array() );
		}
		$substitute   = Entry_Fixtures::insert_scored_entry( $january, 'colour', 'Eve', 'beginner', array() );
		$disqualified = Entry_Fixtures::insert_scored_entry( $january, 'colour', 'Fay', 'beginner', array() );
		$images       = new Images_Repository();
		$images->update_entry_status( $substitute, 'substitute' );
		$images->update_entry_status( $disqualified, 'disqualified' );
		Workflow_Fixtures::publish_results( $january );

		$ballot = array_column( $images->shuffle_deterministic( $images->find_entered( $january, 'colour' ), $january, 'colour' ), 'id' );

		$this->assertSame( array_map( 'intval', $ballot ), $this->shown( $january )['Colour'] );
		$this->assertEqualsCanonicalizing( $colour, $this->shown( $january )['Colour'], 'only entered images' );
		$this->assertSame( array(), $this->shown( $january )['Mono'] );
	}

	public function test_an_image_shows_only_while_its_member_shows_their_images_and_it_isnt_hidden(): void {
		$january = $this->competition( 'january', '2026-01-01 00:00:00' );
		$ann     = Member_Fixtures::insert_with_grade( 'Ann', 'ann@example.com', 'beginner' );
		$kept    = Entry_Fixtures::insert_entry( $january, 'colour', $ann, array() );
		$hidden  = Entry_Fixtures::insert_entry( $january, 'mono', $ann, array() );
		$bob     = Entry_Fixtures::insert_scored_entry( $january, 'colour', 'Bob', 'beginner', array() );
		Workflow_Fixtures::publish_results( $january );
		$members = new Members_Repository();
		$images  = new Images_Repository();

		$images->set_archive_hidden( $hidden, true );
		$this->assertEqualsCanonicalizing( array( $kept, $bob ), array_merge( ...array_values( $this->shown( $january ) ) ), 'one image hidden' );

		$members->set_images_public( $ann, false );
		$this->assertSame( array( $bob ), array_merge( ...array_values( $this->shown( $january ) ) ), 'all hidden' );

		$members->set_images_public( $ann, true );
		$this->assertEqualsCanonicalizing( array( $kept, $bob ), array_merge( ...array_values( $this->shown( $january ) ) ), 'shown again, but the hidden one stays hidden' );

		$images->set_archive_hidden( $hidden, false );
		$this->assertEqualsCanonicalizing( array( $kept, $hidden, $bob ), array_merge( ...array_values( $this->shown( $january ) ) ), 'shown again' );
	}

	/**
	 * Every entry shown, keyed by entry ID.
	 *
	 * @param int  $competition_id Competition ID.
	 * @param bool $show_names     Whether names are shown.
	 * @return array<int, object>
	 */
	private function entries( int $competition_id, bool $show_names = false ): array {
		$entries = array();
		foreach ( ( new Competition_Archive() )->categories( $this->competitions->find( $competition_id ), $show_names ) as $category ) {
			foreach ( $category['entries'] as $entry ) {
				$entries[ $entry->id ] = $entry;
			}
		}

		return $entries;
	}

	public function test_names_are_hidden_unless_asked_for_and_interclub_entries_name_photographer_and_club(): void {
		$january = $this->competition( 'january', '2026-01-01 00:00:00' );
		$ann     = Entry_Fixtures::insert_scored_entry( $january, 'colour', 'Ann', 'beginner', array() );
		$panel   = $this->competition(
			'interclub',
			'2026-02-01 00:00:00',
			array(
				'interclub' => array(
					'enabled'     => true,
					'substitutes' => 0,
				),
			)
		);
		$club    = Entry_Fixtures::insert_scored_entry( $panel, 'colour', 'Cork Camera Club', 'beginner', array() );
		( new Images_Repository() )->update_photographer( $club, 'Ann Other' );
		Workflow_Fixtures::publish_results( $january );
		Workflow_Fixtures::publish_results( $panel );

		$this->assertSame( '', $this->entries( $january )[ $ann ]->by );
		$this->assertSame( 'Ann', $this->entries( $january, true )[ $ann ]->by );
		$this->assertSame( '', $this->entries( $panel )[ $club ]->by );
		$this->assertSame( 'Ann Other — Cork Camera Club', $this->entries( $panel, true )[ $club ]->by );
	}

	public function test_positions_and_scores_show_only_when_the_club_shows_winners(): void {
		$january = $this->competition( 'january', '2026-01-01 00:00:00' );
		$first   = Entry_Fixtures::insert_scored_entry( $january, 'colour', 'Ann', 'beginner', array( 9, 9 ) );
		$second  = Entry_Fixtures::insert_scored_entry( $january, 'colour', 'Bob', 'beginner', array( 9, 8 ) );
		Workflow_Fixtures::publish_results( $january );

		$this->assertNull( $this->entries( $january )[ $first ]->position );

		Competition_Archive::save_settings( true, true );
		$entries = $this->entries( $january );

		$this->assertSame( 1, $entries[ $first ]->position );
		$this->assertSame( 18, $entries[ $first ]->score );
		$this->assertSame( 2, $entries[ $second ]->position );
		$this->assertSame( 'Beginner', $entries[ $second ]->grade );
	}
}
