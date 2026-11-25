<?php
/**
 * Tests for Member_Visibility: a member's choices about the past competitions archive.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Competition_Archive;
use PhotoCompetitionManager\Service\Member_Page_Link;
use PhotoCompetitionManager\Service\Member_Visibility;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use WP_UnitTestCase;

/**
 * A member shows or hides all their images, or one at a time, and only their own.
 * Which images the archive then shows is tested on Competition_Archive.
 */
class Member_Visibility_Test extends WP_UnitTestCase {

	/**
	 * A competition.
	 *
	 * @var int
	 */
	private $competition;

	/**
	 * Ann's member ID.
	 *
	 * @var int
	 */
	private $ann;

	/**
	 * Create a competition and a member.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->competition = (int) ( new Competitions_Repository() )->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'      => 'January',
				'slug'       => 'january',
				'open_date'  => '2026-01-01 00:00:00',
				'close_date' => '2026-01-06 00:00:00',
			)
		);
		$this->ann         = Member_Fixtures::insert_with_grade( 'Ann', 'ann@example.com', 'beginner' );
	}

	/**
	 * Ann, as stored now.
	 *
	 * @return object
	 */
	private function ann(): object {
		return ( new Members_Repository() )->find( $this->ann );
	}

	public function test_a_member_hides_one_of_their_images_and_shows_it_again(): void {
		$entry      = Entry_Fixtures::insert_entry( $this->competition, 'colour', $this->ann, array() );
		$visibility = new Member_Visibility();

		$this->assertTrue( $visibility->set_entry_shown( $this->ann(), $entry, false ) );
		$this->assertSame( 1, $visibility->status( $this->ann() )['hidden_count'] );

		$this->assertTrue( $visibility->set_entry_shown( $this->ann(), $entry, true ) );
		$this->assertSame( 0, $visibility->status( $this->ann() )['hidden_count'] );
	}

	public function test_a_member_cannot_change_another_members_image(): void {
		$bobs = Entry_Fixtures::insert_scored_entry( $this->competition, 'colour', 'Bob', 'beginner', array() );

		$this->assertFalse( ( new Member_Visibility() )->set_entry_shown( $this->ann(), $bobs, false ) );
		$this->assertSame( '0', ( new Images_Repository() )->find( $bobs )->archive_hidden );
	}

	public function test_hiding_all_images_keeps_the_ones_hidden_one_by_one(): void {
		$entry      = Entry_Fixtures::insert_entry( $this->competition, 'colour', $this->ann, array() );
		$visibility = new Member_Visibility();
		$visibility->set_entry_shown( $this->ann(), $entry, false );

		$visibility->set_all_shown( $this->ann(), false );
		$this->assertSame(
			array(
				'shown'        => false,
				'hidden_count' => 1,
			),
			$visibility->status( $this->ann() )
		);

		$visibility->set_all_shown( $this->ann(), true );
		$this->assertSame(
			array(
				'shown'        => true,
				'hidden_count' => 1,
			),
			$visibility->status( $this->ann() )
		);
	}

	public function test_the_hidden_count_leaves_out_images_that_could_never_show(): void {
		$images     = new Images_Repository();
		$visibility = new Member_Visibility();
		$entered    = Entry_Fixtures::insert_entry( $this->competition, 'colour', $this->ann, array() );
		$substitute = Entry_Fixtures::insert_entry( $this->competition, 'mono', $this->ann, array() );
		$images->update_entry_status( $substitute, 'substitute' );
		$archived_competition = (int) ( new Competitions_Repository() )->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'      => 'Old',
				'slug'       => 'old',
				'open_date'  => '2025-01-01 00:00:00',
				'close_date' => '2025-01-06 00:00:00',
			)
		);
		$archived             = Entry_Fixtures::insert_entry( $archived_competition, 'colour', $this->ann, array() );
		( new Competitions_Repository() )->archive( $archived_competition );

		foreach ( array( $entered, $substitute, $archived ) as $entry ) {
			$images->set_archive_hidden( $entry, true );
		}

		$this->assertSame( 1, $visibility->status( $this->ann() )['hidden_count'] );
	}

	/**
	 * Publish a page holding a shortcode.
	 *
	 * @param string $shortcode Shortcode tag.
	 * @return string The page's URL.
	 */
	private function page_with( string $shortcode ): string {
		return (string) get_permalink(
			self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_content' => '[' . $shortcode . ']',
				)
			)
		);
	}

	public function test_the_upload_email_says_nothing_about_the_archive_while_it_is_off(): void {
		$this->page_with( 'competition_archive' );

		$this->assertSame( '', ( new Member_Visibility() )->archive_paragraph( $this->ann() ) );
	}

	public function test_the_upload_email_tells_members_whether_their_images_show_and_links_to_their_entries_page(): void {
		\PhotoCompetitionManager\Service\Public_Results::save( \PhotoCompetitionManager\Service\Public_Results::WITH_ARCHIVE );
		Competition_Archive::save_settings( false );
		$archive_url = $this->page_with( 'competition_archive' );
		$entries_url = $this->page_with( 'competition_my_entries' );
		$entries     = array(
			Entry_Fixtures::insert_entry( $this->competition, 'colour', $this->ann, array() ),
			Entry_Fixtures::insert_entry( $this->competition, 'mono', $this->ann, array() ),
		);
		$visibility  = new Member_Visibility();

		$all_shown = $visibility->archive_paragraph( $this->ann() );
		$visibility->set_entry_shown( $this->ann(), $entries[0], false );
		$one_hidden = $visibility->archive_paragraph( $this->ann() );
		$visibility->set_entry_shown( $this->ann(), $entries[1], false );
		$two_hidden = $visibility->archive_paragraph( $this->ann() );
		$visibility->set_all_shown( $this->ann(), false );
		$all_hidden = $visibility->archive_paragraph( $this->ann() );

		// The member links in each paragraph differ by when they expire, so compare the words.
		$words = array_map( 'wp_strip_all_tags', array( $all_shown, $one_hidden, $two_hidden, $all_hidden ) );
		$this->assertCount( 4, array_unique( $words ), 'all shown, one hidden, two hidden and all hidden each read differently' );
		foreach ( array( $all_shown, $two_hidden, $all_hidden ) as $paragraph ) {
			preg_match_all( '/href="([^"]+)"/', $paragraph, $links );
			$links = array_map( 'html_entity_decode', $links[1] );

			$this->assertContains( $archive_url, $links );
			$member_links = array_filter(
				$links,
				static function ( string $link ) use ( $entries_url ): bool {
					return 0 === strpos( $link, $entries_url ) && false !== strpos( $link, Member_Page_Link::QUERY_ARG . '=' );
				}
			);
			$this->assertCount( 1, $member_links, 'a link to their entries page' );
		}
	}
}
