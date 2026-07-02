<?php
/**
 * Golden-master snapshot tests for Settings_Controller::render().
 *
 * Pins the exact rendered HTML ahead of the template-partial extraction (#40).
 * The per-run nonce and its accompanying referer field are normalized so
 * snapshots do not churn per run; everything else is asserted byte-exact.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Settings_Controller;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * @covers \PhotoCompetitionManager\Admin\Settings_Controller
 */
class Settings_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Settings_Controller */
	private $controller;

	public function set_up(): void {
		parent::set_up();
		$this->controller = new Settings_Controller();
	}

	public function tear_down(): void {
		delete_option( 'photo_comp_default_settings' );
		delete_option( 'photo_comp_voting_ui_type' );
		parent::tear_down();
	}

	/**
	 * Render the page and return normalized HTML.
	 */
	private function render_normalized(): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		// Normalize the per-run nonce hidden-field value from wp_nonce_field().
		$html = preg_replace(
			'/(id="photo_competition_nonce" name="photo_competition_nonce" value=")[a-f0-9]{10}(")/',
			'$1NONCE$2',
			$html
		);

		// Normalize the referer hidden field wp_nonce_field() emits by default;
		// its value is the current REQUEST_URI, which is test-runner dependent.
		$html = preg_replace( '/(name="_wp_http_referer" value=")[^"]*(")/', '$1REFERER$2', $html );

		// Fold numeric &#038; to &amp; so snapshots are agnostic to which
		// ampersand entity WordPress emits (esc_url uses &#038;, esc_attr
		// &amp;; core has changed usage between releases).
		$html = preg_replace( '/&#0*38;/', '&amp;', $html );

		return $html;
	}

	/**
	 * Assert live output equals the stored snapshot; write it on first run.
	 *
	 * @param string $scenario Snapshot scenario name.
	 */
	private function assert_matches_snapshot( string $scenario ): void {
		$dir  = __DIR__ . '/../fixtures/settings-render';
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

	/*
	 * -----------------------------------------------------------------
	 * Scenarios.
	 * -----------------------------------------------------------------
	 */

	public function test_render_defaults_no_saved_settings(): void {
		// No `photo_comp_default_settings` option saved: exercises the
		// hard-coded-defaults branch for every section (2 categories, 3
		// grades, default upload/voting/slideshow/email/url values), plus
		// the "buttons" default for the voting_ui_type option and the
		// "password" auth-mode / "bar" meter-type selected() / checked()
		// branches.
		$this->assert_matches_snapshot( 'defaults-no-saved-settings' );
	}

	public function test_render_configured_settings(): void {
		// Fully configured settings: 3 categories and 2 grades (both counts
		// different from the 2/3 defaults, to prove the category-field and
		// grade-field loops emit the right number of rows), token auth mode,
		// dropdown voting UI, a non-empty voting password, click-to-zoom
		// enabled, a custom score matrix, the "radial" meter type (so the
		// active-card branch fires on a different card than the default),
		// custom slideshow durations, custom email sender fields, and
		// custom URLs for all four pages.
		$settings = array(
			'categories' => array(
				array(
					'slug'  => 'colour',
					'label' => 'Colour',
					'quota' => 2,
				),
				array(
					'slug'  => 'mono',
					'label' => 'Monochrome',
					'quota' => 1,
				),
				array(
					'slug'  => 'nature',
					'label' => 'Nature',
					'quota' => 3,
				),
			),
			'grades'     => array(
				array(
					'slug'  => 'novice',
					'label' => 'Novice',
				),
				array(
					'slug'  => 'expert',
					'label' => 'Expert',
				),
			),
			'upload'     => array(
				'max_file_size_mb' => 8,
				'max_width'        => 2000,
				'max_height'       => 1500,
				'allowed_formats'  => array( 'jpg', 'jpeg' ),
			),
			'voting'     => array(
				'score_matrix'        => array( 10, 8, 6, 4, 2 ),
				'open_categories'     => array(),
				'auth_mode'           => 'token',
				'password'            => 'secret123',
				'click_image_to_zoom' => true,
				'ui_type'             => 'default',
			),
			'slideshow'  => array(
				'progress_meter_type' => 'radial',
				'preview_duration'    => 20,
				'voting_duration'     => 25,
				'critique_duration'   => 5,
			),
			'email'      => array(
				'from_name'  => 'Camera Club',
				'from_email' => 'info@example.com',
			),
			'urls'       => array(
				'upload_page'  => 'https://example.com/upload',
				'voting_page'  => 'https://example.com/vote',
				'results_page' => 'https://example.com/results',
				'top3_page'    => 'https://example.com/top3',
			),
		);

		update_option( 'photo_comp_default_settings', Competition_Settings::encode( $settings ) );
		update_option( 'photo_comp_voting_ui_type', 'dropdown' );

		$this->assert_matches_snapshot( 'configured-settings' );
	}

	public function test_render_with_settings_error(): void {
		// A registered settings error (e.g. from a failed validation on the
		// prior POST) renders via settings_errors() before the form; default
		// settings otherwise.
		add_settings_error(
			'photo_competition_settings',
			'missing_categories',
			'At least one category is required.',
			'error'
		);

		$this->assert_matches_snapshot( 'with-settings-error' );
	}
}
