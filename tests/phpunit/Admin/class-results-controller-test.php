<?php
/**
 * Characterization tests for Results_Controller.
 *
 * Pins current behavior of the results action router (email /
 * send-results / export) ahead of a later refactor. Asserts observable
 * settings-error codes and redirect targets, not internals.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Results_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Email_Job_Manager;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Service\Results_Analytics;
use PhotoCompetitionManager\Service\Results_Ranking;
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;

use function PhotoCompetitionManager\Support\utc_time;

/**
 * Characterization tests for the results controller.
 *
 * @covers \PhotoCompetitionManager\Admin\Results_Controller
 */
class Results_Controller_Test extends Admin_Controller_Test_Case {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * Controller under test.
	 *
	 * @var Results_Controller
	 */
	private $controller;

	/**
	 * Seeded competition ID.
	 *
	 * @var int
	 */
	private $competition_id;

	/**
	 * Competitions made by create_dated_competition(), used to order their created_at.
	 *
	 * @var int
	 */
	private $created_count = 0;

	/**
	 * Set up the controller and a seeded competition.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->competitions = new Competitions_Repository();
		$this->images       = new Images_Repository();
		$this->members      = new Members_Repository();
		$votes              = new Votes_Repository();

		$analytics   = new Results_Analytics( $this->competitions, $this->images, $this->members, $votes );
		$ranking     = new Results_Ranking( $this->images, $votes, $this->members );
		$email       = new Email_Service();
		$job_manager = new Email_Job_Manager(
			$this->competitions,
			$this->images,
			$this->members,
			$votes,
			$analytics,
			$ranking,
			$email
		);

		$this->controller = new Results_Controller(
			$this->competitions,
			$this->images,
			$this->members,
			$votes,
			$analytics,
			$ranking,
			$job_manager
		);

		$this->competition_id = $this->create_competition();
	}

	/**
	 * Create a competition and return its ID.
	 *
	 * @param array<string, mixed> $overrides Field overrides (share_hash, settings, etc.).
	 * @return int Competition ID.
	 */
	private function create_competition( array $overrides = array() ): int {
		return $this->competitions->create(
			array_merge(
				array(
					'title'      => 'Spring Show',
					'slug'       => 'spring-show-' . wp_generate_password( 6, false ),
					// A past, closed range is clock-independent and never overlaps,
					// so tests can create as many as they need.
					'open_date'  => '2020-01-01 00:00:00',
					'close_date' => '2020-02-01 00:00:00',
					'settings'   => array(),
				),
				$overrides
			)
		);
	}

	/**
	 * Create a competition with no dates, so it's open and its results are
	 * worked out from the votes until they're published.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return int Competition ID.
	 */
	private function create_open_competition( array $overrides = array() ): int {
		return $this->create_competition(
			array(
				'open_date'  => null,
				'close_date' => null,
			) + $overrides
		);
	}

