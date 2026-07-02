<?php
/**
 * Tests for Upload_Link_Service.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
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

	public function setUp(): void {
		parent::setUp();
		$this->service = new Upload_Link_Service();
		$this->comps   = new Competitions_Repository();
		$this->members = new Members_Repository();
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

	private function make_member( string $name, string $email ): int {
		return (int) $this->members->create(
			array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => 1,
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

	public function test_send_to_member_success_and_rate_limit() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );

		$mail_count = 0;
		add_filter(
			'wp_mail',
			function ( $atts ) use ( &$mail_count ) {
				++$mail_count;
				return $atts;
			}
		);

		$first = $this->service->send_to_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertTrue( $first );
		$this->assertSame( 1, $mail_count );

		$second = $this->service->send_to_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertTrue( $second );
		$this->assertSame( 1, $mail_count );
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

	public function test_send_by_email_non_send_error_is_success() {
		$this->make_member( 'Carol', 'carol@example.com' );
		$result = $this->service->send_by_email( 9999, 'carol@example.com', 'https://example.com/upload/' );
		$this->assertTrue( $result );
	}

	// --- send_reminders ---

	public function test_reminders_invalid_competition_id() {
		$result = $this->service->send_reminders( 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
	}

	public function test_reminders_missing_competition() {
		$result = $this->service->send_reminders( 9999 );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_competition', $result->get_error_code() );
	}

	public function test_reminders_competition_not_open() {
		$competition_id = $this->make_closed_competition();
		$result         = $this->service->send_reminders( $competition_id );
		$this->assertWPError( $result );
		$this->assertSame( 'competition_not_open', $result->get_error_code() );
	}

	public function test_reminders_no_members() {
		$competition_id = $this->make_open_competition();
		$result         = $this->service->send_reminders( $competition_id );
		$this->assertWPError( $result );
		$this->assertSame( 'no_members', $result->get_error_code() );
	}

	public function test_reminders_sends_then_skips_on_rate_limit() {
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Alice', 'alice@example.com' );
		$this->make_member( 'Bob', 'bob@example.com' );

		$first = $this->service->send_reminders( $competition_id );
		$this->assertIsArray( $first );
		$this->assertTrue( $first['success'] );
		$this->assertSame( 2, $first['sent_count'] );
		$this->assertSame( 0, $first['skipped_count'] );
		$this->assertSame( 0, $first['failed_count'] );
		$this->assertEmpty( $first['errors'] );
		$this->assertSame( 2, $first['total_count'] );

		$second = $this->service->send_reminders( $competition_id );
		$this->assertIsArray( $second );
		$this->assertSame( 0, $second['sent_count'] );
		$this->assertSame( 2, $second['skipped_count'] );
	}
}
