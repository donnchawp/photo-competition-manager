<?php

namespace PhotoCompetitionManager\Tests;

use PhotoCompetitionManager\Support\Competition_Settings;
use WP_UnitTestCase;

class CompetitionSettingsDurationTest extends WP_UnitTestCase {

	public function test_defaults_include_per_step_durations(): void {
		$defaults = Competition_Settings::defaults();

		$this->assertArrayHasKey( 'preview_duration', $defaults['slideshow'] );
		$this->assertArrayHasKey( 'voting_duration', $defaults['slideshow'] );
		$this->assertArrayHasKey( 'critique_duration', $defaults['slideshow'] );
		$this->assertSame( 10, $defaults['slideshow']['preview_duration'] );
		$this->assertSame( 15, $defaults['slideshow']['voting_duration'] );
		$this->assertSame( 0, $defaults['slideshow']['critique_duration'] );
	}

	public function test_defaults_include_category_steps(): void {
		$defaults = Competition_Settings::defaults();

		$this->assertArrayHasKey( 'category_steps', $defaults['voting'] );
		$this->assertIsArray( $defaults['voting']['category_steps'] );
	}

	public function test_parse_preserves_new_duration_defaults(): void {
		$parsed = Competition_Settings::parse( '' );

		$this->assertSame( 10, $parsed['slideshow']['preview_duration'] );
		$this->assertSame( 15, $parsed['slideshow']['voting_duration'] );
		$this->assertSame( 0, $parsed['slideshow']['critique_duration'] );
	}
}
