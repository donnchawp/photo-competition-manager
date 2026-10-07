<?php
/**
 * Golden-master snapshot tests for Voting_Controller::render().
 *
 * Pins the exact rendered HTML ahead of the template-partial extraction (#10).
 * Nonces are normalized so snapshots do not churn per run.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Voting_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Tests\Member_Fixtures;

use function PhotoCompetitionManager\Support\utc_time;

/**
 * @covers \PhotoCompetitionManager\Admin\Voting_Controller
 */
class Voting_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Competitions_Repository */
	private $competitions;

	/** @var Images_Repository */
	private $images;

	/** @var Members_Repository */
	private $members;

	/** @var Voting_Controller */
	private $controller;

	public function set_up(): void {
		parent::set_up();
		$this->competitions = new Competitions_Repository();
		$this->images       = new Images_Repository();
		$this->members      = new Members_Repository();
		$this->controller   = new Voting_Controller( $this->competitions, $this->images, $this->members );
	}

	/**
	 * Render the page and return normalized HTML.
	 *
	 * @param array<int,int> $dynamic_ids Auto-increment IDs (e.g. competition ID) to
	 *                                    normalize; these are not stable across test
	 *                                    runs because MySQL does not roll back
	 *                                    auto-increment counters with the transactional
	 *                                    test rollback, so byte-exact comparison would
	 *                                    otherwise churn on every run.
	 */
	private function render_normalized( array $dynamic_ids = array() ): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();
		// Normalize per-run nonces: _wpnonce=<10 hex> and nonce field values.
		$html = preg_replace( '/(_wpnonce=)[a-f0-9]{10}/', '$1NONCE', $html );
		$html = preg_replace( '/(name="_wpnonce" value=")[a-f0-9]{10}/', '$1NONCE', $html );
		// Normalize non-deterministic auto-increment IDs, scoped to the specific
		// contexts where a competition ID legitimately appears in the markup:
		// URL query args and data attributes. A document-wide digit replacement
		// is too broad -- on a fresh DB the ID is a small integer (1-5) that
		// collides with unrelated numbers (step-circle labels, tab counts),
		// causing false snapshot failures.
		foreach ( $dynamic_ids as $id ) {
			$id_pattern = preg_quote( (string) $id, '/' );
			// competition=<id> query arg, e.g. "...&competition=2884&...".
			$html = preg_replace( '/competition=' . $id_pattern . '(?!\d)/', 'competition=ID', $html );
			// focus=<id>_<slug> query arg, e.g. "...&focus=2884_colour".
			$html = preg_replace( '/focus=' . $id_pattern . '_/', 'focus=ID_', $html );
			// data-competition-id="<id>" attribute.
			$html = preg_replace( '/data-competition-id="' . $id_pattern . '"/', 'data-competition-id="ID"', $html );
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
	 * @param string         $scenario    Snapshot scenario name.
	 * @param array<int,int> $dynamic_ids Auto-increment IDs to normalize before comparing.
	 */
	private function assert_matches_snapshot( string $scenario, array $dynamic_ids = array() ): void {
		$dir  = __DIR__ . '/../fixtures/voting-render';
		$file = $dir . '/' . $scenario . '.html';
		$html = $this->render_normalized( $dynamic_ids );

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
	 * Seed a competition with the given categories and return its ID.
	 *
	 * @param array<int,array{slug:string,label:string}> $categories Category defs.
	 */
	private function seed_competition( array $categories ): int {
		return $this->competitions->create(
			array(
				'title'      => 'Spring Show',
				'slug'       => 'spring-show',
				'open_date'  => null,
				'close_date' => null,
				'settings'   => array( 'categories' => $categories ),
			)
		);
	}

	public function test_render_no_open_competitions(): void {
		// No competitions created at all.
		$this->assert_matches_snapshot( 'no-open-competitions' );
	}

	public function test_render_no_images(): void {
		$this->seed_competition(
			array(
				array(
					'slug'  => 'colour',
					'label' => 'Colour',
				),
			)
		);
		$this->assert_matches_snapshot( 'no-images' );
	}

	public function test_render_happy_path(): void {
		$comp_id   = $this->seed_competition(
			array(
				array(
					'slug'  => 'colour',
					'label' => 'Colour',
				),
				array(
					'slug'  => 'mono',
					'label' => 'Mono',
				),
			)
		);
		$member_id = $this->members->create(
			array(
				'name'  => 'Ada',
				'email' => 'ada@example.com',
				'grade' => 'beginner',
			)
		);
		foreach ( array( 'colour', 'mono' ) as $cat ) {
			$this->images->create(
				array(
					'competition_id' => $comp_id,
					'member_id'      => $member_id,
					'category'       => $cat,
					'filename'       => $cat . '.jpg',
					'random_number'  => 100,
				)
			);
		}
		$this->assert_matches_snapshot( 'happy-path', array( $comp_id ) );
	}

	/**
	 * Render the page with one entrant holding the given grade.
	 *
	 * @param string $grade Entrant's stored grade.
	 * @return string Rendered HTML.
	 */
	private function render_with_entrant_grade( string $grade ): string {
		$comp_id   = $this->seed_competition(
			array(
				array(
					'slug'  => 'colour',
					'label' => 'Colour',
				),
			)
		);
		$member_id = Member_Fixtures::insert_with_grade( 'Ada', 'ada@example.com', $grade );
		$this->images->create(
			array(
				'competition_id' => $comp_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'colour.jpg',
			)
		);

		ob_start();
		$this->controller->render();
		return (string) ob_get_clean();
	}

	/**
	 * Seed a one-category competition with an entry and the given workflow state.
	 *
	 * @param array<string,mixed> $workflow Stored workflow state.
	 */
	private function seed_competition_with_workflow( array $workflow ): void {
		$comp_id   = $this->seed_competition(
			array(
				array(
					'slug'  => 'colour',
					'label' => 'Colour',
				),
			)
		);
		$member_id = $this->members->create(
			array(
				'name'  => 'Ada',
				'email' => 'ada@example.com',
				'grade' => 'beginner',
			)
		);
		$this->images->create(
			array(
				'competition_id' => $comp_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'colour.jpg',
			)
		);
		$this->competitions->save_workflow( $comp_id, $workflow );
	}

	/**
	 * The active step's slideshow button carries what the admin slideshow
	 * posts, and no competition slug, which the server never read.
	 */
	public function test_render_active_slideshow_button_sends_no_competition_slug(): void {
		$this->seed_competition_with_workflow( array( 'uploads_closed' => true ) );

		$html = $this->render_html();

		$this->assertStringContainsString( 'photo-competition-manager-start-slideshow', $html );
		$this->assertStringNotContainsString( 'data-competition-slug', $html );
	}

	/**
	 * A completed competition's Slideshow and Critique replay buttons carry no
	 * competition slug either.
	 */
	public function test_render_replay_buttons_send_no_competition_slug(): void {
		$this->seed_competition_with_workflow(
			array(
				'uploads_closed' => true,
				'stages'         => array( 'colour' => Competition_Workflow::STAGE_DONE ),
			)
		);

		$html = $this->render_html();

		$this->assertStringContainsString( 'All Categories Complete', $html );
		$this->assertStringContainsString( 'photo-competition-manager-start-slideshow', $html );
		$this->assertStringNotContainsString( 'data-competition-slug', $html );
	}

	/**
	 * Uploads can't reopen once a category's voting has started, so Reopen is
	 * disabled with the reason.
	 */
	public function test_render_disables_reopen_once_a_category_has_started_voting(): void {
		$this->seed_competition_with_workflow(
			array(
				'uploads_closed' => true,
				'stages'         => array( 'colour' => 'critique' ),
			)
		);

		$html = $this->render_html();

		$this->assertStringContainsString( '<button type="button" class="button button-small" disabled title="Voting has started in Colour. Reset that category before reopening uploads.">Reopen</button>', $html );
		$this->assertStringNotContainsString( 'action=toggle_uploads', $html );
	}

	/**
	 * A category reset with its votes kept is back at step 1 but still has votes, so it
	 * offers Reset again: moving its entries needs those votes cleared.
	 */
	public function test_render_offers_reset_at_step_one_while_the_category_has_votes(): void {
		$comp_id   = $this->seed_competition(
			array(
				array(
					'slug'  => 'colour',
					'label' => 'Colour',
				),
			)
		);
		$member_id = $this->members->create(
			array(
				'name'  => 'Ada',
				'email' => 'ada@example.com',
				'grade' => 'beginner',
			)
		);
		$image_id  = $this->images->create(
			array(
				'competition_id' => $comp_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'colour.jpg',
			)
		);

		ob_start();
		$this->controller->render();
		$this->assertStringNotContainsString( 'photo-comp-reset-toggle', (string) ob_get_clean() );

		( new Votes_Repository() )->create( $comp_id, 'colour', 'A Voter', $image_id, 5 );

		ob_start();
		$this->controller->render();
		$this->assertStringContainsString( 'photo-comp-reset-toggle', (string) ob_get_clean() );
	}

	/**
	 * An entrant whose grade isn't in the club's list blocks voting, and the
	 * notice names the grade.
	 */
	public function test_render_blocks_entrant_with_grade_not_in_club_list(): void {
		$html = $this->render_with_entrant_grade( 'legacy' );

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'Ada (ada@example.com)', $html );
		$this->assertStringContainsString( '&quot;legacy&quot; isn&#039;t one of the club&#039;s grades', $html );
		$this->assertStringNotContainsString( 'id="focus-panel"', $html );
	}

	/**
	 * A grade label stored instead of its slug, as the old sample CSV did,
	 * blocks voting.
	 */
	public function test_render_blocks_entrant_with_grade_label_stored(): void {
		$html = $this->render_with_entrant_grade( 'Beginner' );

		$this->assertStringContainsString( '&quot;Beginner&quot; isn&#039;t one of the club&#039;s grades', $html );
	}

	/**
	 * An entrant with no grade blocks voting, and the notice says so.
	 */
	public function test_render_blocks_entrant_with_no_grade(): void {
		$html = $this->render_with_entrant_grade( '' );

		$this->assertStringContainsString( 'Ada (ada@example.com)', $html );
		$this->assertStringContainsString( 'no grade', $html );
	}

	/**
	 * Bug: a competition that doesn't override the voting page URL has
	 * $active_settings['urls']['voting_page'] === '' (empty string, but SET).
	 * The controller must fall through to the global default in that case,
	 * not treat the empty string as an authoritative "no page configured".
	 */
	public function test_render_voting_page_not_missing_when_only_global_url_set(): void {
		// Open competition with default (empty) settings: no per-competition
		// urls override, so active_settings['urls']['voting_page'] === ''.
		$this->seed_competition( array() );

		update_option(
			'photo_comp_default_settings',
			Competition_Settings::encode(
				array(
					'urls' => array(
						'voting_page'  => 'https://example.com/vote',
						'results_page' => 'https://example.com/results',
					),
				)
			)
		);

		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Missing pages', $html );
	}

	/**
	 * The Results and Top 3 buttons use the competition's own pages, the same
	 * ones the missing-pages check and the emails go by, over the club's.
	 */
	public function test_render_quick_actions_link_the_competitions_own_pages(): void {
		update_option(
			'photo_comp_default_settings',
			Competition_Settings::encode(
				array(
					'urls' => array(
						'voting_page'  => 'https://example.com/club-vote',
						'results_page' => 'https://example.com/club-results',
						'top3_page'    => 'https://example.com/club-top3',
					),
				)
			)
		);
		$comp_id   = $this->competitions->create(
			array(
				'title'    => 'Spring Show',
				'slug'     => 'spring-show',
				'settings' => array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
						),
					),
					'urls'       => array(
						'results_page' => 'https://example.com/spring-results',
						'top3_page'    => 'https://example.com/spring-top3',
					),
				),
			)
		);
		$member_id = $this->members->create(
			array(
				'name'  => 'Ada',
				'email' => 'ada@example.com',
				'grade' => 'beginner',
			)
		);
		$this->images->create(
			array(
				'competition_id' => $comp_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'colour.jpg',
				'random_number'  => 100,
			)
		);

		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'href="https://example.com/spring-results"', $html );
		$this->assertStringContainsString( 'href="https://example.com/spring-top3"', $html );
		$this->assertStringNotContainsString( 'club-results', $html );
		$this->assertStringNotContainsString( 'club-top3', $html );
	}

	/**
	 * With no top 3 page anywhere, the Voting screen offers no Top 3 button.
	 */
	public function test_render_quick_actions_omit_top3_without_a_top3_page(): void {
		update_option(
			'photo_comp_default_settings',
			Competition_Settings::encode(
				array(
					'urls' => array(
						'voting_page'  => 'https://example.com/vote',
						'results_page' => 'https://example.com/results',
					),
				)
			)
		);
		$comp_id   = $this->seed_competition(
			array(
				array(
					'slug'  => 'colour',
					'label' => 'Colour',
				),
			)
		);
		$member_id = $this->members->create(
			array(
				'name'  => 'Ada',
				'email' => 'ada@example.com',
				'grade' => 'beginner',
			)
		);
		$this->images->create(
			array(
				'competition_id' => $comp_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'colour.jpg',
				'random_number'  => 100,
			)
		);

		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'href="https://example.com/results"', $html );
		$this->assertStringNotContainsString( 'Top 3 Results', $html );
	}

	/**
	 * More than one open competition is a setup mistake, left over from
	 * before only one could be open, so warn and name the competitions.
	 */
	public function test_render_warns_when_multiple_competitions_open(): void {
		$this->seed_competition( array() );
		$this->insert_overlapping_competition( 'Autumn Show', 'autumn-show' );

		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'More than one competition is open', $html );
		$this->assertStringContainsString( 'Spring Show', $html );
		$this->assertStringContainsString( 'Autumn Show', $html );
	}

	/**
	 * Seed two open competitions, as saved before only one could be open.
	 * "Spring Show" opened later but was created first, so it is the current
	 * competition, while "Old Show" is the newest by created_at.
	 *
	 * @param array<int,string> $current_open Categories with voting open in Spring Show.
	 * @param array<int,string> $older_open   Categories with voting open in Old Show.
	 * @return array{0:int,1:int} Spring Show and Old Show IDs.
	 */
	private function seed_two_open_competitions( array $current_open = array(), array $older_open = array() ): array {
		$current_id = $this->insert_overlapping_competition(
			'Spring Show',
			'spring-show',
			array(
				'open_date'  => utc_time( -5 * DAY_IN_SECONDS ),
				'close_date' => utc_time( 25 * DAY_IN_SECONDS ),
				'settings'   => wp_json_encode(
					array(
						'categories' => array(
							array(
								'slug'  => 'colour',
								'label' => 'Colour',
							),
						),
					)
				),
				'created_at' => '2020-01-01 00:00:00',
			)
		);
		$older_id   = $this->insert_overlapping_competition(
			'Old Show',
			'old-show',
			array(
				'open_date' => utc_time( -30 * DAY_IN_SECONDS ),
				'settings'  => wp_json_encode(
					array(
						'categories' => array(
							array(
								'slug'  => 'mono',
								'label' => 'Mono',
							),
						),
					)
				),
			)
		);

		$member_id = $this->members->create(
			array(
				'name'  => 'Ada',
				'email' => 'ada@example.com',
				'grade' => 'beginner',
			)
		);
		foreach ( array(
			$current_id => 'colour',
			$older_id   => 'mono',
		) as $comp_id => $cat ) {
			$this->images->create(
				array(
					'competition_id' => $comp_id,
					'member_id'      => $member_id,
					'category'       => $cat,
					'filename'       => $cat . '.jpg',
					'random_number'  => 100,
				)
			);
		}

		// Two categories voting at once can't be reached through the workflow
		// any more, but competitions saved before only one could be open may
		// have it, so this writes the state directly.
		foreach ( array(
			$current_id => $current_open,
			$older_id   => $older_open,
		) as $comp_id => $open ) {
			$this->competitions->save_workflow(
				$comp_id,
				array( 'stages' => array_fill_keys( $open, Competition_Workflow::STAGE_VOTING ) )
			);
		}

		return array( $current_id, $older_id );
	}

	/**
	 * Render the page and return its HTML.
	 */
	private function render_html(): string {
		ob_start();
		$this->controller->render();
		return (string) ob_get_clean();
	}

	/**
	 * With two competitions open, Voting Controls acts on the same one the
	 * public pages show: the latest to open, not the latest created.
	 */
	public function test_render_acts_on_the_current_competition_when_several_are_open(): void {
		list( $current_id, $older_id ) = $this->seed_two_open_competitions();

		$html = $this->render_html();

		$this->assertMatchesRegularExpression( '/More than one competition is open: <strong>[^<]*Old Show[^<]*<\/strong>/', $html );
		$this->assertStringContainsString( 'status-bar-title">Spring Show<', $html );
		$this->assertMatchesRegularExpression( '/competition=' . $current_id . '(?!\d)/', $html );
		$this->assertDoesNotMatchRegularExpression( '/competition=' . $older_id . '(?!\d)/', $html );
	}

	/**
	 * Voting left open in an older competition blocks opening voting here,
	 * and that competition has no tab, so say where it is and how to close it.
	 */
	public function test_render_names_the_competition_with_voting_open_elsewhere(): void {
		$this->seed_two_open_competitions( array(), array( 'mono' ) );

		$this->assertStringContainsString( 'Voting is open in <strong>Old Show</strong>', $this->render_html() );
	}

	/**
	 * When the current competition has voting open, that's the one the page
	 * follows, even if an older competition has voting open too.
	 */
	public function test_render_prefers_voting_open_in_the_current_competition(): void {
		$this->seed_two_open_competitions( array( 'colour' ), array( 'mono' ) );

		$this->assertStringNotContainsString( 'Voting is open in', $this->render_html() );
	}

	/**
	 * A single open competition shows no multiple-competitions warning.
	 */
	public function test_render_no_multiple_competitions_warning_for_one_open(): void {
		$this->seed_competition( array() );

		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'More than one competition is open', $html );
	}

	/**
	 * Guard: when neither the competition nor the global settings configure a
	 * voting page, the missing-pages notice must still appear. The fix for
	 * the bug above must not suppress a genuinely-missing page.
	 */
	public function test_render_missing_pages_notice_appears_when_no_urls_configured(): void {
		// Open competition with default (empty) settings and no global
		// default settings option saved at all.
		$this->seed_competition( array() );

		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Missing pages', $html );
	}
}
