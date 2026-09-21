<?php
/**
 * Characterization tests for Competitions_Controller.
 *
 * Pins current behavior of the competitions admin action router
 * (handle_actions) ahead of a planned refactor. These tests assert what the
 * code does TODAY, including quirks, and must not be treated as a spec.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Competitions_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Characterization tests for the competitions controller.
 *
 * @covers \PhotoCompetitionManager\Admin\Competitions_Controller
 */
class Competitions_Controller_Test extends Admin_Controller_Test_Case {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Controller under test.
	 *
	 * @var Competitions_Controller
	 */
	private $controller;

	/**
	 * Set up the controller and repository.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->competitions = new Competitions_Repository();
		$this->controller   = new Competitions_Controller( $this->competitions );
	}

	/**
	 * Create a competition and return its ID.
	 *
	 * @param string      $title      Title.
	 * @param string      $slug       Slug.
	 * @param string|null $open_date  Open date (UTC) or null.
	 * @param string|null $close_date Close date (UTC) or null.
	 * @return int Competition ID.
	 */
	private function create_competition( string $title, string $slug, ?string $open_date = null, ?string $close_date = null ): int {
		$id = $this->competitions->create(
			array(
				'title'      => $title,
				'slug'       => $slug,
				'open_date'  => $open_date,
				'close_date' => $close_date,
				'settings'   => array(),
				'share_hash' => 'seed-hash-' . $slug,
			)
		);

		$this->assertIsInt( $id, 'Failed to seed competition.' );
		return $id;
	}

	/**
	 * A site-timezone date, as typed into the competition form, the given
	 * number of days from now.
	 *
	 * @param int $days Days from now; negative for the past.
	 * @return string Y-m-d date.
	 */
	private function form_date( int $days ): string {
		return wp_date( 'Y-m-d', time() + $days * DAY_IN_SECONDS );
	}

