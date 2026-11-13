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
use PhotoCompetitionManager\Tests\Type_Fixtures;
use PhotoCompetitionManager\Repository\Competition_Types_Repository;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Competition_Judges_Repository;
use PhotoCompetitionManager\Repository\Entrants_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Judges_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Service\Upload_Link_Service;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
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
				'type_id'    => Type_Fixtures::id(),
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
	 * A competition's settings as stored, without defaults filled in.
	 *
	 * @param int $competition_id Competition ID.
	 * @return array<string, mixed>
	 */
	private function stored_settings( int $competition_id ): array {
		return (array) json_decode( (string) $this->competitions->find( $competition_id, true )->settings, true );
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
		$competition = $this->competitions->find_by_slug( 'autumn-show' );
		$this->assertNotNull( $competition );
		// Competitions use the club's grades, so none are copied in.
		$this->assertArrayNotHasKey( 'grades', $this->stored_settings( (int) $competition->id ) );
	}

	/**
	 * Replace the site's types with the given ones.
	 *
	 * @param array<string, array<string, mixed>> $types Settings by type name.
	 * @return array<string, int> Type IDs by name.
	 */
	private function use_types( array $types ): array {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'photocomp_competition_types' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'photocomp_member_types' ) );

		$repo = new Competition_Types_Repository();
		$ids  = array();
		foreach ( $types as $name => $settings ) {
			$ids[ $name ] = (int) $repo->create( $name, $settings );
		}

		return $ids;
	}

	/**
	 * Post the create form.
	 *
	 * @param array<string, mixed> $extra Extra posted fields.
	 * @return string Redirect location.
	 */
	private function post_create( array $extra = array() ): string {
		$this->set_request(
			array_merge(
				array(
					'photo_competition_action' => 'create_competition',
					'competition_title'        => 'Typed Show',
					'competition_slug'         => 'typed-show',
					'competition_open_date'    => '',
					'competition_close_date'   => '',
				),
				$extra
			)
		);
		$this->set_nonce( 'photo_competition_create', 'photo_competition_nonce' );

		return $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);
	}

	/**
	 * Post the general tab's form.
	 *
	 * @param int                  $id    Competition ID.
	 * @param array<string, mixed> $extra Extra posted fields.
	 * @return string Redirect location.
	 */
	private function post_update( int $id, array $extra = array() ): string {
		$this->set_request(
			array_merge(
				array(
					'photo_competition_action' => 'update_competition',
					'competition_id'           => (string) $id,
					'competition_title'        => 'Typed Show',
					'competition_slug'         => 'typed-show',
					'competition_open_date'    => '',
					'competition_close_date'   => '',
				),
				$extra
			)
		);
		$this->set_nonce( 'photo_competition_update_' . $id, 'photo_competition_nonce' );

		return $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);
	}

	/**
	 * With one type the form has no type field, so the competition takes that type.
	 */
	public function test_create_with_one_type_needs_no_type_field(): void {
		$ids = $this->use_types( array( 'Individual' => array( 'categories' => array( array( 'label' => 'Open', 'slug' => 'open', 'quota' => 2 ) ) ) ) );

		$this->post_create();

		$competition = $this->competitions->find_by_slug( 'typed-show' );
		$this->assertNotNull( $competition );
		$this->assertSame( $ids['Individual'], (int) $competition->type_id );
		$this->assertSame( 'all', $competition->entry_mode );
	}

	/**
	 * A new competition keeps its own copy of its type's categories and settings,
	 * and the club's page links, not the club's categories.
	 */
	public function test_create_copies_type_categories_and_settings(): void {
		$open = array(
			'slug'  => 'open',
			'label' => 'Open',
			'quota' => 2,
		);
		$this->use_types(
			array(
				'Individual' => array(
					'categories' => array( $open ),
					'voting'     => array( 'score_matrix' => array( 3, 2, 1 ) ),
				),
			)
		);
		update_option( 'photo_comp_default_settings', Competition_Settings::encode( array( 'categories' => array( array_merge( $open, array( 'slug' => 'club' ) ) ) ) ) );

		$this->post_create();

		$stored = $this->stored_settings( (int) $this->competitions->find_by_slug( 'typed-show' )->id );
		$this->assertSame( array( $open ), $stored['categories'] );
		$this->assertSame( array( 3, 2, 1 ), $stored['voting']['score_matrix'] );
		$this->assertArrayNotHasKey( 'grades', $stored );

		// A copy, not a reference: editing the type afterwards leaves the competition alone.
		$type_id = (int) $this->competitions->find_by_slug( 'typed-show' )->type_id;
		( new Competition_Types_Repository() )->update(
			$type_id,
			'Individual',
			array(
				'categories' => array( array( 'label' => 'Changed', 'slug' => 'changed', 'quota' => 9 ) ),
				'voting'     => array( 'score_matrix' => array( 9, 9 ) ),
			),
			array()
		);
		$after = $this->stored_settings( (int) $this->competitions->find_by_slug( 'typed-show' )->id );
		$this->assertSame( $stored['categories'], $after['categories'] );
		$this->assertSame( array( 3, 2, 1 ), $after['voting']['score_matrix'] );
	}

	/**
	 * With several types, one has to be chosen.
	 */
	public function test_create_with_two_types_requires_a_type(): void {
		$cats = array( 'categories' => array( array( 'label' => 'Open', 'slug' => 'open', 'quota' => 2 ) ) );
		$ids  = $this->use_types(
			array(
				'Individual' => $cats,
				'Team'       => $cats,
			)
		);

		$this->post_create();
		$this->assertNull( $this->competitions->find_by_slug( 'typed-show' ) );
		$this->assertContains( 'invalid_type', $this->settings_error_codes( 'photo_competition_manager' ) );

		$this->post_create( array( 'competition_type' => (string) $ids['Team'] ) );
		$this->assertSame( $ids['Team'], (int) $this->competitions->find_by_slug( 'typed-show' )->type_id );
	}

	/**
	 * Changing a competition's type before it has entries takes the new type's categories and settings, and keeps its own page links.
	 */
	public function test_changing_type_replaces_categories_and_settings_but_keeps_urls(): void {
		$ids = $this->use_types(
			array(
				'Individual' => array( 'categories' => array( array( 'label' => 'Open', 'slug' => 'open', 'quota' => 2 ) ) ),
				'Team'       => array(
					'categories' => array( array( 'label' => 'Duo', 'slug' => 'duo', 'quota' => 1 ) ),
					'voting'     => array( 'score_matrix' => array( 3, 2, 1 ) ),
				),
			)
		);

		$this->post_create( array( 'competition_type' => (string) $ids['Individual'] ) );
		$id = (int) $this->competitions->find_by_slug( 'typed-show' )->id;
		$this->competitions->update(
			$id,
			array( 'settings' => array_merge( $this->stored_settings( $id ), array( 'urls' => array( 'upload_page' => 'https://example.com/own-upload/' ) ) ) )
		);

		$this->post_update( $id, array( 'competition_type' => (string) $ids['Team'] ) );

		$settings = $this->stored_settings( $id );
		$this->assertSame( $ids['Team'], (int) $this->competitions->find( $id )->type_id );
		$this->assertSame( array( 'duo' ), wp_list_pluck( $settings['categories'], 'slug' ) );
		$this->assertSame( array( 3, 2, 1 ), $settings['voting']['score_matrix'] );
		$this->assertSame( 'https://example.com/own-upload/', $settings['urls']['upload_page'] );
		$this->assertContains( 'type_settings_copied', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	/**
	 * Once a competition has entries its type is fixed.
	 */
	public function test_type_cannot_change_once_competition_has_entries(): void {
		$cats = array( 'categories' => array( array( 'label' => 'Open', 'slug' => 'open', 'quota' => 2 ) ) );
		$ids  = $this->use_types(
			array(
				'Individual' => $cats,
				'Team'       => $cats,
			)
		);

		$this->post_create( array( 'competition_type' => (string) $ids['Individual'] ) );
		$id = (int) $this->competitions->find_by_slug( 'typed-show' )->id;
		Entry_Fixtures::insert_entry( $id, 'open', 1, array() );

		$this->post_update( $id, array( 'competition_type' => (string) $ids['Team'] ) );

		$this->assertSame( $ids['Individual'], (int) $this->competitions->find( $id )->type_id );
		$this->assertContains( 'type_locked', $this->settings_error_codes( 'photo_competition_manager' ) );
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
		$stale_id = $this->create_competition( 'Stale', 'stale', '2020-01-01 00:00:00' );

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

		// The competition in the way may be too old for the dashboard list,
		// so the message links to its edit screen.
		$messages = implode( ' ', wp_list_pluck( get_settings_errors( 'photo_competition_manager' ), 'message' ) );
		$this->assertMatchesRegularExpression( '/<a href="[^"]*action=edit&(#038;|amp;)?competition=' . $stale_id . '">Stale<\/a>/', $messages );
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
	 * Save the edit form with a close date, keeping the title and slug.
	 *
	 * @param int    $id         Competition ID.
	 * @param string $close_date Close date as the form sends it (Y-m-d).
	 */
	private function save_close_date( int $id, string $close_date ): void {
		$competition = $this->competitions->find( $id );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition',
				'competition_id'           => $id,
				'competition_title'        => $competition->title,
				'competition_slug'         => $competition->slug,
				'competition_open_date'    => gmdate( 'Y-m-d', strtotime( $competition->open_date . ' UTC' ) ),
				'competition_close_date'   => $close_date,
			)
		);
		$this->set_nonce( 'photo_competition_update_' . $id, 'photo_competition_nonce' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'updated', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	/**
	 * The form only has the day, so saving it with the day unchanged keeps
	 * the time the competition closed, such as one set by Close Competition.
	 */
	public function test_saving_the_form_keeps_the_close_time_when_the_day_is_unchanged(): void {
		$closed_at = gmdate( 'Y-m-d 21:15:33', time() - DAY_IN_SECONDS );
		$id        = $this->create_competition( 'Closed', 'closed', utc_time( -10 * DAY_IN_SECONDS ), $closed_at );

		$this->save_close_date( $id, substr( $closed_at, 0, 10 ) );

		$this->assertSame( $closed_at, $this->competitions->find( $id )->close_date );
	}

	/**
	 * Closing an open competition through the form closes it when it's
	 * saved, not at midnight, so nothing done earlier that day, such as
	 * hiding results to correct them, counts as after the close (#196).
	 */
	public function test_closing_through_the_form_closes_the_competition_when_saved(): void {
		$id     = $this->create_competition( 'Open', 'open', utc_time( -10 * DAY_IN_SECONDS ), utc_time( 5 * DAY_IN_SECONDS ) );
		$before = utc_time();

		$this->save_close_date( $id, gmdate( 'Y-m-d' ) );

		$close_date = $this->competitions->find( $id )->close_date;
		$this->assertGreaterThanOrEqual( $before, $close_date );
		$this->assertLessThanOrEqual( utc_time(), $close_date );
	}

	/**
	 * A close date still to come is stored as the start of that day.
	 */
	public function test_a_future_close_date_from_the_form_starts_that_day(): void {
		$id    = $this->create_competition( 'Open', 'open', utc_time( -10 * DAY_IN_SECONDS ), utc_time( 5 * DAY_IN_SECONDS ) );
		$later = gmdate( 'Y-m-d', time() + 10 * DAY_IN_SECONDS );

		$this->save_close_date( $id, $later );

		$this->assertSame( $later . ' 00:00:00', $this->competitions->find( $id )->close_date );
	}

	/**
	 * Moving a closed competition's close date to another past day keeps
	 * the day given: it had already closed, so it doesn't close again now.
	 */
	public function test_moving_a_past_close_date_through_the_form_keeps_the_day_given(): void {
		$id      = $this->create_competition( 'Closed', 'closed', utc_time( -30 * DAY_IN_SECONDS ), gmdate( 'Y-m-d 21:15:33', time() - 10 * DAY_IN_SECONDS ) );
		$earlier = gmdate( 'Y-m-d', time() - 12 * DAY_IN_SECONDS );

		$this->save_close_date( $id, $earlier );

		$this->assertSame( $earlier . ' 00:00:00', $this->competitions->find( $id )->close_date );
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
		$this->assertTrue( ( new Competition_Workflow() )->is_open( $this->competitions->find( $id ) ) );

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
		$this->assertGreaterThanOrEqual( $before, $closed_at );
		$this->assertLessThanOrEqual( time(), $closed_at );
		$this->assertFalse( ( new Competition_Workflow() )->is_open( $competition ) );
	}

	/**
	 * Closing a competition with a category open for voting closes that
	 * voting the same way the Voting Controls "Close Voting" step does.
	 */
	public function test_close_competition_closes_open_voting(): void {
		$id = $this->create_competition( 'Mid Vote', 'mid-vote', '2020-01-01 00:00:00' );
		Entry_Fixtures::insert_entry( $id, 'colour', 1, array() );
		Workflow_Fixtures::set_stage( $id, 'colour', Competition_Workflow::STAGE_VOTING );

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

		$this->assertSame( Competition_Workflow::STAGE_CRITIQUE, ( new Competition_Workflow() )->stage( $this->competitions->find( $id, true ), 'colour' ) );
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
	 * Deleting a competition removes its entries first.
	 */
	public function test_delete_removes_the_competitions_entries(): void {
		$id       = $this->create_competition( 'To Delete', 'to-delete' );
		$entry_id = Entry_Fixtures::insert_entry( $id, 'colour', 1, array( 7 ) );

		$this->set_request(
			array(
				'action'      => 'delete',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_delete_' . $id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'deleted', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNull( $this->competitions->find( $id, true ) );
		$this->assertNull( ( new Images_Repository() )->find( $entry_id ) );
	}

	/**
	 * A competition whose entries won't all go is kept, with the error shown.
	 */
	public function test_delete_keeps_the_competition_when_an_entry_wont_go(): void {
		$id = $this->create_competition( 'To Delete', 'to-delete' );
		Entry_Fixtures::insert_entry( $id, 'colour', 1, array() );

		$failing_repo     = new class() extends Images_Repository {
			public function delete( int $id ) {
				return new \WP_Error( 'db_delete_failed', 'Could not delete image record.' );
			}
		};
		$this->controller = new Competitions_Controller( $this->competitions, null, new Entries( $this->competitions, $failing_repo ) );

		$this->set_request(
			array(
				'action'      => 'delete',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_delete_' . $id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'db_delete_failed', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNotNull( $this->competitions->find( $id, true ) );
	}

	/**
	 * Deleting a competition stops its unfinished email jobs.
	 */
	public function test_delete_discards_unfinished_email_jobs(): void {
		$id     = $this->create_competition( 'To Delete', 'to-delete' );
		$jobs   = ( new \PhotoCompetitionManager\Dependencies() )->email_job_manager;
		$job_id = $jobs->create_job( 'results_published', $id, array( 1 ) );

		$this->set_request(
			array(
				'action'      => 'delete',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_delete_' . $id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertSame( 'failed', $jobs->get_job( $job_id )['status'] );
		$this->assertSame( array(), $jobs->get_abandoned_jobs() );
	}

	/**
	 * Deleting a missing competition surfaces the error code.
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

		$this->assertContains( 'invalid_competition', $this->settings_error_codes( 'photo_competition_manager' ) );
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
		( new Voting_Token_Repository() )->renew( $this->admin_id, $id, 'colour', 'hash_colour', '2099-12-31 00:00:00' );
		Entry_Fixtures::insert_entry( $id, 'colour', 1, array() );
		Workflow_Fixtures::set_stage( $id, 'colour', Competition_Workflow::STAGE_CRITIQUE );

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

		$this->assertSame( Competition_Workflow::STAGE_NOT_STARTED, ( new Competition_Workflow() )->stage( $this->competitions->find( $id ), 'colour' ) );
	}

	/**
	 * Resetting votes on a missing competition reports the error, not success.
	 */
	public function test_reset_votes_not_found_error(): void {
		$missing = 999999;
		$this->set_request(
			array(
				'action'      => 'reset_votes',
				'competition' => $missing,
			)
		);
		$this->set_nonce( 'photo_competition_reset_votes_' . $missing );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertSame( array( 'competition_not_found' ), $this->settings_error_codes( 'photo_competition_manager' ) );
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
	 * Sending reminder emails on an open competition queues a background job
	 * and redirects to the dashboard, which shows its progress.
	 */
	public function test_send_emails_success(): void {
		update_option( 'photo_comp_default_settings', wp_json_encode( array( 'urls' => array( 'upload_page' => 'https://example.com/upload/' ) ) ) );
		// Open competition: null open/close dates make is_open() true.
		$id = $this->create_competition( 'Open Comp', 'open-comp' );
		( new Members_Repository() )->create(
			array(
				'name'   => 'Member One',
				'email'  => 'member-one@example.com',
				'grade'  => 'beginner',
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
		$this->assertStringContainsString( 'job_id=email_job_', $location );
		$this->assertSame( array(), $this->settings_error_codes( 'photo_competition_manager' ) );
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
		$this->assertTrue( ( new Competition_Workflow() )->uploads_closed( $this->competitions->find( $id ) ) );
	}

	/**
	 * Reopening uploads once votes are cast is refused with the workflow's reason.
	 */
	public function test_toggle_uploads_refuses_to_reopen_after_votes(): void {
		$id = $this->create_competition( 'Voted Comp', 'voted-comp' );
		Entry_Fixtures::insert_entry( $id, 'colour', 1, array( 9 ) );
		Workflow_Fixtures::close_uploads( $id );

		$this->set_request(
			array(
				'action'      => 'toggle_uploads',
				'competition' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_toggle_uploads_' . $id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'votes_exist', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertTrue( ( new Competition_Workflow() )->uploads_closed( $this->competitions->find( $id ) ) );
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
	 * A competition can't be saved with no categories, and keeps the ones it has.
	 */
	public function test_update_competition_settings_refuses_no_categories(): void {
		$id = $this->create_competition( 'Settings Comp', 'settings-comp' );
		$this->competitions->update(
			$id,
			array( 'settings' => array( 'categories' => array( array( 'label' => 'Colour', 'slug' => 'colour', 'quota' => 2 ) ) ) )
		);
		$before = $this->settings( $id )['categories'];
		$this->assertCount( 1, $before );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition_settings',
				'competition_id'           => $id,
				'categories'               => array(),
				'score_matrix'             => '9, 8, 7',
			)
		);
		$this->set_nonce( 'photo_competition_update_settings_' . $id, 'photo_competition_nonce' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'tab=settings', $location );
		$this->assertContains( 'no_categories', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNotContains( 'settings_updated', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( $before, $this->settings( $id )['categories'] );
	}

	/**
	 * The settings screen keeps the competition's own size limit, even when
	 * PHP's is lower. Only the upload page shows the lower one.
	 */
	public function test_a_size_limit_above_the_servers_is_saved_and_shown_as_entered(): void {
		$server_limit = static function () {
			return 2 * MB_IN_BYTES;
		};
		add_filter( 'photo_competition_manager_server_upload_limit', $server_limit );
		$id = $this->create_competition( 'Big Files', 'big-files' );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition_settings',
				'competition_id'           => $id,
				'categories'               => array(
					array(
						'label' => 'Colour',
						'slug'  => 'colour',
						'quota' => '1',
					),
				),
				'score_matrix'             => '9, 8, 7',
				'max_file_size_mb'         => '10',
			)
		);
		$this->set_nonce( 'photo_competition_update_settings_' . $id, 'photo_competition_nonce' );
		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertSame( 10, $this->settings( $id )['upload']['max_file_size_mb'] );

		$this->set_request(
			array(
				'action'      => 'edit',
				'competition' => (string) $id,
				'tab'         => 'settings',
			)
		);
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="max_file_size_mb" min="1" max="50" value="10"', $html );
	}

	/**
	 * Saving the Settings tab during competition night used to reopen
	 * uploads and reset the voting stages (#121).
	 */
	public function test_update_competition_settings_leaves_the_workflow_alone(): void {
		$id = $this->create_competition( 'Night', 'night', '2020-01-01 00:00:00' );
		Entry_Fixtures::insert_entry( $id, 'colour', 1, array() );
		Workflow_Fixtures::set_stage( $id, 'colour', Competition_Workflow::STAGE_CRITIQUE );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition_settings',
				'competition_id'           => $id,
				'categories'               => array(
					array(
						'label' => 'Colour',
						'slug'  => 'colour',
						'quota' => '1',
					),
				),
				'score_matrix'             => '9, 8, 7',
				'voting_password'          => 'changed',
			)
		);
		$this->set_nonce( 'photo_competition_update_settings_' . $id, 'photo_competition_nonce' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$workflow    = new Competition_Workflow();
		$competition = $this->competitions->find( $id );
		$this->assertContains( 'settings_updated', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertTrue( $workflow->uploads_closed( $competition ) );
		$this->assertSame( Competition_Workflow::STAGE_CRITIQUE, $workflow->stage( $competition, 'colour' ) );
		$this->assertSame( 'changed', $this->settings( $id )['voting']['password'] );
	}

	/**
	 * A score matrix with a non-number in it is stored as a plain list.
	 */
	public function test_update_competition_settings_stores_the_score_matrix_as_a_list(): void {
		$id = $this->create_competition( 'Matrix', 'matrix' );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition_settings',
				'competition_id'           => $id,
				'categories'               => array(
					array(
						'label' => 'Colour',
						'slug'  => 'colour',
						'quota' => '1',
					),
				),
				'score_matrix'             => '10, x, 8',
			)
		);
		$this->set_nonce( 'photo_competition_update_settings_' . $id, 'photo_competition_nonce' );

		$this->capture_redirect( array( $this->controller, 'handle_actions' ) );

		$this->assertSame( array( 10, 8 ), $this->stored_settings( $id )['voting']['score_matrix'] );
	}

	/**
	 * Saving a competition's settings writes no grade list, even if the
	 * competition had one stored and the form posted one.
	 */
	public function test_update_competition_settings_writes_no_grades(): void {
		$id = $this->create_competition( 'No Grades', 'no-grades' );
		$this->competitions->update(
			$id,
			array(
				'settings' => array(
					'grades' => array(
						array(
							'label' => 'Old',
							'slug'  => 'old',
						),
					),
				),
			)
		);

		$this->set_request(
			array(
				'photo_competition_action' => 'update_competition_settings',
				'competition_id'           => $id,
				'categories'               => array(
					array(
						'label' => 'Colour',
						'slug'  => 'colour',
						'quota' => '1',
					),
				),
				'grades'                   => array(
					array( 'label' => 'Posted' ),
				),
				'score_matrix'             => '9, 8, 7, 6, 5',
			)
		);
		$this->set_nonce( 'photo_competition_update_settings_' . $id, 'photo_competition_nonce' );

		$this->capture_redirect( array( $this->controller, 'handle_actions' ) );

		$this->assertContains( 'settings_updated', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertArrayNotHasKey( 'grades', $this->stored_settings( $id ) );
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

	/*
	 * -----------------------------------------------------------------
	 * Who scores, and the judges panel.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Post the settings form with just the scored-by choice.
	 *
	 * @param int    $id        Competition ID.
	 * @param string $auth_mode Choice for voting_auth_mode.
	 * @return string Redirect location.
	 */
	private function post_scored_by( int $id, string $auth_mode ): string {
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
				'voting_auth_mode'         => $auth_mode,
			)
		);
		$this->set_nonce( 'photo_competition_update_settings_' . $id, 'photo_competition_nonce' );

		return $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);
	}

	/**
	 * Who scores can be set to judges.
	 */
	public function test_scored_by_can_be_set_to_judges(): void {
		$id = $this->create_competition( 'Judged', 'judged' );

		$this->post_scored_by( $id, 'judges' );

		$this->assertSame( 'judges', $this->settings( $id )['voting']['auth_mode'] );
	}

	/**
	 * Once votes exist, who scores can't move between members and judges, but
	 * it can still move between the two member options.
	 */
	public function test_scored_by_cannot_change_once_votes_exist(): void {
		$id = $this->create_competition( 'Voted', 'voted' );
		( new Votes_Repository() )->create_anonymous( $id, 'colour', 4321, 1234, 5 );

		$this->post_scored_by( $id, 'judges' );

		$this->assertContains( 'scored_by_locked', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertNotContains( 'settings_updated', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( 'password', $this->settings( $id )['voting']['auth_mode'] );

		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation.
		$this->post_scored_by( $id, 'token' );

		$this->assertContains( 'settings_updated', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( 'token', $this->settings( $id )['voting']['auth_mode'] );
	}

	/**
	 * A judged competition with votes can't go back to members either.
	 */
	public function test_scored_by_cannot_leave_judges_once_votes_exist(): void {
		$id = $this->create_competition( 'Judged Voted', 'judged-voted' );
		$this->post_scored_by( $id, 'judges' );
		( new Votes_Repository() )->create_anonymous( $id, 'colour', 4321, 1234, 5 );
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation.

		$this->post_scored_by( $id, 'password' );

		$this->assertContains( 'scored_by_locked', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( 'judges', $this->settings( $id )['voting']['auth_mode'] );
	}

	/**
	 * Create a judged competition with a voting page.
	 *
	 * @return int Competition ID.
	 */
	private function create_judged_competition(): int {
		$id = $this->competitions->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'    => 'Judged Show',
				'slug'     => 'judged-show',
				'settings' => array(
					'voting' => array( 'auth_mode' => 'judges' ),
					'urls'   => array( 'voting_page' => 'https://example.com/vote/' ),
				),
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Post one of the judges panel's actions.
	 *
	 * @param int                  $id      Competition ID.
	 * @param string               $action  Action.
	 * @param array<string, mixed> $extra   Extra request variables.
	 * @return string Redirect location.
	 */
	private function post_judges_action( int $id, string $action, array $extra = array() ): string {
		$this->set_request( array_merge( array( 'photo_competition_action' => $action, 'competition_id' => $id ), $extra ) );
		$this->set_nonce( 'photo_competition_judges_' . $id, 'photo_competition_nonce' );

		return $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);
	}

	/**
	 * Ticking judges assigns them and unticking unassigns them.
	 */
	public function test_assign_and_unassign_judges(): void {
		$id     = $this->create_judged_competition();
		$judges = new Judges_Repository();
		$ann    = $judges->create( 'Ann', 'ann@example.com' );
		$bob    = $judges->create( 'Bob', 'bob@example.com' );
		$links  = new Competition_Judges_Repository();

		$location = $this->post_judges_action( $id, 'update_competition_judges', array( 'judge_ids' => array( (string) $ann, (string) $bob ) ) );

		$this->assertStringContainsString( 'tab=settings', $location );
		$this->assertSame( array( 'Ann', 'Bob' ), wp_list_pluck( $links->judges_for( $id ), 'name' ) );

		$this->post_judges_action( $id, 'update_competition_judges', array( 'judge_ids' => array( (string) $bob ) ) );

		$this->assertSame( array( 'Bob' ), wp_list_pluck( $links->judges_for( $id ), 'name' ) );
	}

	/**
	 * Judges can't be assigned to a competition members score.
	 */
	public function test_judges_cant_be_assigned_to_a_member_voted_competition(): void {
		$id    = $this->create_competition( 'Members', 'members' );
		$judge = ( new Judges_Repository() )->create( 'Ann', 'ann@example.com' );

		$this->post_judges_action( $id, 'update_competition_judges', array( 'judge_ids' => array( (string) $judge ) ) );

		$this->assertContains( 'judges_not_scoring', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( array(), ( new Competition_Judges_Repository() )->judges_for( $id ) );
	}

	/**
	 * Send link emails one judge their link and notes when it was sent.
	 */
	public function test_send_judge_link_emails_judge(): void {
		$id    = $this->create_judged_competition();
		$ann   = ( new Judges_Repository() )->create( 'Ann', 'ann@example.com' );
		$bob   = ( new Judges_Repository() )->create( 'Bob', 'bob@example.com' );
		$links = new Competition_Judges_Repository();
		$token = $links->assign( $id, $ann );
		$links->assign( $id, $bob );
		reset_phpmailer_instance();

		$this->post_judges_action( $id, 'send_judge_link', array( 'judge_id' => (string) $ann ) );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertSame( 'ann@example.com', $mailer->mock_sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'https://example.com/vote/?judge=' . rawurlencode( $token ), $mailer->mock_sent[0]['body'] );
		$this->assertStringContainsString( 'Your judging link for Judged Show', $mailer->mock_sent[0]['subject'] );
		$this->assertContains( 'judge_links_sent', $this->settings_error_codes( 'photo_competition_manager' ) );

		$sent = wp_list_pluck( $links->judges_for( $id ), 'sent_at', 'name' );
		$this->assertNotEmpty( $sent['Ann'] );
		$this->assertEmpty( $sent['Bob'] );
	}

	/**
	 * Send link to all judges emails each assigned judge.
	 */
	public function test_send_all_judge_links_emails_every_assigned_judge(): void {
		$id    = $this->create_judged_competition();
		$links = new Competition_Judges_Repository();
		$links->assign( $id, ( new Judges_Repository() )->create( 'Ann', 'ann@example.com' ) );
		$links->assign( $id, ( new Judges_Repository() )->create( 'Bob', 'bob@example.com' ) );
		( new Judges_Repository() )->create( 'Cat', 'cat@example.com' );
		reset_phpmailer_instance();

		$this->post_judges_action( $id, 'send_all_judge_links' );

		$this->assertCount( 2, tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertNotContains( '', array_map( 'strval', wp_list_pluck( $links->judges_for( $id ), 'sent_at' ) ) );
	}

	/**
	 * Without a voting page there is no link, so nothing is sent.
	 */
	public function test_send_judge_link_without_a_voting_page_sends_nothing(): void {
		$id = $this->competitions->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'    => 'No Page',
				'slug'     => 'no-page',
				'settings' => array( 'voting' => array( 'auth_mode' => 'judges' ) ),
			)
		);
		$judge = ( new Judges_Repository() )->create( 'Ann', 'ann@example.com' );
		( new Competition_Judges_Repository() )->assign( $id, $judge );
		reset_phpmailer_instance();

		$this->post_judges_action( $id, 'send_judge_link', array( 'judge_id' => (string) $judge ) );

		$this->assertContains( 'judge_link_no_voting_page', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent );
	}

	/**
	 * A bad nonce aborts the judges actions.
	 */
	public function test_judges_actions_bad_nonce_dies(): void {
		$id = $this->create_judged_competition();
		$this->set_request(
			array(
				'photo_competition_action' => 'send_all_judge_links',
				'competition_id'           => $id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * Entrants panel.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Create a competition open for uploads, with an upload page.
	 *
	 * @param string $entry_mode 'all' or 'selected'.
	 * @return int Competition ID.
	 */
	private function create_entry_competition( string $entry_mode = 'selected' ): int {
		$id = $this->competitions->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'      => 'Entry Show',
				'slug'       => 'entry-show',
				'entry_mode' => $entry_mode,
				'settings'   => array( 'urls' => array( 'upload_page' => 'https://example.com/upload/' ) ),
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Create a member.
	 *
	 * @param string $name Name; the email is made from it.
	 * @return int Member ID.
	 */
	private function create_member( string $name ): int {
		$id = ( new Members_Repository() )->create(
			array(
				'name'  => $name,
				'email' => sanitize_title( $name ) . '@example.com',
				'grade' => 'beginner',
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Post one of the entrants panel's actions.
	 *
	 * @param int                  $id     Competition ID.
	 * @param string               $action Action.
	 * @param array<string, mixed> $extra  Extra request variables.
	 * @return string Redirect location.
	 */
	private function post_entrants_action( int $id, string $action, array $extra = array() ): string {
		$this->set_request( array_merge( array( 'photo_competition_action' => $action, 'competition_id' => $id ), $extra ) );
		$this->set_nonce( 'photo_competition_entrants_' . $id, 'photo_competition_nonce' );

		return $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);
	}

	public function test_set_entry_mode_to_selected(): void {
		$id = $this->create_entry_competition( 'all' );

		$location = $this->post_entrants_action( $id, 'set_entry_mode', array( 'entry_mode' => 'selected' ) );

		$this->assertStringContainsString( 'tab=entrants', $location );
		$this->assertSame( 'selected', $this->competitions->find( $id )->entry_mode );
		$this->assertContains( 'entry_mode_updated', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	public function test_add_entrants_from_the_picker(): void {
		$id  = $this->create_entry_competition();
		$ann = $this->create_member( 'Ann' );
		$bob = $this->create_member( 'Bob' );
		$this->create_member( 'Cat' );
		reset_phpmailer_instance();

		$this->post_entrants_action( $id, 'add_entrants', array( 'member_ids' => array( (string) $ann, (string) $bob ) ) );

		$this->assertSame( array( $ann, $bob ), ( new Entrants_Repository() )->member_ids( $id ) );
		$this->assertCount( 2, tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertContains( 'entrants_added', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	public function test_add_entrants_while_open_to_all_shows_the_error(): void {
		$id  = $this->create_entry_competition( 'all' );
		$ann = $this->create_member( 'Ann' );

		$this->post_entrants_action( $id, 'add_entrants', array( 'member_ids' => array( (string) $ann ) ) );

		$this->assertContains( 'not_selected_mode', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( array(), ( new Entrants_Repository() )->member_ids( $id ) );
	}

	public function test_remove_entrant_with_entries_shows_the_error(): void {
		$id  = $this->create_entry_competition();
		$ann = $this->create_member( 'Ann' );
		( new Entrants_Repository() )->add( $id, $ann );
		Entry_Fixtures::insert_entry( $id, 'colour', $ann, array() );

		$this->post_entrants_action( $id, 'remove_entrant', array( 'member_id' => (string) $ann ) );

		$this->assertContains( 'entrant_has_entries', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( array( $ann ), ( new Entrants_Repository() )->member_ids( $id ) );
	}

	public function test_remove_entrant(): void {
		$id  = $this->create_entry_competition();
		$ann = $this->create_member( 'Ann' );
		( new Entrants_Repository() )->add( $id, $ann );

		$this->post_entrants_action( $id, 'remove_entrant', array( 'member_id' => (string) $ann ) );

		$this->assertContains( 'entrant_removed', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( array(), ( new Entrants_Repository() )->member_ids( $id ) );
	}

	public function test_create_member_and_enter_them(): void {
		$id = $this->create_entry_competition();
		reset_phpmailer_instance();

		$this->post_entrants_action(
			$id,
			'add_new_entrant',
			array(
				'member_name'  => 'Dan Guest',
				'member_email' => 'dan@example.com',
				'member_grade' => 'beginner',
			)
		);

		$members = new Members_Repository();
		$dan     = $members->find_by_email( 'dan@example.com' );
		$this->assertNotNull( $dan );
		$this->assertSame( 'Dan Guest', $dan->name );
		$this->assertSame( array( (int) $dan->id ), ( new Entrants_Repository() )->member_ids( $id ) );
		// Tagged by the one-type rule.
		$this->assertSame( array( Type_Fixtures::id() ), $members->type_ids( (int) $dan->id ) );
		$this->assertSame( 'dan@example.com', tests_retrieve_phpmailer_instance()->mock_sent[0]['to'][0][0] );
		$this->assertContains( 'entrants_added', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	public function test_create_member_and_enter_them_tags_them_with_the_competitions_type_on_a_multi_type_site(): void {
		$id = $this->create_entry_competition();
		Type_Fixtures::create( 'Juniors' );
		$type_id = (int) $this->competitions->find( $id )->type_id;

		$this->post_entrants_action(
			$id,
			'add_new_entrant',
			array(
				'member_name'  => 'Dan Guest',
				'member_email' => 'dan@example.com',
				'member_grade' => 'beginner',
			)
		);

		$members = new Members_Repository();
		$dan     = $members->find_by_email( 'dan@example.com' );
		$this->assertNotNull( $dan );
		$this->assertSame( array( $type_id ), $members->type_ids( (int) $dan->id ) );
	}

	public function test_create_member_with_a_bad_email_shows_the_error(): void {
		$id = $this->create_entry_competition();

		$this->post_entrants_action(
			$id,
			'add_new_entrant',
			array(
				'member_name'  => 'Dan Guest',
				'member_email' => 'not-an-email',
				'member_grade' => 'beginner',
			)
		);

		$this->assertContains( 'invalid_email', $this->settings_error_codes( 'photo_competition_manager' ) );
		$this->assertSame( array(), ( new Entrants_Repository() )->member_ids( $id ) );
	}

	public function test_create_member_after_voting_started_creates_no_one(): void {
		$id  = $this->create_entry_competition();
		$ann = $this->create_member( 'Ann' );
		( new Entrants_Repository() )->add( $id, $ann );
		Entry_Fixtures::insert_entry( $id, 'colour', $ann, array() );
		Workflow_Fixtures::set_stage( $id, 'colour', Competition_Workflow::STAGE_VOTING );

		$this->post_entrants_action(
			$id,
			'add_new_entrant',
			array(
				'member_name'  => 'Dan Guest',
				'member_email' => 'dan@example.com',
				'member_grade' => 'beginner',
			)
		);

		$this->assertNull( ( new Members_Repository() )->find_by_email( 'dan@example.com' ) );
		$this->assertContains( 'entry_closed', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	public function test_create_member_while_open_to_all_creates_no_one(): void {
		$id = $this->create_entry_competition( 'all' );

		$this->post_entrants_action(
			$id,
			'add_new_entrant',
			array(
				'member_name'  => 'Dan Guest',
				'member_email' => 'dan@example.com',
				'member_grade' => 'beginner',
			)
		);

		$this->assertNull( ( new Members_Repository() )->find_by_email( 'dan@example.com' ) );
		$this->assertContains( 'not_selected_mode', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	public function test_resend_upload_link_to_entrant(): void {
		$id  = $this->create_entry_competition();
		$ann = $this->create_member( 'Ann' );
		( new Entrants_Repository() )->add( $id, $ann );
		( new Upload_Link_Service() )->send_to_member( $id, $ann );
		reset_phpmailer_instance();

		// Sent again although one went out moments ago.
		$this->post_entrants_action( $id, 'send_entrant_upload_link', array( 'member_id' => (string) $ann ) );

		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertSame( 'ann@example.com', $mailer->mock_sent[0]['to'][0][0] );
		$this->assertContains( 'upload_link_sent', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	public function test_upload_link_is_only_sent_to_an_entrant(): void {
		$id  = $this->create_entry_competition();
		$ann = $this->create_member( 'Ann' );
		reset_phpmailer_instance();

		$this->post_entrants_action( $id, 'send_entrant_upload_link', array( 'member_id' => (string) $ann ) );

		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent );
		$this->assertContains( 'not_an_entrant', $this->settings_error_codes( 'photo_competition_manager' ) );
	}

	public function test_entrants_actions_bad_nonce_dies(): void {
		$id = $this->create_entry_competition();
		$this->set_request(
			array(
				'photo_competition_action' => 'set_entry_mode',
				'competition_id'           => $id,
				'entry_mode'               => 'all',
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	public function test_multiple_open_notice_shows_grade_titles_without_the_placeholder(): void {
		$controller = $this->controller;
		$method     = new \ReflectionMethod( $controller, 'render_multiple_open_notice' );
		$method->setAccessible( true );

		$html = $method->invoke(
			$controller,
			array(
				(object) array( 'id' => 0, 'title' => '{grade} 2026' ),
				(object) array( 'id' => 0, 'title' => 'Plain' ),
			),
			false
		);

		$this->assertStringNotContainsString( '{grade}', $html );
		$this->assertStringContainsString( 'Plain', $html );
	}
}
