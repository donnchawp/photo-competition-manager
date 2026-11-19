<?php
/**
 * Tests for Judge_Assignments: which judged competitions still need judges.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competition_Judges_Repository;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Judges_Repository;
use PhotoCompetitionManager\Service\Judge_Assignments;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use WP_UnitTestCase;

/**
 * A judged competition that hasn't closed needs judges until one is assigned.
 */
class Judge_Assignments_Test extends WP_UnitTestCase {

	/**
	 * @var Competitions_Repository
	 */
	private $competitions;

	public function setUp(): void {
		parent::setUp();
		$this->competitions = new Competitions_Repository();
	}

	/**
	 * Create a competition.
	 *
	 * @param string      $slug       Slug.
	 * @param string      $auth_mode  Who scores.
	 * @param string      $open_date  Open date.
	 * @param string      $close_date Close date.
	 * @return int
	 */
	private function competition( string $slug, string $auth_mode, string $open_date, string $close_date ): int {
		return (int) $this->competitions->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'      => ucfirst( $slug ),
				'slug'       => $slug,
				'open_date'  => $open_date,
				'close_date' => $close_date,
				'settings'   => array( 'voting' => array( 'auth_mode' => $auth_mode ) ),
			)
		);
	}

	/**
	 * Titles of the competitions needing judges.
	 *
	 * @return string[]
	 */
	private function needing_judges(): array {
		$titles = array_map(
			function ( $competition ) {
				return $competition->title;
			},
			( new Judge_Assignments() )->competitions_needing_judges()
		);
		sort( $titles );

		return $titles;
	}

	public function test_judged_competitions_without_judges_need_them_until_one_is_assigned() {
		$open = $this->competition( 'open', 'judges', '2020-03-01 00:00:00', '2098-01-01 00:00:00' );
		$this->competition( 'scheduled', 'judges', '2099-01-01 00:00:00', '2099-02-01 00:00:00' );

		$this->assertSame( array( 'Open', 'Scheduled' ), $this->needing_judges() );

		$judge_id = ( new Judges_Repository() )->create( 'Jane Judge', 'jane@example.com' );
		( new Competition_Judges_Repository() )->assign( $open, $judge_id );

		$this->assertSame( array( 'Scheduled' ), $this->needing_judges() );
	}

	public function test_closed_archived_and_member_voted_competitions_never_need_judges() {
		$this->competition( 'closed', 'judges', '2020-01-01 00:00:00', '2020-02-01 00:00:00' );
		$this->competitions->archive( $this->competition( 'archived', 'judges', '2020-03-01 00:00:00', '2098-01-01 00:00:00' ) );
		$this->competition( 'password', 'password', '2098-02-01 00:00:00', '2098-03-01 00:00:00' );
		$this->competition( 'links', 'token', '2098-04-01 00:00:00', '2098-05-01 00:00:00' );

		$this->assertSame( array(), $this->needing_judges() );
	}
}
