<?php
/**
 * Golden-master snapshot tests for Logs_Controller::render().
 *
 * Pins the exact rendered HTML ahead of the template-partial extraction (#40).
 * Nonces and non-rollback auto-increment IDs are normalized so snapshots do
 * not churn per run.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Logs_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;

/**
 * @covers \PhotoCompetitionManager\Admin\Logs_Controller
 */
class Logs_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Logs_Repository */
	private $logs;

	/** @var Competitions_Repository */
	private $competitions;

	/** @var Logs_Controller */
	private $controller;

	public function set_up(): void {
		parent::set_up();
		$this->logs         = new Logs_Repository();
		$this->competitions = new Competitions_Repository();
		$this->controller   = new Logs_Controller( $this->logs, $this->competitions );
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
	 * @param array<int,int> $log_ids         Log row IDs to normalize, same reason.
	 */
	private function render_normalized( array $competition_ids = array(), array $log_ids = array() ): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		// Normalize per-run nonces: _wpnonce=<10 hex> query args.
		$html = preg_replace( '/(_wpnonce=)[a-f0-9]{10}/', '$1NONCE', $html );

		// Normalize non-deterministic auto-increment competition IDs, scoped to
		// the specific contexts where a competition ID legitimately appears:
		// the filter dropdown's <option value="..."> and the "competition="
		// query arg used in pagination/export links. A document-wide digit
		// replacement is too broad and would collide with unrelated numbers
		// (page counts, log IDs) elsewhere on the page.
		foreach ( $competition_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			$html       = preg_replace( '/<option value="' . $id_pattern . '"/', '<option value="ID"', $html );
			$html       = preg_replace( '/competition=' . $id_pattern . '(?!\d)/', 'competition=ID', $html );
		}

		// Normalize non-deterministic auto-increment log row IDs, scoped to
		// the specific contexts where a log ID legitimately appears: the
		// metadata toggle button's data attribute and the two metadata
		// container element IDs.
		foreach ( $log_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			$html       = preg_replace( '/data-log-id="' . $id_pattern . '"/', 'data-log-id="ID"', $html );
			$html       = preg_replace( '/id="log-metadata-' . $id_pattern . '"/', 'id="log-metadata-ID"', $html );
			$html       = preg_replace( '/id="log-metadata-row-' . $id_pattern . '"/', 'id="log-metadata-row-ID"', $html );
		}

		return $html;
	}

	/**
	 * Assert live output equals the stored snapshot; write it on first run.
	 *
	 * @param string          $scenario         Snapshot scenario name.
	 * @param array<int,int>  $competition_ids  Competition IDs to normalize before comparing.
	 * @param array<int,int>  $log_ids          Log row IDs to normalize before comparing.
	 */
	private function assert_matches_snapshot( string $scenario, array $competition_ids = array(), array $log_ids = array() ): void {
		$dir  = __DIR__ . '/../fixtures/logs-render';
		$file = $dir . '/' . $scenario . '.html';
		$html = $this->render_normalized( $competition_ids, $log_ids );

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
	 * @param string $title Competition title.
	 */
	private function seed_competition( string $title ): int {
		$id = $this->competitions->create(
			array(
				'title'      => $title,
				'slug'       => sanitize_title( $title ),
				'open_date'  => null,
				'close_date' => null,
			)
		);

		return (int) $id;
	}

	/**
	 * Seed a log entry and return its ID.
	 *
	 * @param array<string, mixed> $overrides Data overrides.
	 */
	private function seed_log( array $overrides = array() ): int {
		$defaults = array(
			'competition_id' => null,
			'event_type'     => 'settings_updated',
			'event_category' => 'settings',
			'actor_type'     => 'admin',
			'actor_id'       => $this->admin_id,
			'actor_name'     => 'Ada Lovelace',
			'description'    => 'Updated global settings.',
			'metadata'       => null,
			'created_at'     => '2026-01-15 10:30:00',
		);

		$this->logs->create( wp_parse_args( $overrides, $defaults ) );

		global $wpdb;
		return (int) $wpdb->insert_id;
	}

	public function test_render_no_logs(): void {
		// No logs, no competitions: empty state, no export button, no pagination.
		$this->assert_matches_snapshot( 'no-logs' );
	}

	public function test_render_logs_with_pagination(): void {
		$comp_a = $this->seed_competition( 'Spring Show' );
		$comp_b = $this->seed_competition( 'Autumn Exhibition' );

		$log_ids = array();

		// Bulk out to 58 global (no-competition) logs so total exceeds the
		// 50-per-page limit and pagination renders (Next/Last present,
		// First/Previous absent since we are viewing page 1). Dated earlier
		// than the two logs below so those two sort onto page 1 (DESC by
		// created_at) and their metadata/no-metadata branches are exercised.
		for ( $i = 0; $i < 58; $i++ ) {
			$log_ids[] = $this->seed_log(
				array(
					'event_type'      => 'settings_updated',
					'event_category'  => 'settings',
					'description'     => 'Updated global settings entry ' . $i . '.',
					'created_at'      => '2026-01-17 00:' . str_pad( (string) ( $i % 60 ), 2, '0', STR_PAD_LEFT ) . ':00',
				)
			);
		}

		// One log with metadata, one without, both tied to a competition,
		// dated after the bulk logs so both land on page 1.
		$log_ids[] = $this->seed_log(
			array(
				'competition_id' => $comp_a,
				'event_type'     => 'image_uploaded',
				'event_category' => 'upload',
				'actor_type'     => 'member',
				'actor_name'     => 'Grace Hopper',
				'description'    => 'Uploaded "Sunset over the harbour".',
				'metadata'       => array(
					'filename' => 'sunset.jpg',
					'category' => 'colour',
				),
				'created_at'     => '2026-01-18 09:00:00',
			)
		);

		$log_ids[] = $this->seed_log(
			array(
				'competition_id' => $comp_b,
				'event_type'     => 'vote_cast',
				'event_category' => 'voting',
				'actor_type'     => 'member',
				'actor_name'     => 'Alan Turing',
				'description'    => 'Cast a vote in the Mono category.',
				'created_at'     => '2026-01-18 10:00:00',
			)
		);

		$this->assert_matches_snapshot( 'logs-with-pagination', array( $comp_a, $comp_b ), $log_ids );
	}

	public function test_render_filtered_second_page(): void {
		$comp_a = $this->seed_competition( 'Winter Salon' );
		$comp_b = $this->seed_competition( 'Summer Showcase' );

		$log_ids = array();

		// 130 matching logs (event_category=voting for comp_a) so filtered
		// pagination spans 3 pages; viewing page 2 exercises all four
		// pagination link branches (First, Previous, Next, Last).
		for ( $i = 0; $i < 130; $i++ ) {
			$log_ids[] = $this->seed_log(
				array(
					'competition_id' => $comp_a,
					'event_type'     => 'vote_cast',
					'event_category' => 'voting',
					'actor_type'     => 'member',
					'actor_name'     => 'Voter ' . $i,
					'description'    => 'Cast a vote entry ' . $i . '.',
					'created_at'     => '2026-02-0' . ( 1 + ( $i % 8 ) ) . ' 12:00:00',
				)
			);
		}

		// Non-matching logs (different category/competition) that the
		// filters must exclude.
		$this->seed_log(
			array(
				'competition_id' => $comp_b,
				'event_type'     => 'image_uploaded',
				'event_category' => 'upload',
				'description'    => 'Uploaded a photo.',
				'created_at'     => '2026-02-02 12:00:00',
			)
		);

		$this->set_request(
			array(
				'page'           => 'photo-competition-manager-logs',
				'competition'    => (string) $comp_a,
				'event_category' => 'voting',
				'event_type'     => 'vote_cast',
				'date_from'      => '2026-02-01',
				'date_to'        => '2026-02-08',
				'paged'          => '2',
			)
		);

		$this->assert_matches_snapshot( 'filtered-second-page', array( $comp_a, $comp_b ), $log_ids );
	}
}
