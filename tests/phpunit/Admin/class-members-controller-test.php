<?php
/**
 * Characterization tests for Members_Controller.
 *
 * Pins current behavior of the members admin action router (create/update/
 * delete/import/download-sample/bulk and per-member upload emails) ahead of a
 * planned refactor. Assertions describe what the code does today.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Members_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Characterization tests for the members controller.
 *
 * @covers \PhotoCompetitionManager\Admin\Members_Controller
 */
class Members_Controller_Test extends Admin_Controller_Test_Case {

	private const GROUP = 'photo_competition_members';

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
	 * Controller under test.
	 *
	 * @var Members_Controller
	 */
	private $controller;

	/**
	 * Set up the controller and repositories.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->competitions = new Competitions_Repository();
		$this->members      = new Members_Repository();
		$this->controller   = new Members_Controller( $this->competitions, $this->members );
	}

	/**
	 * Create an open competition and return its ID.
	 *
	 * @param string $title Title.
	 * @param string $slug  Slug.
	 * @return int Competition ID.
	 */
	private function create_open_competition( string $title = 'Spring Show', string $slug = 'spring-show' ): int {
		return $this->competitions->create(
			array(
				'title'      => $title,
				'slug'       => $slug,
				'open_date'  => '2026-01-01 00:00:00',
				'close_date' => '2026-12-31 00:00:00',
				'settings'   => array(),
			)
		);
	}

	/**
	 * Create a member and return its ID.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 * @return int Member ID.
	 */
	private function create_member( array $overrides = array() ): int {
		$data = array_merge(
			array(
				'name'      => 'Ada Lovelace',
				'email'     => 'ada@example.com',
				'grade'     => 'beginner',
				'active'    => 1,
				'committee' => 0,
			),
			$overrides
		);

		return (int) $this->members->create( $data );
	}

	/*
	 * -----------------------------------------------------------------
	 * Capability guard.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Without the capability, handle_actions() is a no-op (no member created).
	 */
	public function test_handle_actions_noop_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->set_request(
			array(
				'photo_competition_action' => 'create_member',
				'member_name'              => 'Nope',
				'member_email'             => 'nope@example.com',
				'member_grade'             => 'beginner',
			)
		);
		$this->set_nonce( 'photo_competition_member_create', 'photo_competition_member_nonce' );

		$this->controller->handle_actions();

