<?php
/**
 * Tests that admin notices survive the redirect after an action.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Judges_Controller;
use PhotoCompetitionManager\Repository\Judges_Repository;

/**
 * Controllers queue a notice, then redirect; the notice must show on the page
 * the browser lands on, which is a new request. The judges screen stands in for
 * every controller that redirects through the form-rendering trait.
 */
class Redirect_Notices_Test extends Admin_Controller_Test_Case {

	public function test_a_notice_queued_before_a_redirect_shows_on_the_page_it_lands_on(): void {
		$this->set_request(
			array(
				'photo_competition_action' => 'create_judge',
				'judge_name'               => 'Jo Judge',
				'judge_email'              => 'jo@example.com',
			)
		);
		$this->set_nonce( 'photo_competition_judge_create', 'photo_competition_judge_nonce' );

		$location = $this->capture_redirect( array( new Judges_Controller( new Judges_Repository() ), 'handle_actions' ) );

		$this->land_on( $location );

		$this->assertContains( 'judge_created', $this->settings_error_codes( 'photo_competition_judges' ) );
	}

	/**
	 * Start a new request for a URL: nothing queued in memory, only its query string.
	 *
	 * @param string $location Redirect location.
	 */
	private function land_on( string $location ): void {
		global $wp_settings_errors;

		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- A new request starts with none.
		$this->reset_request();
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $_GET );
	}
}
