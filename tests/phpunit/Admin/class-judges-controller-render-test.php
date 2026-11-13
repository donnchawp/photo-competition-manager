<?php
/**
 * Golden-master snapshot tests for Judges_Controller::render().
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Judges_Controller;
use PhotoCompetitionManager\Repository\Judges_Repository;

/**
 * @covers \PhotoCompetitionManager\Admin\Judges_Controller
 */
class Judges_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Judges_Repository */
	private $judges;

	/** @var Judges_Controller */
	private $controller;

	public function set_up(): void {
		parent::set_up();
		$this->judges     = new Judges_Repository();
		$this->controller = new Judges_Controller( $this->judges );
	}

	/**
	 * Render the screen with per-run values normalized.
	 *
	 * @param int[] $judge_ids Judge IDs to normalize (auto-increment survives rollback).
	 */
	private function render_normalized( array $judge_ids = array() ): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		$html = preg_replace( '/_wpnonce=[a-f0-9]{10}/', '_wpnonce=NONCE', $html );
		$html = preg_replace( '/(name="photo_competition_judge_nonce" value=")[a-f0-9]{10}(")/', '$1NONCE$2', $html );
		$html = preg_replace( '/(id="photo_competition_judge_nonce" name="photo_competition_judge_nonce" value=")[a-f0-9]{10}(")/', '$1NONCE$2', $html );
		$html = preg_replace( '/(name="_wp_http_referer" value=")[^"]*(")/', '$1REFERER$2', $html );

		foreach ( $judge_ids as $id ) {
			$pattern = preg_quote( (string) $id, '/' );
			$html    = preg_replace( '/judge=' . $pattern . '(?!\d)/', 'judge=ID', $html );
			$html    = preg_replace( '/name="judge_id" value="' . $pattern . '"/', 'name="judge_id" value="ID"', $html );
		}

		return (string) preg_replace( '/&#0*38;/', '&amp;', $html );
	}

	/**
	 * Assert live output equals the stored snapshot; write it on first run.
	 *
	 * @param string $scenario  Snapshot scenario name.
	 * @param int[]  $judge_ids Judge IDs to normalize.
	 */
	private function assert_matches_snapshot( string $scenario, array $judge_ids = array() ): void {
		$dir  = __DIR__ . '/../fixtures/judges-render';
		$file = $dir . '/' . $scenario . '.html';
		$html = $this->render_normalized( $judge_ids );

		if ( ! file_exists( $file ) ) {
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true );
			}
			file_put_contents( $file, $html );
			$this->markTestSkipped( "Snapshot written for {$scenario}; re-run to assert." );
			return;
		}

		$this->assertSame( file_get_contents( $file ), $html, "Rendered markup drifted for scenario {$scenario}." );
	}

	public function test_render_empty_no_judges(): void {
		$this->assert_matches_snapshot( 'empty-no-judges' );
	}

	public function test_render_list_with_judges(): void {
		$a = $this->judges->create( 'Ann Judge', 'ann@example.com' );
		$b = $this->judges->create( 'Bob <b>Judge</b>', 'bob@example.com' );

		$this->assert_matches_snapshot( 'list-with-judges', array( $a, $b ) );
	}

	public function test_render_edit_judge(): void {
		$id = $this->judges->create( 'Ann Judge', 'ann@example.com' );
		$this->set_request(
			array(
				'judge_action' => 'edit',
				'judge'        => $id,
			)
		);

		$this->assert_matches_snapshot( 'edit-judge', array( $id ) );
	}

	public function test_render_edit_judge_not_found(): void {
		$this->set_request(
			array(
				'judge_action' => 'edit',
				'judge'        => 999999,
			)
		);

		$this->assert_matches_snapshot( 'edit-judge-not-found' );
	}

	public function test_render_confirm_delete(): void {
		$id = $this->judges->create( 'Ann Judge', 'ann@example.com' );
		$this->set_request(
			array(
				'judge_action' => 'delete',
				'judge'        => $id,
			)
		);

		$this->assert_matches_snapshot( 'confirm-delete', array( $id ) );
	}
}
