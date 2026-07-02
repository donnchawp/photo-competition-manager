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
use PhotoCompetitionManager\Support\Competition_Settings;

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
		// Normalize non-deterministic auto-increment IDs, scoped to the specific
		// contexts where a competition ID legitimately appears in the markup:
		// URL query args and data attributes. A document-wide digit replacement
		// is too broad -- on a fresh DB the ID is a small integer (1-5) that
		// collides with unrelated numbers (step-circle labels, tab counts),
		// causing false snapshot failures.
		foreach ( $dynamic_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			// competition=<id> query arg, e.g. "...&competition=2884&...".
			$html = preg_replace( '/competition=' . $id_pattern . '(?!\d)/', 'competition=ID', $html );
			// focus=<id>_<slug> query arg, e.g. "...&focus=2884_colour".
			$html = preg_replace( '/focus=' . $id_pattern . '_/', 'focus=ID_', $html );
			// data-competition-id="<id>" attribute.
			$html = preg_replace( '/data-competition-id="' . $id_pattern . '"/', 'data-competition-id="ID"', $html );
		}
		// Fold numeric &#038; to &amp; so snapshots are agnostic to which
		// ampersand entity WordPress emits (esc_url uses &#038;, esc_attr
		// &amp;; core has changed usage between releases).
		$html = preg_replace( '/&#0*38;/', '&amp;', $html );
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
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				// wp_mkdir_p() has been observed to fail silently in this test
				// environment; fall back to a plain mkdir() before giving up.
				mkdir( $dir, 0777, true );
			}
			if ( ! is_dir( $dir ) ) {
				self::fail( "Could not create snapshot directory {$dir}." );
			}
			if ( false === file_put_contents( $file, $html ) ) {
				self::fail( "Could not write snapshot file {$file}." );
			}
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

	/**
	 * Bug: a competition that doesn't override the voting page URL has
	 * $active_settings['urls']['voting_page'] === '' (empty string, but SET).
	 * The controller must fall through to the global default in that case,
	 * not treat the empty string as an authoritative "no page configured".
	 */
	public function test_render_voting_page_not_missing_when_only_global_url_set(): void {
		// Open competition with default (empty) settings: no per-competition
		// urls override, so active_settings['urls']['voting_page'] === ''.
		$this->seed_competition( array() );

		update_option(
			'photo_comp_default_settings',
			Competition_Settings::encode(
				array(
					'urls' => array(
						'voting_page'  => 'https://example.com/vote',
						'results_page' => 'https://example.com/results',
					),
				)
			)
		);

		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Missing pages', $html );
	}

	/**
	 * Guard: when neither the competition nor the global settings configure a
	 * voting page, the missing-pages notice must still appear. The fix for
	 * the bug above must not suppress a genuinely-missing page.
	 */
	public function test_render_missing_pages_notice_appears_when_no_urls_configured(): void {
		// Open competition with default (empty) settings and no global
		// default settings option saved at all.
		$this->seed_competition( array() );

		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Missing pages', $html );
	}
}
