<?php
/**
 * Characterization tests for Voting_Controller.
 *
 * Pins current behavior of the voting action router and the AJAX step-machine
 * ahead of the dispatcher/template refactors.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Voting_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Characterization tests for the voting controller.
 *
 * @covers \PhotoCompetitionManager\Admin\Voting_Controller
 */
class Voting_Controller_Test extends Admin_Controller_Test_Case {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Controller under test.
	 *
	 * @var Voting_Controller
	 */
	private $controller;

	/**
	 * Seeded open competition ID.
	 *
	 * @var int
	 */
	private $competition_id;

	/**
	 * Set up the controller and a seeded open competition.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->competitions   = new Competitions_Repository();
		$this->controller     = new Voting_Controller( $this->competitions, new Images_Repository() );
		$this->competition_id = $this->create_open_competition( 'Spring Show', 'spring-show' );
	}

	/**
	 * Create an open competition and return its ID.
	 *
	 * @param string $title Title.
	 * @param string $slug  Slug.
	 * @return int Competition ID.
	 */
	private function create_open_competition( string $title, string $slug ): int {
		return $this->competitions->create(
			array(
				'title'      => $title,
				'slug'       => $slug,
				// Null dates make is_open() true independent of the clock, so the
				// "only one category open" constraint test (which relies on the
				// seeded competition reading as open) doesn't rot after any fixed date.
				'open_date'  => null,
				'close_date' => null,
				'settings'   => array(),
			)
		);
	}

	/**
	 * Parsed settings for the seeded competition.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		$competition = $this->competitions->find( $this->competition_id );
		return Competition_Settings::parse( $competition->settings );
	}

	/**
	 * First registered settings-error message for the voting group.
	 *
	 * @return string
	 */
	private function first_voting_error_message(): string {
		$errors = get_settings_errors( 'photo_competition_voting' );
		return $errors ? $errors[0]['message'] : '';
	}

	/**
	 * Seed one anonymous vote for a competition/category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 */
	private function seed_vote( int $competition_id, string $category ): void {
		$votes = new Votes_Repository();
		$votes->create_anonymous( $competition_id, $category, 4321, 1234, 5 );
	}

	/**
	 * Seed one voting token for a competition/category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 */
	private function seed_token( int $competition_id, string $category ): void {
		$tokens = new Voting_Token_Repository();
		$tokens->create( $this->admin_id, $competition_id, $category, 'hash_' . $category, '2099-12-31 00:00:00' );
	}

	/**
	 * Count votes recorded for a competition/category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return int
	 */
	private function vote_count( int $competition_id, string $category ): int {
		return count( ( new Votes_Repository() )->find_by_competition( $competition_id, $category ) );
	}

	/**
	 * Count voting tokens recorded for a competition/category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return int
	 */
	private function token_count( int $competition_id, string $category ): int {
		global $wpdb;
		$repo  = new Voting_Token_Repository();
		$table = $repo->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion; placeholders only.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE competition_id = %d AND category = %s', $table, $competition_id, $category ) );
	}

	/**
	 * Without the capability, handle_actions() is a no-op.
	 */
	public function test_handle_actions_noop_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->set_request(
			array(
				'action'      => 'open_category_voting',
				'competition' => $this->competition_id,
				'category'    => 'colour',
			)
		);
		$this->set_nonce( 'photo_competition_open_voting_' . $this->competition_id . '_colour' );

		$this->controller->handle_actions();

