<?php
/**
 * Tests for Competition_Settings.
 *
 * @package PhotoCompetitionManager\Tests\Support
 */

namespace PhotoCompetitionManager\Tests\Support;

use PhotoCompetitionManager\Support\Competition_Settings;
use WP_UnitTestCase;

class Competition_Settings_Test extends WP_UnitTestCase {

	public function test_defaults_returns_valid_structure(): void {
		$defaults = Competition_Settings::defaults();

		$this->assertIsArray( $defaults );
		$this->assertArrayHasKey( 'categories', $defaults );
		$this->assertArrayHasKey( 'grades', $defaults );
		$this->assertArrayHasKey( 'upload', $defaults );
		$this->assertArrayHasKey( 'voting', $defaults );
		$this->assertArrayHasKey( 'slideshow', $defaults );
		$this->assertArrayHasKey( 'email_reminders', $defaults );
		$this->assertArrayHasKey( 'password', $defaults['voting'] );
		$this->assertSame( '', $defaults['voting']['password'] );
		$this->assertArrayHasKey( 'ui_type', $defaults['voting'] );
		$this->assertSame( 'default', $defaults['voting']['ui_type'] );

		$this->assertCount( 2, $defaults['categories'] );
		$this->assertCount( 3, $defaults['grades'] );
	}

