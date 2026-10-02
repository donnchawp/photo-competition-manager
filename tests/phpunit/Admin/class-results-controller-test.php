<?php
/**
 * Characterization tests for Results_Controller.
 *
 * Pins current behavior of the results action router (recalculate / email /
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
use PhotoCompetitionManager\Service\Score_Calculator;

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
	 * Set up the controller and a seeded competition.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->competitions = new Competitions_Repository();
		$this->images       = new Images_Repository();
		$this->members      = new Members_Repository();
		$votes              = new Votes_Repository();

		$analytics   = new Results_Analytics( $this->competitions, $this->images, $this->members, $votes );
		$calculator  = new Score_Calculator( $this->images, $votes );
		$email       = new Email_Service();
		$job_manager = new Email_Job_Manager(
			$this->competitions,
			$this->images,
			$this->members,
			$votes,
			$analytics,
			$calculator,
			$email
		);

		$this->controller = new Results_Controller(
			$this->competitions,
			$this->images,
			$this->members,
			$votes,
			$analytics,
			$calculator,
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
					// Null dates keep is_open() clock-independent, consistent with the
					// other admin-controller suites (no date-gated behavior here today).
					'open_date'  => null,
					'close_date' => null,
					'settings'   => array(),
				),
				$overrides
			)
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
				'grade'     => 'a',
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
				'action'      => 'recalculate_scores',
				'competition' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_recalculate_scores_' . $this->competition_id );

		// Should simply return without redirecting or recording an error.
		$this->controller->handle_actions();

		$this->assertSame( array(), $this->settings_error_codes( 'photo_competition_results' ) );
	}

	/*
	 * -------------------------------------------------------------------------
	 * recalculate_scores.
	 * -------------------------------------------------------------------------
	 */

	/**
	 * Recalculating scores reports success and redirects back to the competition.
	 */
	public function test_recalculate_scores_success(): void {
		$this->set_request(
			array(
				'action'      => 'recalculate_scores',
				'competition' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_recalculate_scores_' . $this->competition_id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager-results', $location );
		$this->assertStringContainsString( 'competition=' . $this->competition_id, $location );
		$this->assertContains( 'scores_recalculated', $this->settings_error_codes( 'photo_competition_results' ) );
	}

	/**
	 * A missing/invalid nonce aborts recalculation via wp_die().
	 */
	public function test_recalculate_scores_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'action'      => 'recalculate_scores',
				'competition' => $this->competition_id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/**
	 * Recalculating a missing competition yields a not-found error, not success.
	 */
	public function test_recalculate_scores_competition_not_found(): void {
		$missing = 999999;

		$this->set_request(
			array(
				'action'      => 'recalculate_scores',
				'competition' => $missing,
			)
		);
		$this->set_nonce( 'photo_competition_recalculate_scores_' . $missing );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$codes = $this->settings_error_codes( 'photo_competition_results' );
		$this->assertContains( 'competition_not_found', $codes );
		$this->assertNotContains( 'scores_recalculated', $codes );
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
	 * @param int    $score    Cached image score (used when there are no votes).
	 * @return void
	 */
	private function seed_scored_entry( string $name, string $grade, string $category, int $score ): void {
		$member_id = $this->members->create(
			array(
				'name'   => $name,
				'email'  => sanitize_title( $name ) . '@example.com',
				'grade'  => $grade,
				'active' => 1,
			)
		);

		$image_id = $this->images->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $member_id,
				'category'       => $category,
				'filename'       => sanitize_title( $name ) . '.jpg',
			)
		);

		$this->images->update_score( (int) $image_id, $score );
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
}
