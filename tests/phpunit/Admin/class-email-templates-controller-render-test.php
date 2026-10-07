<?php
/**
 * Golden-master snapshot tests for Email_Templates_Controller::render().
 *
 * Pins the exact rendered HTML ahead of the template-partial extraction (#40).
 * Nonces are normalized so snapshots do not churn per run, and the core
 * stylesheet links wp_editor() prints are stripped so they don't depend on
 * test order (#89).
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Email_Templates_Controller;
use PhotoCompetitionManager\Service\Email_Kinds;

/**
 * @covers \PhotoCompetitionManager\Admin\Email_Templates_Controller
 */
class Email_Templates_Controller_Render_Test extends Admin_Controller_Test_Case {

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
	 * Render the page and return normalized HTML.
	 */
	private function render_normalized(): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();
		// Normalize per-run nonces: _wpnonce=<10 hex> and nonce field values.
		$html = preg_replace( '/(_wpnonce=)[a-f0-9]{10}/', '$1NONCE', $html );
		$html = preg_replace( '/(name="_wpnonce" value=")[a-f0-9]{10}/', '$1NONCE', $html );
		// Drop the core stylesheet <link> tags wp_editor() prints. It prints
		// them only for the first editor in the PHP process, so whether they
		// appear depends on which test ran first (#89). They come from WordPress
		// core, not the controller.
		$html = preg_replace( '/<link\b[^>]*\brel=[\'"]stylesheet[\'"][^>]*>\n?/', '', $html );
		// Fold numeric &#038; to &amp; so snapshots are agnostic to which
		// ampersand entity WordPress emits (esc_url uses &#038;, esc_attr
		// &amp;; core has changed usage between releases).
		$html = preg_replace( '/&#0*38;/', '&amp;', $html );
		return $html;
	}

	/**
	 * Assert live output equals the stored snapshot; write it on first run.
	 *
	 * @param string $scenario Snapshot scenario name.
	 */
	private function assert_matches_snapshot( string $scenario ): void {
		$dir  = __DIR__ . '/../fixtures/email-templates-render';
		$file = $dir . '/' . $scenario . '.html';
		$html = $this->render_normalized();

		if ( ! file_exists( $file ) ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				// wp_mkdir_p() has been observed to fail silently in this test
				// environment; fall back to a plain mkdir() before giving up.
				mkdir( $dir, 0777, true );
			}
			if ( ! is_dir( $dir ) ) {
				self::fail( "Could not create snapshot directory {$dir}." );
			}
			if ( false === file_put_contents( $file, $html ) ) {
				self::fail( "Could not write snapshot file {$file}." );
			}
			$this->markTestSkipped( "Snapshot written for {$scenario}; re-run to assert." );
			return;
		}

		$this->assertSame( file_get_contents( $file ), $html, "Rendered markup drifted for scenario {$scenario}." );
	}

	public function test_only_notifications_can_be_switched_off(): void {
		$html = $this->render_normalized();

		$this->assertStringContainsString( 'name="templates[voting_opened][enabled]"', $html );
		$this->assertStringContainsString( 'name="templates[submission_confirmed][enabled]"', $html );
		foreach ( array( 'upload_reminder', 'voting_link', 'results_published', 'results_detailed' ) as $kind ) {
			$this->assertStringNotContainsString( 'name="templates[' . $kind . '][enabled]"', $html, $kind );
			$this->assertStringContainsString( 'name="templates[' . $kind . '][subject]"', $html, $kind );
		}
	}

	public function test_each_merge_tag_says_what_it_is(): void {
		$html = $this->render_normalized();

		$this->assertStringContainsString( '<code>{upload_link}</code> The member&#039;s own upload link.', $html );
		$this->assertStringContainsString( '<code>{member_name}</code> The member&#039;s name.', $html );
	}

	/**
	 * One kind's card from the rendered page.
	 *
	 * @param string $html Rendered page.
	 * @param string $kind Kind key.
	 * @return string
	 */
	private function card( string $html, string $kind ): string {
		foreach ( explode( '<div class="card photo-comp-template-card"', $html ) as $card ) {
			if ( false !== strpos( $card, 'name="templates[' . $kind . '][subject]"' ) ) {
				return $card;
			}
		}
		self::fail( "No card for {$kind}." );
	}

	public function test_only_an_edited_template_says_edited(): void {
		update_option(
			'photo_comp_email_templates',
			array(
				'voting_link' => array(
					'subject' => 'Your voting link',
					'body'    => 'Vote at {voting_link}',
				),
			)
		);

		$html = $this->render_normalized();

		$this->assertStringContainsString( 'Edited', $this->card( $html, 'voting_link' ) );
		$this->assertStringNotContainsString( 'Edited', $this->card( $html, 'upload_reminder' ) );
	}

	public function test_full_copies_saved_by_an_earlier_version_say_edited_only_where_they_differ(): void {
		$saved = array();
		foreach ( Email_Kinds::all() as $kind => $definition ) {
			$saved[ $kind ] = array(
				'enabled' => true,
				'subject' => $definition['subject'],
				// As the editor sent it: paragraphs as blank lines.
				'body'    => str_replace( array( '<p>', '</p>', "\n" ), array( '', '', "\r\n" ), $definition['body'] ),
			);
		}
		$saved['results_published']['subject'] = 'Results are in for {competition_title}';
		update_option( 'photo_comp_email_templates', $saved );

		$html = $this->render_normalized();

		$this->assertStringContainsString( 'Edited', $this->card( $html, 'results_published' ) );
		$this->assertStringContainsString( 'value="Results are in for {competition_title}"', $this->card( $html, 'results_published' ) );
		$this->assertStringNotContainsString( 'Edited', $this->card( $html, 'upload_reminder' ) );
	}

	public function test_each_card_can_restore_its_default_text(): void {
		update_option(
			'photo_comp_email_templates',
			array(
				'voting_link' => array(
					'subject' => 'Your voting link',
					'body'    => 'Vote at {voting_link}',
				),
			)
		);

		$card = $this->card( $this->render_normalized(), 'voting_link' );

		$this->assertStringContainsString( '>Restore default</button>', $card );
		$this->assertStringContainsString( 'data-subject-field="template-voting_link-subject"', $card );
		$this->assertStringContainsString( 'data-body-field="template_voting_link_body"', $card );
		$this->assertStringContainsString( 'data-default-subject="Vote in {competition_title}"', $card );
		$this->assertStringContainsString( 'data-default-body="&lt;p&gt;Hi {member_name},&lt;/p&gt;', $card );
		// The fields still hold the edit until the admin restores the default.
		$this->assertStringContainsString( 'value="Your voting link"', $card );
	}

	public function test_render_default_templates(): void {
		// No saved option: renders the built-in defaults for every template key.
		$this->assert_matches_snapshot( 'default-templates' );
	}

	public function test_render_saved_overrides(): void {
		// Saved option overrides subject/body/enabled for a subset of keys,
		// exercising the merge-with-defaults branch in render().
		update_option(
			'photo_comp_email_templates',
			array(
				'upload_reminder' => array(
					'enabled' => false,
					'subject' => 'Custom subject for {competition_title}',
					'body'    => '<p>Custom body with a "quote" & an ampersand.</p>',
				),
				'voting_opened'   => array(
					'enabled' => true,
					'subject' => 'Voting is live!',
					'body'    => '<p>Go vote.</p>',
				),
			)
		);

		$this->assert_matches_snapshot( 'saved-overrides' );
	}
}