	public function test_find_category_returns_matching_config(): void {
		$settings = array(
			'categories' => array(
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
		);

		$category = Competition_Settings::find_category( $settings, 'mono' );

		$this->assertSame( 'Mono', $category['label'] );
		$this->assertSame( 1, $category['quota'] );
	}

	public function test_find_category_returns_null_for_unknown_slug(): void {
		$this->assertNull( Competition_Settings::find_category( Competition_Settings::defaults(), 'nonexistent' ) );
	}

	/**
	 * The defaults parse() fills in. Grades and categories are left out: they
	 * are read through club_grades() and get_categories(), which fall back to
	 * the club's lists.
	 *
	 * @return array<string, mixed>
	 */
	private function filled_defaults(): array {
		return array_diff_key(
			Competition_Settings::defaults(),
			array(
				'grades'     => true,
				'categories' => true,
			)
		);
	}

	/**
	 * Save the club's categories.
	 *
	 * @param array<int, string> $slugs Category slugs; labels are made from them.
	 */
	private function save_club_categories( array $slugs ): void {
		$categories = array();
		foreach ( $slugs as $slug ) {
			$categories[] = array(
				'slug'  => $slug,
				'label' => ucfirst( $slug ),
				'quota' => 1,
			);
		}

		update_option(
			'photo_comp_default_settings',
			Competition_Settings::encode(
				array(
					'categories' => $categories,
					'grades'     => Competition_Settings::defaults()['grades'],
				)
			)
		);
	}

	public function test_parse_empty_json_returns_defaults(): void {
		$result = Competition_Settings::parse( null );

		$this->assertEquals( $this->filled_defaults(), $result );
	}

	public function test_parse_invalid_json_returns_defaults(): void {
		$result = Competition_Settings::parse( '{invalid json' );

		$this->assertEquals( $this->filled_defaults(), $result );
	}

	public function test_parse_valid_json_merges_with_defaults(): void {
		$custom = array(
			'categories' => array(
				array(
					'slug'  => 'nature',
					'label' => 'Nature',
					'quota' => 3,
				),
			),
		);

		$json   = wp_json_encode( $custom );
		$result = Competition_Settings::parse( $json );

		$this->assertCount( 1, $result['categories'] );
		$this->assertEquals( 'nature', $result['categories'][0]['slug'] );
		// Grades belong to the club, so parse() never fills them in. A
		// competition settings array written back after parsing gains none.
		$this->assertArrayNotHasKey( 'grades', $result );
	}

	public function test_validate_accepts_valid_settings(): void {
		$settings = Competition_Settings::defaults();
		$result   = Competition_Settings::validate( $settings );

		$this->assertTrue( $result );
	}

	public function test_validate_rejects_missing_categories(): void {
		$settings = Competition_Settings::defaults();
		unset( $settings['categories'] );

		$result = Competition_Settings::validate( $settings );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_categories', $result->get_error_code() );
	}

	public function test_validate_rejects_empty_categories(): void {
		$settings               = Competition_Settings::defaults();
		$settings['categories'] = array();

		$result = Competition_Settings::validate( $settings );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_categories', $result->get_error_code() );
	}

	public function test_validate_rejects_category_without_slug(): void {
		$settings = array(
			'categories' => array(
				array(
					'label' => 'Test',
					'quota' => 2,
				),
			),
			'grades'     => array(
				array(
					'slug'  => 'beginner',
					'label' => 'Beginner',
				),
			),
		);

		$result = Competition_Settings::validate( $settings );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_category_fields', $result->get_error_code() );
	}

	public function test_validate_rejects_category_with_invalid_quota(): void {
		$settings = array(
			'categories' => array(
				array(
					'slug'  => 'test',
					'label' => 'Test',
					'quota' => 0,
				),
			),
			'grades'     => array(
				array(
					'slug'  => 'beginner',
					'label' => 'Beginner',
				),
			),
		);

		$result = Competition_Settings::validate( $settings );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_quota', $result->get_error_code() );
	}

	public function test_validate_rejects_missing_grades(): void {
		$settings = Competition_Settings::defaults();
		unset( $settings['grades'] );

		$result = Competition_Settings::validate( $settings );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_grades', $result->get_error_code() );
	}

	public function test_validate_rejects_empty_grades(): void {
		$settings          = Competition_Settings::defaults();
		$settings['grades'] = array();

		$result = Competition_Settings::validate( $settings );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_grades', $result->get_error_code() );
	}

	public function test_validate_rejects_grade_without_slug(): void {
		$settings = array(
			'categories' => array(
				array(
					'slug'  => 'test',
					'label' => 'Test',
					'quota' => 2,
				),
			),
			'grades'     => array(
				array(
					'label' => 'Beginner',
				),
			),
		);

		$result = Competition_Settings::validate( $settings );

		$this->assertWPError( $result );
		$this->assertEquals( 'missing_grade_fields', $result->get_error_code() );
	}

	public function test_validate_rejects_invalid_file_size(): void {
		$settings                           = Competition_Settings::defaults();
		$settings['upload']['max_file_size_mb'] = 0;

		$result = Competition_Settings::validate( $settings );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_file_size', $result->get_error_code() );
	}

	public function test_validate_rejects_invalid_score_matrix(): void {
		$settings                       = Competition_Settings::defaults();
		$settings['voting']['score_matrix'] = array();

		$result = Competition_Settings::validate( $settings );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_score_matrix', $result->get_error_code() );
	}

	public function test_validate_rejects_invalid_voting_ui_type(): void {
		$settings = Competition_Settings::defaults();
		$settings['voting']['ui_type'] = 'slider';

		$result = Competition_Settings::validate( $settings );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_voting_ui_type', $result->get_error_code() );
	}

	public function test_validate_rejects_non_string_voting_password(): void {
		$settings                       = Competition_Settings::defaults();
		$settings['voting']['password'] = array( 'not-a-string' );

		$result = Competition_Settings::validate( $settings );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_voting_password', $result->get_error_code() );
	}

	public function test_encode_returns_json_string(): void {
		$settings = Competition_Settings::defaults();
		$result   = Competition_Settings::encode( $settings );

		$this->assertIsString( $result );
		$this->assertNotEmpty( $result );

		$decoded = json_decode( $result, true );
		$this->assertIsArray( $decoded );
	}

	public function test_get_categories_extracts_categories(): void {
		$settings = Competition_Settings::defaults();
		$result   = Competition_Settings::get_categories( $settings );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertEquals( 'colour', $result[0]['slug'] );
	}

	public function test_empty_category_list_uses_the_clubs_categories(): void {
		$this->save_club_categories( array( 'open' ) );

		$categories = Competition_Settings::get_categories( Competition_Settings::parse( '{"categories":[]}' ) );

		$this->assertSame( array( 'open' ), wp_list_pluck( $categories, 'slug' ) );
	}

	public function test_competition_without_categories_uses_the_clubs_categories(): void {
		$this->save_club_categories( array( 'open', 'nature', 'mono' ) );

		foreach ( array( '{}', null ) as $stored ) {
			$categories = Competition_Settings::get_categories( Competition_Settings::parse( $stored ) );

			$this->assertSame( array( 'open', 'nature', 'mono' ), wp_list_pluck( $categories, 'slug' ) );
		}
	}

	public function test_competitions_own_categories_win_over_the_clubs(): void {
		$this->save_club_categories( array( 'open' ) );

		$categories = Competition_Settings::get_categories(
			Competition_Settings::parse( '{"categories":[{"slug":"portrait","label":"Portrait","quota":2}]}' )
		);

		$this->assertSame( array( 'portrait' ), wp_list_pluck( $categories, 'slug' ) );
	}

	public function test_no_categories_anywhere_uses_the_built_in_categories(): void {
		delete_option( 'photo_comp_default_settings' );

		foreach ( array( '{}', '{"categories":[]}' ) as $stored ) {
			$categories = Competition_Settings::get_categories( Competition_Settings::parse( $stored ) );

			$this->assertSame( array( 'colour', 'black-white' ), wp_list_pluck( $categories, 'slug' ) );
		}
	}

	public function test_parse_does_not_add_categories_to_a_competition_without_them(): void {
		$this->save_club_categories( array( 'open' ) );

		$parsed = Competition_Settings::parse( '{}' );

		$this->assertArrayNotHasKey( 'categories', $parsed );
		$this->assertArrayNotHasKey( 'categories', json_decode( Competition_Settings::encode( $parsed ), true ) );
	}

	public function test_club_grades_returns_defaults_when_none_saved(): void {
		delete_option( 'photo_comp_default_settings' );

		$this->assertSame( Competition_Settings::defaults()['grades'], Competition_Settings::club_grades() );
	}

	public function test_club_grades_returns_saved_grades(): void {
		$grades = array(
			array(
				'slug'  => 'novice',
				'label' => 'Novice',
			),
			array(
				'slug'  => 'salon',
				'label' => 'Salon',
			),
		);
		update_option( 'photo_comp_default_settings', Competition_Settings::encode( array( 'grades' => $grades ) ) );

		$this->assertSame( $grades, Competition_Settings::club_grades() );
	}

	public function test_validate_competition_settings_ignores_grades(): void {
		$settings = Competition_Settings::defaults();
		unset( $settings['grades'] );

		$this->assertTrue( Competition_Settings::validate( $settings, false ) );
	}

	public function test_get_upload_constraints_extracts_upload_config(): void {
		$settings = Competition_Settings::defaults();
		$result   = Competition_Settings::get_upload_constraints( $settings );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'max_file_size_mb', $result );
		$this->assertArrayHasKey( 'max_width', $result );
		$this->assertArrayHasKey( 'max_height', $result );
		$this->assertEquals( 5, $result['max_file_size_mb'] );
	}

