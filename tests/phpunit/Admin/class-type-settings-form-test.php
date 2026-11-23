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

	/**
	 * A blank or 0 size turned resizing off (#291), and the form's 800–5000 is only a browser hint.
	 *
	 * @dataProvider posted_resize_sizes
	 *
	 * @param string $posted What the field held.
	 * @param int    $saved  What's stored.
	 */
	public function test_the_resize_size_is_kept_within_800_to_5000_and_blank_means_1920( string $posted, int $saved ): void {
		$settings = $this->parse(
			array(
				'max_width'  => $posted,
				'max_height' => $posted,
			)
		);

		$this->assertSame( array( $saved, $saved ), array( $settings['upload']['max_width'], $settings['upload']['max_height'] ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public function posted_resize_sizes(): array {
		return array(
			'blank'         => array( '', 1920 ),
			'zero'          => array( '0', 1920 ),
			'below 800'     => array( '300', 800 ),
			'above 5000'    => array( '9000', 5000 ),
			'within range'  => array( '2400', 2400 ),
		);
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

	public function test_the_type_form_saves_its_interclub_section(): void {
		$settings = $this->parse(
			array(
				'interclub_section'            => '1',
				'interclub_enabled'            => '1',
				'interclub_substitutes'        => '2',
				'interclub_photographer_limit' => '2',
			)
		);

		$this->assertSame(
			array(
				'enabled'            => true,
				'substitutes'        => 2,
				'photographer_limit' => 2,
			),
			$settings['interclub']
		);
	}

	public function test_unticking_entrants_are_clubs_turns_interclub_off(): void {
		$_POST   = array(
			'interclub_section'            => '1',
			'interclub_substitutes'        => '2',
			'interclub_photographer_limit' => '0',
		);
		$current = Competition_Settings::type_settings( (object) array() );

		$current['interclub']['enabled'] = true;

		$settings = ( new Type_Settings_Form() )->from_post( $current );

		$this->assertFalse( $settings['interclub']['enabled'] );
	}

	public function test_a_form_without_the_interclub_section_keeps_the_interclub_settings(): void {
		// The competition edit form posts the other sections but not this one.
		$_POST   = array( 'score_matrix' => '9, 8, 7' );
		$current = Competition_Settings::type_settings( (object) array() );

		$current['interclub'] = array(
			'enabled'            => true,
			'substitutes'        => 2,
			'photographer_limit' => 2,
		);

		$settings = ( new Type_Settings_Form() )->from_post( $current );

		$this->assertSame( $current['interclub'], $settings['interclub'] );
	}

	public function test_render_sections_leaves_out_the_voting_ui_type(): void {
		$html = ( new Type_Settings_Form() )->render_sections( Competition_Settings::type_settings( (object) array() ) );

		$this->assertStringContainsString( 'id="categories-container"', $html );
		$this->assertStringContainsString( 'name="score_matrix"', $html );
		$this->assertStringContainsString( 'name="progress_meter_type"', $html );
		$this->assertStringNotContainsString( 'name="voting_ui_type"', $html );
	}
}
