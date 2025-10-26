<?php
/**
 * Cron Handler
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

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

		add_action( 'photo_competition_daily_cron', array( $this, 'update_competition_statuses' ) );
	}

	/**
	 * Update competition statuses.
	 */
	public function update_competition_statuses(): void {
		$competitions = $this->competitions_repo->all( 1000, true );

		foreach ( $competitions as $competition ) {
			$this->update_status( $competition );
		}
	}

	/**
	 * Update the status of a single competition.
	 *
	 * @param object $competition Competition object.
	 */
	private function update_status( object $competition ): void {
		$now        = time();
		$open_date  = strtotime( $competition->open_date );
		$close_date = strtotime( $competition->close_date );

		$new_status = $competition->status;

		if ( $competition->open_date && $now < $open_date ) {
			$new_status = 'scheduled';
		} elseif ( $competition->open_date && $now >= $open_date && ( ! $competition->close_date || $now < $close_date ) ) {
			$new_status = 'active';
		} elseif ( $competition->close_date && $now >= $close_date ) {
			$new_status = 'closed';
		}

		if ( $new_status !== $competition->status ) {
			$this->competitions_repo->update( $competition->id, array( 'status' => $new_status ) );

			// Send notifications when competition closes.
			if ( 'closed' === $new_status ) {
				$this->send_competition_closed_notifications( $competition );
			}
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
