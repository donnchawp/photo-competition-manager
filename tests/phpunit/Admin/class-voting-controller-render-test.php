<?php
/**
 * Golden-master snapshot tests for Voting_Controller::render().
 *
 * Pins the exact rendered HTML ahead of the template-partial extraction (#10).
 * Nonces are normalized so snapshots do not churn per run.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Voting_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;

/**
 * @covers \PhotoCompetitionManager\Admin\Voting_Controller
 */
class Voting_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Competitions_Repository */
	private $competitions;

	/** @var Images_Repository */
	private $images;

	/** @var Members_Repository */
	private $members;

	/** @var Voting_Controller */
	private $controller;

	public function set_up(): void {
		parent::set_up();
		$this->competitions = new Competitions_Repository();
		$this->images       = new Images_Repository();
		$this->members      = new Members_Repository();
		$this->controller   = new Voting_Controller( $this->competitions, $this->images, $this->members );
	}

	/**
	 * Render the page and return normalized HTML.
	 *
	 * @param array<int,int> $dynamic_ids Auto-increment IDs (e.g. competition ID) to
	 *                                    normalize; these are not stable across test
	 *                                    runs because MySQL does not roll back
	 *                                    auto-increment counters with the transactional
	 *                                    test rollback, so byte-exact comparison would
	 *                                    otherwise churn on every run.
	 */
	private function render_normalized( array $dynamic_ids = array() ): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();
		// Normalize per-run nonces: _wpnonce=<10 hex> and nonce field values.
		$html = preg_replace( '/(_wpnonce=)[a-f0-9]{10}/', '$1NONCE', $html );
		$html = preg_replace( '/(name="_wpnonce" value=")[a-f0-9]{10}/', '$1NONCE', $html );
		// Normalize non-deterministic auto-increment IDs embedded in URLs/attributes.
		foreach ( $dynamic_ids as $id ) {
			$html = preg_replace( '/(?<!\d)' . preg_quote( (string) $id, '/' ) . '(?!\d)/', 'ID', $html );
		}
		return $html;
	}

	/**
	 * Assert live output equals the stored snapshot; write it on first run.
	 *
	 * @param string          $scenario    Snapshot scenario name.
	 * @param array<int,int>  $dynamic_ids Auto-increment IDs to normalize before comparing.
	 */
	private function assert_matches_snapshot( string $scenario, array $dynamic_ids = array() ): void {
		$dir  = __DIR__ . '/../fixtures/voting-render';
		$file = $dir . '/' . $scenario . '.html';
		$html = $this->render_normalized( $dynamic_ids );

		if ( ! file_exists( $file ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $file, $html );
			$this->markTestSkipped( "Snapshot written for {$scenario}; re-run to assert." );
			return;
		}

		$this->assertSame( file_get_contents( $file ), $html, "Rendered markup drifted for scenario {$scenario}." );
	}

	/**
	 * Seed a competition with the given categories and return its ID.
	 *
	 * @param array<int,array{slug:string,label:string}> $categories Category defs.
	 */
	private function seed_competition( array $categories ): int {
		return $this->competitions->create(
			array(
				'title'      => 'Spring Show',
				'slug'       => 'spring-show',
				'open_date'  => null,
				'close_date' => null,
				'settings'   => array( 'categories' => $categories ),
			)
		);
	}

	public function test_render_no_open_competitions(): void {
		// No competitions created at all.
		$this->assert_matches_snapshot( 'no-open-competitions' );
	}

	public function test_render_no_images(): void {
		$this->seed_competition( array( array( 'slug' => 'colour', 'label' => 'Colour' ) ) );
		$this->assert_matches_snapshot( 'no-images' );
	}

	public function test_render_happy_path(): void {
		$comp_id = $this->seed_competition(
			array(
				array( 'slug' => 'colour', 'label' => 'Colour' ),
				array( 'slug' => 'mono', 'label' => 'Mono' ),
			)
		);
		$member_id = $this->members->create(
			array( 'name' => 'Ada', 'email' => 'ada@example.com', 'grade' => 'A' )
		);
		foreach ( array( 'colour', 'mono' ) as $cat ) {
			$this->images->create(
				array(
					'competition_id' => $comp_id,
					'member_id'      => $member_id,
					'category'       => $cat,
					'filename'       => $cat . '.jpg',
					'random_number'  => 100,
				)
			);
		}
		$this->assert_matches_snapshot( 'happy-path', array( $comp_id ) );
	}
}
