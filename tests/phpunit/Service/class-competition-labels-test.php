<?php
/**
 * Tests for the grade names a competition shows.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Service\Competition_Labels;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use WP_UnitTestCase;

class Competition_Labels_Test extends WP_UnitTestCase {

	/**
	 * A competition row of a type that renames the club's first grade.
	 *
	 * @param array<string,string> $grade_names Grade names.
	 * @return object
	 */
	private function competition_of_type( array $grade_names ): object {
		return (object) array( 'type_id' => Type_Fixtures::create( 'Cup', $grade_names ) );
	}

	public function test_grade_label_uses_type_name_then_site_label(): void {
		$grades   = Competition_Settings::club_grades();
		$renamed  = $grades[0];
		$plain    = $grades[1];
		$labels   = new Competition_Labels();
		$cup      = $this->competition_of_type(
			array(
				$renamed['slug'] => 'Cup',
				$plain['slug']   => '',
			)
		);

		$this->assertSame( 'Cup', $labels->grade_label( $cup, $renamed['slug'] ) );
		$this->assertSame( $plain['label'], $labels->grade_label( $cup, $plain['slug'] ) );
		$this->assertSame( 'gone', $labels->grade_label( $cup, 'gone' ) );
		$this->assertSame( 'Cup', $labels->grade_labels( $cup )[ $renamed['slug'] ] );
	}

	public function test_admin_grade_label_shows_both_when_renamed(): void {
		$grades  = Competition_Settings::club_grades();
		$labels  = new Competition_Labels();
		$cup     = $this->competition_of_type( array( $grades[0]['slug'] => 'Cup' ) );

		$this->assertSame( 'Cup (' . $grades[0]['label'] . ')', $labels->admin_grade_label( $cup, $grades[0]['slug'] ) );
		$this->assertSame( $grades[1]['label'], $labels->admin_grade_label( $cup, $grades[1]['slug'] ) );
	}
}
