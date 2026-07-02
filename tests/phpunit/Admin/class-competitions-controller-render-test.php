<?php
/**
 * Golden-master snapshot tests for Competitions_Controller::render().
 *
 * Pins the exact rendered HTML ahead of the template-partial extraction (#40).
 * Nonces (both the wp_nonce_url() query-arg form and wp_nonce_field() hidden
 * inputs) and the wp_nonce_field() referer hidden field are normalized so
 * snapshots do not churn per run. Auto-increment competition IDs are
 * normalized in their known contexts (competition= query args, the
 * competition_id hidden field) for the same reason -- MySQL does not roll
 * back AUTO_INCREMENT counters with the transactional test rollback.
 * created_at/updated_at/deleted_at are forced directly via a helper so the
 * "Last Updated" column and list ordering are wall-clock independent and do
 * not need normalization.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Competitions_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;

/**
 * @covers \PhotoCompetitionManager\Admin\Competitions_Controller
 */
class Competitions_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Competitions_Repository */
	private $competitions;

	/** @var Competitions_Controller */
	private $controller;

	public function set_up(): void {
		parent::set_up();

		$this->competitions = new Competitions_Repository();
		$this->controller   = new Competitions_Controller( $this->competitions );
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
	 */
	private function render_normalized( array $competition_ids = array() ): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		// Normalize per-run nonces embedded via wp_nonce_url() query args
		// (table row action links).
		$html = preg_replace( '/_wpnonce=[a-f0-9]{10}/', '_wpnonce=NONCE', $html );

		// Normalize the per-run nonce hidden-field value from wp_nonce_field()
		// (create/general/settings forms all use the same field id/name).
		$html = preg_replace(
			'/(id="photo_competition_nonce" name="photo_competition_nonce" value=")[a-f0-9]{10}(")/',
			'$1NONCE$2',
			$html
		);

		// Normalize the referer hidden field wp_nonce_field() emits by default;
		// its value is the current REQUEST_URI, which is test-runner dependent.
		$html = preg_replace( '/(name="_wp_http_referer" value=")[^"]*(")/', '$1REFERER$2', $html );

		// Normalize non-deterministic auto-increment competition IDs, scoped to
		// the "competition=" query-arg contexts (table row action links) and
		// the competition_id hidden field emitted by the general/settings
		// forms. A document-wide digit replacement is too broad and would
		// collide with unrelated numbers (quotas, durations, score matrices)
		// elsewhere on the page.
		foreach ( $competition_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			$html       = preg_replace( '/competition=' . $id_pattern . '(?!\d)/', 'competition=ID', $html );
			$html       = preg_replace( '/name="competition_id" value="' . $id_pattern . '"/', 'name="competition_id" value="ID"', $html );
		}

		// Fold numeric &#038; to &amp; so snapshots are agnostic to which
		// ampersand entity WordPress emits (esc_url uses &#038;, esc_attr
		// &amp;; core has changed usage between releases).
		$html = preg_replace( '/&#0*38;/', '&amp;', $html );

		return $html;
	}

	/**
	 * Assert live output equals the stored snapshot; write it on first run.
	 *
	 * @param string          $scenario        Snapshot scenario name.
	 * @param array<int, int> $competition_ids Competition IDs to normalize before comparing.
	 */
	private function assert_matches_snapshot( string $scenario, array $competition_ids = array() ): void {
		$dir  = __DIR__ . '/../fixtures/competitions-render';
		$file = $dir . '/' . $scenario . '.html';
		$html = $this->render_normalized( $competition_ids );

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
	 * @param string               $title      Title.
	 * @param string               $slug       Slug.
	 * @param array<string, mixed> $overrides  Field overrides (open_date, close_date, settings, share_hash).
	 * @param array<string, mixed> $force      Fields to force directly on the row after insert
	 *                                         (created_at/updated_at/deleted_at), bypassing the
	 *                                         repository's wall-clock timestamp assignment so list
	 *                                         ordering and the "Last Updated" column are deterministic.
	 */
	private function seed_competition( string $title, string $slug, array $overrides = array(), array $force = array() ): int {
		$id = $this->competitions->create(
			array_merge(
				array(
					'title'      => $title,
					'slug'       => $slug,
					'open_date'  => null,
					'close_date' => null,
				),
				$overrides
			)
		);

		if ( ! empty( $force ) ) {
			$this->force_fields( (int) $id, $force );
		}

		return (int) $id;
	}

	/**
	 * Force fields directly on a competition row, bypassing repository
	 * timestamp auto-assignment, to make created_at/updated_at/deleted_at
	 * deterministic in tests.
	 *
	 * @param int                  $id     Row ID.
	 * @param array<string, mixed> $fields Column => value pairs to set.
	 */
	private function force_fields( int $id, array $fields ): void {
		global $wpdb;

		$formats = array_fill( 0, count( $fields ), '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->competitions->table(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	/*
	 * -----------------------------------------------------------------
	 * Scenarios.
	 * -----------------------------------------------------------------
	 */

	public function test_render_no_competitions_active(): void {
		// No competitions at all, default view (active): empty-state message
		// in the table area, zero counts in the subsubsub view links, plus
		// the always-present create form.
		$this->assert_matches_snapshot( 'no-competitions-active' );
	}

	public function test_render_list_active_with_rows(): void {
		// Two active competitions exercising both states of the per-row
		// branches: Spring Show is currently open (open_date in the past,
		// close_date in the future) with uploads NOT closed, so it shows
		// "Close Uploads" and an active "Send Upload Emails" link. Winter
		// Show is closed (close_date in the past) with uploads closed, so it
		// shows "Open Uploads" and the disabled "Send Upload Emails" span.
		// Winter Show's updated_at is forced NULL to exercise the
		// updated_at-empty fallback to created_at in the "Last Updated"
		// column; Spring Show's updated_at differs from created_at to
		// exercise the non-fallback branch. Distinct created_at values pin
		// the ORDER BY created_at DESC list order (Winter Show first).
		$spring_id = $this->seed_competition(
			'Spring Show',
			'spring-show',
			array(
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2099-01-01 00:00:00',
			),
			array(
				'created_at' => '2026-01-01 00:00:00',
				'updated_at' => '2026-01-05 00:00:00',
			)
		);

		$winter_id = $this->seed_competition(
			'Winter Show',
			'winter-show',
			array(
				'open_date'  => null,
				'close_date' => '2020-01-01 00:00:00',
				'settings'   => array( 'upload' => array( 'uploads_closed' => true ) ),
			),
			array(
				'created_at' => '2026-02-01 00:00:00',
				'updated_at' => null,
			)
		);

		$this->assert_matches_snapshot( 'list-active-with-rows', array( $spring_id, $winter_id ) );
	}

	public function test_render_list_archived_view(): void {
		// A single archived competition, requested via view=archived: the
		// Restore action replaces Archive, the toggle-uploads action is
		// omitted entirely (archived rows never show it), and Send Upload
		// Emails is always the disabled span regardless of open/close dates
		// once archived.
		$comp_id = $this->seed_competition(
			'Retired Salon',
			'retired-salon',
			array(
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-06-01 00:00:00',
			),
			array(
				'created_at' => '2026-01-01 00:00:00',
				'updated_at' => '2026-01-02 00:00:00',
				'deleted_at' => '2026-03-01 00:00:00',
			)
		);

		$this->set_request( array( 'view' => 'archived' ) );

		$this->assert_matches_snapshot( 'list-archived-view', array( $comp_id ) );
	}

	public function test_render_list_with_settings_error(): void {
		// A registered settings error (e.g. from a failed validation on the
		// prior POST) renders via settings_errors() before the table;
		// otherwise the empty active-view state.
		add_settings_error(
			'photo_competition_manager',
			'missing_categories',
			'At least one category is required.',
			'error'
		);

		$this->assert_matches_snapshot( 'list-with-settings-error' );
	}

	public function test_render_edit_not_found(): void {
		// action=edit with a competition ID that does not resolve: the
		// "Competition not found" branch, which returns early without
		// rendering tabs or either form.
		$this->set_request(
			array(
				'action'      => 'edit',
				'competition' => '999999',
			)
		);

		$this->assert_matches_snapshot( 'edit-not-found' );
	}

	public function test_render_edit_general_tab(): void {
		// action=edit with no tab= (defaults to 'general'): the General tab
		// is nav-tab-active, Settings is not, and the general form's date
		// inputs are populated (format_date_for_input's non-empty branch)
		// since both open_date and close_date are set.
		$comp_id = $this->seed_competition(
			'Spring Show',
			'spring-show',
			array(
				'open_date'  => '2026-04-01 00:00:00',
				'close_date' => '2026-04-30 00:00:00',
			),
			array(
				'created_at' => '2026-01-01 00:00:00',
				'updated_at' => '2026-01-01 00:00:00',
			)
		);

		$this->set_request(
			array(
				'action'      => 'edit',
				'competition' => (string) $comp_id,
			)
		);

		$this->assert_matches_snapshot( 'edit-general-tab', array( $comp_id ) );
	}

	public function test_render_edit_settings_tab_defaults(): void {
		// tab=settings with no settings saved (falls back to the hard-coded
		// defaults: 2 categories, 3 grades), empty voting password (neither
		// plaintext nor legacy-hash -- the "leave blank" description
		// branch), auth_mode password, voting_ui_type resolved via the
		// get_voting_ui_type() fallback (no photo_comp_voting_ui_type option
		// saved, so "buttons"), default "bar" progress-meter active card,
		// and a non-empty share_hash with no results_page URL configured
		// (the "share hash, but no results page URL" branch).
		$comp_id = $this->seed_competition(
			'Autumn Exhibition',
			'autumn-exhibition',
			array( 'share_hash' => 'abc123sharehash' ),
			array(
				'created_at' => '2026-01-01 00:00:00',
				'updated_at' => '2026-01-01 00:00:00',
			)
		);

		$this->set_request(
			array(
				'action'      => 'edit',
				'competition' => (string) $comp_id,
				'tab'         => 'settings',
			)
		);

		$this->assert_matches_snapshot( 'edit-settings-tab-defaults', array( $comp_id ) );
	}

	public function test_render_edit_settings_tab_configured(): void {
		// tab=settings with a fully configured, non-default settings array:
		// 3 categories and 2 grades (both counts different from the 2/3
		// defaults, to prove the category-field/grade-field loops emit the
		// right number of rows), token auth mode, a plaintext (non-legacy)
		// voting password, click-to-zoom enabled, voting_ui_type explicitly
		// "dropdown", the "radial" progress-meter active card, and a
		// share_hash with a results_page URL configured (the full share-link
		// branch).
		$settings = array(
			'categories' => array(
				array(
					'slug'  => 'colour',
					'label' => 'Colour',
					'quota' => 2,
				),
				array(
					'slug'  => 'mono',
					'label' => 'Monochrome',
					'quota' => 1,
				),
				array(
					'slug'  => 'nature',
					'label' => 'Nature',
					'quota' => 3,
				),
			),
			'grades'     => array(
				array(
					'slug'  => 'novice',
					'label' => 'Novice',
				),
				array(
					'slug'  => 'expert',
					'label' => 'Expert',
				),
			),
			'upload'     => array(
				'max_file_size_mb' => 8,
				'max_width'        => 2000,
				'max_height'       => 1500,
			),
			'voting'     => array(
				'score_matrix'        => array( 10, 8, 6, 4, 2 ),
				'auth_mode'           => 'token',
				'password'            => 'secret123',
				'click_image_to_zoom' => true,
				'ui_type'             => 'dropdown',
			),
			'slideshow'  => array(
				'progress_meter_type' => 'radial',
			),
			'urls'       => array(
				'upload_page'  => 'https://example.com/upload',
				'voting_page'  => 'https://example.com/vote',
				'results_page' => 'https://example.com/results',
			),
		);

		$comp_id = $this->seed_competition(
			'Summer Salon',
			'summer-salon',
			array(
				'settings'   => $settings,
				'share_hash' => 'def456sharehash',
			),
			array(
				'created_at' => '2026-01-01 00:00:00',
				'updated_at' => '2026-01-01 00:00:00',
			)
		);

		$this->set_request(
			array(
				'action'      => 'edit',
				'competition' => (string) $comp_id,
				'tab'         => 'settings',
			)
		);

		$this->assert_matches_snapshot( 'edit-settings-tab-configured', array( $comp_id ) );
	}

	public function test_render_edit_settings_tab_legacy_password(): void {
		// tab=settings with a legacy phpass-style hashed password (matches
		// /^\$P\$|\$wp\$/): the password field renders blank with the
		// "Remove password protection" checkbox and legacy-specific
		// description, instead of the plaintext value. No share_hash is
		// configured (create()'s default when the override is omitted),
		// exercising the "no share_hash at all" branch that skips the whole
		// share section.
		$comp_id = $this->seed_competition(
			'Winter Salon',
			'winter-salon',
			array(
				'settings' => array(
					'voting' => array(
						'password' => '$P$B1234567890abcdefghijklmnopqrstuv0',
					),
				),
			),
			array(
				'created_at' => '2026-01-01 00:00:00',
				'updated_at' => '2026-01-01 00:00:00',
			)
		);

		$this->set_request(
			array(
				'action'      => 'edit',
				'competition' => (string) $comp_id,
				'tab'         => 'settings',
			)
		);

		$this->assert_matches_snapshot( 'edit-settings-tab-legacy-password', array( $comp_id ) );
	}
}
