<?php
/**
 * Golden-master snapshot tests for Members_Controller::render().
 *
 * Pins the exact rendered HTML ahead of the template-partial extraction (#40).
 * Nonces, upload-link tokens, wall-clock created_at values, and non-rollback
 * auto-increment IDs are normalized so snapshots do not churn per run.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Members_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;

/**
 * @covers \PhotoCompetitionManager\Admin\Members_Controller
 */
class Members_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Competitions_Repository */
	private $competitions;

	/** @var Members_Repository */
	private $members;

	/** @var Members_Controller */
	private $controller;

	public function set_up(): void {
		parent::set_up();
		$this->competitions = new Competitions_Repository();
		$this->members       = new Members_Repository();
		$this->controller    = new Members_Controller( $this->competitions, $this->members );
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
	 */
	private function render_normalized( array $competition_ids = array(), array $member_ids = array() ): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		// Normalize per-run nonces embedded via wp_nonce_url() query args.
		$html = preg_replace( '/_wpnonce=[a-f0-9]{10}/', '_wpnonce=NONCE', $html );

		// Normalize per-run nonce hidden-field values from wp_nonce_field(), for
		// every distinct nonce field name this controller emits.
		$html = preg_replace(
			'/(id="(?:_wpnonce|photo_competition_member_nonce|photo_competition_import_nonce)" name="(?:_wpnonce|photo_competition_member_nonce|photo_competition_import_nonce)" value=")[a-f0-9]{10}(")/',
			'$1NONCE$2',
			$html
		);

		// Normalize the referer hidden field wp_nonce_field() emits by default;
		// its value is the current REQUEST_URI, which is test-runner dependent.
		$html = preg_replace( '/(name="_wp_http_referer" value=")[^"]*(")/', '$1REFERER$2', $html );

		// Normalize per-run upload tokens embedded in "Upload Link" hrefs:
		// generate_upload_url() mints a fresh 64-char hex token whenever no
		// unexpired token exists yet for the member/competition pair.
		$html = preg_replace( '/token=[a-f0-9]{64}/', 'token=TOKEN', $html );

		// Normalize the members table's "Joined" column: Members_Repository::create()
		// always stamps created_at with the current time (it ignores any
		// caller-supplied override), so the formatted date/time is wall-clock
		// dependent and would otherwise churn between the two required test runs.
		$html = preg_replace( '/<td>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}<\/td>/', '<td>CREATED_AT</td>', $html );

		// Normalize non-deterministic auto-increment competition IDs, scoped to
		// the "competition=" query-arg contexts (toggle-uploads link, per-member
		// send-upload-email link). A document-wide digit replacement is too
		// broad and would collide with unrelated numbers (grade slugs, random
		// numbers, etc.) elsewhere on the page.
		foreach ( $competition_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			$html       = preg_replace( '/competition=' . $id_pattern . '(?!\d)/', 'competition=ID', $html );
		}

		// Normalize non-deterministic auto-increment member IDs, scoped to the
		// row checkbox value, the "member=" query-arg contexts (edit link,
		// delete link, send-upload-email link), and the edit form's hidden
		// member_id field.
		foreach ( $member_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			$html       = preg_replace( '/name="member_ids\[\]" value="' . $id_pattern . '"/', 'name="member_ids[]" value="ID"', $html );
			$html       = preg_replace( '/member=' . $id_pattern . '(?!\d)/', 'member=ID', $html );
			$html       = preg_replace( '/name="member_id" value="' . $id_pattern . '"/', 'name="member_id" value="ID"', $html );
		}

		return $html;
	}

	/**
	 * Assert live output equals the stored snapshot; write it on first run.
	 *
	 * @param string         $scenario        Snapshot scenario name.
	 * @param array<int,int> $competition_ids Competition IDs to normalize before comparing.
	 * @param array<int,int> $member_ids      Member IDs to normalize before comparing.
	 */
	private function assert_matches_snapshot( string $scenario, array $competition_ids = array(), array $member_ids = array() ): void {
		$dir  = __DIR__ . '/../fixtures/members-render';
		$file = $dir . '/' . $scenario . '.html';
		$html = $this->render_normalized( $competition_ids, $member_ids );

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
	 * @param string $title    Title.
	 * @param string $slug     Slug.
	 * @param array  $settings Raw settings array.
	 */
	private function seed_competition( string $title, string $slug, array $settings = array() ): int {
		$id = $this->competitions->create(
			array(
				'title'      => $title,
				'slug'       => $slug,
				'open_date'  => null,
				'close_date' => null,
				'settings'   => $settings,
			)
		);

		return (int) $id;
	}

	/**
	 * Seed a member and return its ID.
	 *
	 * @param string               $name  Member name.
	 * @param string               $email Member email.
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

	/*
	 * -----------------------------------------------------------------
	 * Scenarios.
	 * -----------------------------------------------------------------
	 */

	public function test_render_empty_no_members(): void {
		// No members, no competitions: empty-state message, no upload-status
		// notice bar, but the create and import forms still render.
		$this->assert_matches_snapshot( 'empty-no-members' );
	}

	public function test_render_list_no_active_competition(): void {
		// Members exist but there is no open/active competition: every row
		// falls into the disabled "Send Upload Email" span branch, and the
		// upload-status notice bar is absent.
		$active = $this->seed_member( 'Ada Lovelace', 'ada@example.com', array( 'grade' => 'advanced', 'active' => 1 ) );
		$inactive = $this->seed_member( 'Bob Baker', 'bob@example.com', array( 'grade' => 'beginner', 'active' => 0 ) );

		$this->assert_matches_snapshot( 'list-no-active-competition', array(), array( $active, $inactive ) );
	}

	public function test_render_list_uploads_open(): void {
		// Active, open competition with uploads not closed: exercises the
		// "Uploads are open" / "Close Uploads" notice branch, plus the
		// per-row "Send Upload Email" + "Upload Link" branch for active
		// members with an email address, the disabled-span branch for an
		// inactive member, and the grade-label fallback for a grade slug
		// that doesn't match any configured grade option.
		$comp_id = $this->seed_competition( 'Spring Show', 'spring-show' );

		$ada   = $this->seed_member( 'Ada Lovelace', 'ada@example.com', array( 'grade' => 'advanced', 'active' => 1, 'committee' => 1 ) );
		$bob   = $this->seed_member( 'Bob Baker', 'bob@example.com', array( 'grade' => 'beginner', 'active' => 0 ) );
		$carol = $this->seed_member( 'Carol Diaz', 'carol@example.com', array( 'grade' => 'unknown-grade', 'active' => 1 ) );

		$this->assert_matches_snapshot( 'list-uploads-open', array( $comp_id ), array( $ada, $bob, $carol ) );
	}

	public function test_render_list_uploads_closed(): void {
		// Active, open competition with uploads explicitly closed: exercises
		// the "Uploads are closed" / "Open Uploads" notice-warning branch.
		$comp_id = $this->seed_competition(
			'Winter Salon',
			'winter-salon',
			array(
				'upload' => array( 'uploads_closed' => true ),
			)
		);

		$dave = $this->seed_member( 'Dave Evans', 'dave@example.com', array( 'grade' => 'intermediate', 'active' => 1 ) );

		$this->assert_matches_snapshot( 'list-uploads-closed', array( $comp_id ), array( $dave ) );
	}

	public function test_render_filtered_no_match(): void {
		// Search filter that matches nothing: "No members found matching the
		// selected filters." message plus the "Clear Filters" link.
		$this->seed_member( 'Ada Lovelace', 'ada@example.com', array( 'grade' => 'advanced', 'active' => 1 ) );

		$this->set_request(
			array(
				'page' => 'photo-competition-manager-members',
				's'    => 'nonexistent-search-term',
			)
		);

		$this->assert_matches_snapshot( 'filtered-no-match' );
	}

	public function test_render_filtered_with_results(): void {
		// Status filter narrows the list: "Showing X of Y members" count
		// text, plus the "Clear Filters" link.
		$ada = $this->seed_member( 'Ada Lovelace', 'ada@example.com', array( 'grade' => 'advanced', 'active' => 1 ) );
		$bob = $this->seed_member( 'Bob Baker', 'bob@example.com', array( 'grade' => 'beginner', 'active' => 0 ) );

		$this->set_request(
			array(
				'page'   => 'photo-competition-manager-members',
				'status' => 'active',
			)
		);

		$this->assert_matches_snapshot( 'filtered-with-results', array(), array( $ada, $bob ) );
	}

	public function test_render_edit_existing_member(): void {
		// member_action=edit with a valid member ID, and an active/open
		// competition present at the same time (the notice bar and the edit
		// form both render; the members list/create/import forms do not).
		// The member has committee=1 and grade=advanced, exercising the
		// checked()/selected() true branches in the edit form.
		$comp_id = $this->seed_competition( 'Spring Show', 'spring-show' );
		$ada     = $this->seed_member( 'Ada Lovelace', 'ada@example.com', array( 'grade' => 'advanced', 'active' => 1, 'committee' => 1 ) );

		$this->set_request(
			array(
				'page'          => 'photo-competition-manager-members',
				'member_action' => 'edit',
				'member'        => (string) $ada,
			)
		);

		$this->assert_matches_snapshot( 'edit-existing-member', array( $comp_id ), array( $ada ) );
	}

	public function test_render_edit_member_not_found(): void {
		// member_action=edit with an ID that doesn't resolve to a member:
		// the "Member not found" notice + "Back to members" link branch.
		$this->set_request(
			array(
				'page'          => 'photo-competition-manager-members',
				'member_action' => 'edit',
				'member'        => '999999999',
			)
		);

		$this->assert_matches_snapshot( 'edit-member-not-found' );
	}
}
