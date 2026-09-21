<?php
/**
 * Tests for Upload_Link_Service.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Dependencies;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
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

	public function setUp(): void {
		parent::setUp();
		$this->service = new Upload_Link_Service();
		$this->comps   = new Competitions_Repository();
		$this->members = new Members_Repository();

		$this->mail_count = 0;
		add_filter(
			'wp_mail',
			function ( $atts ) {
				++$this->mail_count;
				return $atts;
			}
		);
	}

	private function make_open_competition(): int {
		return (int) $this->comps->create(
			array(
				'title'     => 'Open Comp',
				'slug'      => 'open-comp',
				'open_date' => '2020-01-01 00:00:00',
			)
		);
	}

	private function make_closed_competition(): int {
		return (int) $this->comps->create(
			array(
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
		$result    = $this->service->send_to_member( 9999, $member_id, 'https://example.com/upload/' );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_competition', $result->get_error_code() );
	}

	public function test_send_to_member_missing_member() {
		$competition_id = $this->make_open_competition();
		$result         = $this->service->send_to_member( $competition_id, 9999, 'https://example.com/upload/' );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_member', $result->get_error_code() );
	}

	public function test_send_to_member_missing_email() {
		global $wpdb;
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->members->table(), array( 'email' => '' ), array( 'id' => $member_id ), array( '%s' ), array( '%d' ) );

		$result = $this->service->send_to_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_email', $result->get_error_code() );
	}

	public function test_send_to_member_inactive_member() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com', false );

		$result = $this->service->send_to_member( $competition_id, $member_id, 'https://example.com/upload/', true );
		$this->assertWPError( $result );
		$this->assertSame( 'inactive_member', $result->get_error_code() );
		$this->assertSame( 0, $this->mail_count );
	}

	public function test_send_to_member_success_and_rate_limit() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );

		$first = $this->service->send_to_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertTrue( $first );
		$this->assertSame( 1, $this->mail_count );

		$second = $this->service->send_to_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertTrue( $second );
		$this->assertSame( 1, $this->mail_count );
	}

	public function test_send_to_member_send_failed() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );
		add_filter( 'pre_wp_mail', '__return_false' );

		$result = $this->service->send_to_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertWPError( $result );
		$this->assertSame( 'send_failed', $result->get_error_code() );
	}

	// --- send_by_email ---

	public function test_send_by_email_unknown_email_is_success() {
		$competition_id = $this->make_open_competition();
		$result         = $this->service->send_by_email( $competition_id, 'nobody@example.com', 'https://example.com/upload/' );
		$this->assertTrue( $result );
	}

	public function test_send_by_email_send_failure_returns_false() {
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Bob', 'bob@example.com' );
		add_filter( 'pre_wp_mail', '__return_false' );

		$result = $this->service->send_by_email( $competition_id, 'bob@example.com', 'https://example.com/upload/' );
		$this->assertFalse( $result );
	}

	public function test_send_by_email_inactive_member_is_silent_success() {
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Dave', 'dave@example.com', false );

		$result = $this->service->send_by_email( $competition_id, 'dave@example.com', 'https://example.com/upload/' );
		$this->assertTrue( $result );
		$this->assertSame( 0, $this->mail_count );
	}

	public function test_send_by_email_non_send_error_is_success() {
		$this->make_member( 'Carol', 'carol@example.com' );
		$result = $this->service->send_by_email( 9999, 'carol@example.com', 'https://example.com/upload/' );
		$this->assertTrue( $result );
	}

	// --- send_reminder ---

	public function test_send_reminder_reports_sent_then_skipped() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );

		$this->assertSame( 'sent', $this->service->send_reminder( $competition_id, $member_id, 'https://example.com/upload/' ) );
		$this->assertSame( 'skipped', $this->service->send_reminder( $competition_id, $member_id, 'https://example.com/upload/' ) );
		$this->assertSame( 1, $this->mail_count );
	}

	public function test_send_reminder_returns_send_error() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com', false );

		$result = $this->service->send_reminder( $competition_id, $member_id, 'https://example.com/upload/' );
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
		$this->assertSame( 'upload_link', $job['type'] );
		$this->assertSame( array( $alice ), $job['member_ids'] );
		$this->assertNotEmpty( $job['args']['upload_page_url'] );
		$this->assertSame( 0, $this->mail_count );
		$this->assertNotFalse( wp_next_scheduled( Email_Job_Manager::BATCH_HOOK, array( $job_id ) ) );
	}

	public function test_reminders_only_inactive_members_is_no_members() {
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Bob', 'bob@example.com', false );

		$result = $this->service->queue_reminders( $competition_id, $this->jobs() );
		$this->assertWPError( $result );
		$this->assertSame( 'no_members', $result->get_error_code() );
	}
}