		$settings = $this->settings();
		$this->assertArrayNotHasKey( 'colour', $settings['voting']['category_steps'] ?? array() );
	}

	/**
	 * Opening a category sets it as the only open category at step 3.
	 */
	public function test_open_category_voting_success(): void {
		$this->set_request(
			array(
				'action'      => 'open_category_voting',
				'competition' => $this->competition_id,
				'category'    => 'colour',
			)
		);
		$this->set_nonce( 'photo_competition_open_voting_' . $this->competition_id . '_colour' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager-voting', $location );
		$this->assertContains( 'voting_opened', $this->settings_error_codes( 'photo_competition_voting' ) );

		$settings = $this->settings();
		$this->assertSame( array( 'colour' ), $settings['voting']['open_categories'] );
		$this->assertSame( 3, $settings['voting']['category_steps']['colour'] );
	}

	/**
	 * Opening is blocked when another active competition already has voting open.
	 */
	public function test_open_category_voting_blocked_when_another_open(): void {
		$other                          = $this->create_open_competition( 'Other', 'other' );
		$comp                           = $this->competitions->find( $other );
		$s                              = Competition_Settings::parse( $comp->settings );
		$s['voting']['open_categories'] = array( 'mono' );
		$this->competitions->update( $other, array( 'settings' => $s ) );

		$this->set_request(
			array(
				'action'      => 'open_category_voting',
				'competition' => $this->competition_id,
				'category'    => 'colour',
			)
		);
		$this->set_nonce( 'photo_competition_open_voting_' . $this->competition_id . '_colour' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'voting_already_open', $this->settings_error_codes( 'photo_competition_voting' ) );
		$settings = $this->settings();
		$this->assertArrayNotHasKey( 'colour', $settings['voting']['category_steps'] ?? array() );
	}

	/**
	 * A missing competition yields a not-found error.
	 */
	public function test_open_category_voting_competition_not_found(): void {
		$missing = 999999;
		$this->set_request(
			array(
				'action'      => 'open_category_voting',
				'competition' => $missing,
				'category'    => 'colour',
			)
		);
		$this->set_nonce( 'photo_competition_open_voting_' . $missing . '_colour' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'competition_not_found', $this->settings_error_codes( 'photo_competition_voting' ) );
	}

	/**
	 * A missing/invalid nonce aborts via wp_die().
	 */
	public function test_open_category_voting_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'action'      => 'open_category_voting',
				'competition' => $this->competition_id,
				'category'    => 'colour',
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/**
	 * Closing a category clears open categories, sets step 5, and records the vote.
	 */
	public function test_close_category_voting_success(): void {
		$this->set_request(
			array(
				'action'      => 'close_category_voting',
				'competition' => $this->competition_id,
				'category'    => 'colour',
			)
		);
		$this->set_nonce( 'photo_competition_close_voting_' . $this->competition_id . '_colour' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'voting_closed', $this->settings_error_codes( 'photo_competition_voting' ) );

		$settings = $this->settings();
		$this->assertSame( array(), $settings['voting']['open_categories'] );
		$this->assertSame( 5, $settings['voting']['category_steps']['colour'] );
		$this->assertContains( $this->competition_id . '_colour', $settings['voting']['voted_categories'] );
	}

	/**
	 * Resetting with clear_votes=0 returns to step 1 and keeps votes and tokens.
	 */
	public function test_reset_category_keeps_votes(): void {
		$this->seed_vote( $this->competition_id, 'colour' );
		$this->seed_token( $this->competition_id, 'colour' );

		$this->set_request(
			array(
				'action'      => 'reset_category',
				'competition' => $this->competition_id,
				'category'    => 'colour',
				'clear_votes' => 0,
			)
		);
		$this->set_nonce( 'photo_competition_reset_category_' . $this->competition_id . '_colour' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'category_reset', $this->settings_error_codes( 'photo_competition_voting' ) );
		$this->assertStringContainsString( 'kept', $this->first_voting_error_message() );
		$this->assertSame( 1, $this->settings()['voting']['category_steps']['colour'] );
		$this->assertSame( 1, $this->vote_count( $this->competition_id, 'colour' ), 'Votes must survive clear_votes=0.' );
		$this->assertSame( 1, $this->token_count( $this->competition_id, 'colour' ), 'Tokens must survive clear_votes=0.' );
	}

	/**
	 * Resetting with clear_votes=1 deletes the category's votes and tokens,
	 * leaving sibling categories untouched.
	 */
	public function test_reset_category_clears_votes(): void {
		$this->seed_vote( $this->competition_id, 'colour' );
		$this->seed_token( $this->competition_id, 'colour' );
		// Sibling category proves the deletion is scoped by category, not competition-wide.
		$this->seed_vote( $this->competition_id, 'mono' );
		$this->seed_token( $this->competition_id, 'mono' );

		$this->set_request(
			array(
				'action'      => 'reset_category',
				'competition' => $this->competition_id,
				'category'    => 'colour',
				'clear_votes' => 1,
			)
		);
		$this->set_nonce( 'photo_competition_reset_category_' . $this->competition_id . '_colour' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'category_reset', $this->settings_error_codes( 'photo_competition_voting' ) );
		$this->assertStringContainsString( 'cleared', $this->first_voting_error_message() );
		$this->assertSame( 0, $this->vote_count( $this->competition_id, 'colour' ), 'Colour votes must be deleted.' );
		$this->assertSame( 0, $this->token_count( $this->competition_id, 'colour' ), 'Colour tokens must be deleted.' );
		$this->assertSame( 1, $this->vote_count( $this->competition_id, 'mono' ), 'Sibling category votes must survive.' );
		$this->assertSame( 1, $this->token_count( $this->competition_id, 'mono' ), 'Sibling category tokens must survive.' );
	}

	/**
	 * Showing results marks them visible.
	 */
	public function test_show_results_makes_visible(): void {
		$this->set_request(
			array(
				'action'      => 'show_results',
				'competition' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_show_results_' . $this->competition_id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'results_shown', $this->settings_error_codes( 'photo_competition_voting' ) );
		$this->assertTrue( $this->settings()['results']['results_visible'] );
	}

	/**
	 * Hiding results marks them not visible.
	 */
	public function test_hide_results_makes_hidden(): void {
		$this->set_request(
			array(
				'action'      => 'hide_results',
				'competition' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_hide_results_' . $this->competition_id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'results_hidden', $this->settings_error_codes( 'photo_competition_voting' ) );
		$this->assertFalse( $this->settings()['results']['results_visible'] );
	}

	/**
	 * The AJAX step handler persists the requested step.
	 */
	public function test_advance_step_updates_step(): void {
		$this->set_request(
			array(
				'competition_id' => $this->competition_id,
				'category_slug'  => 'colour',
				'step'           => 3,
			)
		);
		$this->set_nonce( 'photo_comp_voting_step' );

		$json = $this->capture_json(
			function () {
				$this->controller->handle_advance_step();
			}
		);

		$this->assertTrue( $json['success'] );
		$this->assertSame( 3, $this->settings()['voting']['category_steps']['colour'] );
	}

	/**
	 * Advancing to step 6 also records the category as voted.
	 */
	public function test_advance_step_6_records_voted_category(): void {
		$this->set_request(
			array(
				'competition_id' => $this->competition_id,
				'category_slug'  => 'colour',
				'step'           => 6,
			)
		);
		$this->set_nonce( 'photo_comp_voting_step' );

		$json = $this->capture_json(
			function () {
				$this->controller->handle_advance_step();
			}
		);

		$this->assertTrue( $json['success'] );
		$settings = $this->settings();
		$this->assertSame( 6, $settings['voting']['category_steps']['colour'] );
		$this->assertContains( $this->competition_id . '_colour', $settings['voting']['voted_categories'] );
	}

	/**
	 * The AJAX step handler rejects out-of-range steps.
	 */
	public function test_advance_step_rejects_invalid_step(): void {
		$this->set_request(
			array(
				'competition_id' => $this->competition_id,
				'category_slug'  => 'colour',
				'step'           => 7,
			)
		);
		$this->set_nonce( 'photo_comp_voting_step' );

		$json = $this->capture_json(
			function () {
				$this->controller->handle_advance_step();
			}
		);

		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( 'Invalid parameters', $json['data']['message'] );
	}
}