	public function test_get_voting_config_extracts_voting_settings(): void {
		$settings = Competition_Settings::defaults();
		$result   = Competition_Settings::get_voting_config( $settings );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'password', $result );
		$this->assertArrayHasKey( 'score_matrix', $result );
		$this->assertEquals( array( 9, 8, 7, 6, 5 ), $result['score_matrix'] );
	}

	public function test_get_voting_ui_type_respects_competition_override(): void {
		$settings = Competition_Settings::defaults();
		$settings['voting']['ui_type'] = 'dropdown';

		$this->assertSame( 'dropdown', Competition_Settings::get_voting_ui_type( $settings ) );
	}

	public function test_get_voting_ui_type_falls_back_to_global_option(): void {
		$settings = Competition_Settings::defaults();
		$settings['voting']['ui_type'] = 'default';

		update_option( 'photo_comp_voting_ui_type', 'dropdown' );

		$this->assertSame( 'dropdown', Competition_Settings::get_voting_ui_type( $settings ) );

		delete_option( 'photo_comp_voting_ui_type' );
	}

	public function test_get_voting_ui_type_defaults_to_buttons_when_global_invalid(): void {
		$settings = Competition_Settings::defaults();
		$settings['voting']['ui_type'] = 'default';

		update_option( 'photo_comp_voting_ui_type', 'slider' );

		$this->assertSame( 'buttons', Competition_Settings::get_voting_ui_type( $settings ) );

		delete_option( 'photo_comp_voting_ui_type' );
	}

	public function test_custom_categories_persist_through_parse(): void {
		$custom = array(
			'categories' => array(
				array(
					'slug'  => 'portrait',
					'label' => 'Portrait',
					'quota' => 1,
				),
				array(
					'slug'  => 'landscape',
					'label' => 'Landscape',
					'quota' => 3,
				),
			),
			'grades'     => Competition_Settings::defaults()['grades'],
		);

		$json   = Competition_Settings::encode( $custom );
		$parsed = Competition_Settings::parse( $json );

		$categories = Competition_Settings::get_categories( $parsed );
		$this->assertCount( 2, $categories );
		$this->assertEquals( 'portrait', $categories[0]['slug'] );
		$this->assertEquals( 1, $categories[0]['quota'] );
		$this->assertEquals( 'landscape', $categories[1]['slug'] );
		$this->assertEquals( 3, $categories[1]['quota'] );
	}

	public function test_custom_grades_persist_through_parse(): void {
		$custom = array(
			'categories' => Competition_Settings::defaults()['categories'],
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
		);

		$json   = Competition_Settings::encode( $custom );
		$parsed = Competition_Settings::parse( $json );

		$grades = $parsed['grades'];
		$this->assertCount( 2, $grades );
		$this->assertEquals( 'novice', $grades[0]['slug'] );
		$this->assertEquals( 'expert', $grades[1]['slug'] );
	}

	// ---------------------------------------------------------------
	// is_voting_open_for_category()
	// ---------------------------------------------------------------

	public function test_is_voting_open_for_category_returns_true_when_listed(): void {
		$settings = array(
			'voting' => array( 'open_categories' => array( 'colour', 'black-white' ) ),
		);

		$this->assertTrue( Competition_Settings::is_voting_open_for_category( $settings, 'colour' ) );
	}

	public function test_is_voting_open_for_category_returns_false_when_not_listed(): void {
		$settings = array(
			'voting' => array( 'open_categories' => array( 'colour' ) ),
		);

		$this->assertFalse( Competition_Settings::is_voting_open_for_category( $settings, 'black-white' ) );
	}

	public function test_is_voting_open_for_category_returns_false_when_empty(): void {
		$settings = array(
			'voting' => array( 'open_categories' => array() ),
		);

		$this->assertFalse( Competition_Settings::is_voting_open_for_category( $settings, 'colour' ) );
	}

	// ---------------------------------------------------------------
	// get_open_voting_categories()
	// ---------------------------------------------------------------

	public function test_get_open_voting_categories_returns_list(): void {
		$settings = array(
			'voting' => array( 'open_categories' => array( 'colour', 'black-white' ) ),
		);

		$result = Competition_Settings::get_open_voting_categories( $settings );

		$this->assertSame( array( 'colour', 'black-white' ), $result );
	}

	public function test_get_open_voting_categories_returns_empty_when_not_set(): void {
		$settings = array();

		$result = Competition_Settings::get_open_voting_categories( $settings );

		$this->assertSame( array(), $result );
	}

	// ---------------------------------------------------------------
	// global_settings()
	// ---------------------------------------------------------------

	public function test_global_settings_returns_defaults_when_option_unset(): void {
		delete_option( 'photo_comp_default_settings' );

		$result = Competition_Settings::global_settings();

		$this->assertEquals( $this->filled_defaults(), $result );
	}

	public function test_global_settings_reads_stored_option(): void {
		$custom = array(
			'categories' => array(
				array(
					'slug'  => 'macro',
					'label' => 'Macro',
					'quota' => 4,
				),
			),
			'grades'     => Competition_Settings::defaults()['grades'],
		);

		update_option( 'photo_comp_default_settings', Competition_Settings::encode( $custom ) );

		$result = Competition_Settings::global_settings();

		$categories = Competition_Settings::get_categories( $result );
		$this->assertCount( 1, $categories );
		$this->assertSame( 'macro', $categories[0]['slug'] );
		$this->assertSame( 4, $categories[0]['quota'] );

		delete_option( 'photo_comp_default_settings' );
	}

	public function test_global_settings_returns_defaults_when_option_invalid(): void {
		update_option( 'photo_comp_default_settings', '{invalid json' );

		$result = Competition_Settings::global_settings();

		$this->assertEquals( $this->filled_defaults(), $result );

		delete_option( 'photo_comp_default_settings' );
	}

	// ---------------------------------------------------------------
	// parse() auto-detects page URLs even for empty/invalid settings.
	// ---------------------------------------------------------------

	public function test_parse_empty_string_auto_detects_voting_page(): void {
		$page_id = $this->create_page( '[competition_voting]' );

		$expected_url = get_permalink( $page_id );

		$result = Competition_Settings::parse( '' );

		$this->assertSame( $expected_url, $result['urls']['voting_page'] );
	}

	public function test_parse_empty_and_empty_object_return_same_urls(): void {
		$page_id = $this->create_page( '[competition_voting]' );

		$empty_result       = Competition_Settings::parse( '' );
		$empty_object_result = Competition_Settings::parse( '{}' );

		$this->assertSame( $empty_object_result['urls'], $empty_result['urls'] );
		$this->assertNotSame( '', $empty_result['urls']['voting_page'] );
	}

	public function test_parse_null_auto_detects_nothing_when_no_shortcode_page_exists(): void {
		$result = Competition_Settings::parse( null );

		$this->assertSame( '', $result['urls']['voting_page'] );
	}

	public function test_parse_invalid_json_auto_detects_voting_page(): void {
		$page_id = $this->create_page( '[competition_voting]' );

		$expected_url = get_permalink( $page_id );

		$result = Competition_Settings::parse( '{invalid json' );

		$this->assertSame( $expected_url, $result['urls']['voting_page'] );
	}

	// ---------------------------------------------------------------
	// Page URL detection is done once per request until posts change.
	// ---------------------------------------------------------------

	/**
	 * Count get_pages() calls made while running a callback.
	 *
	 * @param callable $run Code to run.
	 * @return int Number of get_pages() calls.
	 */
	private function count_page_lookups( callable $run ): int {
		$lookups = 0;
		$count   = static function ( $pages ) use ( &$lookups ) {
			++$lookups;
			return $pages;
		};

		add_filter( 'get_pages', $count );
		try {
			$run();
		} finally {
			remove_filter( 'get_pages', $count );
		}

		return $lookups;
	}

	/**
	 * Create a published page with the given content.
	 *
	 * @param string $content Page content.
	 * @return int Page ID.
	 */
	private function create_page( string $content ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $content,
			)
		);
	}

	public function test_parsing_several_times_looks_up_pages_once(): void {
		$this->create_page( '[competition_voting]' );

		$lookups = $this->count_page_lookups(
			static function () {
				Competition_Settings::parse( null );
				Competition_Settings::parse( '{}' );
				Competition_Settings::parse( '{"title":"Another"}' );
				Competition_Settings::find_page_url_with_shortcode( 'competition_upload' );
			}
		);

		$this->assertSame( 1, $lookups );
	}

	public function test_page_published_after_first_lookup_is_found(): void {
		$this->assertSame( '', Competition_Settings::parse( null )['urls']['voting_page'] );

		$page_id = $this->create_page( '[competition_voting]' );

		$this->assertSame( get_permalink( $page_id ), Competition_Settings::parse( null )['urls']['voting_page'] );
	}

	public function test_trashed_page_is_no_longer_found(): void {
		$page_id = $this->create_page( '[competition_voting]' );
		$this->assertSame( get_permalink( $page_id ), Competition_Settings::parse( null )['urls']['voting_page'] );

		wp_trash_post( $page_id );

		$this->assertSame( '', Competition_Settings::parse( null )['urls']['voting_page'] );
	}

	public function test_each_shortcode_resolves_to_its_own_page(): void {
		$upload  = $this->create_page( '[competition_upload]' );
		$voting  = $this->create_page( '[competition_voting]' );
		$results = $this->create_page( '[competition_results]' );
		$top3    = $this->create_page( '[competition_top3]' );

		$urls = Competition_Settings::parse( null )['urls'];

		$this->assertSame( get_permalink( $upload ), $urls['upload_page'] );
		$this->assertSame( get_permalink( $voting ), $urls['voting_page'] );
		$this->assertSame( get_permalink( $results ), $urls['results_page'] );
		$this->assertSame( get_permalink( $top3 ), $urls['top3_page'] );
		$this->assertSame( get_permalink( $upload ), Competition_Settings::find_page_url_with_shortcode( 'competition_upload' ) );
	}

	public function test_undetected_page_url_is_left_unset(): void {
		$this->create_page( '[competition_voting]' );

		$this->assertArrayNotHasKey( 'top3_page', Competition_Settings::parse( null )['urls'] );
	}

	public function test_page_with_two_shortcodes_is_found_for_both(): void {
		$page_id = $this->create_page( '[competition_results] [competition_top3]' );

		$urls = Competition_Settings::parse( null )['urls'];

		$this->assertSame( get_permalink( $page_id ), $urls['results_page'] );
		$this->assertSame( get_permalink( $page_id ), $urls['top3_page'] );
	}

	public function test_first_page_in_title_order_wins_when_two_have_the_shortcode(): void {
		$second = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'B Voting',
				'post_content' => '[competition_voting]',
			)
		);
		$first  = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'A Voting',
				'post_content' => '[competition_voting]',
			)
		);

		$this->assertSame( get_permalink( $first ), Competition_Settings::parse( null )['urls']['voting_page'] );
		$this->assertNotSame( get_permalink( $second ), get_permalink( $first ) );
	}

	public function test_empty_shortcode_tag_finds_no_page(): void {
		$this->create_page( '[competition_upload]' );

		$this->assertSame( '', Competition_Settings::find_page_url_with_shortcode( '' ) );
	}

	public function test_set_page_url_is_not_replaced_by_detection(): void {
		$this->create_page( '[competition_voting]' );

		$urls = Competition_Settings::parse( '{"urls":{"voting_page":"https://example.com/vote"}}' )['urls'];

		$this->assertSame( 'https://example.com/vote', $urls['voting_page'] );
	}

	public function test_unknown_shortcode_is_still_looked_up(): void {
		$page_id = $this->create_page( '[gallery]' );

		$this->assertSame( get_permalink( $page_id ), Competition_Settings::find_page_url_with_shortcode( 'gallery' ) );
		$this->assertSame( '', Competition_Settings::find_page_url_with_shortcode( 'no_such_shortcode' ) );
	}
}
