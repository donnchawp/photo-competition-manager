<?php
/**
 * Golden-master snapshot tests for Submissions_Controller::render().
 *
 * Pins the exact rendered HTML ahead of the template-partial extraction (#40).
 * Nonces, upload-link tokens, and non-rollback auto-increment IDs are
 * normalized so snapshots do not churn per run.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Submissions_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;

/**
 * @covers \PhotoCompetitionManager\Admin\Submissions_Controller
 */
class Submissions_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Competitions_Repository */
	private $competitions;

	/** @var Members_Repository */
	private $members;

	/** @var Images_Repository */
	private $images;

	/** @var Votes_Repository */
	private $votes;

	/** @var Submissions_Controller */
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
		$this->members      = new Members_Repository();
		$this->images       = new Images_Repository();
		$this->votes        = new Votes_Repository();
		$this->controller   = new Submissions_Controller(
			$this->competitions,
			$this->members,
			$this->images,
			$this->votes
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
	 * @param array<int,int> $image_ids       Image (submission) IDs to normalize, same reason.
	 */
	private function render_normalized( array $competition_ids = array(), array $member_ids = array(), array $image_ids = array() ): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		// Normalize per-run nonces: _wpnonce=<10 hex> and nonce field values.
		$html = preg_replace( '/(_wpnonce=)[a-f0-9]{10}/', '$1NONCE', $html );
		$html = preg_replace( '/(name="_wpnonce" value=")[a-f0-9]{10}/', '$1NONCE', $html );

		// Normalize the referer hidden field wp_nonce_field() emits by default;
		// its value is the current REQUEST_URI, which is test-runner dependent.
		$html = preg_replace( '/(name="_wp_http_referer" value=")[^"]*(")/', '$1REFERER$2', $html );

		// Normalize per-run upload tokens embedded in "View/Edit Uploads" links:
		// generate_upload_url() mints a fresh 64-char hex token whenever no
		// unexpired token exists yet for the member/competition pair.
		$html = preg_replace( '/token=[a-f0-9]{64}/', 'token=TOKEN', $html );

		// Normalize the submissions table's "Submitted" column: Images_Repository::create()
		// always stamps created_at with the current time (it ignores any
		// caller-supplied override), so the formatted date/time is wall-clock
		// dependent and would otherwise churn between the two required test runs.
		$html = preg_replace( '/<td>[A-Z][a-z]+ \d{1,2}, \d{4} \d{1,2}:\d{2} (?:am|pm)<\/td><\/tr>/', '<td>SUBMITTED</td></tr>', $html );

		// Normalize non-deterministic auto-increment competition IDs, scoped to
		// the specific contexts where a competition ID legitimately appears:
		// the filter dropdown's <option value="..."> and the "competition_id="
		// hidden-input/query-arg contexts used by the action forms. A
		// document-wide digit replacement is too broad and would collide with
		// unrelated numbers (random numbers, scores, vote counts) elsewhere on
		// the page.
		foreach ( $competition_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			$html       = preg_replace( '/<option value="' . $id_pattern . '"/', '<option value="ID"', $html );
			$html       = preg_replace( '/competition_id=' . $id_pattern . '(?!\d)/', 'competition_id=ID', $html );
			$html       = preg_replace( '/name="competition_id" value="' . $id_pattern . '"/', 'name="competition_id" value="ID"', $html );
		}

		// Normalize non-deterministic auto-increment member IDs, scoped to the
		// member filter dropdown's <option value="..."> and the row checkbox
		// values are image IDs, not member IDs, so no collision there.
		foreach ( $member_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			$html       = preg_replace( '/<option value="' . $id_pattern . '"/', '<option value="ID"', $html );
		}

		// Normalize non-deterministic auto-increment image (submission) IDs,
		// scoped to the row checkbox value attribute.
		foreach ( $image_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			$html       = preg_replace( '/name="image_ids\[\]" value="' . $id_pattern . '"/', 'name="image_ids[]" value="ID"', $html );
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
	 * @param string         $scenario        Snapshot scenario name.
	 * @param array<int,int> $competition_ids Competition IDs to normalize before comparing.
	 * @param array<int,int> $member_ids      Member IDs to normalize before comparing.
	 * @param array<int,int> $image_ids       Image (submission) IDs to normalize before comparing.
	 */
	private function assert_matches_snapshot( string $scenario, array $competition_ids = array(), array $member_ids = array(), array $image_ids = array() ): void {
		$dir  = __DIR__ . '/../fixtures/submissions-render';
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
	 * @param array|null  $categories Category config; null uses global defaults.
	 * @param bool        $archived   Whether to archive the competition immediately.
	 * @param string|null $created_at Explicit created_at to force a deterministic
	 *                                Competitions_Repository::all() DESC ordering
	 *                                when a scenario seeds more than one competition;
	 *                                MySQL does not guarantee tie-break order for
	 *                                equal auto-assigned timestamps.
	 */
	private function seed_competition( string $title, string $slug, ?array $categories = null, bool $archived = false, ?string $created_at = null ): int {
		$settings = array();
		if ( null !== $categories ) {
			$settings['categories'] = $categories;
		}

		$id = $this->competitions->create(
			array(
				'title'      => $title,
				'slug'       => $slug,
				'open_date'  => null,
				'close_date' => null,
				'settings'   => $settings,
			)
		);

		if ( null !== $created_at ) {
			$this->force_created_at( $this->competitions->table(), (int) $id, $created_at );
		}

		if ( $archived ) {
			$this->competitions->archive( $id );
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
	 * @param string $name  Member name.
	 * @param string $email Member email.
	 */
	private function seed_member( string $name, string $email ): int {
		return (int) $this->members->create(
			array(
				'name'  => $name,
				'email' => $email,
				'grade' => 'A',
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
				'member_id'      => 42,
				'category'       => 'colour',
				'filename'       => 'photo.jpg',
				'random_number'  => 7,
			),
			$overrides
		);

		return (int) $this->images->create( $data );
	}

	/**
	 * Write a full-size and thumbnail file to the uploads dir so the
	 * submissions table renders the "image available" thumbnail branch
	 * instead of "Unavailable".
	 *
	 * @param string $slug     Competition slug.
	 * @param string $category Category slug.
	 * @param string $filename Image filename.
	 */
	private function write_submission_files( string $slug, string $category, string $filename ): void {
		$uploads = wp_upload_dir();
		$folder  = trailingslashit( $uploads['basedir'] ) . 'competitions/' . $slug . '/' . $category;

		wp_mkdir_p( $folder );

		$info  = pathinfo( $filename );
		$base  = $info['filename'] ?? $filename;
		$ext   = isset( $info['extension'] ) && '' !== $info['extension'] ? '.' . $info['extension'] : '';
		$thumb = $base . '-thumb' . $ext;

		$full_path  = trailingslashit( $folder ) . $filename;
		$thumb_path = trailingslashit( $folder ) . $thumb;

		file_put_contents( $full_path, 'full' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $thumb_path, 'thumb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$this->written_files[] = $full_path;
		$this->written_files[] = $thumb_path;
	}

	/**
	 * Mark an upload token as opened, for a member/competition pair.
	 *
	 * @param int $member_id      Member ID.
	 * @param int $competition_id Competition ID.
	 */
	private function mark_upload_link_opened( int $member_id, int $competition_id ): void {
		$repo  = new Upload_Token_Repository();
		$token = $repo->find_or_create( $member_id, $competition_id );
		$repo->find_valid_token( $token->token );
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

	public function test_render_no_submissions(): void {
		// Competition exists (global default categories, since none are set
		// explicitly) but has no submissions: filters, action forms, a
		// zero-state submission-status table, then the "no submissions" notice.
		$comp_id = $this->seed_competition( 'Spring Show', 'spring-show' );

		$this->assert_matches_snapshot( 'no-submissions', array( $comp_id ) );
	}

	public function test_render_selected_competition_with_null_settings_does_not_deprecate(): void {
		// Competitions created without a settings payload persist a literal NULL
		// in the settings column. Selecting such a competition on the
		// submissions screen previously passed that NULL straight to
		// json_decode(), which is deprecated on PHP 8.1+.
		global $wpdb;
		$comp_id = $this->seed_competition( 'No Settings Show', 'no-settings-show' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->competitions->table(), array( 'settings' => null ), array( 'id' => $comp_id ), array( '%s' ), array( '%d' ) );

		$this->set_request(
			array(
				'page'           => 'photo-competition-manager-submissions',
				'competition_id' => (string) $comp_id,
			)
		);

		$json_decode_deprecations = array();
		set_error_handler(
			static function ( $errno, $errstr ) use ( &$json_decode_deprecations ) {
				if ( false !== strpos( $errstr, 'json_decode' ) ) {
					$json_decode_deprecations[] = $errstr;
				}
				return true;
			},
			E_DEPRECATED
		);

		try {
			ob_start();
			$this->controller->render();
			$html = (string) ob_get_clean();
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $json_decode_deprecations, 'Rendering a null-settings competition must not emit a json_decode() deprecation.' );
		// The selected competition still renders its admin action forms.
		$this->assertStringContainsString( 'name="competition_id" value="' . $comp_id . '"', $html );
	}

	public function test_render_happy_path(): void {
		// Two competitions, one archived (exercises the "(Archived)" label in
		// the filter dropdown). Explicit, distinct created_at values keep the
		// Competitions_Repository::all() ORDER BY created_at DESC list
		// deterministic -- MySQL does not guarantee tie-break order when both
		// rows would otherwise share the same auto-assigned timestamp.
		$archived_id = $this->seed_competition( 'Winter Salon', 'winter-salon', null, true, '2026-01-02 00:00:00' );
		$comp_id     = $this->seed_competition(
			'Spring Show',
			'spring-show',
			array(
				array(
					'slug'  => 'colour',
					'label' => 'Colour',
					'quota' => 2,
				),
				array(
					'slug'  => 'mono',
					'label' => 'Mono',
					'quota' => 1,
				),
			),
			false,
			'2026-01-01 00:00:00'
		);

		$ada   = $this->seed_member( 'Ada Lovelace', 'ada@example.com' );
		$grace = $this->seed_member( 'Grace Hopper', 'grace@example.com' );

		// Ada: two colour submissions (quota met), no mono (quota not met).
		// One of the two colour images has real files on disk (thumbnail
		// branch); the other does not (Unavailable branch).
		$this->write_submission_files( 'spring-show', 'colour', 'ada-sunset.jpg' );
		$ada_img_1 = $this->seed_image(
			$comp_id,
			array(
				'member_id'     => $ada,
				'category'      => 'colour',
				'filename'      => 'ada-sunset.jpg',
				'random_number' => 3,
			)
		);
		$ada_img_2 = $this->seed_image(
			$comp_id,
			array(
				'member_id'     => $ada,
				'category'      => 'colour',
				'filename'      => 'ada-harbour.jpg',
				'random_number' => 3,
			)
		);

		// Grace: one mono submission (quota met), no colour (quota not met).
		$grace_img = $this->seed_image(
			$comp_id,
			array(
				'member_id'     => $grace,
				'category'      => 'mono',
				'filename'      => 'grace-portrait.jpg',
				'random_number' => 5,
			)
		);

		// Votes on one image so total-score/vote-count columns are non-empty.
		$this->votes->create_anonymous( $comp_id, 'colour', 101, $ada_img_1, 8 );
		$this->votes->create_anonymous( $comp_id, 'colour', 102, $ada_img_1, 6 );

		// Ada's upload link has been opened; Grace's has not, and neither has
		// opened their voting link -- exercises both tracking-column states.
		$this->mark_upload_link_opened( $ada, $comp_id );

		$this->set_request(
			array(
				'page'           => 'photo-competition-manager-submissions',
				'competition_id' => (string) $comp_id,
			)
		);

		$this->assert_matches_snapshot(
			'happy-path',
			array( $archived_id, $comp_id ),
			array( $ada, $grace ),
			array( $ada_img_1, $ada_img_2, $grace_img )
		);
	}

	public function test_render_filtered_by_member(): void {
		$comp_id = $this->seed_competition(
			'Autumn Exhibition',
			'autumn-exhibition',
			array(
				array(
					'slug'  => 'colour',
					'label' => 'Colour',
					'quota' => 1,
				),
			)
		);

		$ada   = $this->seed_member( 'Ada Lovelace', 'ada@example.com' );
		$grace = $this->seed_member( 'Grace Hopper', 'grace@example.com' );

		$this->seed_image(
			$comp_id,
			array(
				'member_id'     => $ada,
				'category'      => 'colour',
				'filename'      => 'ada-photo.jpg',
				'random_number' => 1,
			)
		);
		$grace_img = $this->seed_image(
			$comp_id,
			array(
				'member_id'     => $grace,
				'category'      => 'colour',
				'filename'      => 'grace-photo.jpg',
				'random_number' => 2,
			)
		);

		$this->set_request(
			array(
				'page'           => 'photo-competition-manager-submissions',
				'competition_id' => (string) $comp_id,
				'member_id'      => (string) $grace,
			)
		);

		$this->assert_matches_snapshot(
			'filtered-by-member',
			array( $comp_id ),
			array( $ada, $grace ),
			array( $grace_img )
		);
	}
}
