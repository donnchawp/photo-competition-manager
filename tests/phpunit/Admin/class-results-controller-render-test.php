<?php
/**
 * Golden-master snapshot tests for Results_Controller::render().
 *
 * Pins the exact rendered HTML ahead of the template-partial extraction (#40).
 * Nonces, wall-clock vote timestamps, and non-rollback auto-increment IDs are
 * normalized so snapshots do not churn per run. Rendered ORDER (rankings) is
 * NOT normalized -- deterministic seed data (distinct/explicit scores and
 * random_numbers, forced created_at where more than one row would otherwise
 * tie) pins ranking order by fixture content instead.
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
use PhotoCompetitionManager\Service\Email_Results_Job_Manager;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Service\Results_Analytics;
use PhotoCompetitionManager\Service\Score_Calculator;

/**
 * @covers \PhotoCompetitionManager\Admin\Results_Controller
 */
class Results_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Competitions_Repository */
	private $competitions;

	/** @var Images_Repository */
	private $images;

	/** @var Members_Repository */
	private $members;

	/** @var Votes_Repository */
	private $votes;

	/** @var Results_Controller */
	private $controller;

	/**
	 * Files written to the uploads directory during a test, cleaned up in tear_down().
	 *
	 * @var array<int,string>
	 */
	private $written_files = array();

	public function set_up(): void {
		parent::set_up();

		$this->competitions = new Competitions_Repository();
		$this->images        = new Images_Repository();
		$this->members       = new Members_Repository();
		$this->votes          = new Votes_Repository();

		$analytics   = new Results_Analytics( $this->competitions, $this->images, $this->members, $this->votes );
		$calculator  = new Score_Calculator( $this->images, $this->votes );
		$email       = new Email_Service();
		$job_manager = new Email_Results_Job_Manager(
			$this->competitions,
			$this->images,
			$this->members,
			$this->votes,
			$analytics,
			$calculator,
			$email
		);

		$this->controller = new Results_Controller(
			$this->competitions,
			$this->images,
			$this->members,
			$this->votes,
			$analytics,
			$calculator,
			$email,
			$job_manager
		);
	}

	public function tear_down(): void {
		foreach ( $this->written_files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$this->written_files = array();
		parent::tear_down();
	}

	/**
	 * Render the page and return normalized HTML.
	 *
	 * @param array<int,int> $competition_ids Competition IDs to normalize; these
	 *                                        are not stable across test runs
	 *                                        because MySQL does not roll back
	 *                                        auto-increment counters with the
	 *                                        transactional test rollback, so
	 *                                        byte-exact comparison would
	 *                                        otherwise churn on every run.
	 * @param array<int,int> $member_ids      Member IDs to normalize, same reason.
	 * @param array<int,int> $image_ids       Image IDs to normalize, same reason.
	 */
	private function render_normalized( array $competition_ids = array(), array $member_ids = array(), array $image_ids = array() ): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		// Normalize per-run nonces embedded via wp_nonce_url() query args.
		$html = preg_replace( '/_wpnonce=[a-f0-9]{10}/', '_wpnonce=NONCE', $html );

		// Normalize the individual-votes table's "Timestamp" column:
		// Votes_Repository::create()/create_anonymous() always stamp created_at
		// with the current time, so the formatted date/time is wall-clock
		// dependent and would otherwise churn between the two required runs.
		$html = preg_replace( '/<td>[A-Z][a-z]+ \d{1,2}, \d{4} \d{1,2}:\d{2} (?:am|pm)<\/td><\/tr>/', '<td>VOTE_TIME</td></tr>', $html );

		// Normalize non-deterministic auto-increment competition IDs, scoped to
		// the "competition=" query-arg contexts (selector option values, tab
		// links, action nonced links, detail links) and the selector option's
		// value attribute. A document-wide digit replacement is too broad and
		// would collide with unrelated numbers (scores, vote counts, image
		// numbers) elsewhere on the page.
		foreach ( $competition_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			$html       = preg_replace( '/competition=' . $id_pattern . '(?!\d)/', 'competition=ID', $html );
		}

		// Normalize non-deterministic auto-increment image IDs, scoped to the
		// "image=" query-arg context in the per-row "View Details" link.
		foreach ( $image_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			$html       = preg_replace( '/image=' . $id_pattern . '(?!\d)/', 'image=ID', $html );
		}

		// Member IDs never appear in this controller's markup (results are
		// keyed by image, not member), so $member_ids is accepted for call-site
		// symmetry with the other rollout suites but intentionally unused here.
		unset( $member_ids );

		return $html;
	}

	/**
	 * Assert live output equals the stored snapshot; write it on first run.
	 *
	 * @param string         $scenario        Snapshot scenario name.
	 * @param array<int,int> $competition_ids Competition IDs to normalize before comparing.
	 * @param array<int,int> $member_ids      Member IDs to normalize before comparing.
	 * @param array<int,int> $image_ids       Image IDs to normalize before comparing.
	 */
	private function assert_matches_snapshot( string $scenario, array $competition_ids = array(), array $member_ids = array(), array $image_ids = array() ): void {
		$dir  = __DIR__ . '/../fixtures/results-render';
		$file = $dir . '/' . $scenario . '.html';
		$html = $this->render_normalized( $competition_ids, $member_ids, $image_ids );

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
	 * Seed a competition and return its ID.
	 *
	 * @param string      $title      Title.
	 * @param string      $slug       Slug.
	 * @param array       $overrides  Field overrides (settings, share_hash).
	 * @param string|null $created_at Explicit created_at to force a deterministic
	 *                                Competitions_Repository::all() DESC ordering
	 *                                when a scenario seeds more than one
	 *                                competition; MySQL does not guarantee
	 *                                tie-break order for equal auto-assigned
	 *                                timestamps.
	 */
	private function seed_competition( string $title, string $slug, array $overrides = array(), ?string $created_at = null ): int {
		$id = $this->competitions->create(
			array_merge(
				array(
					'title'      => $title,
					'slug'       => $slug,
					'open_date'  => null,
					'close_date' => null,
					'settings'   => array(),
				),
				$overrides
			)
		);

		if ( null !== $created_at ) {
			$this->force_created_at( $this->competitions->table(), (int) $id, $created_at );
		}

		return (int) $id;
	}

	/**
	 * Force a row's created_at directly, bypassing repository timestamp
	 * auto-assignment, to make ORDER BY created_at DESC deterministic in tests.
	 *
	 * @param string $table      Table name.
	 * @param int    $id         Row ID.
	 * @param string $created_at Timestamp to set.
	 */
	private function force_created_at( string $table, int $id, string $created_at ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'created_at' => $created_at ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Seed a member and return its ID.
	 *
	 * @param string               $name      Member name.
	 * @param string               $email     Member email.
	 * @param array<string, mixed> $overrides Field overrides (grade, active, committee).
	 */
	private function seed_member( string $name, string $email, array $overrides = array() ): int {
		return (int) $this->members->create(
			array_merge(
				array(
					'name'  => $name,
					'email' => $email,
					'grade' => 'beginner',
				),
				$overrides
			)
		);
	}

	/**
	 * Seed an image record and return its ID.
	 *
	 * @param int   $competition_id Competition ID.
	 * @param array $overrides      Field overrides.
	 */
	private function seed_image( int $competition_id, array $overrides = array() ): int {
		$data = array_merge(
			array(
				'competition_id' => $competition_id,
				'category'       => 'colour',
				'filename'       => 'photo.jpg',
				'random_number'  => 1,
			),
			$overrides
		);

		return (int) $this->images->create( $data );
	}

	/**
	 * Write a thumbnail file to the uploads dir so the results table renders
	 * the "thumbnail available" <img> branch instead of the dashicon fallback.
	 *
	 * @param string $slug     Competition slug.
	 * @param string $category Category slug.
	 * @param string $filename Image filename.
	 */
	private function write_thumbnail_file( string $slug, string $category, string $filename ): void {
		$uploads = wp_upload_dir();
		$folder  = trailingslashit( $uploads['basedir'] ) . 'competitions/' . $slug . '/' . $category;

		wp_mkdir_p( $folder );

		$info  = pathinfo( $filename );
		$base  = $info['filename'] ?? $filename;
		$ext   = isset( $info['extension'] ) && '' !== $info['extension'] ? '.' . $info['extension'] : '';
		$thumb = $base . '-thumb' . $ext;

		$thumb_path = trailingslashit( $folder ) . $thumb;

		file_put_contents( $thumb_path, 'thumb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->written_files[] = $thumb_path;
	}

	/**
	 * Seed an email-results job option directly, bypassing create_job(), so
	 * display_email_job_notice() branches can be exercised without needing
	 * real submissions.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $data   Job data overrides.
	 */
	private function seed_job( string $job_id, array $data ): void {
		update_option(
			'photo_comp_email_job_' . $job_id,
			array_merge(
				array(
					'job_id'         => $job_id,
					'competition_id' => 0,
					'member_ids'     => array( 1 ),
					'processed_ids'  => array(),
					'status'         => 'pending',
					'total_count'    => 1,
					'sent_count'     => 0,
					'failed_count'   => 0,
					'error_log'      => array(),
					'started_at'     => '2026-01-01 00:00:00',
					'completed_at'   => null,
				),
				$data
			),
			false
		);
	}

	/*
	 * -----------------------------------------------------------------
	 * Scenarios.
	 * -----------------------------------------------------------------
	 */

	public function test_render_no_competitions(): void {
		// No competitions created at all: empty state, nothing else renders.
		$this->assert_matches_snapshot( 'no-competitions' );
	}

	public function test_render_competition_not_found(): void {
		// A competition exists (so the "no competitions" branch is not hit),
		// but the requested ID does not resolve: "Competition not found" branch.
		$comp_id = $this->seed_competition( 'Spring Show', 'spring-show' );

		$this->set_request( array( 'competition' => '999999' ) );

		$this->assert_matches_snapshot( 'competition-not-found', array( $comp_id ) );
	}

	public function test_render_overview_happy_path(): void {
		// Two competitions (explicit, distinct created_at to keep the
		// selector's ORDER BY created_at DESC list deterministic), default
		// categories/grades, a tied pair in one grade (dense ranking), a
		// distinct-score row in a second grade, and a third grade left with
		// no results (naturally skipped, no table rendered for it). One
		// image has a real thumbnail on disk (the <img> branch); the others
		// fall back to the dashicon placeholder. Share results section hits
		// the "share hash + results page configured" branch.
		$winter_id = $this->seed_competition( 'Winter Salon', 'winter-salon', array(), '2026-02-01 00:00:00' );
		$spring_id = $this->seed_competition(
			'Spring Show',
			'spring-show',
			array(
				'share_hash' => 'abc123sharehash',
				'settings'   => array( 'urls' => array( 'results_page' => 'https://example.com/results' ) ),
			),
			'2026-01-01 00:00:00'
		);

		$ada   = $this->seed_member( 'Ada Lovelace', 'ada@example.com', array( 'grade' => 'beginner' ) );
		$bob   = $this->seed_member( 'Bob Baker', 'bob@example.com', array( 'grade' => 'beginner' ) );
		$carol = $this->seed_member( 'Carol Diaz', 'carol@example.com', array( 'grade' => 'beginner' ) );
		$dave  = $this->seed_member( 'Dave Evans', 'dave@example.com', array( 'grade' => 'intermediate' ) );

		$this->write_thumbnail_file( 'spring-show', 'colour', 'ada-sunset.jpg' );
		$ada_img = $this->seed_image(
			$spring_id,
			array(
				'member_id'     => $ada,
				'filename'      => 'ada-sunset.jpg',
				'random_number' => 1,
			)
		);
		$bob_img = $this->seed_image(
			$spring_id,
			array(
				'member_id'     => $bob,
				'filename'      => 'bob-lake.jpg',
				'random_number' => 2,
			)
		);
		$carol_img = $this->seed_image(
			$spring_id,
			array(
				'member_id'     => $carol,
				'filename'      => 'carol-hills.jpg',
				'random_number' => 3,
			)
		);
		$dave_img = $this->seed_image(
			$spring_id,
			array(
				'member_id'     => $dave,
				'filename'      => 'dave-forest.jpg',
				'random_number' => 4,
			)
		);

		// Ada and Bob tie at total_score 17 (rank 1); Carol trails at 9 (rank 2,
		// dense ranking -- not 3).
		$this->votes->create_anonymous( $spring_id, 'colour', 101, $ada_img, 9 );
		$this->votes->create_anonymous( $spring_id, 'colour', 102, $ada_img, 8 );
		$this->votes->create_anonymous( $spring_id, 'colour', 103, $bob_img, 9 );
		$this->votes->create_anonymous( $spring_id, 'colour', 104, $bob_img, 8 );
		$this->votes->create_anonymous( $spring_id, 'colour', 105, $carol_img, 5 );
		$this->votes->create_anonymous( $spring_id, 'colour', 106, $carol_img, 4 );

		// Dave (intermediate grade) is the sole entrant in his grade's table.
		$this->votes->create_anonymous( $spring_id, 'colour', 107, $dave_img, 7 );
		$this->votes->create_anonymous( $spring_id, 'colour', 108, $dave_img, 6 );

		$this->set_request(
			array(
				'competition' => (string) $spring_id,
				'category'    => 'colour',
			)
		);

		$this->assert_matches_snapshot(
			'overview-happy-path',
			array( $winter_id, $spring_id ),
			array(),
			array( $ada_img, $bob_img, $carol_img, $dave_img )
		);
	}

	public function test_render_overview_no_share_hash(): void {
		// No share_hash configured: "Generate a results link" message branch.
		// Category selected but no images/votes yet: breakdown renders as all
		// zeroes, and every grade's results table is skipped (no results).
		$comp_id = $this->seed_competition( 'Autumn Exhibition', 'autumn-exhibition' );

		$this->set_request(
			array(
				'competition' => (string) $comp_id,
				'category'    => 'colour',
			)
		);

		$this->assert_matches_snapshot( 'overview-no-share-hash', array( $comp_id ) );
	}

	public function test_render_overview_share_hash_no_results_page(): void {
		// share_hash configured but no results-page URL: the third share
		// branch ("No results page URL configured.").
		$comp_id = $this->seed_competition(
			'Summer Salon',
			'summer-salon',
			array( 'share_hash' => 'def456sharehash' )
		);

		$this->assert_matches_snapshot( 'overview-share-hash-no-results-page', array( $comp_id ) );
	}

	public function test_render_overview_no_categories(): void {
		// Empty categories array: the nav-tab-wrapper and the entire category
		// breakdown/results-table section are both skipped (selected_category
		// stays empty).
		$comp_id = $this->seed_competition(
			'No Categories Show',
			'no-categories-show',
			array( 'settings' => array( 'categories' => array() ) )
		);

		$this->assert_matches_snapshot( 'overview-no-categories', array( $comp_id ) );
	}

	public function test_render_image_details_happy_path(): void {
		// A member with an email and grade, plus one named voter and one
		// anonymous/token voter (the voter_name ?: 'Token #' fallback branch).
		$comp_id = $this->seed_competition( 'Spring Show', 'spring-show' );
		$ada     = $this->seed_member( 'Ada Lovelace', 'ada@example.com', array( 'grade' => 'advanced' ) );
		$image   = $this->seed_image(
			$comp_id,
			array(
				'member_id'     => $ada,
				'filename'      => 'ada-sunset.jpg',
				'random_number' => 3,
			)
		);

		$this->votes->create( $comp_id, 'colour', 'Grace Hopper', $image, 9 );
		$this->votes->create_anonymous( $comp_id, 'colour', 202, $image, 7 );

		$this->set_request(
			array(
				'competition' => (string) $comp_id,
				'image'       => (string) $image,
			)
		);

		$this->assert_matches_snapshot( 'image-details-happy-path', array( $comp_id ), array(), array( $image ) );
	}

	public function test_render_image_details_no_votes_no_member(): void {
		// The image's member_id does not resolve to any member row: both the
		// "Unknown" member-name fallback and the skipped email/grade
		// paragraphs. Zero votes: the statistics table renders all zeroes and
		// the "No votes recorded for this image." message is shown instead of
		// a votes table.
		$comp_id = $this->seed_competition( 'Spring Show', 'spring-show' );
		$image   = $this->seed_image(
			$comp_id,
			array(
				'member_id'     => 999999,
				'filename'      => 'orphan.jpg',
				'random_number' => 9,
			)
		);

		$this->set_request(
			array(
				'competition' => (string) $comp_id,
				'image'       => (string) $image,
			)
		);

		$this->assert_matches_snapshot( 'image-details-no-votes-no-member', array( $comp_id ), array(), array( $image ) );
	}

	public function test_render_image_details_not_found(): void {
		// image= does not resolve to any image: "Image not found" branch.
		$comp_id = $this->seed_competition( 'Spring Show', 'spring-show' );

		$this->set_request(
			array(
				'competition' => (string) $comp_id,
				'image'       => '999999',
			)
		);

		$this->assert_matches_snapshot( 'image-details-not-found', array( $comp_id ) );
	}

	public function test_render_email_job_notice_processing(): void {
		// A pending/processing job: progress notice + the auto-refresh meta tag.
		$comp_id = $this->seed_competition( 'Spring Show', 'spring-show' );

		$this->seed_job(
			'email_job_processing_test',
			array(
				'competition_id' => $comp_id,
				'status'         => 'processing',
				'total_count'    => 4,
				'processed_ids'  => array( 1, 2 ),
			)
		);

		$this->set_request(
			array(
				'competition' => (string) $comp_id,
				'job_id'      => 'email_job_processing_test',
			)
		);

		$this->assert_matches_snapshot( 'email-job-notice-processing', array( $comp_id ) );
	}

	public function test_render_email_job_notice_completed_with_failures(): void {
		// A completed job with some failures: success notice + failure count.
		$comp_id = $this->seed_competition( 'Spring Show', 'spring-show' );

		$this->seed_job(
			'email_job_completed_test',
			array(
				'competition_id' => $comp_id,
				'status'         => 'completed',
				'total_count'    => 4,
				'sent_count'     => 3,
				'failed_count'   => 1,
				'processed_ids'  => array( 1, 2, 3, 4 ),
			)
		);

		$this->set_request(
			array(
				'competition' => (string) $comp_id,
				'job_id'      => 'email_job_completed_test',
			)
		);

		$this->assert_matches_snapshot( 'email-job-notice-completed', array( $comp_id ) );
	}

	public function test_render_email_job_notice_failed(): void {
		// A failed job: error notice with an error log list.
		$comp_id = $this->seed_competition( 'Spring Show', 'spring-show' );

		$this->seed_job(
			'email_job_failed_test',
			array(
				'competition_id' => $comp_id,
				'status'         => 'failed',
				'error_log'      => array( 'Competition not found' ),
			)
		);

		$this->set_request(
			array(
				'competition' => (string) $comp_id,
				'job_id'      => 'email_job_failed_test',
			)
		);

		$this->assert_matches_snapshot( 'email-job-notice-failed', array( $comp_id ) );
	}
}
