<?php
/**
 * Tests for Email_Job_Manager.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Email_Job_Manager;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Service\Results_Analytics;
use PhotoCompetitionManager\Service\Score_Calculator;
use WP_UnitTestCase;

class Email_Job_Manager_Test extends WP_UnitTestCase {

	/**
	 * @var Email_Job_Manager
	 */
	private $manager;

	/**
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * @var int
	 */
	private $competition_id;

	/**
	 * Addresses wp_mail() was asked to send to.
	 *
	 * @var array<int, string>
	 */
	private $recipients = array();

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$competitions  = new Competitions_Repository();
		$this->images  = new Images_Repository();
		$this->members = new Members_Repository();
		$votes         = new Votes_Repository();

		$this->manager = new Email_Job_Manager(
			$competitions,
			$this->images,
			$this->members,
			$votes,
			new Results_Analytics( $competitions, $this->images, $this->members, $votes ),
			new Score_Calculator( $this->images, $votes ),
			new Email_Service()
		);

		$this->competition_id = (int) $competitions->create(
			array(
				'title'      => 'Spring Show',
				'slug'       => 'spring-show-' . wp_generate_password( 6, false ),
				'open_date'  => null,
				'close_date' => null,
				'settings'   => array(),
			)
		);

		$this->recipients = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		remove_filter( 'pre_wp_mail', '__return_false' );
		parent::tear_down();
	}

	/**
	 * Record the recipient and report the mail as sent.
	 *
	 * @param null|bool            $short_circuit Filter value.
	 * @param array<string, mixed> $atts          wp_mail() arguments.
	 * @return bool
	 */
	public function capture_mail( $short_circuit, array $atts ): bool {
		$this->recipients[] = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'];
		return true;
	}

	/**
	 * Seed a member who submitted an image.
	 *
	 * @param string $email Member email.
	 * @return int Member ID.
	 */
	private function seed_entrant( string $email ): int {
		$member_id = (int) $this->members->create(
			array(
				'name'  => 'Entrant',
				'email' => $email,
				'grade' => 'beginner',
			)
		);

		$this->images->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'photo-' . $member_id . '.jpg',
			)
		);

		return $member_id;
	}

	public function test_queue_results_leaves_out_inactive_entrants(): void {
		$active   = $this->seed_entrant( 'active@example.com' );
		$inactive = $this->seed_entrant( 'gone@example.com' );
		$this->members->set_active( $inactive, false );

		$job = $this->manager->get_job( $this->manager->queue_results( $this->competition_id ) );

		$this->assertSame( array( $active ), array_map( 'intval', $job['member_ids'] ) );
		$this->assertSame( 1, $job['total_count'] );
	}

	public function test_queue_results_returns_false_when_every_entrant_is_inactive(): void {
		$inactive = $this->seed_entrant( 'gone@example.com' );
		$this->members->set_active( $inactive, false );

		$this->assertFalse( $this->manager->queue_results( $this->competition_id ) );
	}

	public function test_process_batch_skips_member_deactivated_after_job_created(): void {
		$this->seed_entrant( 'active@example.com' );
		$leaver = $this->seed_entrant( 'leaver@example.com' );
		$job_id = $this->manager->queue_results( $this->competition_id );

		$this->members->set_active( $leaver, false );
		$this->manager->process_batch( $job_id );

		$job = $this->manager->get_job( $job_id );
		$this->assertSame( array( 'active@example.com' ), $this->recipients );
		$this->assertSame( 1, $job['sent_count'] );
		$this->assertSame( 0, $job['failed_count'] );
		$this->assertSame( 1, $job['total_count'] );
		$this->assertSame( 'completed', $job['status'] );
	}

	/**
	 * Seed an active member without any submissions.
	 *
	 * @param string $email Member email.
	 * @return int Member ID.
	 */
	private function seed_member( string $email ): int {
		return (int) $this->members->create(
			array(
				'name'  => 'Member',
				'email' => $email,
			)
		);
	}

	/**
	 * Enable a notification template that is off by default.
	 *
	 * @param string $key Template key.
	 */
	private function enable_template( string $key ): void {
		update_option(
			'photo_comp_email_templates',
			array(
				$key => array(
					'enabled' => true,
					'subject' => 'Subject {competition_title}',
					'body'    => '<p>Body</p>',
				),
			)
		);
	}

	public function test_queue_schedules_first_batch(): void {
		$job_id = $this->manager->queue( 'competition_closed', $this->competition_id, array( $this->seed_member( 'a@example.com' ) ) );

		$this->assertNotFalse( wp_next_scheduled( Email_Job_Manager::BATCH_HOOK, array( $job_id ) ) );
		$this->assertSame( array(), $this->recipients, 'Nothing is sent until the batch runs.' );
	}

	public function test_queue_with_no_recipients_returns_false(): void {
		$this->assertFalse( $this->manager->queue( 'competition_closed', $this->competition_id, array() ) );
	}

	public function test_process_batch_sends_one_batch_then_reschedules(): void {
		$member_ids = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$member_ids[] = $this->seed_member( "m{$i}@example.com" );
		}
		$this->enable_template( 'competition_closed' );
		$job_id = $this->manager->create_job( 'competition_closed', $this->competition_id, $member_ids );

		$this->manager->process_batch( $job_id );

		$job = $this->manager->get_job( $job_id );
		$this->assertCount( 10, $this->recipients );
		$this->assertSame( 'processing', $job['status'] );
		$this->assertNotFalse( wp_next_scheduled( Email_Job_Manager::BATCH_HOOK, array( $job_id ) ) );

		$this->manager->process_batch( $job_id );

		$job = $this->manager->get_job( $job_id );
		$this->assertCount( 12, $this->recipients );
		$this->assertSame( 12, $job['sent_count'] );
		$this->assertSame( 'completed', $job['status'] );
	}

	public function test_upload_link_job_sends_then_skips_rate_limited_members(): void {
		$alice = $this->seed_member( 'alice@example.com' );
		$bob   = $this->seed_member( 'bob@example.com' );
		$args  = array( 'upload_page_url' => 'https://example.com/upload/' );

		$first = $this->manager->create_job( 'upload_link', $this->competition_id, array( $alice, $bob ), $args );
		$this->manager->process_batch( $first );

		$second = $this->manager->create_job( 'upload_link', $this->competition_id, array( $alice, $bob ), $args );
		$this->manager->process_batch( $second );

		$this->assertSame( array( 'alice@example.com', 'bob@example.com' ), $this->recipients );
		$this->assertSame( 2, $this->manager->get_job( $first )['sent_count'] );
		$this->assertSame( 0, $this->manager->get_job( $second )['sent_count'] );
		$this->assertSame( 2, $this->manager->get_job( $second )['skipped_count'] );
	}

	public function test_results_share_job_sends_share_link(): void {
		$member_id = $this->seed_member( 'a@example.com' );
		$job_id    = $this->manager->create_job( 'results_share', $this->competition_id, array( $member_id ), array( 'share_url' => 'https://example.com/results?share=abc' ) );

		$this->manager->process_batch( $job_id );

		$this->assertSame( array( 'a@example.com' ), $this->recipients );
		$this->assertSame( 1, $this->manager->get_job( $job_id )['sent_count'] );
	}

	public function test_voting_opened_job_sends_notification(): void {
		$this->enable_template( 'voting_opened' );
		$member_id = $this->seed_member( 'a@example.com' );
		$job_id    = $this->manager->create_job(
			'voting_opened',
			$this->competition_id,
			array( $member_id ),
			array(
				'voting_page_url' => 'https://example.com/vote/',
				'close_date'      => '',
			)
		);

		$this->manager->process_batch( $job_id );

		$this->assertSame( array( 'a@example.com' ), $this->recipients );
		$this->assertSame( 1, $this->manager->get_job( $job_id )['sent_count'] );
	}

	public function test_failed_send_is_counted_and_logged(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		add_filter( 'pre_wp_mail', '__return_false' );
		$member_id = $this->seed_member( 'a@example.com' );
		$job_id    = $this->manager->create_job( 'results_share', $this->competition_id, array( $member_id ), array( 'share_url' => 'https://example.com/r' ) );

		$this->manager->process_batch( $job_id );

		$job = $this->manager->get_job( $job_id );
		$this->assertSame( 0, $job['sent_count'] );
		$this->assertSame( 1, $job['failed_count'] );
		$this->assertStringContainsString( 'a@example.com', $job['error_log'][0] );
		$this->assertSame( 'completed', $job['status'] );
	}

	public function test_job_without_type_is_treated_as_results(): void {
		// Jobs queued before job types existed have no 'type' key.
		$this->seed_entrant( 'active@example.com' );
		$job_id = $this->manager->queue_results( $this->competition_id );
		$job    = $this->manager->get_job( $job_id );
		unset( $job['type'], $job['args'], $job['skipped_count'] );
		update_option( 'photo_comp_email_job_' . $job_id, $job, false );

		$this->manager->process_batch( $job_id );

		$this->assertSame( array( 'active@example.com' ), $this->recipients );
		$this->assertSame( 'completed', $this->manager->get_job( $job_id )['status'] );
	}

	public function test_cleanup_old_jobs_deletes_only_old_finished_jobs(): void {
		$member_id = $this->seed_member( 'a@example.com' );
		$old       = $this->manager->create_job( 'results_share', $this->competition_id, array( $member_id ) );
		$recent    = $this->manager->create_job( 'results_share', $this->competition_id, array( $member_id ) );
		$running   = $this->manager->create_job( 'results_share', $this->competition_id, array( $member_id ) );

		$job                 = $this->manager->get_job( $old );
		$job['status']       = 'completed';
		$job['completed_at'] = '2000-01-01 00:00:00';
		update_option( 'photo_comp_email_job_' . $old, $job, false );

		$job           = $this->manager->get_job( $recent );
		$job['status'] = 'completed';
		update_option( 'photo_comp_email_job_' . $recent, $job, false );

		$this->assertSame( 1, $this->manager->cleanup_old_jobs() );
		$this->assertNull( $this->manager->get_job( $old ) );
		$this->assertNotNull( $this->manager->get_job( $recent ) );
		$this->assertNotNull( $this->manager->get_job( $running ) );
	}
}
