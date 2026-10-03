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
use PhotoCompetitionManager\Support\Competition_Settings;
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
}
