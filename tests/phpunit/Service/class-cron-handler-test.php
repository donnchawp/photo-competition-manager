<?php
/**
 * Tests for Cron_Handler.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Dependencies;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Cron_Handler;
use PhotoCompetitionManager\Service\Email_Job_Manager;
use WP_UnitTestCase;

class Cron_Handler_Test extends WP_UnitTestCase {

	/**
	 * @var Email_Job_Manager
	 */
	private $jobs;

	/**
	 * @var Cron_Handler
	 */
	private $handler;

	/**
	 * @var int
	 */
	private $mail_count = 0;

	public function set_up(): void {
		parent::set_up();

		$this->jobs    = ( new Dependencies() )->email_job_manager;
		$this->handler = new Cron_Handler( null, null, $this->jobs );

		( new Competitions_Repository() )->create(
			array(
				'title'      => 'Closed Comp',
				'slug'       => 'closed-comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-01-02 00:00:00',
			)
		);
		( new Members_Repository() )->create(
			array(
				'name'  => 'Member',
				'email' => 'member@example.com',
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
	 * Count and swallow outgoing mail.
	 *
	 * @return bool
	 */
	public function count_mail(): bool {
		++$this->mail_count;
		return true;
	}

	public function test_closed_notifications_are_queued_not_sent(): void {
		update_option(
			'photo_comp_email_templates',
			array(
				'competition_closed' => array(
					'enabled' => true,
					'subject' => 'Closed',
					'body'    => '<p>Closed.</p>',
				),
			)
		);

		$this->handler->send_closed_notifications();

		$this->assertSame( 0, $this->mail_count );
		$this->assertContains( Email_Job_Manager::BATCH_HOOK, $this->scheduled_hooks() );
	}

	public function test_closed_notifications_not_queued_when_template_disabled(): void {
		$this->handler->send_closed_notifications();

		$this->assertNotContains( Email_Job_Manager::BATCH_HOOK, $this->scheduled_hooks() );
	}

	/**
	 * Hook names of every scheduled WP-Cron event, whatever their args.
	 *
	 * @return string[]
	 */
	private function scheduled_hooks(): array {
		$hooks = array();
		foreach ( _get_cron_array() as $events ) {
			$hooks = array_merge( $hooks, array_keys( $events ) );
		}
		return $hooks;
	}
}
