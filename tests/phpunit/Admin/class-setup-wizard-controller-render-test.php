<?php
/**
 * Golden-master snapshot tests for Setup_Wizard_Controller::render().
 *
 * Pins the exact rendered HTML ahead of the template-partial extraction (#40).
 * Nonces are normalized so snapshots do not churn per run.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Setup_Wizard_Controller;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * @covers \PhotoCompetitionManager\Admin\Setup_Wizard_Controller
 */
class Setup_Wizard_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Setup_Wizard_Controller */
	private $controller;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'photo_comp_default_settings' );
		$this->controller = new Setup_Wizard_Controller();
	}

	public function tear_down(): void {
		delete_option( 'photo_comp_default_settings' );
		parent::tear_down();
	}

	/**
	 * Render the page and return normalized HTML.
	 *
	 * @param array<int,int> $dynamic_ids Post IDs (e.g. seeded pages) to normalize;
	 *                                    these are not stable across test runs
	 *                                    because MySQL does not roll back
	 *                                    auto-increment counters with the
	 *                                    transactional test rollback, so
	 *                                    byte-exact comparison would otherwise
	 *                                    churn on every run.
	 */
	private function render_normalized( array $dynamic_ids = array() ): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();
		// Normalize per-run nonces: _wpnonce=<10 hex> query args and the
		// custom-named nonce field (wp_nonce_field( ..., 'photo_competition_nonce' )).
		$html = preg_replace( '/(_wpnonce=)[a-f0-9]{10}/', '$1NONCE', $html );
		$html = preg_replace( '/(name="photo_competition_nonce" value=")[a-f0-9]{10}/', '$1NONCE', $html );
		// Normalize non-deterministic auto-increment post IDs, scoped to the
		// specific contexts where a page ID legitimately appears in the
		// "Existing Pages" markup: permalink/edit-link hrefs. A document-wide
		// digit replacement is too broad and would collide with unrelated
		// numbers elsewhere on the page.
		foreach ( $dynamic_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			// get_permalink() default structure: ?page_id=<id>.
			$html = preg_replace( '/page_id=' . $id_pattern . '(?!\d)/', 'page_id=ID', $html );
			// get_edit_post_link() default structure: post.php?post=<id>&action=edit.
			$html = preg_replace( '/post=' . $id_pattern . '&(?:#0*38;)?action=edit/', 'post=ID&#038;action=edit', $html );
		}
		return $html;
	}

	/**
	 * Assert live output equals the stored snapshot; write it on first run.
	 *
	 * @param string          $scenario    Snapshot scenario name.
	 * @param array<int,int>  $dynamic_ids Post IDs to normalize before comparing.
	 */
	private function assert_matches_snapshot( string $scenario, array $dynamic_ids = array() ): void {
		$dir  = __DIR__ . '/../fixtures/setup-wizard-render';
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

	public function test_render_no_existing_settings(): void {
		// No `photo_comp_default_settings` option at all: no "already configured"
		// notice, and no seeded pages, so render_existing_pages_info() bails out
		// early (empty $found_pages -> no "Existing Pages" section).
		$this->assert_matches_snapshot( 'no-existing-settings' );
	}

	public function test_render_already_configured_pages(): void {
		// Settings already have upload/voting URLs saved: exercises the
		// "already configured some pages" notice branch.
		$settings         = Competition_Settings::parse( '' );
		$settings['urls'] = array(
			'upload_page' => 'https://example.org/upload/',
			'voting_page' => 'https://example.org/vote/',
		);
		update_option( 'photo_comp_default_settings', Competition_Settings::encode( $settings ) );

		$this->assert_matches_snapshot( 'already-configured-pages' );
	}

	public function test_render_existing_shortcode_pages(): void {
		// Published pages exist containing competition shortcodes: exercises
		// render_existing_pages_info()'s "Existing Pages" listing branch,
		// including multiple pages under the same shortcode label.
		$upload_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Upload Photos',
				'post_content' => '[competition_upload]',
			)
		);
		$voting_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Cast Your Vote',
				'post_content' => '[competition_voting]',
			)
		);
		$results_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'See Results',
				'post_content' => '[competition_results]',
			)
		);

		$this->assert_matches_snapshot( 'existing-shortcode-pages', array( $upload_id, $voting_id, $results_id ) );
	}
}
