<?php
/**
 * Email Results Job Manager.
 *
 * Manages background processing of email results to avoid timeouts.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;

/**
 * Class Email_Results_Job_Manager
 *
 * @package PhotoCompetitionManager\Service
 */
class Email_Results_Job_Manager {

	/**
	 * Get batch size for email sending.
	 *
	 * @return int
	 */
	private function get_batch_size(): int {
		return defined( 'CLUB_COMPETE_EMAIL_BATCH_SIZE' ) ? CLUB_COMPETE_EMAIL_BATCH_SIZE : 5;
	}

	/**
	 * Get delay between batches in seconds.
	 *
	 * @return int
	 */
	private function get_batch_delay(): int {
		return defined( 'CLUB_COMPETE_EMAIL_BATCH_DELAY' ) ? CLUB_COMPETE_EMAIL_BATCH_DELAY : 1;
	}

	/**
	 * Get job retention period in seconds.
	 *
	 * @return int
	 */
	private function get_job_retention(): int {
		return defined( 'CLUB_COMPETE_EMAIL_JOB_RETENTION' ) ? CLUB_COMPETE_EMAIL_JOB_RETENTION : ( 30 * DAY_IN_SECONDS );
	}

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes;

	/**
	 * Results analytics service.
	 *
	 * @var Results_Analytics
	 */
	private $analytics;

	/**
	 * Score calculator service.
	 *
	 * @var Score_Calculator
	 */
	private $calculator;

