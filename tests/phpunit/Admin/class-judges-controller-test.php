<?php
/**
 * Tests for Judges_Controller.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Judges_Controller;
use PhotoCompetitionManager\Admin\Members_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Judges_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;

/**
 * @covers \PhotoCompetitionManager\Admin\Judges_Controller
 */
class Judges_Controller_Test extends Admin_Controller_Test_Case {

	private const GROUP = 'photo_competition_judges';

	/**
	 * @var Judges_Repository
	 */
	private $judges;

	/**
	 * @var Judges_Controller
	 */
	private $controller;

	public function set_up(): void {
		parent::set_up();

		$this->judges     = new Judges_Repository();
		$this->controller = new Judges_Controller( $this->judges );
	}

	public function test_add_judge(): void {
		$this->set_request(
			array(
				'photo_competition_action' => 'create_judge',
				'judge_name'               => 'Jo Judge',
				'judge_email'              => 'jo@example.com',
			)
		);
		$this->set_nonce( 'photo_competition_judge_create', 'photo_competition_judge_nonce' );

		$location = $this->capture_redirect( array( $this->controller, 'handle_actions' ) );

		$this->assertStringContainsString( 'page=photo-competition-manager-judges', $location );
		$this->assertContains( 'judge_created', $this->settings_error_codes( self::GROUP ) );

		$judges = $this->judges->find_by_email( 'jo@example.com' );
		$this->assertCount( 1, $judges );
		$this->assertSame( 'Jo Judge', $judges[0]->name );
	}

	public function test_add_judge_rejects_invalid_email(): void {
		$this->set_request(
			array(
				'photo_competition_action' => 'create_judge',
				'judge_name'               => 'Jo Judge',
				'judge_email'              => 'not-an-email',
			)
		);
		$this->set_nonce( 'photo_competition_judge_create', 'photo_competition_judge_nonce' );

		$this->capture_redirect( array( $this->controller, 'handle_actions' ) );

		$this->assertContains( 'invalid_email', $this->settings_error_codes( self::GROUP ) );
		$this->assertSame( array(), $this->judges->all() );
	}

	public function test_add_judge_needs_a_valid_nonce(): void {
		$this->set_request(
			array(
				'photo_competition_action' => 'create_judge',
				'judge_name'               => 'Jo Judge',
				'judge_email'              => 'jo@example.com',
				'photo_competition_judge_nonce' => 'bad',
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	public function test_edit_judge(): void {
		$id = $this->judges->create( 'Jo Judge', 'jo@example.com' );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_judge',
				'judge_id'                 => $id,
				'judge_name'               => 'Joan Judge',
				'judge_email'              => 'joan@example.com',
			)
		);
		$this->set_nonce( 'photo_competition_judge_update_' . $id, 'photo_competition_judge_nonce' );

		$this->capture_redirect( array( $this->controller, 'handle_actions' ) );

		$this->assertContains( 'judge_updated', $this->settings_error_codes( self::GROUP ) );
		$this->assertSame( 'Joan Judge', $this->judges->find( $id )->name );
	}

	public function test_delete_judge(): void {
		$id = $this->judges->create( 'Jo Judge', 'jo@example.com' );

		$this->set_request(
			array(
				'photo_competition_action' => 'delete_judge',
				'judge_id'                 => $id,
			)
		);
		$this->set_nonce( 'photo_competition_delete_judge_' . $id, 'photo_competition_judge_nonce' );

		$this->capture_redirect( array( $this->controller, 'handle_actions' ) );

		$this->assertContains( 'judge_deleted', $this->settings_error_codes( self::GROUP ) );
		$this->assertNull( $this->judges->find( $id ) );
	}

	public function test_without_capability_nothing_happens(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->set_request(
			array(
				'photo_competition_action' => 'create_judge',
				'judge_name'               => 'Jo Judge',
				'judge_email'              => 'jo@example.com',
			)
		);

		$this->controller->handle_actions();

		$this->assertSame( array(), $this->judges->all() );
	}

	public function test_judges_never_appear_on_members_screen(): void {
		$this->judges->create( 'Jo Judge', 'jo@example.com' );

		$members_screen = new Members_Controller( new Competitions_Repository(), new Members_Repository() );
		ob_start();
		$members_screen->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Jo Judge', $html );
		$this->assertStringNotContainsString( 'jo@example.com', $html );

		ob_start();
		$this->controller->render();
		$this->assertStringContainsString( 'Jo Judge', (string) ob_get_clean() );
	}
}
