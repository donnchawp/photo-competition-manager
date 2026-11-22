<?php
/**
 * Tests for Setup_Wizard_Controller page creation.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Setup_Wizard_Controller;

/**
 * The setup wizard creates the member page when it's ticked.
 */
class Setup_Wizard_Controller_Test extends Admin_Controller_Test_Case {

	/**
	 * Submit the wizard with the given fields.
	 *
	 * @param array<string, string> $fields Posted fields.
	 * @return void
	 */
	private function submit( array $fields ): void {
		$this->set_request( array( 'photo_competition_action' => 'create_pages' ) + $fields );
		$this->set_nonce( 'photo_competition_create_pages', 'photo_competition_nonce' );

		$this->capture_redirect(
			function () {
				( new Setup_Wizard_Controller() )->handle_actions();
			}
		);
	}

	/**
	 * Published pages holding the member page shortcode.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function member_pages(): array {
		return array_values(
			array_filter(
				get_posts(
					array(
						'post_type'   => 'page',
						'post_status' => 'publish',
						'numberposts' => -1,
					)
				),
				static function ( $page ): bool {
					return has_shortcode( $page->post_content, 'competition_my_entries' );
				}
			)
		);
	}

	public function test_ticking_the_member_page_creates_it_with_its_title(): void {
		$this->submit(
			array(
				'create_member_page' => '1',
				'member_page_title'  => 'Your Entries',
			)
		);

		$pages = $this->member_pages();
		$this->assertCount( 1, $pages );
		$this->assertSame( 'Your Entries', $pages[0]->post_title );
	}

	public function test_leaving_the_member_page_unticked_creates_none(): void {
		$this->submit( array( 'create_top3_page' => '1' ) );

		$this->assertSame( array(), $this->member_pages() );
	}
}
