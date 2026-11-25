<?php
/**
 * Tests for Archive_Shortcode: the past competitions page and its permalinks.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Archive_Shortcode;
use PhotoCompetitionManager\Service\Public_Results;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Service\Competition_Archive;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

/**
 * The page the shortcode is on lists past competitions, and the page's path
 * followed by a competition's slug shows that competition. Which competitions
 * are in the archive is tested on Competition_Archive.
 */
class Archive_Shortcode_Test extends WP_UnitTestCase {

	/**
	 * The archive page.
	 *
	 * @var int
	 */
	private $page_id;

	/**
	 * The shortcode, hooked up.
	 *
	 * @var Archive_Shortcode
	 */
	private $shortcode;

	/**
	 * Pretty permalinks, an archive page, and the archive switched on.
	 */
	public function setUp(): void {
		parent::setUp();

		// Past competitions show only while published results are public.
		Public_Results::save( true );

		$this->set_permalink_structure( '/%postname%/' );
		Competition_Archive::save_settings( true, false );

		$this->shortcode = new Archive_Shortcode();
		$this->shortcode->register();

		$this->page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'competitions',
				'post_title'   => 'Competitions',
				'post_content' => '[competition_archive]',
			)
		);
		$this->rebuild_rules();
	}

	/**
	 * Rebuild the rules, as the next request does after the page moves.
	 *
	 * @return void
	 */
	private function rebuild_rules(): void {
		flush_rewrite_rules( false );
	}

	/**
	 * Create a competition, with its results published or not.
	 *
	 * @param string $slug      Slug.
	 * @param bool   $published Whether its results are published.
	 * @return int
	 */
	private function competition( string $slug, bool $published = true ): int {
		$id = (int) ( new Competitions_Repository() )->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'      => ucfirst( $slug ),
				'slug'       => $slug,
				'open_date'  => '2026-01-01 00:00:00',
				'close_date' => '2026-01-06 00:00:00',
			)
		);
		if ( $published ) {
			Workflow_Fixtures::publish_results( $id );
		}

		return $id;
	}

	/**
	 * Request a URL and run the archive's template_redirect check.
	 *
	 * @param string $url URL.
	 * @return void
	 */
	private function visit( string $url ): void {
		$this->go_to( $url );
		$GLOBALS['post'] = get_post( $this->page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The shortcode links back to the page it's on.
		$this->shortcode->not_found();
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array<string, string> $atts Attributes.
	 * @return string
	 */
	private function render( array $atts = array() ): string {
		return $this->shortcode->render( $atts );
	}

	public function test_the_page_lists_past_competitions_linking_to_their_permalinks(): void {
		$this->competition( 'november-2026' );

		$this->visit( home_url( '/competitions/' ) );

		$this->assertStringContainsString( 'href="' . home_url( '/competitions/november-2026/' ) . '"', $this->render() );
	}

	public function test_a_permalink_shows_its_competition(): void {
		$this->competition( 'november-2026' );

		$this->visit( home_url( '/competitions/november-2026/' ) );

		$this->assertFalse( is_404() );
		$this->assertTrue( is_page( $this->page_id ) );
		$this->assertStringContainsString( '<h2>November-2026</h2>', $this->render() );
	}

	public function test_a_permalink_to_a_competition_not_in_the_archive_is_not_found(): void {
		$this->competition( 'unpublished', false );

		$this->visit( home_url( '/competitions/unpublished/' ) );
		$this->assertTrue( is_404(), 'unpublished' );

		$this->visit( home_url( '/competitions/no-such-competition/' ) );
		$this->assertTrue( is_404(), 'unknown' );
	}

	public function test_the_query_var_on_another_page_is_ignored(): void {
		$other = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);

		$this->go_to( add_query_arg( Archive_Shortcode::QUERY_VAR, 'unknown', get_permalink( $other ) ) );
		$this->shortcode->not_found();

		$this->assertFalse( is_404() );
	}

	public function test_while_the_archive_is_off_the_page_shows_nothing_and_permalinks_are_not_found(): void {
		$this->competition( 'november-2026' );
		Competition_Archive::save_settings( false, false );

		$this->visit( home_url( '/competitions/' ) );
		$this->assertSame( '', $this->render() );

		$this->visit( home_url( '/competitions/november-2026/' ) );
		$this->assertTrue( is_404() );
	}

	public function test_a_child_page_of_the_archive_page_still_shows(): void {
		$child = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'rules',
				'post_parent' => $this->page_id,
			)
		);
		$this->rebuild_rules();

		$this->visit( home_url( '/competitions/rules/' ) );

		$this->assertFalse( is_404() );
		$this->assertTrue( is_page( $child ) );
	}

	public function test_links_work_when_the_archive_page_is_the_front_page(): void {
		$this->competition( 'november-2026' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $this->page_id );

		$this->visit( home_url( '/' ) );
		$this->assertStringContainsString( 'href="' . home_url( '/competitions/november-2026/' ) . '"', $this->shortcode->render( array() ) );

		$this->visit( home_url( '/competitions/november-2026/' ) );
		$this->assertFalse( is_404() );
	}

	public function test_the_archive_page_is_found_on_a_site_with_many_pages(): void {
		$this->competition( 'november-2026' );
		// Pages whose titles sort before the archive page's.
		self::factory()->post->create_many(
			100,
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
			)
		);
		wp_update_post(
			array(
				'ID'        => $this->page_id,
				'post_name' => 'past',
			)
		);
		$this->rebuild_rules();

		$this->visit( home_url( '/past/november-2026/' ) );

		$this->assertFalse( is_404() );
		$this->assertTrue( is_page( $this->page_id ) );
	}

	public function test_renaming_the_page_moves_the_permalinks(): void {
		$this->competition( 'november-2026' );

		wp_update_post(
			array(
				'ID'        => $this->page_id,
				'post_name' => 'past',
			)
		);
		$this->rebuild_rules();

		$this->visit( home_url( '/past/november-2026/' ) );
		$this->assertTrue( is_page( $this->page_id ) );
		$this->assertFalse( is_404() );
	}
}
