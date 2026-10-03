<?php
/**
 * Tests for Top3_Shortcode podium markup.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Top3_Shortcode;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use WP_UnitTestCase;

/**
 * The podium shows each winner's name and score but no vote count.
 */
class Top3_Shortcode_Test extends WP_UnitTestCase {

	/**
	 * Create a competition with visible results and one graded entry.
	 */
	public function setUp(): void {
		parent::setUp();

		$competitions   = new Competitions_Repository();
		$competition_id = (int) $competitions->create(
			array(
				'title'     => 'Top3 Comp',
				'slug'      => 'top3-comp',
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
					'results'    => array( 'results_visible' => true ),
				),
			)
		);

		$member_id = (int) ( new Members_Repository() )->create(
			array(
				'name'  => 'Ann Example',
				'email' => 'ann@example.com',
				'grade' => 'beginner',
			)
		);

		( new Images_Repository() )->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'ann-example-colour.jpg',
			)
		);
	}

	/**
	 * Winners show name and score, and no vote count.
	 */
	public function test_podium_shows_score_without_vote_count(): void {
		$html = ( new Top3_Shortcode() )->render( array( 'competition' => 'top3-comp' ) );

		$this->assertStringContainsString( '<div class="member-name">Ann Example</div>', $html );
		$this->assertStringContainsString( '<span class="score">0</span>', $html );
		$this->assertStringNotContainsString( 'vote-count', $html );
		$this->assertStringNotContainsString( 'votes)', $html );
	}

	/**
	 * Results night when next month's competition was created early: with
	 * no competition named, the page shows the competition whose results
	 * are out, not the newest one.
	 */
	public function test_defaults_to_latest_competition_with_visible_results(): void {
		$competitions = new Competitions_Repository();
		$published    = $competitions->find_by_slug( 'top3-comp' );
		$competitions->update( (int) $published->id, array( 'close_date' => '2020-02-01 00:00:00' ) );
		$GLOBALS['wpdb']->update( $competitions->table(), array( 'created_at' => '2020-01-01 00:00:00' ), array( 'id' => $published->id ) );
		$competitions->create(
			array(
				'title'     => 'Next Month',
				'slug'      => 'next-month',
				'open_date' => '2020-02-01 00:00:00',
			)
		);

		$html = ( new Top3_Shortcode() )->render( array() );

		$this->assertStringContainsString( '<div class="member-name">Ann Example</div>', $html );
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

		$html = ( new Top3_Shortcode() )->render( array( 'competition' => 'top3-comp' ) );

		$this->assertStringContainsString( 'Club Starters', $html );
	}

	/**
	 * A named competition with hidden results shows them only with its
	 * share link.
	 */
	public function test_named_competition_shows_hidden_results_only_with_its_share_link(): void {
		$competitions = new Competitions_Repository();
		$competition  = $competitions->find_by_slug( 'top3-comp' );
		$competitions->update( (int) $competition->id, array( 'settings' => array( 'results' => array( 'results_visible' => false ) ) ) );
		$competitions->update_share_hash( (int) $competition->id, 'share-hash' );

		$hidden = ( new Top3_Shortcode() )->render( array( 'competition' => 'top3-comp' ) );

		$_GET['share'] = 'share-hash';
		$shared        = ( new Top3_Shortcode() )->render( array( 'competition' => 'top3-comp' ) );

		$this->assertStringContainsString( 'Results are not yet available.', $hidden );
		$this->assertStringContainsString( '<div class="member-name">Ann Example</div>', $shared );
	}

	/**
	 * Clear the share parameter set by the share link test.
	 */
	public function tearDown(): void {
		unset( $_GET['share'] );

		parent::tearDown();
	}

	/**
	 * Tied entries share a place, so a grade can show more than three
	 * entries: 20, 20, 15, 10 take 1st, 1st, 2nd and 3rd, and 5 is left out.
	 */
	public function test_ties_can_put_more_than_three_entries_on_the_podium(): void {
		$this->add_scored_entry( 'top3-comp', 'First A', 'beginner', 20 );
		$this->add_scored_entry( 'top3-comp', 'First B', 'beginner', 20 );
		$this->add_scored_entry( 'top3-comp', 'Second', 'beginner', 15 );
		$this->add_scored_entry( 'top3-comp', 'Third', 'beginner', 10 );
		$this->add_scored_entry( 'top3-comp', 'Fourth', 'beginner', 5 );

		$html = ( new Top3_Shortcode() )->render( array( 'competition' => 'top3-comp' ) );

		preg_match_all( '#<div class="position-badge">\s*(.+?)\s*</div>.*?<div class="member-name">(.+?)</div>#s', $html, $matches );
		$this->assertSame(
			array( '1st Place:First A', '1st Place:First B', '2nd Place:Second', '3rd Place:Third' ),
			array_map( static fn( $place, $name ) => $place . ':' . $name, $matches[1], $matches[2] )
		);
	}

	/**
	 * Ungraded entries never reach the podium.
	 */
	public function test_ungraded_entry_is_left_out(): void {
		$this->add_scored_entry( 'top3-comp', 'No Grade', '', 20 );

		$html = ( new Top3_Shortcode() )->render( array( 'competition' => 'top3-comp' ) );

		$this->assertStringNotContainsString( 'No Grade', $html );
		$this->assertStringContainsString( '<div class="member-name">Ann Example</div>', $html );
	}

	/**
	 * Add a colour entry with one vote of the given score.
	 *
	 * @param string $slug  Competition slug.
	 * @param string $name  Member name.
	 * @param string $grade Member grade, valid or not.
	 * @param int    $score Score of the entry's one vote.
	 */
	private function add_scored_entry( string $slug, string $name, string $grade, int $score ): void {
		$competition_id = (int) ( new Competitions_Repository() )->find_by_slug( $slug )->id;
		$member_id      = Member_Fixtures::insert_with_grade( $name, sanitize_title( $name ) . '@example.com', $grade );
		$image_id       = (int) ( new Images_Repository() )->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => sanitize_title( $name ) . '.jpg',
			)
		);

		( new Votes_Repository() )->create_anonymous( $competition_id, 'colour', $member_id, $image_id, $score );
	}
}
