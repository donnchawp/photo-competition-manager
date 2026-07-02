<?php
/**
 * Golden-master snapshot tests for Email_Templates_Controller::render().
 *
 * Pins the exact rendered HTML ahead of the template-partial extraction (#40).
 * Nonces are normalized so snapshots do not churn per run.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Email_Templates_Controller;

/**
 * @covers \PhotoCompetitionManager\Admin\Email_Templates_Controller
 */
class Email_Templates_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Email_Templates_Controller */
	private $controller;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'photo_comp_email_templates' );
		$this->controller = new Email_Templates_Controller();
	}

	public function tear_down(): void {
		delete_option( 'photo_comp_email_templates' );
		parent::tear_down();
	}

	/**
	 * Render the page and return normalized HTML.
	 */
	private function render_normalized(): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();
		// Normalize per-run nonces: _wpnonce=<10 hex> and nonce field values.
		$html = preg_replace( '/(_wpnonce=)[a-f0-9]{10}/', '$1NONCE', $html );
		$html = preg_replace( '/(name="_wpnonce" value=")[a-f0-9]{10}/', '$1NONCE', $html );
		// Normalize the WordPress version string on enqueued core assets
		// (e.g. dashicons.min.css?ver=7.0) so patch releases don't churn the snapshot.
		$html = preg_replace( '/([?&]ver=)[^\'"&\s]+/', '$1VER', $html );
		return $html;
	}

	/**
	 * Assert live output equals the stored snapshot; write it on first run.
	 *
	 * @param string $scenario Snapshot scenario name.
	 */
	private function assert_matches_snapshot( string $scenario ): void {
		$dir  = __DIR__ . '/../fixtures/email-templates-render';
		$file = $dir . '/' . $scenario . '.html';
		$html = $this->render_normalized();

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

	public function test_render_default_templates(): void {
		// No saved option: renders the built-in defaults for every template key.
		$this->assert_matches_snapshot( 'default-templates' );
	}

	public function test_render_saved_overrides(): void {
		// Saved option overrides subject/body/enabled for a subset of keys,
		// exercising the merge-with-defaults branch in render().
		update_option(
			'photo_comp_email_templates',
			array(
				'upload_reminder' => array(
					'enabled' => false,
					'subject' => 'Custom subject for {competition_title}',
					'body'    => '<p>Custom body with a "quote" & an ampersand.</p>',
				),
				'voting_opened'   => array(
					'enabled' => true,
					'subject' => 'Voting is live!',
					'body'    => '<p>Go vote.</p>',
				),
			)
		);

		$this->assert_matches_snapshot( 'saved-overrides' );
	}
}
