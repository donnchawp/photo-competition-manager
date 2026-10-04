<?php
/**
 * Tests for Voting_Controller's request handling: nonces, capability,
 * which competition it may act on, and how it reports the workflow's answers.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Voting_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Email_Job_Manager;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;

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
				'settings'   => array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
							'quota' => 1,
						),
						array(
							'slug'  => 'mono',
							'label' => 'Mono',
							'quota' => 1,
						),
					),
				),
			)
		);
	}

	/**
	 * The seeded competition's colour stage.
	 *
	 * @return string
	 */
	private function colour_stage(): string {
		return ( new Competition_Workflow() )->stage( $this->competitions->find( $this->competition_id, true ), 'colour' );
	}

	/**
	 * Give the seeded competition a colour entry and walk colour to a stage.
	 *
	 * @param string $stage Stage to stop at.
	 */
	private function colour_at( string $stage ): void {
		Entry_Fixtures::insert_entry( $this->competition_id, 'colour', 1, array() );
		Workflow_Fixtures::close_uploads( $this->competition_id );
		Workflow_Fixtures::set_stage( $this->competition_id, 'colour', $stage );
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
		Entry_Fixtures::insert_entry( $competition_id, $category, $this->admin_id, array( 5 ) );
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

		$this->colour_at( Competition_Workflow::STAGE_PREVIEWED );

		$this->controller->handle_actions();

		$this->assertSame( Competition_Workflow::STAGE_PREVIEWED, $this->colour_stage() );
	}

	/**
	 * Opening voting on a previewed category starts voting on it.
	 */
	public function test_open_category_voting_success(): void {
		$this->colour_at( Competition_Workflow::STAGE_PREVIEWED );
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

		$this->assertSame( Competition_Workflow::STAGE_VOTING, $this->colour_stage() );
	}

	/**
	 * A refused open voting shows the workflow's reason and changes nothing.
	 */
	public function test_open_category_voting_refused_while_uploads_are_open(): void {
		Entry_Fixtures::insert_entry( $this->competition_id, 'colour', 1, array() );
		Workflow_Fixtures::set_stage( $this->competition_id, 'colour', Competition_Workflow::STAGE_PREVIEWED );
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
		$this->assertContains( 'uploads_open', $this->settings_error_codes( 'photo_competition_voting' ) );
		$this->assertSame( Competition_Workflow::STAGE_PREVIEWED, $this->colour_stage() );
	}

	/**
	 * With the voting-opened email enabled, opening voting queues the emails
	 * instead of sending them during the request, and the page it lands on
	 * shows the job's progress.
	 */
	public function test_open_category_voting_queues_voting_opened_emails(): void {
		update_option( 'photo_comp_default_settings', wp_json_encode( array( 'urls' => array( 'voting_page' => 'https://example.com/vote/' ) ) ) );
		update_option(
			'photo_comp_email_templates',
			array(
				'voting_opened' => array(
					'enabled' => true,
					'subject' => 'Voting is open',
					'body'    => '<p>Go vote.</p>',
				),
			)
		);
		( new Members_Repository() )->create(
			array(
				'name'  => 'Voter',
				'email' => 'voter@example.com',
				'grade' => 'beginner',
			)
		);
		$this->colour_at( Competition_Workflow::STAGE_PREVIEWED );
		$mail_count = 0;
		add_filter(
			'pre_wp_mail',
			function () use ( &$mail_count ) {
				++$mail_count;
				return true;
			}
		);

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

		$this->assertMatchesRegularExpression( '/[?&]job_id=email_job_/', $location );
		$this->assertSame( 0, $mail_count );
	}

	/**
	 * With the voting-opened email turned off, opening voting queues no job.
	 */
	public function test_open_category_voting_queues_nothing_when_the_email_is_off(): void {
		global $wpdb;

		update_option( 'photo_comp_default_settings', wp_json_encode( array( 'urls' => array( 'voting_page' => 'https://example.com/vote/' ) ) ) );
		update_option(
			'photo_comp_email_templates',
			array(
				'voting_opened' => array(
					'enabled' => false,
					'subject' => 'Voting is open',
					'body'    => '<p>Go vote.</p>',
				),
			)
		);
		( new Members_Repository() )->create(
			array(
				'name'  => 'Voter',
				'email' => 'voter@example.com',
				'grade' => 'beginner',
			)
		);
		$this->colour_at( Competition_Workflow::STAGE_PREVIEWED );

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

		$this->assertStringNotContainsString( 'job_id=', $location );
		$this->assertSame( Competition_Workflow::STAGE_VOTING, $this->colour_stage() );
		$this->assertSame(
			'0',
			$wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE option_name LIKE %s',
					$wpdb->options,
					$wpdb->esc_like( Email_Job_Manager::OPTION_PREFIX ) . '%'
				)
			)
		);
	}

	/**
	 * Opening is blocked when another active competition already has voting open.
	 */
	public function test_open_category_voting_blocked_when_another_open(): void {
		$other = $this->insert_overlapping_competition( 'Other', 'other', array( 'created_at' => '2020-01-01 00:00:00' ) );
		Entry_Fixtures::insert_entry( $other, 'colour', 2, array() );
		Workflow_Fixtures::set_stage( $other, 'colour', Competition_Workflow::STAGE_VOTING );
		$this->colour_at( Competition_Workflow::STAGE_PREVIEWED );

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

		$this->assertContains( 'another_category_voting', $this->settings_error_codes( 'photo_competition_voting' ) );
		$this->assertSame( Competition_Workflow::STAGE_PREVIEWED, $this->colour_stage() );
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
	 * Closing voting moves the category on to critique.
	 */
	public function test_close_category_voting_success(): void {
		$this->colour_at( Competition_Workflow::STAGE_VOTING );
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

		$this->assertSame( Competition_Workflow::STAGE_CRITIQUE, $this->colour_stage() );
	}

	/**
	 * Resetting with clear_votes=0 returns to step 1 and keeps votes and tokens.
	 */
	public function test_reset_category_keeps_votes(): void {
		$this->colour_at( Competition_Workflow::STAGE_CRITIQUE );
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
		$this->assertSame( Competition_Workflow::STAGE_NOT_STARTED, $this->colour_stage() );
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
	 * Showing results publishes them.
	 */
	public function test_show_results_makes_visible(): void {
		Workflow_Fixtures::close_uploads( $this->competition_id );
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
		$this->assertTrue( ( new Competition_Workflow() )->results_published( $this->competitions->find( $this->competition_id ) ) );
	}

	/**
	 * Hiding results unpublishes them.
	 */
	public function test_hide_results_makes_hidden(): void {
		Workflow_Fixtures::publish_results( $this->competition_id );
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
		$this->assertFalse( ( new Competition_Workflow() )->results_published( $this->competitions->find( $this->competition_id ) ) );
	}

	/**
	 * The AJAX step handler moves the category on after its preview.
	 */
	public function test_advance_step_updates_step(): void {
		$this->set_request(
			array(
				'competition_id' => $this->competition_id,
				'category_slug'  => 'colour',
				'step'           => 2,
			)
		);
		$this->set_nonce( 'photo_comp_voting_step' );

		$json = $this->capture_json(
			function () {
				$this->controller->handle_advance_step();
			}
		);

		$this->assertTrue( $json['success'] );
		$this->assertSame( Competition_Workflow::STAGE_PREVIEWED, $this->colour_stage() );
	}

	/**
	 * A crafted step can't skip opening voting (#121).
	 */
	public function test_advance_step_refuses_to_skip_opening_voting(): void {
		$this->colour_at( Competition_Workflow::STAGE_PREVIEWED );
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

		$this->assertFalse( $json['success'] );
		$this->assertSame( Competition_Workflow::STAGE_PREVIEWED, $this->colour_stage() );
	}

	/**
	 * Continuing after the critique marks the category done.
	 */
	public function test_advance_step_6_records_voted_category(): void {
		$this->colour_at( Competition_Workflow::STAGE_CRITIQUE );
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
		$this->assertSame( Competition_Workflow::STAGE_DONE, $this->colour_stage() );
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

	/**
	 * Insert an older competition that overlaps the current one, as saved
	 * before only one could be open. Both have no open date, so the one
	 * from set_up() is current because it was created later.
	 *
	 * @return int Competition ID.
	 */
	private function insert_older_open_competition(): int {
		return $this->insert_overlapping_competition(
			'Old Show',
			'old-show',
			array( 'created_at' => '2020-01-01 00:00:00' )
		);
	}

	/**
	 * Voting actions available from the Voting Controls page.
	 *
	 * @return array<string, array{0:string,1:string,2:bool}> Action, nonce prefix, whether it takes a category.
	 */
	public function voting_actions(): array {
		return array(
			'open voting'    => array( 'open_category_voting', 'photo_competition_open_voting_', true ),
			'close voting'   => array( 'close_category_voting', 'photo_competition_close_voting_', true ),
			'reset category' => array( 'reset_category', 'photo_competition_reset_category_', true ),
			'show results'   => array( 'show_results', 'photo_competition_show_results_', false ),
			'hide results'   => array( 'hide_results', 'photo_competition_hide_results_', false ),
		);
	}

	/**
	 * A stale tab or bookmarked link can't act on a competition Voting
	 * Controls no longer shows and members can't reach.
	 *
	 * @dataProvider voting_actions
	 *
	 * @param string $action       Action name.
	 * @param string $nonce_prefix Nonce action prefix.
	 * @param bool   $has_category Whether the action takes a category.
	 */
	public function test_voting_action_refuses_competition_that_is_not_current( string $action, string $nonce_prefix, bool $has_category ): void {
		$older_id = $this->insert_older_open_competition();
		$before   = $this->competitions->find( $older_id )->workflow;
		$request  = array(
			'action'      => $action,
			'competition' => $older_id,
		);

		if ( $has_category ) {
			$request['category'] = 'colour';
		}

		$this->set_request( $request );
		$this->set_nonce( $nonce_prefix . $older_id . ( $has_category ? '_colour' : '' ) );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'competition_not_current', $this->settings_error_codes( 'photo_competition_voting' ) );
		$this->assertSame( $before, $this->competitions->find( $older_id )->workflow );
	}

	/**
	 * Another open competition doesn't stop the current one being run.
	 */
	public function test_voting_action_accepts_current_competition_when_another_is_open(): void {
		$this->insert_older_open_competition();
		Workflow_Fixtures::close_uploads( $this->competition_id );

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
	}

	/**
	 * The competition can reach its close date while the admin still has
	 * Voting Controls open, and the last steps must still work then.
	 */
	public function test_show_results_allowed_when_no_competition_is_current(): void {
		Workflow_Fixtures::close_uploads( $this->competition_id );
		$this->competitions->update( $this->competition_id, array( 'close_date' => '2020-02-01 00:00:00' ) );

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
	}

	/**
	 * The slideshow's step button can't advance a competition that isn't current.
	 */
	public function test_advance_step_refuses_competition_that_is_not_current(): void {
		$older_id = $this->insert_older_open_competition();

		$this->set_request(
			array(
				'competition_id' => $older_id,
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

		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( "isn't the current competition", $json['data']['message'] );
		$this->assertNull( $this->competitions->find( $older_id )->workflow );
	}
}
