<?php
/**
 * Tests for Email_Service.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Service\Email_Service;
use WP_UnitTestCase;

/**
 * Sending one kind of email.
 */
class Email_Service_Test extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Email_Service
	 */
	private $service;

	/**
	 * Last captured wp_mail() arguments.
	 *
	 * @var array<string, mixed>|null
	 */
	private $last_mail = null;

	/**
	 * Set up the service and capture outgoing mail.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'blogname', 'Camera Club' );
		$this->service = new Email_Service();

		// Capture outgoing mail instead of sending it.
		add_filter(
			'wp_mail',
			function ( $atts ) {
				$this->last_mail = $atts;
				return $atts;
			}
		);
	}

	/**
	 * A member row.
	 *
	 * @param string $name Member name.
	 * @return object
	 */
	private function member( string $name = 'Alice' ): object {
		return (object) array(
			'id'    => 7,
			'name'  => $name,
			'email' => 'alice@example.com',
		);
	}

	/**
	 * A competition row.
	 *
	 * @return object
	 */
	private function competition(): object {
		return (object) array(
			'id'    => 3,
			'title' => 'Spring Show',
		);
	}

	/**
	 * With nothing saved, an upload link email is the default the screen shows.
	 */
	public function test_an_upload_link_with_nothing_saved_sends_the_default() {
		$result = $this->service->send(
			'upload_reminder',
			$this->member(),
			$this->competition(),
			array(
				'{upload_link}' => 'https://example.org/upload/?t=abc',
				'{voting_page}' => 'https://example.org/vote/',
			)
		);

		$this->assertSame( 'sent', $result );
		$this->assertSame( 'alice@example.com', $this->last_mail['to'] );
		$this->assertSame( '[Camera Club] Upload your images for Spring Show', $this->last_mail['subject'] );
		$this->assertStringContainsString( '<p>Hi Alice,</p>', $this->last_mail['message'] );
		$this->assertStringContainsString( 'href="https://example.org/upload/?t=abc"', $this->last_mail['message'] );
		$this->assertStringContainsString( 'https://example.org/vote/', $this->last_mail['message'] );
	}

	/**
	 * Send an upload link email with its tags filled in.
	 *
	 * @return string|\WP_Error
	 */
	private function send_upload_link() {
		return $this->service->send(
			'upload_reminder',
			$this->member(),
			$this->competition(),
			array(
				'{upload_link}' => 'https://example.org/upload/?t=abc',
				'{voting_page}' => 'https://example.org/vote/',
			)
		);
	}

	/**
	 * A saved template replaces the default.
	 */
	public function test_a_saved_template_replaces_the_default() {
		update_option(
			'photo_comp_email_templates',
			array(
				'upload_reminder' => array(
					'enabled' => true,
					'subject' => 'Time to enter {competition_title}',
					'body'    => 'Dear {member_name}, go to {upload_link}',
				),
			)
		);

		$this->send_upload_link();

		$this->assertSame( '[Camera Club] Time to enter Spring Show', $this->last_mail['subject'] );
		$this->assertStringContainsString( 'Dear Alice, go to https://example.org/upload/?t=abc', $this->last_mail['message'] );
		$this->assertStringNotContainsString( 'Hi Alice,', $this->last_mail['message'] );
	}

	/**
	 * A requested email can't be switched off, so a saved "off" is ignored.
	 */
	public function test_a_requested_email_sends_even_when_saved_switched_off() {
		update_option(
			'photo_comp_email_templates',
			array(
				'upload_reminder' => array(
					'enabled' => false,
					'subject' => 'Time to enter {competition_title}',
					'body'    => 'Dear {member_name}',
				),
			)
		);

		$this->assertSame( 'sent', $this->send_upload_link() );
		$this->assertSame( '[Camera Club] Time to enter Spring Show', $this->last_mail['subject'] );
	}

	/**
	 * Send a voting opened notification with its tags filled in.
	 *
	 * @return string|\WP_Error
	 */
	private function send_voting_opened() {
		return $this->service->send(
			'voting_opened',
			$this->member(),
			$this->competition(),
			array(
				'{voting_page}' => 'https://example.org/vote/',
				'{close_date}'  => 'Friday 3 April',
			)
		);
	}

	/**
	 * A notification is off until an admin switches it on, and then nothing is sent.
	 */
	public function test_a_notification_thats_off_by_default_is_skipped() {
		$this->assertSame( 'skipped', $this->send_voting_opened() );
		$this->assertNull( $this->last_mail );
	}

	/**
	 * A notification an admin switched on sends its default.
	 */
	public function test_a_notification_switched_on_sends_its_default() {
		update_option( 'photo_comp_email_templates', array( 'voting_opened' => array( 'enabled' => true ) ) );

		$this->assertSame( 'sent', $this->send_voting_opened() );
		$this->assertSame( '[Camera Club] Voting is now open for Spring Show', $this->last_mail['subject'] );
		$this->assertStringContainsString( 'Voting closes on Friday 3 April.', $this->last_mail['message'] );
	}

	/**
	 * A notification an admin switched off is skipped, whatever its text.
	 */
	public function test_a_notification_switched_off_is_skipped() {
		update_option(
			'photo_comp_email_templates',
			array(
				'voting_opened' => array(
					'enabled' => false,
					'subject' => 'Vote now',
					'body'    => 'Go vote',
				),
			)
		);

		$this->assertSame( 'skipped', $this->send_voting_opened() );
		$this->assertNull( $this->last_mail );
	}

	/**
	 * A caller must pass every one of its kind's tags, and no others.
	 *
	 * @dataProvider wrong_tags
	 *
	 * @param array<string, string> $tags Tags the caller passes.
	 */
	public function test_a_caller_passing_the_wrong_tags_sends_nothing( array $tags ) {
		$result = $this->service->send( 'upload_reminder', $this->member(), $this->competition(), $tags );

		$this->assertWPError( $result );
		$this->assertSame( 'wrong_email_tags', $result->get_error_code() );
		$this->assertNull( $this->last_mail );
	}

	/**
	 * Tag sets that don't match the upload link email's.
	 *
	 * @return array<string, array<int, array<string, string>>>
	 */
	public function wrong_tags(): array {
		return array(
			'missing a tag'    => array( array( '{upload_link}' => 'https://example.org/u' ) ),
			'an undeclared one' => array(
				array(
					'{upload_link}' => 'https://example.org/u',
					'{voting_page}' => '',
					'{close_date}'  => 'Friday',
				),
			),
			'a shared one'     => array(
				array(
					'{upload_link}' => 'https://example.org/u',
					'{voting_page}' => '',
					'{member_name}' => 'Bob',
				),
			),
		);
	}

	/**
	 * Text tag values are HTML-escaped and link tag values URL-escaped.
	 */
	public function test_tag_values_are_escaped_for_their_type() {
		$this->service->send(
			'upload_reminder',
			$this->member( 'Seán & <Co>' ),
			$this->competition(),
			array(
				'{upload_link}' => 'https://example.org/upload/?a=1&b="><script>',
				'{voting_page}' => 'javascript:alert(1)',
			)
		);

		$message = $this->last_mail['message'];
		$this->assertStringContainsString( 'Hi Seán &amp; &lt;Co&gt;,', $message );
		$this->assertStringContainsString( 'href="https://example.org/upload/?a=1&#038;b=script"', $message );
		$this->assertStringNotContainsString( '<script>', $message );
		$this->assertStringNotContainsString( 'javascript:', $message );
	}

	/**
	 * Site names as typed in Settings > General.
	 *
	 * @return array<string, array{string}>
	 */
	public function site_names(): array {
		return array(
			'plain'                 => array( 'Camera Club' ),
			'ampersand'             => array( 'Camera & Club' ),
			'apostrophe and quotes' => array( 'Seán\'s "Snappers"' ),
		);
	}

	/**
	 * WordPress saves the site name escaped, so it's decoded before going
	 * into the plain-text subject and escaped exactly once in the body.
	 *
	 * @dataProvider site_names
	 *
	 * @param string $typed Site name as typed.
	 */
	public function test_the_site_name_appears_as_typed( string $typed ) {
		update_option( 'blogname', $typed );
		update_option(
			'photo_comp_email_templates',
			array(
				'upload_reminder' => array(
					'enabled' => true,
					'subject' => '{site_name}: {competition_title}',
					'body'    => 'Welcome to {site_name}',
				),
			)
		);

		$this->send_upload_link();

		$this->assertSame( "[{$typed}] {$typed}: Spring Show", $this->last_mail['subject'] );
		$this->assertStringContainsString( 'Welcome to ' . esc_html( $typed ), $this->last_mail['message'] );
		$this->assertStringContainsString( 'This email was sent by ' . esc_html( $typed ), $this->last_mail['message'] );
		$this->assertStringNotContainsString( '&amp;amp;', $this->last_mail['message'] );
	}

	/**
	 * A value that looks like a tag is left as it is, not filled in.
	 */
	public function test_a_value_that_looks_like_a_tag_stays_as_it_is() {
		$this->service->send(
			'upload_reminder',
			$this->member( '{upload_link}' ),
			$this->competition(),
			array(
				'{upload_link}' => 'https://example.org/upload/?t=abc',
				'{voting_page}' => '',
			)
		);

		$this->assertStringContainsString( '<p>Hi {upload_link},</p>', $this->last_mail['message'] );
	}

	/**
	 * The results table goes in after the template is formatted, so it arrives as built.
	 */
	public function test_the_results_table_arrives_as_built() {
		$table = "<div class=\"results\">\nRank: 1\n\nScore: 9\n</div>";

		$result = $this->service->send( 'results_detailed', $this->member(), $this->competition(), array( '{results_table}' => $table ) );

		$this->assertSame( 'sent', $result );
		$this->assertStringContainsString( $table, $this->last_mail['message'] );
		$this->assertStringNotContainsString( '<p>' . $table, $this->last_mail['message'] );
		$this->assertSame( '[Camera Club] Results for Spring Show', $this->last_mail['subject'] );
	}

	/**
	 * A saved template's paragraph around the results table is unwrapped, whatever the editor saved.
	 */
	public function test_the_results_table_isnt_left_inside_a_saved_paragraph() {
		update_option(
			'photo_comp_email_templates',
			array(
				'results_detailed' => array(
					'subject' => 'Results',
					'body'    => "<p>Hi</p>\n<p style=\"text-align: left;\"> {results_table} </p>",
				),
			)
		);
		$table = '<div class="results">Rank: 1</div>';

		$this->service->send( 'results_detailed', $this->member(), $this->competition(), array( '{results_table}' => $table ) );

		$this->assertStringContainsString( "<p>Hi</p>\n" . $table, $this->last_mail['message'] );
	}

	/**
	 * Each send is logged under its kind, against its competition.
	 */
	public function test_a_sent_email_is_logged_under_its_kind() {
		$this->send_upload_link();

		$logs = ( new Logs_Repository() )->find_by_competition( 3 );

		$this->assertCount( 1, $logs );
		$this->assertSame( 'upload_reminder', $logs[0]->event_type );
		$this->assertSame( 'email', $logs[0]->event_category );
		$this->assertSame( 'Email sent to Alice: Upload link', $logs[0]->description );
	}

	/**
	 * There is no such kind of email.
	 */
	public function test_an_unknown_kind_sends_nothing() {
		$result = $this->service->send( 'no_such_kind', $this->member(), $this->competition(), array() );

		$this->assertWPError( $result );
		$this->assertNull( $this->last_mail );
	}

	/**
	 * An enabled template with a subject and body counts as enabled.
	 */
	public function test_is_template_enabled_true_for_enabled_template() {
		update_option(
			'photo_comp_email_templates',
			array(
				'voting_opened' => array(
					'enabled' => true,
					'subject' => 'Voting is open for {competition_title}',
					'body'    => '<p>Hi {member_name}</p>',
				),
			)
		);

		$this->assertTrue( $this->service->is_template_enabled( 'voting_opened' ) );
	}

	/**
	 * A disabled or never-saved template is not enabled.
	 */
	public function test_is_template_enabled_false_for_disabled_or_missing_template() {
		update_option(
			'photo_comp_email_templates',
			array(
				'voting_opened' => array(
					'enabled' => false,
					'subject' => 'Voting is open for {competition_title}',
					'body'    => '<p>Hi {member_name}</p>',
				),
			)
		);

		$this->assertFalse( $this->service->is_template_enabled( 'voting_opened' ) );
		$this->assertFalse( $this->service->is_template_enabled( 'no_such_template' ) );
	}
}
