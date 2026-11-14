<?php
/**
 * Tests for Type_Settings_Form.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

use PhotoCompetitionManager\Admin\Type_Settings_Form;
use PhotoCompetitionManager\Support\Competition_Settings;
use WP_UnitTestCase;

/**
 * @covers \PhotoCompetitionManager\Admin\Type_Settings_Form
 */
class Type_Settings_Form_Test extends WP_UnitTestCase {

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Parse a posted form over the default settings.
	 *
	 * @param array<string, mixed> $post Posted fields.
	 * @return array<string, mixed>
	 */
	private function parse( array $post ): array {
		$_POST = $post;

		return ( new Type_Settings_Form() )->from_post( Competition_Settings::type_settings( (object) array() ) );
	}

	public function test_score_matrix_with_a_non_number_is_stored_as_a_list(): void {
		$settings = $this->parse( array( 'score_matrix' => '10, x, 8' ) );

		$this->assertSame( array( 10, 8 ), $settings['voting']['score_matrix'] );
	}

	public function test_an_empty_score_matrix_falls_back_to_nine_to_five(): void {
		$settings = $this->parse( array( 'score_matrix' => '' ) );

		$this->assertSame( array( 9, 8, 7, 6, 5 ), $settings['voting']['score_matrix'] );
	}

	public function test_who_scores_can_be_judges(): void {
		$settings = $this->parse( array( 'voting_auth_mode' => 'judges' ) );

		$this->assertSame( 'judges', $settings['voting']['auth_mode'] );
	}

	public function test_an_unknown_who_scores_falls_back_to_password(): void {
		$settings = $this->parse( array( 'voting_auth_mode' => 'robots' ) );

		$this->assertSame( 'password', $settings['voting']['auth_mode'] );
	}

	public function test_category_rows_missing_a_field_are_dropped(): void {
		$settings = $this->parse(
			array(
				'categories' => array(
					array(
						'label' => 'Open <b>Mono</b>',
						'slug'  => 'Open Mono',
						'quota' => '2',
					),
					array( 'label' => 'No slug' ),
				),
			)
		);

		$this->assertSame(
			array(
				array(
					'label' => 'Open Mono',
					'slug'  => 'open-mono',
					'quota' => 2,
				),
			),
			$settings['categories']
		);
	}

	public function test_settings_the_form_does_not_show_are_kept(): void {
		$_POST   = array();
		$current = Competition_Settings::type_settings( (object) array() );

		$current['email_reminders']['days_before_open'] = 3;
		$current['upload']['originals_quality']         = 70;

		$settings = ( new Type_Settings_Form() )->from_post( $current );

		$this->assertSame( 3, $settings['email_reminders']['days_before_open'] );
		$this->assertSame( 70, $settings['upload']['originals_quality'] );
		$this->assertSame( Competition_Settings::TYPE_KEYS, array_keys( $settings ) );
	}

	public function test_render_sections_leaves_out_the_voting_ui_type(): void {
		$html = ( new Type_Settings_Form() )->render_sections( Competition_Settings::type_settings( (object) array() ) );

		$this->assertStringContainsString( 'id="categories-container"', $html );
		$this->assertStringContainsString( 'name="score_matrix"', $html );
		$this->assertStringContainsString( 'name="progress_meter_type"', $html );
		$this->assertStringNotContainsString( 'name="voting_ui_type"', $html );
	}
}
