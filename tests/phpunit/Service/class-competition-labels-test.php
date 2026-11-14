<?php
/**
 * Tests for the grade names a competition shows.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
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

	/**
	 * A saved competition titled "{grade} 2026" of a type renaming the first grade "Cup".
	 *
	 * @param string[] $entrant_grades Grades of members who each upload an image.
	 * @return object
	 */
	private function grade_titled_competition( array $entrant_grades = array() ): object {
		$grades      = Competition_Settings::club_grades();
		$competition = new Competitions_Repository();
		$id          = (int) $competition->create(
			array(
				'type_id'   => Type_Fixtures::create( 'Cup', array( $grades[0]['slug'] => 'Cup' ) ),
				'title'     => '{grade} 2026',
				'slug'      => 'grade-title',
				'open_date' => '2020-01-01 00:00:00',
			)
		);

		foreach ( $entrant_grades as $n => $grade ) {
			$member_id = (int) ( new Members_Repository() )->create(
				array(
					'name'  => 'Entrant ' . $n,
					'email' => "entrant{$n}@example.com",
					'grade' => $grade,
				)
			);
			( new Images_Repository() )->create(
				array(
					'competition_id' => $id,
					'member_id'      => $member_id,
					'category'       => 'colour',
					'filename'       => "entry{$n}.jpg",
				)
			);
		}

		return $competition->find( $id );
	}

	public function test_title_without_placeholder_unchanged(): void {
		$labels = new Competition_Labels();

		$this->assertSame( 'Spring Show', $labels->title_for( (object) array( 'id' => 1, 'title' => 'Spring Show' ) ) );
	}

	public function test_title_for_member_uses_their_grade_name(): void {
		$grades      = Competition_Settings::club_grades();
		$competition = $this->grade_titled_competition();
		$labels      = new Competition_Labels();

		$this->assertSame( 'Cup 2026', $labels->title_for( $competition, (object) array( 'grade' => $grades[0]['slug'] ) ) );
		$this->assertSame( $grades[1]['label'] . ' 2026', $labels->title_for( $competition, (object) array( 'grade' => $grades[1]['slug'] ) ) );
	}

	public function test_title_for_everyone_joins_grade_names_with_entries(): void {
		$grades      = Competition_Settings::club_grades();
		$competition = $this->grade_titled_competition( array( $grades[0]['slug'], $grades[1]['slug'], $grades[1]['slug'] ) );
		$labels      = new Competition_Labels();

		$this->assertSame( 'Cup / ' . $grades[1]['label'] . ' 2026', $labels->title_for_everyone( $competition ) );
	}

	public function test_title_for_everyone_with_no_entries_uses_every_grade(): void {
		$grades      = Competition_Settings::club_grades();
		$competition = $this->grade_titled_competition();
		$expected    = array( 'Cup' );
		foreach ( array_slice( $grades, 1 ) as $grade ) {
			$expected[] = $grade['label'];
		}

		$this->assertSame( implode( ' / ', $expected ) . ' 2026', ( new Competition_Labels() )->title_for_everyone( $competition ) );
	}

	public function test_title_for_recipient_without_grade_uses_everyone_form(): void {
		$grades      = Competition_Settings::club_grades();
		$competition = $this->grade_titled_competition( array( $grades[1]['slug'] ) );
		$labels      = new Competition_Labels();
		$everyone    = $grades[1]['label'] . ' 2026';

		$this->assertSame( $everyone, $labels->title_for( $competition, (object) array( 'name' => 'A Judge' ) ) );
		$this->assertSame( $everyone, $labels->title_for( $competition, (object) array( 'grade' => 'gone' ) ) );
	}
}
