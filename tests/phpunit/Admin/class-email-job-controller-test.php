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
		$this->controller = new Email_Job_Controller( $this->jobs, new Competitions_Repository() );

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
		$this->assertSame( 5, $this->mail_count );
		$this->assertStringContainsString( 'data-job-id="' . $job_id . '"', $json['data']['html'] );
		$this->assertStringContainsString( 'Progress: 5 of 12 emails sent', $json['data']['html'] );
	}

	public function test_send_batch_returns_finished_notice_on_last_batch(): void {
		$job_id = $this->queue_job( 2 );

		$json = $this->send_batch( $job_id );

		$this->assertSame( 2, $this->mail_count );
		$this->assertStringContainsString( 'Sent 2 of 2 emails.', $json['data']['html'] );
		$this->assertStringNotContainsString( 'photo-comp-email-job', $json['data']['html'], 'A finished notice has nothing for the script to send.' );
	}

	public function test_progress_leaves_out_members_deleted_since_the_job_was_queued(): void {
		// They're dropped from the total, so counting them as processed too overshoots.
		$job_id  = $this->queue_job( 12 );
		$members = new Members_Repository();
		foreach ( array_slice( $this->jobs->get_job( $job_id )['member_ids'], 0, 4 ) as $member_id ) {
			$members->delete( (int) $member_id );
		}

		$json = $this->send_batch( $job_id );
		$this->assertStringContainsString( 'Progress: 1 of 8 emails sent', $json['data']['html'] );

		$this->age_job( $job_id, 400 );
		$this->reset_request();
		$this->assertStringContainsString( 'stopped at 1 of 8.', $this->abandoned_notices() );
	}

	public function test_failed_notice_shows_why_the_job_stopped_after_earlier_errors(): void {
		$job_id               = $this->queue_job( 1 );
		$job                  = $this->jobs->get_job( $job_id );
		$job['status']        = 'failed';
		$job['error_log']     = array( 'error 1', 'error 2', 'error 3', 'error 4', 'error 5', 'Competition not found' );
		update_option( 'photo_comp_email_job_' . $job_id, $job, false );

		$json = $this->send_batch( $job_id );

		$this->assertStringContainsString( 'Competition not found', $json['data']['html'] );
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

	/**
	 * Make a job look as if it was last saved some time ago.
	 *
	 * @param string $job_id  Job ID.
	 * @param int    $seconds How long ago.
	 * @return void
	 */
	private function age_job( string $job_id, int $seconds ): void {
		$job               = $this->jobs->get_job( $job_id );
		$job['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		update_option( 'photo_comp_email_job_' . $job_id, $job, false );
	}

	/**
	 * Render the abandoned job notices for the current request.
	 *
	 * @return string Notice HTML.
	 */
	private function abandoned_notices(): string {
		ob_start();
		$this->controller->render_abandoned_job_notices();
		return (string) ob_get_clean();
	}

	public function test_abandoned_job_notice_offers_carry_on_and_discard(): void {
		$job_id = $this->queue_job( 15 );
		$this->send_batch( $job_id );
		$this->age_job( $job_id, 400 );
		$this->reset_request();

		$html = $this->abandoned_notices();

		$this->assertStringContainsString( 'Sending results link emails for Spring Show stopped at 5 of 15.', $html );
		$this->assertStringContainsString( 'Carry on', $html );
		$this->assertStringContainsString( 'Discard', $html );
		$this->assertStringContainsString( 'action=photo_comp_discard_email_job', $html );
	}

	public function test_abandoned_job_notice_names_an_archived_competition(): void {
		$job_id = $this->queue_job( 15 );
		$this->age_job( $job_id, 400 );
		( new Competitions_Repository() )->archive( $this->competition_id );

		$this->assertStringContainsString( 'for Spring Show stopped at 0 of 15.', $this->abandoned_notices() );
	}

	public function test_no_notice_for_job_that_moved_recently(): void {
		$job_id = $this->queue_job( 15 );
		$this->age_job( $job_id, 240 );

		$this->assertSame( '', $this->abandoned_notices() );
	}

	public function test_no_notice_without_capability(): void {
		$job_id = $this->queue_job( 15 );
		$this->age_job( $job_id, 400 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->abandoned_notices() );
	}

	public function test_no_notice_for_job_already_on_the_page(): void {
		$job_id = $this->queue_job( 15 );
		$this->age_job( $job_id, 400 );
		$this->set_request( array( 'job_id' => $job_id ) );

		$this->assertSame( '', $this->abandoned_notices() );
	}

	/**
	 * @return array<string, array{string, array<string, string>}>
	 */
	public function carry_on_pages(): array {
		return array(
			'upload links'  => array( 'upload_link', array( 'page' => 'photo-competition-manager' ) ),
			'results'       => array( 'results', array( 'page' => 'photo-competition-manager-results' ) ),
			'results link'  => array( 'results_share', array( 'page' => 'photo-competition-manager-results' ) ),
			'voting opened' => array( 'voting_opened', array( 'page' => 'photo-competition-manager-voting' ) ),
		);
	}

	/**
	 * @dataProvider carry_on_pages
	 *
	 * @param string                $type  Job type.
	 * @param array<string, string> $query Expected query args.
	 */
	public function test_carry_on_links_to_the_page_that_sends_the_job( string $type, array $query ): void {
		$member_id = (int) ( new Members_Repository() )->create(
			array(
				'name'  => 'Member',
				'email' => 'm@example.com',
			)
		);
		$job_id    = $this->jobs->create_job( $type, $this->competition_id, array( $member_id ) );
		$this->age_job( $job_id, 400 );

		preg_match( '/href="([^"]+)"[^>]*>Carry on/', $this->abandoned_notices(), $matches );
		parse_str( (string) wp_parse_url( html_entity_decode( $matches[1] ), PHP_URL_QUERY ), $args );

		$this->assertSame( $query['page'], $args['page'] );
		$this->assertSame( $job_id, $args['job_id'] );
		if ( 'photo-competition-manager-results' === $query['page'] ) {
			$this->assertSame( (string) $this->competition_id, $args['competition'] );
		}
	}

	/**
	 * Call the Discard handler for a job, coming from a page.
	 *
	 * @param string $job_id  Job ID.
	 * @param string $referer Page the Discard link was on.
	 * @return string Redirect location.
	 */
	private function discard( string $job_id, string $referer ): string {
		$this->set_request( array( 'job_id' => $job_id ) );
		$this->set_nonce( Email_Job_Controller::DISCARD_ACTION . '_' . $job_id );
		$_SERVER['HTTP_REFERER'] = $referer;

		try {
			return $this->capture_redirect(
				function () {
					$this->controller->handle_discard();
				}
			);
		} finally {
			unset( $_SERVER['HTTP_REFERER'] );
		}
	}

	public function test_discard_fails_the_job_and_redirects_back(): void {
		$job_id  = $this->queue_job( 15 );
		$members = admin_url( 'admin.php?page=photo-competition-manager-members' );

		$location = $this->discard( $job_id, $members );

		$job = $this->jobs->get_job( $job_id );
		$this->assertSame( 'failed', $job['status'] );
		$this->assertStringStartsWith( 'Discarded by ', end( $job['error_log'] ) );
		$this->assertSame( $members, $location );
	}

	public function test_discard_while_sending_says_so_and_leaves_the_job_alone(): void {
		$job_id = $this->queue_job( 15 );
		add_option( 'photo_comp_email_lock_' . $job_id, time(), '', false );

		$location = $this->discard( $job_id, admin_url( 'admin.php?page=photo-competition-manager-members' ) );

		$this->assertSame( 'pending', $this->jobs->get_job( $job_id )['status'] );
		$this->assertStringContainsString( 'email_job_not_discarded=1', $location );

		$this->set_request( array( 'email_job_not_discarded' => '1' ) );
		$this->assertStringContainsString( 'still sending or has already finished', $this->abandoned_notices() );
	}

	public function test_discard_drops_an_earlier_not_discarded_message_from_the_page(): void {
		$job_id = $this->queue_job( 15 );

		$location = $this->discard( $job_id, admin_url( 'admin.php?page=photo-competition-manager-members&email_job_not_discarded=1' ) );

		$this->assertSame( admin_url( 'admin.php?page=photo-competition-manager-members' ), $location );
	}

	public function test_discard_rejects_bad_nonce(): void {
		$job_id = $this->queue_job( 15 );
		$this->set_request(
			array(
				'job_id'   => $job_id,
				'_wpnonce' => 'bad',
			)
		);

		try {
			$this->controller->handle_discard();
			$this->fail( 'Expected the request to be rejected.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'pending', $this->jobs->get_job( $job_id )['status'] );
		}
	}

	public function test_discard_requires_capability(): void {
		$job_id = $this->queue_job( 15 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->set_request( array( 'job_id' => $job_id ) );
		$this->set_nonce( Email_Job_Controller::DISCARD_ACTION . '_' . $job_id );

		try {
			$this->controller->handle_discard();
			$this->fail( 'Expected the request to be rejected.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'pending', $this->jobs->get_job( $job_id )['status'] );
		}
	}
}
