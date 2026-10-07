<?php
/**
 * Tests for Results_Shortcode table markup.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Results_Shortcode;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

/**
 * Result cells carry the labels the stacked mobile layout shows in place of the table header.
 */
class Results_Shortcode_Test extends WP_UnitTestCase {

	/**
	 * The competition setUp() creates.
	 *
	 * @var int
	 */
	private $competition_id;

	/**
	 * Create a competition with visible results and one graded entry.
	 */
	public function setUp(): void {
		parent::setUp();

		$competitions         = new Competitions_Repository();
		$this->competition_id = (int) $competitions->create(
			array(
				'title'     => 'Results Comp',
				'slug'      => 'results-comp',
				'open_date' => '2020-01-01 00:00:00',
				'settings'  => array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
							'quota' => 1,
						),
					),
					'grades'     => array(
						array(
							'slug'  => 'beginner',
							'label' => 'Beginner',
						),
					),
				),
			)
		);
		Workflow_Fixtures::publish_results( $this->competition_id );

		$member_id = (int) ( new Members_Repository() )->create(
			array(
				'name'  => 'Ann Example',
				'email' => 'ann@example.com',
				'grade' => 'beginner',
			)
		);

		( new Images_Repository() )->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'ann-example-colour.jpg',
			)
		);
	}

	/**
	 * Member and score cells expose data-label captions for the mobile card layout, and no votes column is shown.
	 */
	public function test_result_cells_have_data_labels_and_no_votes_column(): void {
		$html = ( new Results_Shortcode() )->render( array( 'competition' => 'results-comp' ) );

		$this->assertStringContainsString( '<td class="member-name" data-label="Member">Ann Example</td>', $html );
		$this->assertStringContainsString( '<td class="score" data-label="Score">0</td>', $html );
		$this->assertStringNotContainsString( 'vote-count', $html );
	}

	/**
	 * With names hidden, no member cell (and so no Member caption) is rendered.
	 */
	public function test_hidden_names_omit_member_cell(): void {
		$html = ( new Results_Shortcode() )->render(
			array(
				'competition' => 'results-comp',
				'hide_names'  => 'true',
			)
		);

		$this->assertStringNotContainsString( 'data-label="Member"', $html );
		$this->assertStringContainsString( 'data-label="Score"', $html );
	}

	/**
	 * Results night when next month's competition was created early: with
	 * no competition named, the page shows the competition whose results
	 * are out, not the newest one.
	 */
	public function test_defaults_to_latest_competition_with_visible_results(): void {
		$this->create_next_month();

		$html = ( new Results_Shortcode() )->render( array() );

		$this->assertStringContainsString( '<td class="member-name" data-label="Member">Ann Example</td>', $html );
	}

	/**
	 * With no results out, the page still names the competition and says
	 * its results aren't available yet.
	 */
	public function test_default_without_visible_results_shows_not_available(): void {
		$competitions = new Competitions_Repository();
		$competition  = $competitions->find_by_slug( 'results-comp' );
		( new Competition_Workflow() )->unpublish_results( (int) $competition->id );

		$html = ( new Results_Shortcode() )->render( array() );

		$this->assertStringContainsString( 'Results Comp - Results', $html );
		$this->assertStringContainsString( 'Results are not yet available.', $html );
	}

	/**
	 * A share link shows its competition's results before they're out.
	 */
	public function test_share_link_shows_hidden_results(): void {
		$competitions = new Competitions_Repository();
		$competition  = $competitions->find_by_slug( 'results-comp' );
		( new Competition_Workflow() )->unpublish_results( (int) $competition->id );
		$competitions->update_share_hash( (int) $competition->id, 'share-hash' );
		$_GET['share'] = 'share-hash';

		$html = ( new Results_Shortcode() )->render( array() );

		$this->assertStringNotContainsString( 'Results are not yet available.', $html );
		$this->assertStringContainsString( 'Ann Example', $html );
	}

	/**
	 * An unknown share link falls back to the latest results that are out.
	 */
	public function test_unknown_share_link_falls_back_to_latest_visible_results(): void {
		$this->create_next_month();
		$_GET['share'] = 'unknown-hash';

		$html = ( new Results_Shortcode() )->render( array() );

		$this->assertStringContainsString( '<td class="member-name" data-label="Member">Ann Example</td>', $html );
	}

	/**
	 * Clear the share parameter set by the share link tests.
	 */
	public function tearDown(): void {
		unset( $_GET['share'] );

		parent::tearDown();
	}

	/**
	 * Close the published competition and create next month's after it,
	 * with its results hidden: newer by both open and creation date.
	 */
	private function create_next_month(): void {
		$competitions = new Competitions_Repository();
		$published    = $competitions->find_by_slug( 'results-comp' );
		$competitions->update( (int) $published->id, array( 'close_date' => '2020-02-01 00:00:00' ) );
		$GLOBALS['wpdb']->update( $competitions->table(), array( 'created_at' => '2020-01-01 00:00:00' ), array( 'id' => $published->id ) );
		$competitions->create(
			array(
				'title'     => 'Next Month',
				'slug'      => 'next-month',
				'open_date' => '2020-02-01 00:00:00',
			)
		);
	}

	/**
	 * Grades come from the club's list, not the list the competition has
	 * stored.
	 */
	public function test_uses_club_grade_labels(): void {
		update_option(
			'photo_comp_default_settings',
			Competition_Settings::encode(
				array(
					'grades' => array(
						array(
							'slug'  => 'beginner',
							'label' => 'Club Starters',
						),
					),
				)
			)
		);

		$html = ( new Results_Shortcode() )->render( array( 'competition' => 'results-comp' ) );

		$this->assertStringContainsString( 'Club Starters', $html );
	}

	/**
	 * A named competition with hidden results shows them only with its
	 * share link.
	 */
	public function test_named_competition_shows_hidden_results_only_with_its_share_link(): void {
		$competitions = new Competitions_Repository();
		$competition  = $competitions->find_by_slug( 'results-comp' );
		( new Competition_Workflow() )->unpublish_results( (int) $competition->id );
		$competitions->update_share_hash( (int) $competition->id, 'share-hash' );

		$hidden = ( new Results_Shortcode() )->render( array( 'competition' => 'results-comp' ) );

		$_GET['share'] = 'share-hash';
		$shared        = ( new Results_Shortcode() )->render( array( 'competition' => 'results-comp' ) );

		$this->assertStringContainsString( 'Results are not yet available.', $hidden );
		$this->assertStringContainsString( '<td class="member-name" data-label="Member">Ann Example</td>', $shared );
	}

	/**
	 * Ungraded entries are for admins to fix and never appear on the page,
	 * and they don't move the graded entries' positions.
	 */
	public function test_ungraded_entry_is_left_out_and_graded_positions_are_kept(): void {
		Entry_Fixtures::insert_scored_entry( $this->competition_id, 'colour', 'Ben Example', 'beginner', array( 9 ) );
		Entry_Fixtures::insert_scored_entry( $this->competition_id, 'colour', 'No Grade', '', array( 20 ) );
		Entry_Fixtures::insert_scored_entry( $this->competition_id, 'colour', 'Old Grade', 'retired', array( 15 ) );

		$html = ( new Results_Shortcode() )->render( array( 'competition' => 'results-comp' ) );

		$this->assertStringNotContainsString( 'No Grade', $html );
		$this->assertStringNotContainsString( 'Old Grade', $html );
		$this->assertStringNotContainsString( 'Ungraded', $html );
		$this->assertMatchesRegularExpression( '#<td class="position">1</td>.*?Ben Example.*?<td class="position">2</td>.*?Ann Example#s', $html );
	}

	/**
	 * A member deleted after results are published stays in their place,
	 * as a former member without an image, and nobody moves up.
	 */
	public function test_a_deleted_members_entry_keeps_its_place_as_a_former_member(): void {
		$winner    = Entry_Fixtures::insert_scored_entry( $this->competition_id, 'colour', 'Zed Winner', 'beginner', array( 9 ) );
		$member_id = (int) ( new Images_Repository() )->find( $winner )->member_id;
		( new Results_Shortcode() )->render( array( 'competition' => 'results-comp' ) );

		( new Entries() )->remove_member_entries( Actor::admin(), $member_id );
		( new Members_Repository() )->delete( $member_id );
		$html = ( new Results_Shortcode() )->render( array( 'competition' => 'results-comp' ) );

		$this->assertStringContainsString( '<td class="member-name" data-label="Member">Former member</td>', $html );
		$this->assertStringNotContainsString( 'Zed Winner', $html );
		$this->assertStringContainsString( 'Image unavailable', $html );
		$this->assertMatchesRegularExpression( '/Former member.*Ann Example/s', $html );
	}

	/**
	 * Recorded results stay on the page after every entry is removed, as
	 * they do on the Results screen.
	 */
	public function test_recorded_results_are_shown_after_every_entry_is_removed(): void {
		( new Results_Shortcode() )->render( array( 'competition' => 'results-comp' ) );
		$image = ( new Images_Repository() )->find_by_competition( $this->competition_id )[0];

		( new Entries() )->remove( Actor::admin(), $this->competition_id, (int) $image->id );
		$html = ( new Results_Shortcode() )->render( array( 'competition' => 'results-comp' ) );

		$this->assertStringNotContainsString( 'No images submitted', $html );
		$this->assertStringContainsString( '<td class="member-name" data-label="Member">Ann Example</td>', $html );
		$this->assertStringContainsString( 'Image unavailable', $html );
	}

	/**
	 * A recorded entry keeps the grade it was entered in, even once that
	 * grade is gone from the club's list.
	 */
	public function test_recorded_entries_in_a_grade_since_removed_are_shown(): void {
		( new Results_Shortcode() )->render( array( 'competition' => 'results-comp' ) );
		update_option(
			'photo_comp_default_settings',
			Competition_Settings::encode(
				array(
					'grades' => array(
						array(
							'slug'  => 'advanced',
							'label' => 'Advanced',
						),
					),
				)
			)
		);

		$html = ( new Results_Shortcode() )->render( array( 'competition' => 'results-comp' ) );

		$this->assertStringContainsString( '<h4>beginner</h4>', $html );
		$this->assertStringContainsString( '<td class="member-name" data-label="Member">Ann Example</td>', $html );
	}
}