	/**
	 * Parsed settings for a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @return array<string, mixed>
	 */
	private function settings( int $competition_id ): array {
		$competition = $this->competitions->find( $competition_id, true );
		return Competition_Settings::parse( $competition->settings );
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

	/*
	 * -----------------------------------------------------------------
	 * Capability guard.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Without manage_photo_competitions, handle_actions() is a no-op.
	 */
	public function test_handle_actions_noop_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->set_request(
			array(
				'photo_competition_action' => 'create_competition',
				'competition_title'        => 'Blocked',
				'competition_slug'         => 'blocked',
			)
		);
		$this->set_nonce( 'photo_competition_create', 'photo_competition_nonce' );

		$before = $this->competitions->count( false );
		$this->controller->handle_actions();

		$this->assertSame( $before, $this->competitions->count( false ), 'No competition should be created without capability.' );
		$this->assertSame( array(), $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	/*
	 * -----------------------------------------------------------------
	 * create_competition.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Creating a competition succeeds and redirects to the dashboard.
	 */
	public function test_create_competition_success(): void {
		$this->set_request(
			array(
				'photo_competition_action' => 'create_competition',
				'competition_title'        => 'Autumn Show',
				'competition_slug'         => 'autumn-show',
				'competition_open_date'    => '',
				'competition_close_date'   => '',
			)
		);
		$this->set_nonce( 'photo_competition_create', 'photo_competition_nonce' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertStringNotContainsString( 'action=edit', $location );
		$this->assertContains( 'created', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNotNull( $this->competitions->find_by_slug( 'autumn-show' ) );
	}

	/**
	 * A duplicate slug surfaces the repository error code.
	 */
	public function test_create_competition_duplicate_slug_error(): void {
		$this->create_competition( 'First', 'dupe-slug', '2020-01-01 00:00:00', '2020-02-01 00:00:00' );

		$this->set_request(
			array(
				'photo_competition_action' => 'create_competition',
				'competition_title'        => 'Second',
				'competition_slug'         => 'dupe-slug',
			)
		);
		$this->set_nonce( 'photo_competition_create', 'photo_competition_nonce' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertContains( 'duplicate_slug', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	/**
	 * Only one competition may be open at a time, so a new competition whose
	 * dates overlap an existing one is refused.
	 */
	public function test_create_competition_overlapping_dates_error(): void {
		$this->create_competition( 'Stale', 'stale', '2020-01-01 00:00:00' );

		$this->set_request(
			array(
				'photo_competition_action' => 'create_competition',
				'competition_title'        => 'October',
				'competition_slug'         => 'october',
				'competition_open_date'    => $this->form_date( 10 ),
				'competition_close_date'   => $this->form_date( 40 ),
			)
		);
		$this->set_nonce( 'photo_competition_create', 'photo_competition_nonce' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertContains( 'competition_overlap', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNull( $this->competitions->find_by_slug( 'october' ) );
	}

	/**
	 * After Close Competition, the next competition can open today. Close
	 * Competition stores the current time, which is later than the next
	 * competition's midnight open date, but they are never open together.
	 */
	public function test_create_competition_opening_today_after_close_competition(): void {
		$id = $this->create_competition( 'This Month', 'this-month', '2020-01-01 00:00:00' );

		$this->set_request(
			array(
				'action'      => 'close_competition',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_close_' . $id );
		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->set_request(
			array(
				'photo_competition_action' => 'create_competition',
				'competition_title'        => 'Next Month',
				'competition_slug'         => 'next-month',
				'competition_open_date'    => $this->form_date( 0 ),
				'competition_close_date'   => $this->form_date( 30 ),
			)
		);
		$this->set_nonce( 'photo_competition_create', 'photo_competition_nonce' );
		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertNotContains( 'competition_overlap', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNotNull( $this->competitions->find_by_slug( 'next-month' ) );
	}

	/**
	 * A missing/invalid nonce aborts create via wp_die().
	 */
	public function test_create_competition_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'photo_competition_action' => 'create_competition',
				'competition_title'        => 'No Nonce',
				'competition_slug'         => 'no-nonce',
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * update_competition.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Updating a competition persists the change and redirects to dashboard.
	 */
	public function test_update_competition_success(): void {
		$id = $this->create_competition( 'Old Title', 'old-title' );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition',
				'competition_id'           => $id,
				'competition_title'        => 'New Title',
				'competition_slug'         => 'new-title',
				'competition_open_date'    => '',
				'competition_close_date'   => '',
			)
		);
		$this->set_nonce( 'photo_competition_update_' . $id, 'photo_competition_nonce' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertStringNotContainsString( 'action=edit', $location );
		$this->assertContains( 'updated', $this->settings_error_codes( 'photo_competition_manager' ) );

		$competition = $this->competitions->find( $id );
		$this->assertSame( 'New Title', $competition->title );
	}

	/**
	 * A duplicate slug on update surfaces an error and redirects to the edit screen.
	 */
	public function test_update_competition_duplicate_slug_error(): void {
		$this->create_competition( 'Alpha', 'alpha', '2020-01-01 00:00:00', '2020-02-01 00:00:00' );
		$id = $this->create_competition( 'Beta', 'beta' );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition',
				'competition_id'           => $id,
				'competition_title'        => 'Beta',
				'competition_slug'         => 'alpha',
			)
		);
		$this->set_nonce( 'photo_competition_update_' . $id, 'photo_competition_nonce' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'action=edit', $location );
		$this->assertStringContainsString( 'competition=' . $id, $location );
		$this->assertContains( 'duplicate_slug', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	/**
	 * Changing a competition's dates so they overlap another is refused and
	 * returns to the edit screen with the dates unchanged.
	 */
	public function test_update_competition_overlapping_dates_error(): void {
		$this->create_competition( 'Current', 'current', utc_time( -10 * DAY_IN_SECONDS ), utc_time( 20 * DAY_IN_SECONDS ) );
		$next_open = utc_time( 20 * DAY_IN_SECONDS );
		$id        = $this->create_competition( 'Next', 'next', $next_open, utc_time( 50 * DAY_IN_SECONDS ) );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition',
				'competition_id'           => $id,
				'competition_title'        => 'Next',
				'competition_slug'         => 'next',
				'competition_open_date'    => $this->form_date( 10 ),
				'competition_close_date'   => $this->form_date( 50 ),
			)
		);
		$this->set_nonce( 'photo_competition_update_' . $id, 'photo_competition_nonce' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'action=edit', $location );
		$this->assertContains( 'competition_overlap', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( $next_open, $this->competitions->find( $id )->open_date );
	}

	/**
	 * Two past competitions whose dates overlapped can still be edited,
	 * because only one competition has to be open from now on.
	 */
	public function test_update_competition_allows_overlap_in_the_past(): void {
		$this->create_competition( 'January', 'january', '2025-01-01 00:00:00', '2025-02-05 00:00:00' );
		$id = $this->create_competition( 'February', 'february', '2025-02-01 00:00:00', '2025-03-01 00:00:00' );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition',
				'competition_id'           => $id,
				'competition_title'        => 'February 2025',
				'competition_slug'         => 'february',
				'competition_open_date'    => '2025-02-01',
				'competition_close_date'   => '2025-03-01',
			)
		);
		$this->set_nonce( 'photo_competition_update_' . $id, 'photo_competition_nonce' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'updated', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( 'February 2025', $this->competitions->find( $id )->title );
	}

	/**
	 * A missing/invalid nonce aborts update via wp_die().
	 */
	public function test_update_competition_bad_nonce_dies(): void {
		$id = $this->create_competition( 'Guarded', 'guarded' );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition',
				'competition_id'           => $id,
				'competition_title'        => 'Changed',
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * generate_results_link.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Generating a results link rotates the share hash and reports success.
	 */
	public function test_generate_results_link_success(): void {
		$id     = $this->create_competition( 'Shareable', 'shareable' );
		$before = $this->competitions->find( $id )->share_hash;

		$this->set_request(
			array(
				'action'      => 'generate_results_link',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_generate_results_link_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertContains( 'results_link_generated', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNotSame( $before, $this->competitions->find( $id )->share_hash );
	}

	/**
	 * A missing competition yields a not-found error.
	 */
	public function test_generate_results_link_not_found(): void {
		$missing = 999999;

		$this->set_request(
			array(
				'action'      => 'generate_results_link',
				'competition' => $missing,
			)
		);
		$this->set_nonce( 'photo_competition_generate_results_link_' . $missing );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertContains( 'competition_not_found', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	/*
	 * -----------------------------------------------------------------
	 * close_competition.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Closing sets the close date to now so the competition is no longer open.
	 */
	public function test_close_competition_sets_close_date_to_now(): void {
		$id = $this->create_competition( 'To Close', 'to-close', '2020-01-01 00:00:00' );
		$this->assertTrue( $this->competitions->is_open( $this->competitions->find( $id ) ) );

		$this->set_request(
			array(
				'action'      => 'close_competition',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_close_' . $id );

		$before   = time();
		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$competition = $this->competitions->find( $id );
		$closed_at   = strtotime( $competition->close_date . ' UTC' );

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertContains( 'competition_closed', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertGreaterThanOrEqual( $before - 1, $closed_at );
		$this->assertLessThanOrEqual( time(), $closed_at );
		$this->assertFalse( $this->competitions->is_open( $competition ) );
	}

	/**
	 * Closing a competition with a category open for voting closes that
	 * voting the same way the Voting Controls "Close Voting" step does.
	 */
	public function test_close_competition_closes_open_voting(): void {
		$id = $this->create_competition( 'Mid Vote', 'mid-vote', '2020-01-01 00:00:00' );

		$settings                                        = $this->settings( $id );
		$settings['voting']['open_categories']           = array( 'colour' );
		$settings['voting']['category_steps']['colour']  = 3;
		$this->competitions->update( $id, array( 'settings' => $settings ) );

		$this->set_request(
			array(
				'action'      => 'close_competition',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_close_' . $id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$after = $this->settings( $id );
		$this->assertSame( array(), $after['voting']['open_categories'] );
		$this->assertSame( 5, $after['voting']['category_steps']['colour'] );
		$this->assertContains( $id . '_colour', $after['voting']['voted_categories'] );
	}

	/**
	 * Closing a missing competition reports competition_not_found.
	 */
	public function test_close_competition_not_found(): void {
		$this->set_request(
			array(
				'action'      => 'close_competition',
				'competition' => 999999,
			)
		);
		$this->set_nonce( 'photo_competition_close_999999' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'competition_not_found', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	/**
	 * Closing an already-closed competition (a replayed link) leaves its
	 * close date alone.
	 */
	public function test_close_competition_already_closed(): void {
		$id = $this->create_competition( 'Done', 'done', '2020-01-01 00:00:00', '2020-02-01 00:00:00' );

		$this->set_request(
			array(
				'action'      => 'close_competition',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_close_' . $id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'competition_already_closed', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( '2020-02-01 00:00:00', $this->competitions->find( $id )->close_date );
	}

	/**
	 * A missing/invalid nonce aborts close via wp_die().
	 */
	public function test_close_competition_bad_nonce_dies(): void {
		$id = $this->create_competition( 'Guarded Close', 'guarded-close', '2020-01-01 00:00:00' );

		$this->set_request(
			array(
				'action'      => 'close_competition',
				'competition' => $id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * archive / restore (shared group).
	 * -----------------------------------------------------------------
	 */

	/**
	 * Archiving soft-deletes the competition and reports success.
	 */
	public function test_archive_success(): void {
		$id = $this->create_competition( 'To Archive', 'to-archive' );

		$this->set_request(
			array(
				'action'      => 'archive',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_archive_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertContains( 'archived', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNull( $this->competitions->find( $id ), 'Archived competition should be hidden from default find().' );
		$this->assertNotNull( $this->competitions->find( $id, true ) );
	}

	/**
	 * A missing/invalid nonce aborts the archive group via wp_die().
	 */
	public function test_archive_bad_nonce_dies(): void {
		$id = $this->create_competition( 'Guarded Archive', 'guarded-archive' );

		$this->set_request(
			array(
				'action'      => 'archive',
				'competition' => $id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/**
	 * Restoring an archived competition reports success and redirects to the archived view.
	 */
	public function test_restore_success(): void {
		$id = $this->create_competition( 'To Restore', 'to-restore' );
		$this->competitions->archive( $id );

		$this->set_request(
			array(
				'action'      => 'restore',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_restore_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'view=archived', $location );
		$this->assertContains( 'restored', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNotNull( $this->competitions->find( $id ), 'Restored competition should reappear in default find().' );
	}

	/**
	 * Restoring a competition whose dates overlap another is refused, so
	 * restore can't bring back a second open competition.
	 */
	public function test_restore_overlapping_dates_error(): void {
		$id = $this->create_competition( 'Old', 'old' );
		$this->competitions->archive( $id );
		$this->create_competition( 'Current', 'current', '2020-01-01 00:00:00' );

		$this->set_request(
			array(
				'action'      => 'restore',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_restore_' . $id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'competition_overlap', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNull( $this->competitions->find( $id ), 'Competition should stay archived.' );
	}

	/**
	 * An old competition can be restored even if its dates overlapped
	 * another old one, since neither is open now.
	 */
	public function test_restore_allows_overlap_in_the_past(): void {
		$this->create_competition( 'January', 'january', '2025-01-01 00:00:00', '2025-02-05 00:00:00' );
		$id = $this->create_competition( 'February', 'february', '2025-02-01 00:00:00', '2025-03-01 00:00:00' );
		$this->competitions->archive( $id );

		$this->set_request(
			array(
				'action'      => 'restore',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_restore_' . $id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertNotContains( 'competition_overlap', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNotNull( $this->competitions->find( $id ), 'Competition should be restored.' );
	}

	/*
	 * -----------------------------------------------------------------
	 * delete.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Deleting permanently removes the competition and reports success.
	 */
	public function test_delete_success(): void {
		$id = $this->create_competition( 'To Delete', 'to-delete' );

		$this->set_request(
			array(
				'action'      => 'delete',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_delete_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertContains( 'deleted', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNull( $this->competitions->find( $id, true ), 'Competition should be permanently gone.' );
	}

	/**
	 * Deleting a missing competition surfaces the repository error code.
	 */
	public function test_delete_not_found_error(): void {
		$missing = 999999;

		$this->set_request(
			array(
				'action'      => 'delete',
				'competition' => $missing,
			)
		);
		$this->set_nonce( 'photo_competition_delete_' . $missing );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'missing_competition', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	/*
	 * -----------------------------------------------------------------
	 * reset_votes.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Resetting votes deletes votes and clears voting workflow state.
	 */
	public function test_reset_votes_success(): void {
		$id = $this->create_competition( 'To Reset', 'to-reset' );

		// Seed a vote and pre-existing workflow state.
		( new Votes_Repository() )->create_anonymous( $id, 'colour', 4321, 1234, 5 );
		( new Voting_Token_Repository() )->create( $this->admin_id, $id, 'colour', 'hash_colour', '2099-12-31 00:00:00' );
		$settings                               = $this->settings( $id );
		$settings['voting']['category_steps']   = array( 'colour' => 5 );
		$settings['voting']['voted_categories'] = array( $id . '_colour' );
		$this->competitions->update( $id, array( 'settings' => $settings ) );

		$this->set_request(
			array(
				'action'      => 'reset_votes',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_reset_votes_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertContains( 'votes_reset', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( 0, $this->vote_count( $id, 'colour' ), 'Votes must be deleted on reset.' );

		$after = $this->settings( $id );
		$this->assertSame( array(), $after['voting']['category_steps'] );
		$this->assertSame( array(), $after['voting']['voted_categories'] );
		$this->assertSame( array(), $after['voting']['open_categories'] );
	}

	/**
	 * A missing/invalid nonce aborts reset_votes via wp_die().
	 */
	public function test_reset_votes_bad_nonce_dies(): void {
		$id = $this->create_competition( 'Guarded Reset', 'guarded-reset' );

		$this->set_request(
			array(
				'action'      => 'reset_votes',
				'competition' => $id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * send_emails.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Sending reminder emails on an open competition with members reports success.
	 */
	public function test_send_emails_success(): void {
		// Open competition: null open/close dates make is_open() true.
		$id = $this->create_competition( 'Open Comp', 'open-comp' );
		( new Members_Repository() )->create(
			array(
				'name'   => 'Member One',
				'email'  => 'member-one@example.com',
				'active' => 1,
			)
		);

		$this->set_request(
			array(
				'action'      => 'send_emails',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_send_emails_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertContains( 'emails_sent', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	/**
	 * Sending reminder emails on a closed competition surfaces the not-open error.
	 */
	public function test_send_emails_competition_not_open(): void {
		$id = $this->create_competition( 'Closed Comp', 'closed-comp', '2000-01-01 00:00:00', '2000-12-31 00:00:00' );

		$this->set_request(
			array(
				'action'      => 'send_emails',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_send_emails_' . $id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'competition_not_open', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	/*
	 * -----------------------------------------------------------------
	 * toggle_uploads.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Toggling uploads flips the uploads_closed flag and redirects to the dashboard.
	 */
	public function test_toggle_uploads_success(): void {
		$id = $this->create_competition( 'Toggle Comp', 'toggle-comp' );

		$this->set_request(
			array(
				'action'      => 'toggle_uploads',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_toggle_uploads_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertContains( 'uploads_toggled', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertTrue( ! empty( $this->settings( $id )['upload']['uploads_closed'] ) );
	}

	/**
	 * The ref_page=voting query var routes the toggle redirect to the voting page.
	 */
	public function test_toggle_uploads_ref_page_voting_redirect(): void {
		$id = $this->create_competition( 'Toggle Ref', 'toggle-ref' );

		$this->set_request(
			array(
				'action'      => 'toggle_uploads',
				'competition' => $id,
				'ref_page'    => 'voting',
			)
		);
		$this->set_nonce( 'photo_competition_toggle_uploads_' . $id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager-voting', $location );
	}

	/**
	 * A missing competition still redirects but registers no settings error.
	 *
	 * Characterizes the current silent behavior of the not-found path.
	 */
	public function test_toggle_uploads_not_found_is_silent(): void {
		$missing = 999999;

		$this->set_request(
			array(
				'action'      => 'toggle_uploads',
				'competition' => $missing,
			)
		);
		$this->set_nonce( 'photo_competition_toggle_uploads_' . $missing );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager', $location );
		$this->assertSame( array(), $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	/*
	 * -----------------------------------------------------------------
	 * update_competition_settings.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Saving competition settings persists and redirects to the settings tab.
	 */
	public function test_update_competition_settings_success(): void {
		$id = $this->create_competition( 'Settings Comp', 'settings-comp' );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition_settings',
				'competition_id'           => $id,
				'categories'               => array(
					array(
						'label' => 'Colour',
						'slug'  => 'colour',
						'quota' => '2',
					),
				),
				'grades'                   => array(
					array( 'label' => 'A Grade' ),
				),
				'score_matrix'             => '9, 8, 7, 6, 5',
				'voting_auth_mode'         => 'password',
				'voting_ui_type'           => 'buttons',
				'progress_meter_type'      => 'bar',
			)
		);
		$this->set_nonce( 'photo_competition_update_settings_' . $id, 'photo_competition_nonce' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'action=edit', $location );
		$this->assertStringContainsString( 'tab=settings', $location );
		$this->assertContains( 'settings_updated', $this->settings_error_codes( 'photo_competition_manager' ) );

		$settings = $this->settings( $id );
		$this->assertSame( 'colour', $settings['categories'][0]['slug'] );
		$this->assertSame( 2, $settings['categories'][0]['quota'] );
	}

	/**
	 * Invalid settings (quota below 1) surface a validation error and redirect back.
	 */
	public function test_update_competition_settings_validation_error(): void {
		$id = $this->create_competition( 'Bad Settings', 'bad-settings' );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition_settings',
				'competition_id'           => $id,
				'categories'               => array(
					array(
						'label' => 'Colour',
						'slug'  => 'colour',
						'quota' => '0',
					),
				),
				'grades'                   => array(),
			)
		);
		$this->set_nonce( 'photo_competition_update_settings_' . $id, 'photo_competition_nonce' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'action=edit', $location );
		$this->assertStringContainsString( 'tab=settings', $location );
		$this->assertContains( 'invalid_quota', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	/**
	 * A missing/invalid nonce aborts settings save via wp_die().
	 */
	public function test_update_competition_settings_bad_nonce_dies(): void {
		$id = $this->create_competition( 'Guarded Settings', 'guarded-settings' );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition_settings',
				'competition_id'           => $id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}
}
