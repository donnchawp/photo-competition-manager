<?php
/**
 * Tests for AJAX-driven voting step persistence.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use WP_UnitTestCase;

class VotingStepAjaxTest extends WP_UnitTestCase {

	private $competitions;

	public function set_up(): void {
		parent::set_up();
		$this->competitions = new Competitions_Repository();
	}

	public function test_advance_step_updates_category_steps(): void {
		$comp_id = $this->competitions->create( array(
			'title'      => 'Test Competition',
			'slug'       => 'test-comp',
			'open_date'  => '2026-01-01',
			'close_date' => '2026-12-31',
			'settings'   => array(),
		) );

		$competition = $this->competitions->find( $comp_id );
		$settings    = Competition_Settings::parse( $competition->settings );

		$settings['voting']['category_steps']['colour'] = 2;
		$this->competitions->update( $comp_id, array( 'settings' => $settings ) );

		$competition = $this->competitions->find( $comp_id );
		$settings    = Competition_Settings::parse( $competition->settings );
		$this->assertSame( 2, $settings['voting']['category_steps']['colour'] );
	}

	public function test_step_6_writes_voted_categories(): void {
		$comp_id = $this->competitions->create( array(
			'title'      => 'Test Competition',
			'slug'       => 'test-comp',
			'open_date'  => '2026-01-01',
			'close_date' => '2026-12-31',
			'settings'   => array(),
		) );

		$competition = $this->competitions->find( $comp_id );
		$settings    = Competition_Settings::parse( $competition->settings );

		$settings['voting']['category_steps']['colour']   = 6;
		$settings['voting']['voted_categories'][]         = $comp_id . '_colour';
		$this->competitions->update( $comp_id, array( 'settings' => $settings ) );

		$competition = $this->competitions->find( $comp_id );
		$settings    = Competition_Settings::parse( $competition->settings );
		$this->assertContains( $comp_id . '_colour', $settings['voting']['voted_categories'] );
		$this->assertSame( 6, $settings['voting']['category_steps']['colour'] );
	}
}
