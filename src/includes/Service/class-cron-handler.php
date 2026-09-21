<?php
/**
 * Cron Handler
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Class Cron_Handler
 *
 * @package PhotoCompetitionManager\Service
 */
class Cron_Handler {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions_repo;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members_repo;

	/**
	 * Email job queue.
	 *
	 * @var Email_Job_Manager
	 */
	private $email_jobs;

	/**
	 * Cron_Handler constructor.
	 *
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 * @param Email_Job_Manager|null       $email_jobs        Email job queue.
	 */
	public function __construct( ?Competitions_Repository $competitions_repo = null, ?Members_Repository $members_repo = null, ?Email_Job_Manager $email_jobs = null ) {
		$this->competitions_repo = $competitions_repo ?? new Competitions_Repository();
		$this->members_repo      = $members_repo ?? new Members_Repository();
		$this->email_jobs        = $email_jobs ?? ( new \PhotoCompetitionManager\Dependencies() )->email_job_manager;
	}

	/**
	 * Register the cron job.
	 */
	public function register(): void {
		if ( ! wp_next_scheduled( 'photo_competition_daily_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'photo_competition_daily_cron' );
		}

		add_action( 'photo_competition_daily_cron', array( $this, 'send_closed_notifications' ) );
	}

	/**
	 * Send notifications for newly closed competitions.
	 *
	 * Checks for competitions whose close_date has just passed and sends
	 * closing notifications to all active members.
	 */
	public function send_closed_notifications(): void {
		// Get all non-archived competitions.
		$competitions = $this->competitions_repo->all( 1000, false );
		$now          = utc_time();

		foreach ( $competitions as $competition ) {
			// Check if close date has passed.
			if ( empty( $competition->close_date ) ) {
				continue;
			}

			if ( $competition->close_date >= $now ) {
				continue; // Not yet closed.
			}

			// Check if we already sent notification (using a transient to track).
			$transient_key = 'photo_comp_closed_notif_' . $competition->id;
			if ( get_transient( $transient_key ) ) {
				continue; // Already sent.
			}

			// Send notifications and mark as sent.
			$this->send_competition_closed_notifications( $competition );
			set_transient( $transient_key, '1', MONTH_IN_SECONDS );
		}
	}

	/**
	 * Queue competition closed notifications to all active members.
	 *
	 * @param object $competition Competition object.
	 * @return void
	 */
	private function send_competition_closed_notifications( object $competition ): void {
		if ( ! ( new Email_Service() )->is_template_enabled( 'competition_closed' ) ) {
			return;
		}

		$member_ids = array();
		foreach ( $this->members_repo->all( 10000, true ) as $member ) {
			if ( ! empty( $member->email ) ) {
				$member_ids[] = (int) $member->id;
			}
		}

		$this->email_jobs->queue( 'competition_closed', (int) $competition->id, $member_ids );
	}
}
