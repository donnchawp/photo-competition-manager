<?php
/**
 * Tests for Types_Controller.
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
class Types_Controller_Test extends Admin_Controller_Test_Case {

	private const GROUP = 'photo_competition_types';

	/**
	 * @var Competition_Types_Repository
	 */
	private $types;

	/**
	 * @var Types_Controller
	 */
	private $controller;

	/**
	 * The type the upgrade would have made.
	 *
	 * @var int
	 */
	private $individual;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		// Remove types the upgrade may have left, inside the test transaction.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'photocomp_competition_types' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . 'photocomp_member_types' ) );

		$this->types      = new Competition_Types_Repository();
		$this->controller = new Types_Controller( $this->types );
		$this->individual = $this->types->create( 'Individual', array( 'categories' => $this->categories() ) );
	}

	/**
	 * One category, as the form posts it.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function categories(): array {
		return array(
			array(
				'label' => 'Open',
				'slug'  => 'open',
				'quota' => '2',
			),
		);
	}

	/**
	 * Post an action to the controller and return where it redirects.
	 *
	 * @param array<string, mixed> $fields Posted fields.
	 * @param string               $nonce  Nonce action.
	 * @return string
	 */
	private function post( array $fields, string $nonce ): string {
		$this->set_request( $fields );
		$this->set_nonce( $nonce, 'photo_competition_type_nonce' );

		return $this->capture_redirect( array( $this->controller, 'handle_actions' ) );
	}

	/**
	 * Find a type by name.
	 *
	 * @param string $name Type name.
	 * @return object|null
	 */
	private function type_named( string $name ): ?object {
		foreach ( $this->types->all() as $type ) {
			if ( $name === $type->name ) {
				return $type;
			}
		}

		return null;
	}

	public function test_add_type_saves_name_categories_and_settings(): void {
		$location = $this->post(
			array(
				'photo_competition_action' => 'create_type',
				'type_name'                => 'Panel',
				'categories'               => array(
					array(
						'label' => 'Prints',
						'slug'  => 'prints',
						'quota' => '3',
					),
				),
				'max_file_size_mb'         => '8',
				'max_width'                => '2400',
				'max_height'               => '1600',
				'voting_auth_mode'         => 'judges',
				'voting_password'          => 'Secret',
				'click_image_to_zoom'      => '1',
				'score_matrix'             => '10, 8, 6',
				'progress_meter_type'      => 'dots',
				'preview_duration'         => '12',
				'voting_duration'          => '200',
				'critique_duration'        => '4',
			),
			'photo_competition_type_create'
		);

		$this->assertStringContainsString( 'page=photo-competition-manager-types', $location );
		$this->assertContains( 'type_created', $this->settings_error_codes( self::GROUP ) );

		$type = $this->type_named( 'Panel' );
		$this->assertNotNull( $type );
		$this->assertSame(
			array(
				array(
					'label' => 'Prints',
					'slug'  => 'prints',
					'quota' => 3,
				),
			),
			$type->settings['categories']
		);
		$this->assertSame( 8, $type->settings['upload']['max_file_size_mb'] );
		$this->assertSame( 2400, $type->settings['upload']['max_width'] );
		$this->assertSame( 1600, $type->settings['upload']['max_height'] );
		$this->assertSame( 'judges', $type->settings['voting']['auth_mode'] );
		$this->assertSame( 'Secret', $type->settings['voting']['password'] );
		$this->assertTrue( $type->settings['voting']['click_image_to_zoom'] );
		$this->assertSame( array( 10, 8, 6 ), $type->settings['voting']['score_matrix'] );
		$this->assertSame( 'dots', $type->settings['slideshow']['progress_meter_type'] );
		$this->assertSame( 12, $type->settings['slideshow']['preview_duration'] );
		$this->assertSame( 120, $type->settings['slideshow']['voting_duration'] );
		$this->assertSame( 4, $type->settings['slideshow']['critique_duration'] );
		$this->assertSame( array(), $type->grade_names );
	}

	public function test_add_type_rejects_missing_name(): void {
		$location = $this->post(
			array(
				'photo_competition_action' => 'create_type',
				'type_name'                => '   ',
				'categories'               => $this->categories(),
			),
			'photo_competition_type_create'
		);

		$this->assertStringContainsString( 'type_action=add', $location );
		$this->assertSame( array( 'missing_name' ), $this->settings_error_codes( self::GROUP ) );
		$this->assertSame( 1, $this->types->count() );
	}

	public function test_add_type_rejects_no_categories(): void {
		$this->post(
			array(
				'photo_competition_action' => 'create_type',
				'type_name'                => 'Empty',
			),
			'photo_competition_type_create'
		);

		$this->assertSame( array( 'invalid_settings' ), $this->settings_error_codes( self::GROUP ) );
		$this->assertNull( $this->type_named( 'Empty' ) );
	}

	public function test_add_type_needs_a_valid_nonce(): void {
		$this->set_request(
			array(
				'photo_competition_action'     => 'create_type',
				'type_name'                    => 'Panel',
				'categories'                   => $this->categories(),
				'photo_competition_type_nonce' => 'bad',
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	public function test_edit_type_saves_grade_names_and_blank_means_default(): void {
		$this->types->update( $this->individual, 'Individual', array( 'categories' => $this->categories() ), array( 'advanced' => 'Senior' ) );

		$location = $this->post(
			array(
				'photo_competition_action' => 'update_type',
				'type_id'                  => (string) $this->individual,
				'type_name'                => 'Cup',
				'categories'               => $this->categories(),
				'grade_names'              => array(
					'beginner'     => 'Shield',
					'intermediate' => '   ',
					'advanced'     => '',
					'not-a-grade'  => 'Ignored',
				),
			),
			'photo_competition_type_update_' . $this->individual
		);

		$this->assertStringNotContainsString( 'type_action=edit', $location );
		$this->assertContains( 'type_updated', $this->settings_error_codes( self::GROUP ) );

		$type = $this->types->find( $this->individual );
		$this->assertSame( 'Cup', $type->name );
		$this->assertSame( array( 'beginner' => 'Shield' ), $type->grade_names );
	}

	public function test_edit_type_with_an_error_goes_back_to_the_form(): void {
		$location = $this->post(
			array(
				'photo_competition_action' => 'update_type',
				'type_id'                  => (string) $this->individual,
				'type_name'                => '',
				'categories'               => $this->categories(),
			),
			'photo_competition_type_update_' . $this->individual
		);

		$this->assertStringContainsString( 'type_action=edit', $location );
		$this->assertStringContainsString( 'type=' . $this->individual, $location );
		$this->assertSame( array( 'missing_name' ), $this->settings_error_codes( self::GROUP ) );
		$this->assertSame( 'Individual', $this->types->find( $this->individual )->name );
	}

	/**
	 * Render the types screen for a view.
	 *
	 * @param array<string, string> $query Query vars.
	 * @return string
	 */
	private function render_view( array $query ): string {
		$this->reset_request();
		$this->set_request( $query );

		ob_start();
		$this->controller->render();

		return (string) ob_get_clean();
	}

	public function test_a_refused_add_keeps_what_was_typed_once(): void {
		$this->post(
			array(
				'photo_competition_action' => 'create_type',
				'type_name'                => 'individual',
				'categories'               => array(
					array(
						'label' => 'Monochrome Prints',
						'slug'  => 'mono-prints',
						'quota' => '3',
					),
				),
				'grade_names'              => array( 'advanced' => 'Cup' ),
			),
			'photo_competition_type_create'
		);
		$this->assertSame( array( 'duplicate_name' ), $this->settings_error_codes( self::GROUP ) );

		$html = $this->render_view( array( 'type_action' => 'add' ) );
		$this->assertStringContainsString( 'name="type_name" class="regular-text" required value="individual"', $html );
		$this->assertStringContainsString( 'Monochrome Prints', $html );
		$this->assertStringContainsString( 'value="Cup"', $html );

		$again = $this->render_view( array( 'type_action' => 'add' ) );
		$this->assertStringNotContainsString( 'Monochrome Prints', $again );
	}

	public function test_a_refused_edit_keeps_what_was_typed_for_that_type_only(): void {
		$other = $this->types->create( 'Panel', array( 'categories' => $this->categories() ) );

		$this->post(
			array(
				'photo_competition_action' => 'update_type',
				'type_id'                  => (string) $this->individual,
				'type_name'                => 'Panel',
				'categories'               => array(
					array(
						'label' => 'Nature',
						'slug'  => 'nature',
						'quota' => '1',
					),
				),
			),
			'photo_competition_type_update_' . $this->individual
		);
		$this->assertSame( array( 'duplicate_name' ), $this->settings_error_codes( self::GROUP ) );

		$wrong = $this->render_view(
			array(
				'type_action' => 'edit',
				'type'        => (string) $other,
			)
		);
		$this->assertStringNotContainsString( 'Nature', $wrong );

		$this->post(
			array(
				'photo_competition_action' => 'update_type',
				'type_id'                  => (string) $this->individual,
				'type_name'                => 'Panel',
				'categories'               => array(
					array(
						'label' => 'Nature',
						'slug'  => 'nature',
						'quota' => '1',
					),
				),
			),
			'photo_competition_type_update_' . $this->individual
		);
		$html = $this->render_view(
			array(
				'type_action' => 'edit',
				'type'        => (string) $this->individual,
			)
		);
		$this->assertStringContainsString( 'required value="Panel"', $html );
		$this->assertStringContainsString( 'Nature', $html );
		$this->assertSame( 'Individual', $this->types->find( $this->individual )->name );
	}

	public function test_edit_type_keeps_settings_the_form_does_not_show(): void {
		$this->types->update(
			$this->individual,
			'Individual',
			array(
				'categories'      => $this->categories(),
				'email_reminders' => array(
					'enabled'          => false,
					'days_before_open' => 3,
				),
			),
			array()
		);

		$this->post(
			array(
				'photo_competition_action' => 'update_type',
				'type_id'                  => (string) $this->individual,
				'type_name'                => 'Individual',
				'categories'               => $this->categories(),
			),
			'photo_competition_type_update_' . $this->individual
		);

		$reminders = $this->types->find( $this->individual )->settings['email_reminders'];
		$this->assertFalse( $reminders['enabled'] );
		$this->assertSame( 3, $reminders['days_before_open'] );
	}

	public function test_delete_type(): void {
		$other = $this->types->create( 'Panel', array( 'categories' => $this->categories() ) );

		$this->post(
			array(
				'photo_competition_action' => 'delete_type',
				'type_id'                  => (string) $other,
			),
			'photo_competition_delete_type_' . $other
		);

		$this->assertContains( 'type_deleted', $this->settings_error_codes( self::GROUP ) );
		$this->assertNull( $this->types->find( $other ) );
	}

	public function test_delete_type_in_use_shows_error(): void {
		global $wpdb;

		$this->types->create( 'Panel', array( 'categories' => $this->categories() ) );
		$wpdb->insert(
			$wpdb->prefix . 'photocomp_competitions',
			array(
				'title'   => 'Typed',
				'slug'    => 'typed',
				'type_id' => $this->individual,
			)
		);

		$this->post(
			array(
				'photo_competition_action' => 'delete_type',
				'type_id'                  => (string) $this->individual,
			),
			'photo_competition_delete_type_' . $this->individual
		);

		$this->assertSame( array( 'type_in_use' ), $this->settings_error_codes( self::GROUP ) );
		$this->assertNotNull( $this->types->find( $this->individual ) );
	}

	public function test_delete_last_type_shows_error(): void {
		$this->post(
			array(
				'photo_competition_action' => 'delete_type',
				'type_id'                  => (string) $this->individual,
			),
			'photo_competition_delete_type_' . $this->individual
		);

		$this->assertSame( array( 'last_type' ), $this->settings_error_codes( self::GROUP ) );
		$this->assertNotNull( $this->types->find( $this->individual ) );
	}

	public function test_without_the_capability_nothing_happens(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->set_request(
			array(
				'photo_competition_action' => 'delete_type',
				'type_id'                  => (string) $this->individual,
			)
		);

		$this->controller->handle_actions();

		$this->assertNotNull( $this->types->find( $this->individual ) );
	}
}
