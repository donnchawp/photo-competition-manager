<?php
/**
 * Email Job Manager.
 *
 * Sends bulk member emails in small WP-Cron batches so no single request
 * risks a timeout.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Support\Image_Processor;
use WP_Error;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Class Email_Job_Manager
 *
 * A job is one bulk send of one email type to a list of members. Job types:
 * results, upload_link, voting_opened, results_share, competition_closed.
 *
 * @package PhotoCompetitionManager\Service
 */
class Email_Job_Manager {

	/**
	 * WP-Cron hook that processes one batch of a job.
	 */
	const BATCH_HOOK = 'photo_comp_send_email_batch';

	/**
	 * Get batch size for email sending.
	 *
	 * @return int
	 */
	private function get_batch_size(): int {
		return defined( 'CLUB_COMPETE_EMAIL_BATCH_SIZE' ) ? CLUB_COMPETE_EMAIL_BATCH_SIZE : 10;
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
	 * Image processor.
	 *
	 * @var Image_Processor
	 */
	private $image_processor;

	/**
	 * Upload link service.
	 *
	 * @var Upload_Link_Service
	 */
	private $upload_links;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository  $competitions    Competitions repository.
	 * @param Images_Repository        $images          Images repository.
	 * @param Members_Repository       $members         Members repository.
	 * @param Votes_Repository         $votes           Votes repository.
	 * @param Results_Analytics        $analytics       Results analytics service.
	 * @param Score_Calculator         $calculator      Score calculator service.
	 * @param Email_Service            $email_service   Email service.
	 * @param Image_Processor|null     $image_processor Image processor (optional).
	 * @param Upload_Link_Service|null $upload_links    Upload link service (optional).
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Images_Repository $images,
		Members_Repository $members,
		Votes_Repository $votes,
		Results_Analytics $analytics,
		Score_Calculator $calculator,
		Email_Service $email_service,
		?Image_Processor $image_processor = null,
		?Upload_Link_Service $upload_links = null
	) {
		$this->competitions    = $competitions;
		$this->images          = $images;
		$this->members         = $members;
		$this->votes           = $votes;
		$this->analytics       = $analytics;
		$this->calculator      = $calculator;
		$this->email_service   = $email_service;
		$this->image_processor = $image_processor ?? new Image_Processor();
		$this->upload_links    = $upload_links ?? new Upload_Link_Service( null, $competitions, $members, $email_service );
	}

	/**
	 * Queue results emails to every active member who entered a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @return string|false Job ID on success, false if there is nobody to email.
	 */
	public function queue_results( int $competition_id ) {
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

		// Deactivated members don't get results.
		$members    = $this->members->find_many( array_keys( $member_ids ) );
		$member_ids = array_values(
			array_filter(
				array_keys( $member_ids ),
				function ( $member_id ) use ( $members ) {
					return ! isset( $members[ (int) $member_id ] ) || (int) $members[ (int) $member_id ]->active;
				}
			)
		);

		if ( empty( $member_ids ) ) {
			return false;
		}

		return $this->queue( 'results', $competition_id, $member_ids );
	}

	/**
	 * Queue an email job and schedule its first batch.
	 *
	 * If the same send is already queued or running, that job is returned
	 * instead, so a second click doesn't email every member twice.
	 *
	 * @param string              $type           Job type.
	 * @param int                 $competition_id Competition ID.
	 * @param array<int, int>     $member_ids     Recipient member IDs.
	 * @param array<string,mixed> $args           Type-specific send arguments.
	 * @return string|false Job ID on success, false if there are no recipients.
	 */
	public function queue( string $type, int $competition_id, array $member_ids, array $args = array() ) {
		$running = $this->find_unfinished_job( $type, $competition_id, $args );
		if ( $running ) {
			return $running;
		}

		$job_id = $this->create_job( $type, $competition_id, $member_ids, $args );

		if ( $job_id ) {
			$this->schedule_next_batch( $job_id, 0 );
		}

		return $job_id;
	}