		$this->assertNull( $this->members->find_by_email( 'nope@example.com' ) );
		$this->assertSame( array(), $this->settings_error_codes( self::GROUP ) );
	}

	/*
	 * -----------------------------------------------------------------
	 * create_member.
	 * -----------------------------------------------------------------
	 */

	/**
	 * A valid submission creates the member and redirects to the members list.
	 */
	public function test_create_member_success(): void {
		$this->set_request(
			array(
				'photo_competition_action' => 'create_member',
				'member_name'              => 'Grace Hopper',
				'member_email'             => 'grace@example.com',
				'member_grade'             => 'advanced',
				'member_active'            => '1',
			)
		);
		$this->set_nonce( 'photo_competition_member_create', 'photo_competition_member_nonce' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager-members', $location );
		$this->assertContains( 'member_created', $this->settings_error_codes( self::GROUP ) );

		$member = $this->members->find_by_email( 'grace@example.com' );
		$this->assertNotNull( $member );
		$this->assertSame( 'Grace Hopper', $member->name );
	}

	/**
	 * A missing name surfaces the repository's invalid_name error and creates nothing.
	 */
	public function test_create_member_validation_error_missing_name(): void {
		$this->set_request(
			array(
				'photo_competition_action' => 'create_member',
				'member_name'              => '',
				'member_email'             => 'noname@example.com',
				'member_grade'             => 'beginner',
			)
		);
		$this->set_nonce( 'photo_competition_member_create', 'photo_competition_member_nonce' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'invalid_name', $this->settings_error_codes( self::GROUP ) );
		$this->assertNull( $this->members->find_by_email( 'noname@example.com' ) );
	}

	/**
	 * A missing/invalid nonce aborts create via wp_die().
	 */
	public function test_create_member_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'photo_competition_action' => 'create_member',
				'member_name'              => 'Grace Hopper',
				'member_email'             => 'grace@example.com',
				'member_grade'             => 'advanced',
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * update_member.
	 * -----------------------------------------------------------------
	 */

	/**
	 * A valid update persists the changes and redirects to the members list.
	 */
	public function test_update_member_success(): void {
		$id = $this->create_member( array( 'name' => 'Old Name' ) );

		$this->set_request(
			array(
				'photo_competition_action' => 'update_member',
				'member_id'                => $id,
				'member_name'              => 'New Name',
				'member_email'             => 'ada@example.com',
				'member_grade'             => 'advanced',
				'member_active'            => '1',
			)
		);
		$this->set_nonce( 'photo_competition_member_update_' . $id, 'photo_competition_member_nonce' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager-members', $location );
		$this->assertStringNotContainsString( 'member_action=edit', $location );
		$this->assertContains( 'member_updated', $this->settings_error_codes( self::GROUP ) );
		$this->assertSame( 'New Name', $this->members->find( $id )->name );
	}

	/**
	 * A validation failure (duplicate email) surfaces the error and redirects
	 * back to the edit form for that member.
	 */
	public function test_update_member_validation_error_redirects_to_edit(): void {
		$this->create_member( array( 'email' => 'taken@example.com' ) );
		$id = $this->create_member(
			array(
				'name'  => 'Second',
				'email' => 'second@example.com',
			)
		);

		$this->set_request(
			array(
				'photo_competition_action' => 'update_member',
				'member_id'                => $id,
				'member_name'              => 'Second',
				'member_email'             => 'taken@example.com',
				'member_grade'             => 'beginner',
			)
		);
		$this->set_nonce( 'photo_competition_member_update_' . $id, 'photo_competition_member_nonce' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'duplicate_email', $this->settings_error_codes( self::GROUP ) );
		$this->assertStringContainsString( 'member_action=edit', $location );
		$this->assertStringContainsString( 'member=' . $id, $location );
		// Email unchanged.
		$this->assertSame( 'second@example.com', $this->members->find( $id )->email );
	}

	/**
	 * A missing/invalid nonce aborts update via wp_die().
	 */
	public function test_update_member_bad_nonce_dies(): void {
		$id = $this->create_member();

		$this->set_request(
			array(
				'photo_competition_action' => 'update_member',
				'member_id'                => $id,
				'member_name'              => 'New Name',
				'member_email'             => 'ada@example.com',
				'member_grade'             => 'beginner',
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * delete_member.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Deleting an existing member removes it and reports success.
	 */
	public function test_delete_member_success(): void {
		$id = $this->create_member();

		$this->set_request(
			array(
				'action' => 'delete_member',
				'member' => $id,
			)
		);
		$this->set_nonce( 'photo_competition_delete_member_' . $id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'member_deleted', $this->settings_error_codes( self::GROUP ) );
		$this->assertNull( $this->members->find( $id ) );
	}

	/**
	 * A zero/invalid member ID short-circuits before the nonce check with
	 * an invalid_member error.
	 */
	public function test_delete_member_invalid_id(): void {
		$this->set_request(
			array(
				'action' => 'delete_member',
				'member' => 0,
			)
		);

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'invalid_member', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A non-existent member ID (with a valid nonce) reports member_not_found.
	 */
	public function test_delete_member_not_found(): void {
		$missing = 999999;

		$this->set_request(
			array(
				'action' => 'delete_member',
				'member' => $missing,
			)
		);
		$this->set_nonce( 'photo_competition_delete_member_' . $missing );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'member_not_found', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A missing/invalid nonce aborts delete via wp_die().
	 */
	public function test_delete_member_bad_nonce_dies(): void {
		$id = $this->create_member();

		$this->set_request(
			array(
				'action' => 'delete_member',
				'member' => $id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * send_member_upload_email.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Sending to an active member of an open competition reports success.
	 */
	public function test_send_member_upload_email_success(): void {
		$competition_id = $this->create_open_competition();
		$member_id      = $this->create_member(
			array(
				'active' => 1,
				'email'  => 'active@example.com',
			)
		);

		$this->set_request(
			array(
				'action'      => 'send_member_upload_email',
				'member'      => $member_id,
				'competition' => $competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_send_member_email_' . $member_id . '_' . $competition_id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'upload_email_sent', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * Missing member/competition parameters short-circuit (before the nonce
	 * check) with invalid_params.
	 */
	public function test_send_member_upload_email_invalid_params(): void {
		$this->set_request(
			array(
				'action'      => 'send_member_upload_email',
				'member'      => 0,
				'competition' => 0,
			)
		);

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'invalid_params', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A competition that is not open yields invalid_competition.
	 */
	public function test_send_member_upload_email_competition_not_open(): void {
		$missing_competition = 999999;
		$member_id           = $this->create_member();

		$this->set_request(
			array(
				'action'      => 'send_member_upload_email',
				'member'      => $member_id,
				'competition' => $missing_competition,
			)
		);
		$this->set_nonce( 'photo_competition_send_member_email_' . $member_id . '_' . $missing_competition );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'invalid_competition', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * An inactive member (open competition) yields invalid_member.
	 */
	public function test_send_member_upload_email_inactive_member(): void {
		$competition_id = $this->create_open_competition();
		$member_id      = $this->create_member(
			array(
				'active' => 0,
				'email'  => 'inactive@example.com',
			)
		);

		$this->set_request(
			array(
				'action'      => 'send_member_upload_email',
				'member'      => $member_id,
				'competition' => $competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_send_member_email_' . $member_id . '_' . $competition_id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'invalid_member', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A missing/invalid nonce aborts the send via wp_die().
	 */
	public function test_send_member_upload_email_bad_nonce_dies(): void {
		$competition_id = $this->create_open_competition();
		$member_id      = $this->create_member();

		$this->set_request(
			array(
				'action'      => 'send_member_upload_email',
				'member'      => $member_id,
				'competition' => $competition_id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * import_members_csv.
	 *
	 * The controller requires is_uploaded_file() to be true for the happy
	 * path, which cannot be satisfied inside PHPUnit (no real HTTP upload).
	 * Only the no-file and bad-nonce failure branches are exercised here.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Importing with no file attached reports the no_file error.
	 */
	public function test_import_members_csv_no_file(): void {
		$_FILES = array();

		$this->set_request(
			array(
				'photo_competition_action' => 'import_members_csv',
			)
		);
		$this->set_nonce( 'photo_competition_import_members', 'photo_competition_import_nonce' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'no_file', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A missing/invalid nonce aborts import via wp_die().
	 */
	public function test_import_members_csv_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'photo_competition_action' => 'import_members_csv',
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * download_sample_csv.
	 *
	 * The success path echoes CSV then calls exit() directly (not via the
	 * redirect filter), which is uncatchable in-process, so only the
	 * bad-nonce guard is exercised.
	 * -----------------------------------------------------------------
	 */

	/**
	 * A missing/invalid nonce aborts the sample download via wp_die().
	 */
	public function test_download_sample_csv_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'action' => 'download_sample_csv',
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * Bulk actions.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Bulk activate flips selected members to active and reports the count.
	 */
	public function test_bulk_activate_success(): void {
		$a = $this->create_member(
			array(
				'email'  => 'a@example.com',
				'active' => 0,
			)
		);
		$b = $this->create_member(
			array(
				'email'  => 'b@example.com',
				'active' => 0,
			)
		);

		$this->set_request(
			array(
				'action'     => 'bulk_activate',
				'member_ids' => array( $a, $b ),
			)
		);
		$this->set_nonce( 'photo_competition_bulk_members' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'bulk_activated', $this->settings_error_codes( self::GROUP ) );
		$this->assertSame( 1, (int) $this->members->find( $a )->active );
		$this->assertSame( 1, (int) $this->members->find( $b )->active );
	}

	/**
	 * Bulk deactivate flips selected members to inactive.
	 */
	public function test_bulk_deactivate_success(): void {
		$a = $this->create_member(
			array(
				'email'  => 'a@example.com',
				'active' => 1,
			)
		);

		$this->set_request(
			array(
				'action'     => 'bulk_deactivate',
				'member_ids' => array( $a ),
			)
		);
		$this->set_nonce( 'photo_competition_bulk_members' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'bulk_deactivated', $this->settings_error_codes( self::GROUP ) );
		$this->assertSame( 0, (int) $this->members->find( $a )->active );
	}

	/**
	 * Bulk update grade sets the new grade on selected members.
	 */
	public function test_bulk_update_grade_success(): void {
		$a = $this->create_member(
			array(
				'email' => 'a@example.com',
				'grade' => 'beginner',
			)
		);

		$this->set_request(
			array(
				'action'     => 'bulk_update_grade',
				'member_ids' => array( $a ),
				'bulk_grade' => 'advanced',
			)
		);
		$this->set_nonce( 'photo_competition_bulk_members' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'bulk_grade_updated', $this->settings_error_codes( self::GROUP ) );
		$this->assertSame( 'advanced', $this->members->find( $a )->grade );
	}

	/**
	 * Bulk update grade with no grade selected reports no_grade_selected.
	 */
	public function test_bulk_update_grade_no_grade_selected(): void {
		$a = $this->create_member();

		$this->set_request(
			array(
				'action'     => 'bulk_update_grade',
				'member_ids' => array( $a ),
				'bulk_grade' => '',
			)
		);
		$this->set_nonce( 'photo_competition_bulk_members' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'no_grade_selected', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * Bulk action with no members selected reports no_members_selected.
	 */
	public function test_bulk_no_members_selected(): void {
		$this->set_request(
			array(
				'action'     => 'bulk_activate',
				'member_ids' => array(),
			)
		);
		$this->set_nonce( 'photo_competition_bulk_members' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'no_members_selected', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A mix of a valid and a non-existent member ID reports both the success
	 * count and the partial-failure error.
	 */
	public function test_bulk_activate_partial_failure(): void {
		$a       = $this->create_member( array( 'active' => 0 ) );
		$missing = 999999;

		$this->set_request(
			array(
				'action'     => 'bulk_activate',
				'member_ids' => array( $a, $missing ),
			)
		);
		$this->set_nonce( 'photo_competition_bulk_members' );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$codes = $this->settings_error_codes( self::GROUP );
		$this->assertContains( 'bulk_activated', $codes );
		$this->assertContains( 'bulk_partial_failure', $codes );
		$this->assertSame( 1, (int) $this->members->find( $a )->active );
	}

	/**
	 * A missing/invalid nonce aborts bulk actions via wp_die().
	 */
	public function test_bulk_bad_nonce_dies(): void {
		$a = $this->create_member();

		$this->set_request(
			array(
				'action'     => 'bulk_activate',
				'member_ids' => array( $a ),
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}
}
