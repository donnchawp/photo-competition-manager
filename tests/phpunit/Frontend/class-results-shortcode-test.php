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
use WP_UnitTestCase;

/**
 * Result cells carry the labels the stacked mobile layout shows in place of the table header.
 */
class Results_Shortcode_Test extends WP_UnitTestCase {

	/**
	 * Create a competition with visible results and one graded entry.
	 */
	public function setUp(): void {
		parent::setUp();

		$competitions   = new Competitions_Repository();
		$competition_id = (int) $competitions->create(
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
	 * Member, score and vote cells expose data-label captions for the mobile card layout.
	 */
	public function test_result_cells_have_data_labels(): void {
		$html = ( new Results_Shortcode() )->render( array( 'competition' => 'results-comp' ) );

		$this->assertStringContainsString( '<td class="member-name" data-label="Member">Ann Example</td>', $html );
		$this->assertStringContainsString( '<td class="score" data-label="Score">0</td>', $html );
		$this->assertStringContainsString( '<td class="vote-count" data-label="Votes">0</td>', $html );
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
}
