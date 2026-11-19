<?php
/**
 * The "needs judges" warning on the screens an admin is likely to be on.
 *
 * Which competitions need judges is tested in Judge_Assignments_Test; these
 * check only that each screen shows the warning and links to the judges section.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Competitions_Controller;
use PhotoCompetitionManager\Admin\Judges_Controller;
use PhotoCompetitionManager\Admin\Voting_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Judges_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Tests\Type_Fixtures;

/**
 * @covers \PhotoCompetitionManager\Admin\Judges_Controller
 * @covers \PhotoCompetitionManager\Admin\Competitions_Controller
 * @covers \PhotoCompetitionManager\Admin\Voting_Controller
 */
class Judges_Needed_Notice_Test extends Admin_Controller_Test_Case {

	/** @var Competitions_Repository */
	private $competitions;

	/** @var int */
	private $competition_id;

	public function set_up(): void {
		parent::set_up();

		$this->competitions   = new Competitions_Repository();
		$this->competition_id = (int) $this->competitions->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'      => 'Spring Salon',
				'slug'       => 'spring-salon',
				'open_date'  => '2099-01-01 00:00:00',
				'close_date' => '2099-02-01 00:00:00',
				'settings'   => array( 'voting' => array( 'auth_mode' => 'judges' ) ),
			)
		);
	}

	/**
	 * Render a controller's screen.
	 *
	 * @param object $controller A controller with render().
	 * @return string
	 */
	private function render( $controller ): string {
		ob_start();
		$controller->render();
		return (string) ob_get_clean();
	}

	/**
	 * Assert the warning names the competition and links to its judges section.
	 *
	 * @param string $html Rendered screen.
	 */
	private function assert_warns( string $html ): void {
		$this->assertStringContainsString( 'Spring Salon', $html );
		$this->assertStringContainsString( 'has no judges', $html );
		$this->assertMatchesRegularExpression( '/competition=' . $this->competition_id . '(&amp;|&#038;)tab=settings#photo-comp-judges/', $html );
	}

	public function test_judges_screen_warns_about_a_judged_competition_without_judges() {
		$this->assert_warns( $this->render( new Judges_Controller( new Judges_Repository() ) ) );
	}

	public function test_competitions_list_warns_about_a_judged_competition_without_judges() {
		$this->assert_warns( $this->render( new Competitions_Controller( $this->competitions ) ) );
	}

	public function test_voting_screen_warns_about_a_judged_competition_without_judges() {
		$this->assert_warns( $this->render( new Voting_Controller( $this->competitions, new Images_Repository(), new Members_Repository() ) ) );
	}
}
