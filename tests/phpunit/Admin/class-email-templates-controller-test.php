<?php
/**
 * Capability tests for Email_Templates_Controller.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Email_Templates_Controller;

/**
 * @covers \PhotoCompetitionManager\Admin\Email_Templates_Controller
 */
class Email_Templates_Controller_Test extends Admin_Controller_Test_Case {

	/** @var Email_Templates_Controller */
	private $controller;

	public function set_up(): void {
		parent::set_up();
		delete_option( 'photo_comp_email_templates' );
		$this->controller = new Email_Templates_Controller();
	}

	public function tear_down(): void {
		delete_option( 'photo_comp_email_templates' );
		parent::tear_down();
	}

	/**
	 * Log in as an editor with the plugin capability, as the activator sets up.
	 */
	private function become_editor(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_user_by( 'id', $editor_id )->add_cap( 'manage_photo_competitions' );
		wp_set_current_user( $editor_id );
	}

	/**
	 * Seed a save_email_templates request with a valid nonce.
	 */
	private function request_save(): void {
		$this->set_request(
			array(
				'photo_competition_action' => 'save_email_templates',
				'templates'                => array(
					'voting_opened' => array(
						'enabled' => '1',
						'subject' => 'Voting is open',
						'body'    => '<p>Go vote.</p>',
					),
				),
			)
		);
		$this->set_nonce( 'photo_competition_email_templates' );
	}

	public function test_editor_can_save_templates(): void {
		$this->become_editor();
		$this->request_save();

		$this->capture_redirect( array( $this->controller, 'handle_actions' ) );

		$saved = get_option( 'photo_comp_email_templates' );
		$this->assertSame( 'Voting is open', $saved['voting_opened']['subject'] );
	}

	public function test_editor_can_render_page(): void {
		$this->become_editor();

		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'photo-comp-email-templates', $html );
	}

	public function test_save_is_noop_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->request_save();

		$this->controller->handle_actions();

		$this->assertFalse( get_option( 'photo_comp_email_templates' ) );
	}

	public function test_render_dies_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );
		$this->controller->render();
	}
}