	/**
	 * Seed a member and an image they submitted in a category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return int Member ID.
	 */
	private function seed_member_with_image( int $competition_id, string $category ): int {
		$member_id = $this->members->create(
			array(
				'name'      => 'Ada Member',
				'email'     => 'ada+' . wp_generate_password( 6, false ) . '@example.com',
				'grade'     => 'beginner',
				'active'    => 1,
				'committee' => 1,
			)
		);

		$this->images->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $member_id,
				'category'       => $category,
				'filename'       => 'photo.jpg',
			)
		);

		return (int) $member_id;
	}

	/*
	 * -------------------------------------------------------------------------
	 * Capability guard.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Without the capability, handle_actions() is a no-op (no error, no redirect).
	 */
	public function test_handle_actions_noop_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->set_request(
			array(
				'action'      => 'email_results',
				'competition' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_email_results_' . $this->competition_id );

		// Should simply return without redirecting or recording an error.
		$this->controller->handle_actions();

		$this->assertSame( array(), $this->settings_error_codes( 'photo_competition_results' ) );
	}

	/*
	 * -------------------------------------------------------------------------
	 * email_results (background job).
	 * -------------------------------------------------------------------------
	 */

	/**
	 * When members have submissions, a job is created and the user is redirected
	 * to the processing view carrying the job id.
	 */
	public function test_email_results_success_redirects_with_job(): void {
		$this->seed_member_with_image( $this->competition_id, 'colour' );
		Workflow_Fixtures::publish_results( $this->competition_id );

		$this->set_request(
			array(
				'action'      => 'email_results',
				'competition' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_email_results_' . $this->competition_id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager-results', $location );
		$this->assertStringContainsString( 'status=processing', $location );
		$this->assertStringContainsString( 'job_id=', $location );
		// Success path does not record a settings error.
		$this->assertSame( array(), $this->settings_error_codes( 'photo_competition_results' ) );
	}

	/**
	 * With no members/submissions the job cannot be created; an error is recorded
	 * and the user is redirected back to the competition.
	 */
	public function test_email_results_no_members_records_error(): void {
		Workflow_Fixtures::publish_results( $this->competition_id );
		$this->set_request(
			array(
				'action'      => 'email_results',
				'competition' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_email_results_' . $this->competition_id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'email_job_failed', $this->settings_error_codes( 'photo_competition_results' ) );
		$this->assertStringContainsString( 'competition=' . $this->competition_id, $location );
	}

	/**
	 * Detailed results wait for Show Results, so the position each entrant
	 * is told is the recorded one.
	 */
	public function test_email_results_before_results_are_published_is_refused(): void {
		$id = $this->create_open_competition();
		$this->seed_member_with_image( $id, 'colour' );
		$this->set_request(
			array(
				'action'      => 'email_results',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_email_results_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'results_not_published', $this->settings_error_codes( 'photo_competition_results' ) );
		$this->assertStringNotContainsString( 'job_id=', $location );
		$this->assertStringContainsString( 'or once the competition has closed', implode( ' ', wp_list_pluck( get_settings_errors( 'photo_competition_results' ), 'message' ) ) );
	}

	/**
	 * A competition that closed without anyone pressing Show Results is read
	 * from its record, so its results can be emailed.
	 */
	public function test_email_results_once_the_competition_has_closed_is_allowed(): void {
		$this->seed_member_with_image( $this->competition_id, 'colour' );
		$this->set_request(
			array(
				'action'      => 'email_results',
				'competition' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_email_results_' . $this->competition_id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'job_id=', $location );
		$this->assertSame( array(), $this->settings_error_codes( 'photo_competition_results' ) );
	}

	/**
	 * A missing/invalid nonce aborts the email action via wp_die().
	 */
	public function test_email_results_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'action'      => 'email_results',
				'competition' => $this->competition_id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -------------------------------------------------------------------------
	 * send_results_committee / send_results_all (share link).
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Sending the results link to committee members succeeds when a share hash
	 * and results-page URL are configured.
	 */
	public function test_send_results_committee_success(): void {
		$id = $this->create_competition(
			array(
				'share_hash' => 'abc123hash',
				'settings'   => array( 'urls' => array( 'results_page' => 'https://example.com/results' ) ),
			)
		);
		$this->seed_member_with_image( $id, 'colour' );

		$this->set_request(
			array(
				'action'      => 'send_results_committee',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_send_results_committee_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'competition=' . $id, $location );
		$this->assertStringContainsString( 'job_id=email_job_', $location );
		$this->assertSame( array(), $this->settings_error_codes( 'photo_competition_results' ) );
	}

	/**
	 * The results link is built on the club's results page when the
	 * competition has none, ahead of a page holding the results shortcode.
	 */
	public function test_send_results_links_the_clubs_results_page(): void {
		update_option( 'photo_comp_default_settings', wp_json_encode( array( 'urls' => array( 'results_page' => 'https://example.com/club-results/' ) ) ) );
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[competition_results]',
			)
		);
		$id = $this->create_competition( array( 'share_hash' => 'abc123hash' ) );
		$this->seed_member_with_image( $id, 'colour' );
		Workflow_Fixtures::publish_results( $id );

		$this->set_request(
			array(
				'action'      => 'send_results_all',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_send_results_all_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$job = ( new \PhotoCompetitionManager\Dependencies() )->email_job_manager->get_job( $query['job_id'] );

		$this->assertSame( 'https://example.com/club-results/?share=abc123hash', $job['args']['share_url'] );
	}

	/**
	 * Sending the results link to all active members succeeds under the same
	 * preconditions (covers the send_results_all branch).
	 */
	public function test_send_results_all_success(): void {
		$id = $this->create_competition(
			array(
				'share_hash' => 'def456hash',
				'settings'   => array( 'urls' => array( 'results_page' => 'https://example.com/results' ) ),
			)
		);
		$this->seed_member_with_image( $id, 'colour' );
		Workflow_Fixtures::publish_results( $id );

		$this->set_request(
			array(
				'action'      => 'send_results_all',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_send_results_all_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'job_id=email_job_', $location );
	}

	/**
	 * The results link goes to every member only once results are published.
	 */
	public function test_send_results_all_before_results_are_published_is_refused(): void {
		$id = $this->create_open_competition(
			array(
				'share_hash' => 'jkl012hash',
				'settings'   => array( 'urls' => array( 'results_page' => 'https://example.com/results' ) ),
			)
		);
		$this->seed_member_with_image( $id, 'colour' );

		$this->set_request(
			array(
				'action'      => 'send_results_all',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_send_results_all_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'results_not_published', $this->settings_error_codes( 'photo_competition_results' ) );
		$this->assertStringNotContainsString( 'job_id=', $location );
	}

	/**
	 * Once the competition has closed, its results are read from the
	 * record, so every member can be sent the link.
	 */
	public function test_send_results_all_once_the_competition_has_closed_is_allowed(): void {
		$id = $this->create_competition(
			array(
				'share_hash' => 'closedhash',
				'settings'   => array( 'urls' => array( 'results_page' => 'https://example.com/results' ) ),
			)
		);
		$this->seed_member_with_image( $id, 'colour' );

		$this->set_request(
			array(
				'action'      => 'send_results_all',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_send_results_all_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'job_id=email_job_', $location );
		$this->assertSame( array(), $this->settings_error_codes( 'photo_competition_results' ) );
	}

	/**
	 * With nobody to email, no job is queued and an error is recorded.
	 */
	public function test_send_results_no_recipients(): void {
		$id = $this->create_competition(
			array(
				'share_hash' => 'ghi789hash',
				'settings'   => array( 'urls' => array( 'results_page' => 'https://example.com/results' ) ),
			)
		);

		$this->set_request(
			array(
				'action'      => 'send_results_committee',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_send_results_committee_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringNotContainsString( 'job_id=', $location );
		$this->assertContains( 'no_recipients', $this->settings_error_codes( 'photo_competition_results' ) );
	}

	/**
	 * A missing competition yields a not-found error.
	 */
	public function test_send_results_competition_not_found(): void {
		$missing = 999999;

		$this->set_request(
			array(
				'action'      => 'send_results_committee',
				'competition' => $missing,
			)
		);
		$this->set_nonce( 'photo_competition_send_results_committee_' . $missing );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'competition_not_found', $this->settings_error_codes( 'photo_competition_results' ) );
	}

	/**
	 * A competition without a share hash is rejected.
	 */
	public function test_send_results_no_share_hash(): void {
		$id = $this->create_competition(
			array( 'settings' => array( 'urls' => array( 'results_page' => 'https://example.com/results' ) ) )
		);

		$this->set_request(
			array(
				'action'      => 'send_results_committee',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_send_results_committee_' . $id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'no_share_hash', $this->settings_error_codes( 'photo_competition_results' ) );
	}

	/**
	 * A competition with a share hash but no results-page URL is rejected.
	 */
	public function test_send_results_no_results_page(): void {
		$id = $this->create_competition( array( 'share_hash' => 'hash-no-url' ) );

		$this->set_request(
			array(
				'action'      => 'send_results_committee',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_send_results_committee_' . $id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'no_results_page', $this->settings_error_codes( 'photo_competition_results' ) );
	}

	/**
	 * A missing/invalid nonce aborts the send-results action via wp_die().
	 */
	public function test_send_results_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'action'      => 'send_results_committee',
				'competition' => $this->competition_id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -------------------------------------------------------------------------
	 * export_results_csv.
	 * -------------------------------------------------------------------------
	 *
	 * The success path streams a CSV to php://output, sends headers, then the
	 * router calls exit; that is not observable in PHPUnit, so only the
	 * failure paths (bad nonce, missing competition -> wp_die) are pinned here.
	 */

	/**
	 * Exporting a missing competition aborts via wp_die().
	 */
	public function test_export_results_csv_competition_not_found_dies(): void {
		$missing = 999999;

		$this->set_request(
			array(
				'action'      => 'export_results_csv',
				'competition' => $missing,
			)
		);
		$this->set_nonce( 'photo_competition_export_results_' . $missing );

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/**
	 * A missing/invalid nonce aborts the export action via wp_die().
	 */
	public function test_export_results_csv_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'action'      => 'export_results_csv',
				'competition' => $this->competition_id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/**
	 * Seed a graded member with one scored image in a category.
	 *
	 * @param string $name     Member name.
	 * @param string $grade    Grade slug.
	 * @param string $category Category slug.
	 * @param int    $score    Total score, given as one vote.
	 * @return void
	 */
	private function seed_scored_entry( string $name, string $grade, string $category, int $score ): void {
		// Any grade, so a test can seed one that isn't in the club's list.
		Entry_Fixtures::insert_scored_entry( $this->competition_id, $category, $name, $grade, array( $score ) );
	}

	/**
	 * Map export rows to "grade|category|rank|member" strings, dropping the header.
	 *
	 * @param array<int, array<int, mixed>> $rows Export rows.
	 * @return array<int, string>
	 */
	private function summarize_export_rows( array $rows ): array {
		$this->assertSame( array( 'Competition', 'Grade', 'Category', 'Rank' ), array_slice( $rows[0], 0, 4 ) );

		return array_map(
			static function ( array $row ): string {
				return implode( '|', array( $row[1], $row[2], $row[3], $row[5] ) );
			},
			array_slice( $rows, 1 )
		);
	}

	/**
	 * Rows are grouped by grade (configured order), then category; ranks restart
	 * for each grade within each category.
	 */
	public function test_export_rows_rank_within_grade_and_category(): void {
		$this->competition_id = $this->create_competition(
			array(
				'settings' => array(
					'categories' => array(
						array(
							'slug'  => 'open',
							'label' => 'Open',
						),
						array(
							'slug'  => 'mono',
							'label' => 'Mono',
						),
					),
					'grades'     => array(
						array(
							'slug'  => 'beginner',
							'label' => 'Beginner',
						),
						array(
							'slug'  => 'advanced',
							'label' => 'Advanced',
						),
					),
				),
			)
		);

		$this->seed_scored_entry( 'Adv High', 'advanced', 'open', 50 );
		$this->seed_scored_entry( 'Beg High', 'beginner', 'open', 40 );
		$this->seed_scored_entry( 'Adv Low', 'advanced', 'open', 30 );
		$this->seed_scored_entry( 'Beg Low', 'beginner', 'open', 20 );
		$this->seed_scored_entry( 'Mono Adv', 'advanced', 'mono', 10 );

		$rows = $this->controller->get_export_rows( $this->competitions->find( $this->competition_id ) );

		$this->assertSame(
			array(
				'Beginner|Open|1|Beg High',
				'Beginner|Open|2|Beg Low',
				'Advanced|Open|1|Adv High',
				'Advanced|Open|2|Adv Low',
				'Advanced|Mono|1|Mono Adv',
			),
			$this->summarize_export_rows( $rows )
		);
	}

	/**
	 * Export uses the club's grade labels, not a list the competition has
	 * stored from before.
	 */
	public function test_export_rows_use_club_grades_not_stored_competition_grades(): void {
		$this->competition_id = $this->create_competition(
			array(
				'settings' => array(
					'categories' => array(
						array(
							'slug'  => 'open',
							'label' => 'Open',
						),
					),
					'grades'     => array(
						array(
							'slug'  => 'beginner',
							'label' => 'Stale Label',
						),
					),
				),
			)
		);

		$this->seed_scored_entry( 'Starter', 'beginner', 'open', 10 );
		$this->seed_scored_entry( 'Senior', 'advanced', 'open', 20 );

		$rows = $this->controller->get_export_rows( $this->competitions->find( $this->competition_id ) );

		$this->assertSame(
			array(
				'Beginner|Open|1|Starter',
				'Advanced|Open|1|Senior',
			),
			$this->summarize_export_rows( $rows )
		);
	}

	/**
	 * Tied scores share a rank, and entrants with an unconfigured grade are
	 * exported under "Ungraded" rather than dropped.
	 */
	public function test_export_rows_ties_share_rank_and_unknown_grade_kept(): void {
		$this->competition_id = $this->create_competition(
			array(
				'settings' => array(
					'categories' => array(
						array(
							'slug'  => 'open',
							'label' => 'Open',
						),
					),
					'grades'     => array(
						array(
							'slug'  => 'beginner',
							'label' => 'Beginner',
						),
					),
				),
			)
		);

		$this->seed_scored_entry( 'Tie One', 'beginner', 'open', 40 );
		$this->seed_scored_entry( 'Tie Two', 'beginner', 'open', 40 );
		$this->seed_scored_entry( 'Third', 'beginner', 'open', 10 );
		$this->seed_scored_entry( 'Orphan', 'retired-grade', 'open', 99 );

		$rows = $this->controller->get_export_rows( $this->competitions->find( $this->competition_id ) );

		$this->assertSame(
			array(
				'Beginner|Open|1|Tie One',
				'Beginner|Open|1|Tie Two',
				'Beginner|Open|2|Third',
				'Ungraded|Open|1|Orphan',
			),
			$this->summarize_export_rows( $rows )
		);
	}

	/**
	 * A member deleted after results are recorded is exported in their place
	 * as a former member, with no email or image.
	 */
	public function test_export_rows_keep_a_deleted_members_entry_as_a_former_member(): void {
		$this->seed_scored_entry( 'Winner', 'beginner', 'colour', 9 );
		$this->seed_scored_entry( 'Runner Up', 'beginner', 'colour', 5 );
		$competition = $this->competitions->find( $this->competition_id );
		$this->controller->get_export_rows( $competition );
		$winner = $this->members->find_by_email( 'winner@example.com' );

		( new Entries() )->remove_member_entries( Actor::admin(), (int) $winner->id );
		$this->members->delete( (int) $winner->id );
		$rows = $this->controller->get_export_rows( $competition );

		$this->assertSame(
			array(
				'Beginner|Colour|1|Former member',
				'Beginner|Colour|2|Runner Up',
			),
			$this->summarize_export_rows( $rows )
		);
		$this->assertSame( array( '', '', '' ), array( $rows[1][4], $rows[1][6], $rows[1][9] ) );
	}

	/**
	 * Before results are recorded, an entry whose member is missing is an
	 * ungraded data error, not a former member's, so it's exported with no
	 * member name.
	 */
	public function test_export_rows_give_no_name_to_a_missing_member_before_results_are_recorded(): void {
		$this->competition_id = $this->create_open_competition();
		$this->seed_scored_entry( 'Winner', 'beginner', 'colour', 9 );
		$this->images->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => 999999,
				'category'       => 'colour',
				'filename'       => 'orphan.jpg',
			)
		);

		$rows = $this->controller->get_export_rows( $this->competitions->find( $this->competition_id ) );

		$this->assertSame(
			array(
				'Beginner|Colour|1|Winner',
				'Ungraded|Colour|1|',
			),
			$this->summarize_export_rows( $rows )
		);
	}

	/*
	 * -------------------------------------------------------------------------
	 * Default competition (no ?competition= in the URL).
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Results night: next month's competition was created a few days early.
	 * The screen opens on the competition being judged, not the newest one.
	 */
	public function test_default_is_the_current_competition_not_the_newest(): void {
		$this->create_dated_competition( 'This Month', -3, 1 );
		$this->create_dated_competition( 'Next Month', 25, 30 );

		$this->assertSame( 'This Month', $this->selected_competition_title() );
	}

	/**
	 * Between competitions nothing is current, and next month's competition
	 * hasn't opened, so the screen opens on the last one to open.
	 */
	public function test_default_between_competitions_is_the_latest_opened(): void {
		$this->create_dated_competition( 'Last Month', -10, -5 );
		$this->create_dated_competition( 'Next Month', 20, 25 );

		$this->assertSame( 'Last Month', $this->selected_competition_title() );
	}

	/**
	 * With every competition still to open, the screen falls back to the first
	 * one in the selector, the newest created.
	 */
	public function test_default_when_nothing_has_opened_is_the_first_in_the_selector(): void {
		// The set_up() competition opened in 2020.
		$this->competitions->archive( $this->competition_id );
		$this->create_dated_competition( 'Soon', 5, 10 );
		$this->create_dated_competition( 'Later', 20, 25 );

		$this->assertSame( 'Later', $this->selected_competition_title() );
	}

	public function test_competition_in_the_url_overrides_the_default(): void {
		$this->create_dated_competition( 'This Month', -3, 1 );
		$chosen_id = $this->create_dated_competition( 'Next Month', 25, 30 );

		$this->set_request( array( 'competition' => (string) $chosen_id ) );

		$this->assertSame( 'Next Month', $this->selected_competition_title() );
	}

	/**
	 * Create a competition open from $opens_in to $closes_in days from now.
	 *
	 * Each one is stamped as created after every competition before it,
	 * including the set_up() one, so creation order follows call order.
	 * Those created_at times are in the future, which is only safe because
	 * every competition here has an open date: without one, it would count
	 * as not yet opened.
	 *
	 * @param string $title     Title.
	 * @param int    $opens_in  Days from now until it opens (negative: in the past).
	 * @param int    $closes_in Days from now until it closes.
	 * @return int Competition ID.
	 */
	private function create_dated_competition( string $title, int $opens_in, int $closes_in ): int {
		global $wpdb;

		$id = $this->create_competition(
			array(
				'title'      => $title,
				'open_date'  => utc_time( $opens_in * DAY_IN_SECONDS ),
				'close_date' => utc_time( $closes_in * DAY_IN_SECONDS ),
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->competitions->table(), array( 'created_at' => utc_time( ++$this->created_count * MINUTE_IN_SECONDS ) ), array( 'id' => $id ) );

		return $id;
	}

	/**
	 * Render the Results screen and return the title selected in its competition selector.
	 *
	 * @return string|null
	 */
	private function selected_competition_title(): ?string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		return preg_match( '#<option[^>]* selected>([^<]+)</option>#', $html, $match ) ? $match[1] : null;
	}
}
