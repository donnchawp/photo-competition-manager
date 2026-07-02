<?php
/**
 * Characterization tests for Competitions_Repository::send_submission_reminder_emails.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use WP_UnitTestCase;

class Competitions_Repository_Reminders_Test extends WP_UnitTestCase {

	/**
	 * @var Competitions_Repository
	 */
	private $repo;

	/**
	 * @var Members_Repository
	 */
	private $members;

	public function setUp(): void {
		parent::setUp();
		$this->repo    = new Competitions_Repository();
		$this->members = new Members_Repository();
	}

	private function make_open_competition(): int {
		return (int) $this->repo->create(
			array(
				'title'     => 'Open Comp',
				'slug'      => 'open-comp',
				'open_date' => '2020-01-01 00:00:00',
			)
		);
	}

	private function make_closed_competition(): int {
		return (int) $this->repo->create(
			array(
				'title'      => 'Closed Comp',
				'slug'       => 'closed-comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-01-02 00:00:00',
			)
		);
	}

	private function make_member( string $name, string $email ): void {
		$this->members->create(
			array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => 1,
			)
		);
	}

	public function test_invalid_competition_id() {
		$result = $this->repo->send_submission_reminder_emails( 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
	}

	public function test_missing_competition() {
		$result = $this->repo->send_submission_reminder_emails( 9999 );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_competition', $result->get_error_code() );
	}

	public function test_competition_not_open() {
		$competition_id = $this->make_closed_competition();
		$result         = $this->repo->send_submission_reminder_emails( $competition_id );
		$this->assertWPError( $result );
		$this->assertSame( 'competition_not_open', $result->get_error_code() );
	}

	public function test_no_members() {
		$competition_id = $this->make_open_competition();
		$result         = $this->repo->send_submission_reminder_emails( $competition_id );
		$this->assertWPError( $result );
		$this->assertSame( 'no_members', $result->get_error_code() );
	}

	public function test_sends_then_skips_on_rate_limit() {
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Alice', 'alice@example.com' );
		$this->make_member( 'Bob', 'bob@example.com' );

		$first = $this->repo->send_submission_reminder_emails( $competition_id );
		$this->assertIsArray( $first );
		$this->assertTrue( $first['success'] );
		$this->assertSame( 2, $first['sent_count'] );
		$this->assertSame( 0, $first['skipped_count'] );
		$this->assertSame( 2, $first['total_count'] );

		// Second run: both are rate-limited.
		$second = $this->repo->send_submission_reminder_emails( $competition_id );
		$this->assertIsArray( $second );
		$this->assertSame( 0, $second['sent_count'] );
		$this->assertSame( 2, $second['skipped_count'] );
	}
}
