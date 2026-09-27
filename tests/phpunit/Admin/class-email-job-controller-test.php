<?php
/**
 * Tests for Email_Job_Controller.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Email_Job_Controller;
use PhotoCompetitionManager\Dependencies;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Email_Job_Manager;

class Email_Job_Controller_Test extends Admin_Controller_Test_Case {

	/**
	 * @var Email_Job_Manager
	 */
	private $jobs;

	/**
	 * @var Email_Job_Controller
	 */
	private $controller;

	/**
	 * @var int
	 */
	private $competition_id;

	/**
	 * Emails wp_mail() was asked to send.
	 *
	 * @var int
	 */
	private $mail_count = 0;

	public function set_up(): void {
		parent::set_up();

		$this->jobs       = ( new Dependencies() )->email_job_manager;
		$this->controller = new Email_Job_Controller( $this->jobs );

		$this->competition_id = (int) ( new Competitions_Repository() )->create(
			array(
				'title'      => 'Spring Show',
				'slug'       => 'spring-show',
				'open_date'  => null,
				'close_date' => null,
				'settings'   => array(),
			)
		);

		$this->mail_count = 0;
		add_filter( 'pre_wp_mail', array( $this, 'count_mail' ) );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'count_mail' ) );
		parent::tear_down();
	}

	/**
	 * Count the mail and report it as sent.
	 *
	 * @return bool
	 */
	public function count_mail(): bool {
		++$this->mail_count;
		return true;
	}

	/**
	 * Queue a results link job to some new members.
	 *
	 * @param int $count Number of members.
	 * @return string Job ID.
	 */
	private function queue_job( int $count ): string {
		$members    = new Members_Repository();
		$member_ids = array();
		for ( $i = 1; $i <= $count; $i++ ) {
			$member_ids[] = (int) $members->create(
				array(
					'name'  => "Member {$i}",
					'email' => "m{$i}@example.com",
				)
			);
		}

		return $this->jobs->queue( 'results_share', $this->competition_id, $member_ids, array( 'share_url' => 'https://example.com/r?share=abc' ) );
	}

	/**
	 * Call the AJAX handler for a job.
	 *
	 * @param string $job_id Job ID.
	 * @return array<string, mixed> Decoded JSON response.
	 */
	private function send_batch( string $job_id ): array {
		$this->set_request( array( 'job_id' => $job_id ) );
		$this->set_nonce( Email_Job_Controller::AJAX_ACTION );

		return $this->capture_json(
			function () {
				$this->controller->handle_send_batch();
			}
		);
	}

	public function test_send_batch_sends_one_batch_and_returns_running_notice(): void {
		$job_id = $this->queue_job( 12 );

		$json = $this->send_batch( $job_id );

		$this->assertTrue( $json['success'] );
		$this->assertSame( 10, $this->mail_count );
		$this->assertStringContainsString( 'data-job-id="' . $job_id . '"', $json['data']['html'] );
		$this->assertStringContainsString( 'Progress: 10 of 12 emails sent', $json['data']['html'] );
	}

	public function test_send_batch_returns_finished_notice_on_last_batch(): void {
		$job_id = $this->queue_job( 2 );

		$json = $this->send_batch( $job_id );

		$this->assertSame( 2, $this->mail_count );
		$this->assertStringContainsString( 'Sent 2 of 2 emails.', $json['data']['html'] );
		$this->assertStringNotContainsString( 'photo-comp-email-job', $json['data']['html'], 'A finished notice has nothing for the script to send.' );
	}

	public function test_send_batch_unknown_job_is_an_error(): void {
		$json = $this->send_batch( 'email_job_missing' );

		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( 'not found', $json['data']['message'] );
	}

	public function test_send_batch_requires_capability(): void {
		$job_id = $this->queue_job( 1 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$json = $this->send_batch( $job_id );

		$this->assertFalse( $json['success'] );
		$this->assertSame( 0, $this->mail_count );
	}

	public function test_send_batch_rejects_bad_nonce(): void {
		$job_id = $this->queue_job( 1 );
		$this->set_request(
			array(
				'job_id'   => $job_id,
				'_wpnonce' => 'bad',
			)
		);

		// check_ajax_referer() dies before anything is sent. Outside an AJAX
		// request it would call die() and end the test run.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new \WPDieException();
				};
			}
		);
		$this->expectException( \WPDieException::class );
		$this->controller->handle_send_batch();
	}
}
