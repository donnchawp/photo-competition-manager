<?php
/**
 * Tests for Competition_Settings.
 *
 * @package ClubCompetitions\Tests\Support
 */

namespace ClubCompetitions\Tests\Support;

use ClubCompetitions\Support\Competition_Settings;
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

		$this->assertCount( 2, $defaults['categories'] );
		$this->assertCount( 3, $defaults['grades'] );
	}

	public function test_parse_empty_json_returns_defaults(): void {
		$result = Competition_Settings::parse( null );

		$this->assertEquals( Competition_Settings::defaults(), $result );
	}

	public function test_parse_invalid_json_returns_defaults(): void {
		$result = Competition_Settings::parse( '{invalid json' );

		$this->assertEquals( Competition_Settings::defaults(), $result );
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
		$this->assertArrayHasKey( 'grades', $result );
		$this->assertCount( 3, $result['grades'] );
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

	public function test_get_grades_extracts_grades(): void {
		$settings = Competition_Settings::defaults();
		$result   = Competition_Settings::get_grades( $settings );

		$this->assertIsArray( $result );
		$this->assertCount( 3, $result );
		$this->assertEquals( 'beginner', $result[0]['slug'] );
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
		$this->assertArrayHasKey( 'auto_open', $result );
		$this->assertEquals( array( 9, 8, 7, 6, 5 ), $result['score_matrix'] );
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

		$grades = Competition_Settings::get_grades( $parsed );
		$this->assertCount( 2, $grades );
		$this->assertEquals( 'novice', $grades[0]['slug'] );
		$this->assertEquals( 'expert', $grades[1]['slug'] );
	}
}
