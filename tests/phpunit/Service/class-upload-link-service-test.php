<?php
/**
 * Tests for Upload_Link_Service.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Dependencies;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Entrants_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Service\Email_Job_Manager;
use PhotoCompetitionManager\Service\Upload_Link_Service;
use WP_UnitTestCase;

class Upload_Link_Service_Test extends WP_UnitTestCase {

	/**
	 * @var Upload_Link_Service
	 */
	private $service;

	/**
	 * @var Competitions_Repository
	 */
	private $comps;

	/**
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * @var int
	 */
	private $mail_count = 0;

	/**
	 * @var array<string, mixed>|null
	 */
	private $last_mail = null;

	public function setUp(): void {
		parent::setUp();
		$this->service = new Upload_Link_Service();
		$this->comps   = new Competitions_Repository();
		$this->members = new Members_Repository();

		update_option( 'photo_comp_default_settings', wp_json_encode( array( 'urls' => array( 'upload_page' => 'https://example.com/upload/' ) ) ) );

		$this->mail_count = 0;
		add_filter(
			'wp_mail',
			function ( $atts ) {
				++$this->mail_count;
				$this->last_mail = $atts;
				return $atts;
			}
		);
	}

	private function make_open_competition(): int {
		return (int) $this->comps->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'     => 'Open Comp',
				'slug'      => 'open-comp',
				'open_date' => '2020-01-01 00:00:00',
			)
		);
	}

	private function make_closed_competition(): int {
		return (int) $this->comps->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'      => 'Closed Comp',
				'slug'       => 'closed-comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-01-02 00:00:00',
			)
		);
	}

	private function make_member( string $name, string $email, bool $active = true ): int {
		return (int) $this->members->create(
			array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => $active ? 1 : 0,
			)
		);
	}

	// --- send_to_member ---

	public function test_send_to_member_missing_competition() {
		$member_id = $this->make_member( 'Alice', 'alice@example.com' );
		$result    = $this->service->send_to_member( 9999, $member_id );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_competition', $result->get_error_code() );
	}

	public function test_send_to_member_missing_member() {
		$competition_id = $this->make_open_competition();
		$result         = $this->service->send_to_member( $competition_id, 9999 );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_member', $result->get_error_code() );
	}

	public function test_send_to_member_missing_email() {
		global $wpdb;
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->members->table(), array( 'email' => '' ), array( 'id' => $member_id ), array( '%s' ), array( '%d' ) );

		$result = $this->service->send_to_member( $competition_id, $member_id );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_email', $result->get_error_code() );
	}

	public function test_send_to_member_inactive_member() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com', false );

		$result = $this->service->send_to_member( $competition_id, $member_id, true );
		$this->assertWPError( $result );
		$this->assertSame( 'inactive_member', $result->get_error_code() );
		$this->assertSame( 0, $this->mail_count );
	}

	public function test_send_to_member_success_and_rate_limit() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );

		$first = $this->service->send_to_member( $competition_id, $member_id );
		$this->assertTrue( $first );
		$this->assertSame( 1, $this->mail_count );

		$second = $this->service->send_to_member( $competition_id, $member_id );
		$this->assertTrue( $second );
		$this->assertSame( 1, $this->mail_count );
	}

	public function test_send_to_member_sends_the_upload_link_email_the_screen_shows() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );

		$this->service->send_to_member( $competition_id, $member_id );

		$this->assertSame( 'alice@example.com', $this->last_mail['to'] );
		$this->assertStringContainsString( 'Here is your link to upload images for Open Comp.', $this->last_mail['message'] );
		$this->assertMatchesRegularExpression( '#href="https://example.com/upload/\?[^"]+"#', $this->last_mail['message'] );
	}

	public function test_upload_link_email_in_judged_competition_has_no_voting_line() {
		update_option( 'photo_comp_default_settings', wp_json_encode( array( 'urls' => array( 'upload_page' => 'https://example.com/upload/', 'voting_page' => 'https://example.com/vote/' ) ) ) );
		$competition_id = $this->make_open_competition();
		$this->comps->update( $competition_id, array( 'settings' => array( 'voting' => array( 'auth_mode' => 'judges', 'score_matrix' => array( 5, 4, 3 ) ) ) ) );
		$member_id = $this->make_member( 'Alice', 'alice@example.com' );

		$this->service->send_to_member( $competition_id, $member_id );

		$this->assertSame( 1, $this->mail_count );
		$this->assertStringNotContainsString( 'you can vote at', $this->last_mail['message'] );
		$this->assertStringNotContainsString( 'example.com/vote', $this->last_mail['message'] );
		$this->assertStringNotContainsString( '<p></p>', $this->last_mail['message'] );
	}

	public function test_upload_link_email_in_judged_competition_ignores_voting_page_in_an_edited_template() {
		update_option( 'photo_comp_default_settings', wp_json_encode( array( 'urls' => array( 'upload_page' => 'https://example.com/upload/', 'voting_page' => 'https://example.com/vote/' ) ) ) );
		update_option(
			'photo_comp_email_templates',
			array(
				'upload_reminder' => array(
					'enabled' => true,
					'subject' => 'Upload',
					'body'    => '<p>Upload at {upload_link} and vote at {voting_page}</p>',
				),
			)
		);
		$competition_id = $this->make_open_competition();
		$this->comps->update( $competition_id, array( 'settings' => array( 'voting' => array( 'auth_mode' => 'judges', 'score_matrix' => array( 5, 4, 3 ) ) ) ) );
		$member_id = $this->make_member( 'Alice', 'alice@example.com' );

		$this->service->send_to_member( $competition_id, $member_id );

		$this->assertSame( 1, $this->mail_count );
		$this->assertStringNotContainsString( 'example.com/vote', $this->last_mail['message'] );
	}

	public function test_upload_link_email_in_member_competition_keeps_voting_line() {
		update_option( 'photo_comp_default_settings', wp_json_encode( array( 'urls' => array( 'upload_page' => 'https://example.com/upload/', 'voting_page' => 'https://example.com/vote/' ) ) ) );
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );

		$this->service->send_to_member( $competition_id, $member_id );

		$this->assertStringContainsString( 'Once voting opens, you can vote at <a href="https://example.com/vote/">', $this->last_mail['message'] );
	}

	public function test_send_to_member_links_the_pages_the_rule_finds() {
		update_option(
			'photo_comp_default_settings',
			wp_json_encode(
				array(
					'urls' => array(
						'upload_page' => 'https://example.com/club-upload/',
						'voting_page' => 'https://example.com/club-vote/',
					),
				)
			)
		);
		self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[competition_voting] [competition_upload]',
			)
		);
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );

		$this->service->send_to_member( $competition_id, $member_id );

		$this->assertMatchesRegularExpression( '#href="https://example.com/club-upload/\?[^"]+"#', $this->last_mail['message'] );
		$this->assertStringContainsString( 'you can vote at <a href="https://example.com/club-vote/">', $this->last_mail['message'] );
	}

	public function test_send_to_member_refuses_when_no_upload_page_can_be_found() {
		delete_option( 'photo_comp_default_settings' );
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );

		$result = $this->service->send_to_member( $competition_id, $member_id, true );

		$this->assertWPError( $result );
		$this->assertSame( 'no_upload_page', $result->get_error_code() );
		$this->assertSame( 0, $this->mail_count );
	}

	public function test_send_by_email_fails_visibly_when_no_upload_page_can_be_found() {
		delete_option( 'photo_comp_default_settings' );
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Bob', 'bob@example.com' );

		$this->assertFalse( $this->service->send_by_email( $competition_id, 'bob@example.com' ) );
		$this->assertSame( 0, $this->mail_count );
	}

	public function test_send_by_email_logs_why_it_failed_when_no_upload_page_can_be_found() {
		delete_option( 'photo_comp_default_settings' );
		$competition_id = $this->make_open_competition();

		$this->service->send_by_email( $competition_id, 'bob@example.com' );

		$logs = ( new Logs_Repository() )->find_by_competition( $competition_id, 50, 0, array( 'event_type' => 'upload_link_no_upload_page' ) );
		$this->assertCount( 1, $logs );
		$this->assertStringNotContainsString( 'bob@example.com', wp_json_encode( $logs ) );
	}

	public function test_send_to_member_send_failed() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );
		add_filter( 'pre_wp_mail', '__return_false' );

		$result = $this->service->send_to_member( $competition_id, $member_id );
		$this->assertWPError( $result );
		$this->assertSame( 'send_failed', $result->get_error_code() );
	}

	// --- send_by_email ---

	public function test_send_by_email_unknown_email_is_success() {
		$competition_id = $this->make_open_competition();
		$result         = $this->service->send_by_email( $competition_id, 'nobody@example.com' );
		$this->assertTrue( $result );
	}

	public function test_send_by_email_send_failure_returns_false() {
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Bob', 'bob@example.com' );
		add_filter( 'pre_wp_mail', '__return_false' );

		$result = $this->service->send_by_email( $competition_id, 'bob@example.com' );
		$this->assertFalse( $result );
	}

	public function test_send_by_email_inactive_member_is_silent_success() {
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Dave', 'dave@example.com', false );

		$result = $this->service->send_by_email( $competition_id, 'dave@example.com' );
		$this->assertTrue( $result );
		$this->assertSame( 0, $this->mail_count );
	}

	public function test_send_by_email_non_send_error_is_success() {
		$this->make_member( 'Carol', 'carol@example.com' );
		$result = $this->service->send_by_email( 9999, 'carol@example.com' );
		$this->assertTrue( $result );
	}

	// --- send_reminder ---

	public function test_send_reminder_reports_sent_then_skipped() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );

		$this->assertSame( 'sent', $this->service->send_reminder( $competition_id, $member_id ) );
		$this->assertSame( 'skipped', $this->service->send_reminder( $competition_id, $member_id ) );
		$this->assertSame( 1, $this->mail_count );
	}

	public function test_send_reminder_returns_send_error() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com', false );

		$result = $this->service->send_reminder( $competition_id, $member_id );
		$this->assertWPError( $result );
		$this->assertSame( 'inactive_member', $result->get_error_code() );
	}

	// --- queue_reminders ---

	private function jobs(): Email_Job_Manager {
		return ( new Dependencies() )->email_job_manager;
	}

	public function test_reminders_invalid_competition_id() {
		$result = $this->service->queue_reminders( 0, $this->jobs() );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
	}

	public function test_reminders_missing_competition() {
		$result = $this->service->queue_reminders( 9999, $this->jobs() );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_competition', $result->get_error_code() );
	}

	public function test_reminders_competition_not_open() {
		$competition_id = $this->make_closed_competition();
		$result         = $this->service->queue_reminders( $competition_id, $this->jobs() );
		$this->assertWPError( $result );
		$this->assertSame( 'competition_not_open', $result->get_error_code() );
	}

	public function test_reminders_no_members() {
		$competition_id = $this->make_open_competition();
		$result         = $this->service->queue_reminders( $competition_id, $this->jobs() );
		$this->assertWPError( $result );
		$this->assertSame( 'no_members', $result->get_error_code() );
	}

	public function test_reminders_queue_active_members_without_sending() {
		$competition_id = $this->make_open_competition();
		$alice          = $this->make_member( 'Alice', 'alice@example.com' );
		$this->make_member( 'Bob', 'bob@example.com', false );
		$jobs = $this->jobs();

		$job_id = $this->service->queue_reminders( $competition_id, $jobs );

		$this->assertIsString( $job_id );
		$job = $jobs->get_job( $job_id );
		$this->assertSame( 'upload_reminder', $job['type'] );
		$this->assertSame( array( $alice ), $job['member_ids'] );
		$this->assertSame( 0, $this->mail_count );
	}

	public function test_reminders_refuse_when_no_upload_page_can_be_found() {
		delete_option( 'photo_comp_default_settings' );
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Alice', 'alice@example.com' );

		$result = $this->service->queue_reminders( $competition_id, $this->jobs() );

		$this->assertWPError( $result );
		$this->assertSame( 'no_upload_page', $result->get_error_code() );
	}

	public function test_reminders_only_inactive_members_is_no_members() {
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Bob', 'bob@example.com', false );

		$result = $this->service->queue_reminders( $competition_id, $this->jobs() );
		$this->assertWPError( $result );
		$this->assertSame( 'no_members', $result->get_error_code() );
	}

	// --- who may enter ---

	/**
	 * Two types, Individual and Team, with an open competition of Individual.
	 *
	 * @param string $entry_mode 'all' or 'selected'.
	 * @return array{0:int,1:array<string,int>} Competition ID and type IDs by name.
	 */
	private function two_type_competition( string $entry_mode = 'all' ): array {
		Type_Fixtures::clear();
		$types = array(
			'Individual' => Type_Fixtures::create( 'Individual' ),
			'Team'       => Type_Fixtures::create( 'Team' ),
		);

		$competition_id = (int) $this->comps->create(
			array(
				'type_id'    => $types['Individual'],
				'title'      => 'Open Comp',
				'slug'       => 'open-comp',
				'open_date'  => '2020-01-01 00:00:00',
				'entry_mode' => $entry_mode,
			)
		);

		return array( $competition_id, $types );
	}

	private function make_typed_member( string $name, array $type_ids ): int {
		return (int) $this->members->create(
			array(
				'name'     => $name,
				'email'    => strtolower( $name ) . '@example.com',
				'grade'    => 'beginner',
				'type_ids' => $type_ids,
			)
		);
	}

	public function test_untagged_member_gets_no_link_for_open_competition() {
		list( $competition_id, $types ) = $this->two_type_competition();
		$member_id                      = $this->make_typed_member( 'Tess', array( $types['Team'] ) );

		$result = $this->service->send_to_member( $competition_id, $member_id, true );

		$this->assertWPError( $result );
		$this->assertSame( 'not_an_entrant', $result->get_error_code() );
		$this->assertSame( 0, $this->mail_count );
		$this->assertSame( array(), ( new Upload_Token_Repository() )->get_tracking_by_competition( $competition_id ) );
	}

	public function test_tagged_member_gets_a_link_for_open_competition() {
		list( $competition_id, $types ) = $this->two_type_competition();
		$member_id                      = $this->make_typed_member( 'Ian', array( $types['Individual'] ) );

		$this->assertTrue( $this->service->send_to_member( $competition_id, $member_id, true ) );
		$this->assertSame( 1, $this->mail_count );
	}

	public function test_reminders_go_only_to_entrants_when_selected() {
		list( $competition_id, $types ) = $this->two_type_competition( 'selected' );
		$entrant                        = $this->make_typed_member( 'Ann', array( $types['Individual'] ) );
		$this->make_typed_member( 'Ben', array( $types['Individual'] ) );
		( new Entrants_Repository() )->add( $competition_id, $entrant );
		$jobs = $this->jobs();

		$job_id = $this->service->queue_reminders( $competition_id, $jobs );

		$this->assertIsString( $job_id );
		$this->assertSame( array( $entrant ), $jobs->get_job( $job_id )['member_ids'] );
	}

	public function test_reminders_go_only_to_the_type_when_open_to_all() {
		list( $competition_id, $types ) = $this->two_type_competition();
		$individual                     = $this->make_typed_member( 'Ann', array( $types['Individual'] ) );
		$this->make_typed_member( 'Ben', array( $types['Team'] ) );
		$jobs = $this->jobs();

		$job_id = $this->service->queue_reminders( $competition_id, $jobs );

		$this->assertSame( array( $individual ), $jobs->get_job( $job_id )['member_ids'] );
	}

	public function test_reminders_with_no_one_who_may_enter_is_no_members() {
		list( $competition_id, $types ) = $this->two_type_competition( 'selected' );
		$this->make_typed_member( 'Ann', array( $types['Individual'] ) );

		$result = $this->service->queue_reminders( $competition_id, $this->jobs() );

		$this->assertWPError( $result );
		$this->assertSame( 'no_members', $result->get_error_code() );
	}

	public function test_queued_reminder_for_a_removed_entrant_is_not_sent() {
		list( $competition_id, $types ) = $this->two_type_competition( 'selected' );
		$member_id                      = $this->make_typed_member( 'Ann', array( $types['Individual'] ) );

		$result = $this->service->send_reminder( $competition_id, $member_id );

		$this->assertWPError( $result );
		$this->assertSame( 'not_an_entrant', $result->get_error_code() );
		$this->assertSame( 0, $this->mail_count );
	}

	public function test_request_link_by_email_for_non_entrant_sends_nothing() {
		list( $competition_id, $types ) = $this->two_type_competition( 'selected' );
		$this->make_typed_member( 'Ann', array( $types['Individual'] ) );

		$this->assertTrue( $this->service->send_by_email( $competition_id, 'ann@example.com' ) );
		$this->assertSame( 0, $this->mail_count );
	}

	public function test_one_type_site_sends_links_to_all_active_members_as_before() {
		$competition_id = $this->make_open_competition();
		$alice          = $this->make_member( 'Alice', 'alice@example.com' );
		$bob            = $this->make_member( 'Bob', 'bob@example.com' );
		$jobs           = $this->jobs();

		$this->assertTrue( $this->service->send_to_member( $competition_id, $alice, true ) );
		$this->assertTrue( $this->service->send_by_email( $competition_id, 'bob@example.com' ) );
		$this->assertSame( 2, $this->mail_count );

		$job_id = $this->service->queue_reminders( $competition_id, $jobs );
		$this->assertSame( array( $alice, $bob ), $jobs->get_job( $job_id )['member_ids'] );
	}
}
