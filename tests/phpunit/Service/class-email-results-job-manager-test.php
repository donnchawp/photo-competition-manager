<?php
/**
 * Tests for Email_Results_Job_Manager.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Email_Results_Job_Manager;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Service\Results_Analytics;
use PhotoCompetitionManager\Service\Score_Calculator;
use WP_UnitTestCase;

class Email_Results_Job_Manager_Test extends WP_UnitTestCase {

	/**
	 * @var Email_Results_Job_Manager
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

		$this->manager = new Email_Results_Job_Manager(
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

	public function test_create_job_leaves_out_inactive_entrants(): void {
		$active   = $this->seed_entrant( 'active@example.com' );
		$inactive = $this->seed_entrant( 'gone@example.com' );
		$this->members->set_active( $inactive, false );

		$job = $this->manager->get_job( $this->manager->create_job( $this->competition_id ) );

		$this->assertSame( array( $active ), array_map( 'intval', $job['member_ids'] ) );
		$this->assertSame( 1, $job['total_count'] );
	}

	public function test_create_job_returns_false_when_every_entrant_is_inactive(): void {
		$inactive = $this->seed_entrant( 'gone@example.com' );
		$this->members->set_active( $inactive, false );

		$this->assertFalse( $this->manager->create_job( $this->competition_id ) );
	}

	public function test_process_batch_skips_member_deactivated_after_job_created(): void {
		$this->seed_entrant( 'active@example.com' );
		$leaver = $this->seed_entrant( 'leaver@example.com' );
		$job_id = $this->manager->create_job( $this->competition_id );

		$this->members->set_active( $leaver, false );
		$this->manager->process_batch( $job_id );

		$job = $this->manager->get_job( $job_id );
		$this->assertSame( array( 'active@example.com' ), $this->recipients );
		$this->assertSame( 1, $job['sent_count'] );
		$this->assertSame( 0, $job['failed_count'] );
		$this->assertSame( 1, $job['total_count'] );
		$this->assertSame( 'completed', $job['status'] );
	}
}
