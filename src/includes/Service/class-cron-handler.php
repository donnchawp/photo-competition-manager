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
	 * Cron_Handler constructor.
	 *
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 */
	public function __construct( ?Competitions_Repository $competitions_repo = null, ?Members_Repository $members_repo = null ) {
		$this->competitions_repo = $competitions_repo ?? new Competitions_Repository();
		$this->members_repo      = $members_repo ?? new Members_Repository();
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
		$now          = current_time( 'mysql' );

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
	 * Send competition closed notifications to all active members.
	 *
	 * @param object $competition Competition object.
	 * @return void
	 */
	private function send_competition_closed_notifications( object $competition ): void {
		// Get all active members.
		$members = $this->members_repo->all( true );

		if ( empty( $members ) ) {
			return;
		}

		$email_service = new Email_Service();

		foreach ( $members as $member ) {
			if ( ! empty( $member->email ) ) {
				$email_service->send_competition_closed_notification(
					$member->email,
					$member->name,
					$competition->title
				);
			}
		}
	}
}