	/**
	 * Store a new email job without scheduling it.
	 *
	 * @param string              $type           Job type.
	 * @param int                 $competition_id Competition ID.
	 * @param array<int, int>     $member_ids     Recipient member IDs.
	 * @param array<string,mixed> $args           Type-specific send arguments.
	 * @return string|false Job ID on success, false if there are no recipients.
	 */
	public function create_job( string $type, int $competition_id, array $member_ids, array $args = array() ) {
		$member_ids = array_values( array_unique( array_map( 'intval', $member_ids ) ) );

		if ( empty( $member_ids ) ) {
			return false;
		}

		// Generate unique job ID.
		$job_id = uniqid( 'email_job_', true );

		// Create job data.
		$job_data = array(
			'job_id'         => $job_id,
			'type'           => $type,
			'args'           => $args,
			'competition_id' => $competition_id,
			'member_ids'     => $member_ids,
			'processed_ids'  => array(),
			'status'         => 'pending',
			'total_count'    => count( $member_ids ),
			'sent_count'     => 0,
			'skipped_count'  => 0,
			'failed_count'   => 0,
			'error_log'      => array(),
			'started_at'     => utc_time(),
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
		return wp_schedule_single_event( $timestamp, self::BATCH_HOOK, array( $job_id ) ) !== false;
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

			// Deactivated since the job was created.
			if ( ! (int) $member->active ) {
				--$job['total_count'];
				$job['processed_ids'][] = $member_id;
				continue;
			}

			$outcome = $this->send_to_member( $job, $competition, $member );

			if ( 'sent' === $outcome ) {
				++$job['sent_count'];
			} elseif ( 'skipped' === $outcome ) {
				$job['skipped_count'] = ( $job['skipped_count'] ?? 0 ) + 1;
			} else {
				++$job['failed_count'];
				$job['error_log'][] = sprintf( 'Failed to send email to %s (%s): %s', $member->name, $member->email, $outcome->get_error_message() );
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
	 * Send one member their email for a job.
	 *
	 * @param array  $job         Job data.
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @return string|WP_Error 'sent', 'skipped' (e.g. rate limited), or an error.
	 */
	private function send_to_member( array $job, object $competition, object $member ) {
		$args = $job['args'] ?? array();

		switch ( $job['type'] ?? 'results' ) {
			case 'results':
				$sent = $this->send_results( $competition, $member );
				break;

			case 'upload_link':
				return $this->upload_links->send_reminder( (int) $competition->id, (int) $member->id, (string) $args['upload_page_url'] );

			case 'voting_opened':
				$sent = $this->email_service->send_voting_opened_notification(
					$member->email,
					$member->name,
					$competition->title,
					(string) $args['voting_page_url'],
					(string) $args['close_date']
				);
				break;

			case 'results_share':
				$sent = $this->email_service->send_results_share_link(
					$member->email,
					$member->name,
					$competition->title,
					(string) $args['share_url'],
					(int) $competition->id
				);
				break;

			case 'competition_closed':
				$sent = $this->email_service->send_competition_closed_notification(
					$member->email,
					$member->name,
					$competition->title
				);
				break;

			default:
				return new WP_Error( 'unknown_email_job_type', sprintf( 'Unknown email job type "%s"', $job['type'] ) );
		}

		return $sent ? 'sent' : new WP_Error( 'send_failed', 'wp_mail() failed' );
	}

	/**
	 * Build and send one member's detailed results email.
	 *
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @return bool Whether the email was sent.
	 */
	private function send_results( object $competition, object $member ): bool {
		$competition_id = (int) $competition->id;
		$member_id      = (int) $member->id;

		$settings   = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings );
		$categories = \PhotoCompetitionManager\Support\Competition_Settings::get_categories( $settings );

		$member_results = array(
			'images' => array(),
		);

		// Get the member's grade.
		$member_grade = ! empty( $member->grade ) ? $member->grade : '';

		foreach ( $categories as $category ) {
			$category_slug  = $category['slug'] ?? '';
			$category_label = $category['label'] ?? $category_slug;

			if ( empty( $category_slug ) ) {
				continue;
			}

			$results = $this->calculator->get_results( $competition_id, $category_slug );

			// Build a members lookup for grade filtering.
			$members_lookup = array();
			foreach ( $results as $result ) {
				$result_member_id = (int) $result->member_id;
				if ( ! isset( $members_lookup[ $result_member_id ] ) ) {
					$members_lookup[ $result_member_id ] = $this->members->find( $result_member_id );
				}
			}

			// Filter results to only include images from the member's grade.
			$grade_results = array();
			if ( ! empty( $member_grade ) ) {
				foreach ( $results as $result ) {
					$result_member_id = (int) $result->member_id;
					$result_member    = $members_lookup[ $result_member_id ] ?? null;
					if ( $result_member && $result_member->grade === $member_grade ) {
						$grade_results[] = $result;
					}
				}
			} else {
				// If member has no grade, fall back to all results.
				$grade_results = $results;
			}

			$total_in_grade = count( $grade_results );

			// Find this member's images in the grade results.
			$rank = 1;
			foreach ( $grade_results as $result ) {
				if ( (int) $result->member_id === (int) $member_id ) {
					$image_details = $this->analytics->get_image_details( (int) $result->id );

					// Get thumbnail URL.
					$thumbnail_url = $this->image_processor->get_thumbnail_url(
						$competition->slug,
						$category_slug,
						$result->filename
					);

					$member_results['images'][] = array(
						'category_label' => $category_label,
						'image_number'   => $result->random_number,
						'rank'           => $rank,
						'total_in_grade' => $total_in_grade,
						'grade'          => $member_grade,
						'thumbnail_url'  => is_wp_error( $thumbnail_url ) ? '' : $thumbnail_url,
						'statistics'     => $image_details['statistics'],
						'votes'          => $image_details['votes'],
					);
				}
				++$rank;
			}
		}

		return $this->email_service->send_results_email(
			$member->email,
			$member->name,
			$competition->title,
			$member_results
		);
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
		$job['completed_at'] = utc_time();

		$this->update_job( $job_id, $job );
	}

	/**
	 * Clean up old completed jobs.
	 *
	 * @return int Number of jobs cleaned up.
	 */
	public function cleanup_old_jobs(): int {
		$cutoff_time = time() - $this->get_job_retention();

		$cleaned = 0;

		foreach ( $this->get_all_jobs() as $option_name => $job_data ) {
			// Only clean up completed or failed jobs.
			if ( ! in_array( $job_data['status'], array( 'completed', 'failed' ), true ) ) {
				continue;
			}

			// Check if job is old enough to clean up.
			$completed_at = isset( $job_data['completed_at'] ) ? strtotime( $job_data['completed_at'] ) : 0;
			$started_at   = isset( $job_data['started_at'] ) ? strtotime( $job_data['started_at'] ) : 0;
			$job_time     = $completed_at ? $completed_at : $started_at;

			if ( $job_time && $job_time < $cutoff_time ) {
				delete_option( $option_name );
				++$cleaned;
			}
		}

		return $cleaned;
	}

	/**
	 * Find a job for the same send that hasn't finished yet.
	 *
	 * @param string              $type           Job type.
	 * @param int                 $competition_id Competition ID.
	 * @param array<string,mixed> $args           Type-specific send arguments.
	 * @return string|null Job ID, or null if there is no unfinished match.
	 */
	private function find_unfinished_job( string $type, int $competition_id, array $args ): ?string {
		foreach ( $this->get_all_jobs() as $option_name => $job ) {
			if (
				in_array( $job['status'] ?? '', array( 'pending', 'processing' ), true )
				&& ( $job['type'] ?? 'results' ) === $type
				&& (int) $job['competition_id'] === $competition_id
				&& ( $job['args'] ?? array() ) === $args
			) {
				return substr( $option_name, strlen( 'photo_comp_email_job_' ) );
			}
		}

		return null;
	}

	/**
	 * Load every stored email job.
	 *
	 * @return array<string, array> Job data keyed by option name.
	 */
	private function get_all_jobs(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$job_options = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( 'photo_comp_email_job_' ) . '%'
			)
		);

		$jobs = array();
		foreach ( $job_options as $option ) {
			$job_data = maybe_unserialize( $option->option_value );
			if ( is_array( $job_data ) ) {
				$jobs[ $option->option_name ] = $job_data;
			}
		}

		return $jobs;
	}
}