	/**
	 * Email service.
	 *
	 * @var Email_Service
	 */
	private $email_service;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository $competitions   Competitions repository.
	 * @param Images_Repository       $images         Images repository.
	 * @param Members_Repository      $members        Members repository.
	 * @param Votes_Repository        $votes          Votes repository.
	 * @param Results_Analytics       $analytics      Results analytics service.
	 * @param Score_Calculator        $calculator     Score calculator service.
	 * @param Email_Service           $email_service  Email service.
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Images_Repository $images,
		Members_Repository $members,
		Votes_Repository $votes,
		Results_Analytics $analytics,
		Score_Calculator $calculator,
		Email_Service $email_service
	) {
		$this->competitions  = $competitions;
		$this->images        = $images;
		$this->members       = $members;
		$this->votes         = $votes;
		$this->analytics     = $analytics;
		$this->calculator    = $calculator;
		$this->email_service = $email_service;
	}

	/**
	 * Create a new email results job.
	 *
	 * @param int $competition_id Competition ID.
	 * @return string|false Job ID on success, false on failure.
	 */
	public function create_job( int $competition_id ) {
		$competition = $this->competitions->find( $competition_id );
		if ( ! $competition ) {
			return false;
		}

		$settings   = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings );
		$categories = \PhotoCompetitionManager\Support\Competition_Settings::get_categories( $settings );

		// Collect all members who submitted images.
		$member_ids = array();
		foreach ( $categories as $category ) {
			$category_slug = $category['slug'] ?? '';
			if ( empty( $category_slug ) ) {
				continue;
			}

			$images = $this->images->find_by_competition( $competition_id, $category_slug );
			foreach ( $images as $image ) {
				$member_ids[ $image->member_id ] = true;
			}
		}

		$member_ids = array_keys( $member_ids );

		if ( empty( $member_ids ) ) {
			return false;
		}

		// Generate unique job ID.
		$job_id = uniqid( 'email_job_', true );

		// Create job data.
		$job_data = array(
			'job_id'         => $job_id,
			'competition_id' => $competition_id,
			'member_ids'     => $member_ids,
			'processed_ids'  => array(),
			'status'         => 'pending',
			'total_count'    => count( $member_ids ),
			'sent_count'     => 0,
			'failed_count'   => 0,
			'error_log'      => array(),
			'started_at'     => current_time( 'mysql' ),
			'completed_at'   => null,
		);

		// Store job data.
		update_option( 'photo_comp_email_job_' . $job_id, $job_data, false );

		return $job_id;
	}

	/**
	 * Schedule the next batch to be processed.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $delay  Delay in seconds before processing.
	 * @return bool Whether the event was scheduled.
	 */
	public function schedule_next_batch( string $job_id, int $delay = 0 ): bool {
		$timestamp = time() + $delay;
		return wp_schedule_single_event( $timestamp, 'photo_comp_send_results_batch', array( $job_id ) ) !== false;
	}

	/**
	 * Process a batch of emails.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function process_batch( string $job_id ): void {
		$job = $this->get_job( $job_id );

		if ( ! $job || ( 'pending' !== $job['status'] && 'processing' !== $job['status'] ) ) {
			return;
		}

		// Mark as processing.
		if ( 'pending' === $job['status'] ) {
			$job['status'] = 'processing';
			$this->update_job( $job_id, $job );
		}

		$competition_id = $job['competition_id'];
		$competition    = $this->competitions->find( $competition_id );

		if ( ! $competition ) {
			$job['status']      = 'failed';
			$job['error_log'][] = 'Competition not found';
			$this->update_job( $job_id, $job );
			return;
		}

		// Get next batch of unprocessed members.
		$remaining = array_diff( $job['member_ids'], $job['processed_ids'] );
		$batch     = array_slice( $remaining, 0, $this->get_batch_size() );

		$settings   = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings );
		$categories = \PhotoCompetitionManager\Support\Competition_Settings::get_categories( $settings );

		// Process each member in batch.
		foreach ( $batch as $member_id ) {
			// Avoid duplicate processing.
			if ( in_array( $member_id, $job['processed_ids'], true ) ) {
				continue;
			}

			$member = $this->members->find( (int) $member_id );
			if ( ! $member || empty( $member->email ) ) {
				++$job['failed_count'];
				$job['error_log'][]     = sprintf( 'Member %d has no email address', $member_id );
				$job['processed_ids'][] = $member_id;
				continue;
			}

			// Build member results data.
			$member_results = array(
				'images' => array(),
			);

			foreach ( $categories as $category ) {
				$category_slug  = $category['slug'] ?? '';
				$category_label = $category['label'] ?? $category_slug;

				if ( empty( $category_slug ) ) {
					continue;
				}

				$results = $this->calculator->get_results( $competition_id, $category_slug );

				// Find this member's images in the results.
				$rank = 1;
				foreach ( $results as $result ) {
					if ( (int) $result->member_id === (int) $member_id ) {
						$image_details = $this->analytics->get_image_details( (int) $result->id );

						$member_results['images'][] = array(
							'category_label' => $category_label,
							'image_number'   => $result->random_number,
							'rank'           => $rank,
							'statistics'     => $image_details['statistics'],
							'votes'          => $image_details['votes'],
						);
					}
					++$rank;
				}
			}

			// Send email to member.
			$sent = $this->email_service->send_results_email(
				$member->email,
				$member->name,
				$competition->title,
				$member_results
			);

			if ( $sent ) {
				++$job['sent_count'];
			} else {
				++$job['failed_count'];
				$job['error_log'][] = sprintf( 'Failed to send email to %s (%s)', $member->name, $member->email );
			}

			$job['processed_ids'][] = $member_id;
		}

		// Update job progress.
		$this->update_job( $job_id, $job );

		// Schedule next batch or mark complete.
		if ( count( $job['processed_ids'] ) < count( $job['member_ids'] ) ) {
			$this->schedule_next_batch( $job_id, $this->get_batch_delay() );
		} else {
			$this->mark_job_complete( $job_id );
		}
	}

	/**
	 * Get job data.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Job data or null if not found.
	 */
	public function get_job( string $job_id ): ?array {
		$job_data = get_option( 'photo_comp_email_job_' . $job_id, null );

		return is_array( $job_data ) ? $job_data : null;
	}

	/**
	 * Get job status for display.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Job status data or null if not found.
	 */
	public function get_job_status( string $job_id ): ?array {
		return $this->get_job( $job_id );
	}

	/**
	 * Update job data.
	 *
	 * @param string $job_id   Job ID.
	 * @param array  $job_data Job data.
	 * @return bool Whether the update was successful.
	 */
	private function update_job( string $job_id, array $job_data ): bool {
		return update_option( 'photo_comp_email_job_' . $job_id, $job_data, false );
	}

	/**
	 * Mark job as complete.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	private function mark_job_complete( string $job_id ): void {
		$job = $this->get_job( $job_id );

		if ( ! $job ) {
			return;
		}

		$job['status']       = 'completed';
		$job['completed_at'] = current_time( 'mysql' );

		$this->update_job( $job_id, $job );
	}

	/**
	 * Clean up old completed jobs.
	 *
	 * @return int Number of jobs cleaned up.
	 */
	public function cleanup_old_jobs(): int {
		global $wpdb;

		$option_name_pattern = 'photo_comp_email_job_%';
		$cutoff_time         = time() - $this->get_job_retention();

		// Get all email job options.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$job_options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$option_name_pattern
			)
		);

		$cleaned = 0;

		foreach ( $job_options as $option ) {
			$job_data = maybe_unserialize( $option->option_value );

			if ( ! is_array( $job_data ) ) {
				continue;
			}

			// Only clean up completed or failed jobs.
			if ( ! in_array( $job_data['status'], array( 'completed', 'failed' ), true ) ) {
				continue;
			}

			// Check if job is old enough to clean up.
			$completed_at = isset( $job_data['completed_at'] ) ? strtotime( $job_data['completed_at'] ) : 0;
			$started_at   = isset( $job_data['started_at'] ) ? strtotime( $job_data['started_at'] ) : 0;
			$job_time     = $completed_at ? $completed_at : $started_at;

			if ( $job_time && $job_time < $cutoff_time ) {
				delete_option( $option->option_name );
				++$cleaned;
			}
		}

		return $cleaned;
	}
}
