<?php
/**
 * Tests for the plugin's suggested privacy-policy text.
 *
 * @package PhotoCompetitionManager\Tests\Support
 */

namespace PhotoCompetitionManager\Tests\Support;

use PhotoCompetitionManager\Support\Privacy_Policy;
use WP_Privacy_Policy_Content;
use WP_UnitTestCase;

/**
 * Settings > Privacy > Policy Guide shows what the club holds about members.
 *
 * @covers \PhotoCompetitionManager\Support\Privacy_Policy
 */
class Privacy_Policy_Test extends WP_UnitTestCase {

	public function test_the_policy_guide_suggests_the_plugins_text(): void {
		global $wp_actions;

		$this->assertNotFalse( has_action( 'admin_init', array( Privacy_Policy::class, 'suggest' ) ) );

		// As in wp-admin, once admin_init has fired; the test case restores both afterwards.
		set_current_screen( 'dashboard' );
		$wp_actions['admin_init'] = 1; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Privacy_Policy::suggest();

		$suggested = array_values(
			array_filter(
				WP_Privacy_Policy_Content::get_suggested_policy_text(),
				static function ( $content ) {
					return 'Photo Competition Manager' === $content['plugin_name'];
				}
			)
		);

		$this->assertCount( 1, $suggested );
		$text = $suggested[0]['policy_text'];
		foreach ( array( 'name, email address and grade', 'original', 'votes they cast', 'log of the emails', 'publish', 'without their name' ) as $phrase ) {
			$this->assertStringContainsString( $phrase, $text );
		}
	}

	public function tearDown(): void {
		set_current_screen( 'front' );

		parent::tearDown();
	}
}
