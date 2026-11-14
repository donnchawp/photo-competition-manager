<?php
/**
 * Golden-master snapshot tests for Types_Controller::render().
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Types_Controller;
use PhotoCompetitionManager\Repository\Competition_Types_Repository;

/**
 * @covers \PhotoCompetitionManager\Admin\Types_Controller
 */
class Types_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Competition_Types_Repository */
	private $types;

	/** @var Types_Controller */
	private $controller;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'photocomp_competition_types' ) );

		$this->types      = new Competition_Types_Repository();
		$this->controller = new Types_Controller( $this->types );
	}

	public function tear_down(): void {
		delete_option( 'photo_comp_default_settings' );
		parent::tear_down();
	}

	/**
	 * A type's settings.
	 *
	 * @param string $slug Category slug.
	 * @return array<string, mixed>
	 */
	private function settings( string $slug ): array {
		return array(
			'categories' => array(
				array(
					'label' => ucfirst( $slug ),
					'slug'  => $slug,
					'quota' => 2,
				),
				array(
					'label' => 'Mono',
					'slug'  => 'mono',
					'quota' => 1,
				),
			),
			'upload'     => array( 'max_file_size_mb' => 8 ),
			'voting'     => array(
				'score_matrix'        => array( 10, 8, 6 ),
				'auth_mode'           => 'judges',
				'password'            => 'secret',
				'click_image_to_zoom' => true,
			),
			'slideshow'  => array(
				'progress_meter_type' => 'radial',
				'preview_duration'    => 20,
			),
		);
	}

	/**
	 * Render the screen with per-run values normalized.
	 *
	 * @param int[] $type_ids Type IDs to normalize (auto-increment survives rollback).
	 */
	private function render_normalized( array $type_ids = array() ): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		$html = preg_replace( '/(name="photo_competition_type_nonce" value=")[a-f0-9]{10}(")/', '$1NONCE$2', $html );
		$html = preg_replace( '/(name="_wp_http_referer" value=")[^"]*(")/', '$1REFERER$2', $html );

		foreach ( $type_ids as $index => $id ) {
			$pattern = preg_quote( (string) $id, '/' );
			$html    = preg_replace( '/type=' . $pattern . '(?!\d)/', 'type=ID' . $index, $html );
			$html    = preg_replace( '/name="type_id" value="' . $pattern . '"/', 'name="type_id" value="ID' . $index . '"', $html );
		}

		return (string) preg_replace( '/&#0*38;/', '&amp;', $html );
	}

	/**
	 * Assert live output equals the stored snapshot; write it on first run.
	 *
	 * @param string $scenario Snapshot scenario name.
	 * @param int[]  $type_ids Type IDs to normalize.
	 */
	private function assert_matches_snapshot( string $scenario, array $type_ids = array() ): void {
		$dir  = __DIR__ . '/../fixtures/types-render';
		$file = $dir . '/' . $scenario . '.html';
		$html = $this->render_normalized( $type_ids );

		if ( ! file_exists( $file ) ) {
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true );
			}
			file_put_contents( $file, $html );
			$this->markTestSkipped( "Snapshot written for {$scenario}; re-run to assert." );
			return;
		}

		$this->assertSame( file_get_contents( $file ), $html, "Rendered markup drifted for scenario {$scenario}." );
	}

	public function test_render_list_with_one_type_has_no_delete(): void {
		$id = $this->types->create( 'Individual', $this->settings( 'colour' ) );

		$this->assert_matches_snapshot( 'list-one-type', array( $id ) );
	}

	public function test_render_list_with_types(): void {
		$a = $this->types->create( 'Individual', $this->settings( 'colour' ) );
		$b = $this->types->create( 'Club <b>Panel</b>', $this->settings( 'prints' ) );

		$this->assert_matches_snapshot( 'list-with-types', array( $a, $b ) );
	}

	public function test_render_add_form(): void {
		$this->set_request( array( 'type_action' => 'add' ) );

		$this->assert_matches_snapshot( 'add-form' );
	}

	public function test_render_edit_form(): void {
		$id = $this->types->create( 'Individual', $this->settings( 'colour' ), array( 'advanced' => 'Senior "A"' ) );
		$this->set_request(
			array(
				'type_action' => 'edit',
				'type'        => $id,
			)
		);

		$this->assert_matches_snapshot( 'edit-form', array( $id ) );
	}

	public function test_render_edit_form_with_error(): void {
		$id = $this->types->create( 'Individual', $this->settings( 'colour' ) );
		$this->set_request(
			array(
				'type_action' => 'edit',
				'type'        => $id,
			)
		);
		add_settings_error( 'photo_competition_types', 'missing_name', 'Enter a name for the type.', 'error' );

		$this->assert_matches_snapshot( 'edit-form-with-error', array( $id ) );
	}

	public function test_render_edit_type_not_found(): void {
		$this->set_request(
			array(
				'type_action' => 'edit',
				'type'        => 999999,
			)
		);

		$this->assert_matches_snapshot( 'edit-type-not-found' );
	}

	public function test_render_confirm_delete(): void {
		$this->types->create( 'Individual', $this->settings( 'colour' ) );
		$id = $this->types->create( 'Panel', $this->settings( 'prints' ) );
		$this->set_request(
			array(
				'type_action' => 'delete',
				'type'        => $id,
			)
		);

		$this->assert_matches_snapshot( 'confirm-delete', array( $id ) );
	}
}
