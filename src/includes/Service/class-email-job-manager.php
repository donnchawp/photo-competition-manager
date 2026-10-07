<?php
/**
 * Email Job Manager.
 *
 * Sends bulk member emails in small batches, one batch per admin-ajax request
 * from the admin page showing the job's progress, so no single request risks
 * a timeout.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use WP_Error;
use function PhotoCompetitionManager\Support\render_template;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Class Email_Job_Manager
 *
 * A job is one bulk send of one kind of email to a list of members. A job's
 * type is the kind's key, from Email_Kinds.
 *
 * @package PhotoCompetitionManager\Service
 */
class Email_Job_Manager {

	/**
	 * Seconds after which a batch lock is treated as abandoned.
	 */
	const LOCK_TIMEOUT = 120;

	/**
	 * Seconds an unfinished job can go without progress before it counts as
	 * abandoned. Longer than LOCK_TIMEOUT, so a request stuck on one slow
	 * send isn't offered to someone else the moment its lock can be taken.
	 */
	const ABANDONED_AFTER = 300;

	/**
	 * Prefix of the option each job is stored in. The rest is the job ID.
	 */
	const OPTION_PREFIX = 'photo_comp_email_job_';

	/**
	 * Get batch size for email sending.
	 *
	 * @return int
	 */
	private function get_batch_size(): int {
		return defined( 'CLUB_COMPETE_EMAIL_BATCH_SIZE' ) ? CLUB_COMPETE_EMAIL_BATCH_SIZE : 5;
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
	 * Results ranking service.
	 *
	 * @var Results_Ranking
	 */
	private $ranking;

	/**
	 * Email service.
	 *
	 * @var Email_Service
	 */
	private $email_service;

	/**
	 * Entries module.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Upload link service.
	 *
	 * @var Upload_Link_Service
	 */
	private $upload_links;

	/**
	 * Category rankings for the batch being sent.
	 *
	 * Every member in a results batch gets the same results, so each category
	 * is ranked once per batch. Keyed by "competition_id:category_slug".
	 * Cleared at the start of each batch.
	 *
	 * @var array<string, array<int, array>>
	 */
	private $rankings = array();

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository  $competitions    Competitions repository.
	 * @param Images_Repository        $images          Images repository.
	 * @param Members_Repository       $members         Members repository.
	 * @param Votes_Repository         $votes           Votes repository.
	 * @param Results_Analytics        $analytics       Results analytics service.
	 * @param Results_Ranking          $ranking         Results ranking service.
	 * @param Email_Service            $email_service   Email service.
	 * @param Entries|null             $entries         Entries module (optional).
	 * @param Upload_Link_Service|null $upload_links    Upload link service (optional).
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Images_Repository $images,
		Members_Repository $members,
		Votes_Repository $votes,
		Results_Analytics $analytics,
		Results_Ranking $ranking,
		Email_Service $email_service,
		?Entries $entries = null,
		?Upload_Link_Service $upload_links = null
	) {
		$this->competitions  = $competitions;
		$this->images        = $images;
		$this->members       = $members;
		$this->votes         = $votes;
		$this->analytics     = $analytics;
		$this->ranking       = $ranking;
		$this->email_service = $email_service;
		$this->entries       = $entries ?? new Entries( $competitions, $images, $members );
		$this->upload_links  = $upload_links ?? new Upload_Link_Service( null, $competitions, $members, $email_service );
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

		$settings   = Competition_Settings::parse( $competition->settings );
		$categories = Competition_Settings::get_categories( $settings );

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

		return $this->queue( 'results_detailed', $competition_id, $member_ids );
	}

	/**
	 * Queue an email job.
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

		return $this->create_job( $type, $competition_id, $member_ids, $args );
	}

	/**
	 * Store a new email job.
	 *
	 * Only a kind of email sent in bulk, one with job wording in Email_Kinds,
	 * can be a job, so every stored job can be shown on the admin pages.
	 *
	 * @param string              $type           Job type: a kind of email sent in bulk.
	 * @param int                 $competition_id Competition ID.
	 * @param array<int, int>     $member_ids     Recipient member IDs.
	 * @param array<string,mixed> $args           Type-specific send arguments.
	 * @return string|false Job ID on success, false if there are no recipients or the kind isn't sent in bulk.
	 */
	public function create_job( string $type, int $competition_id, array $member_ids, array $args = array() ) {
		if ( empty( Email_Kinds::get( $type )['job'] ) ) {
			_doing_it_wrong( __METHOD__, esc_html( sprintf( '"%s" is not a kind of email sent in bulk.', $type ) ), '0.4.0' );
			return false;
		}

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
			'updated_at'     => utc_time(),
			'completed_at'   => null,
		);

		// Store job data.
		update_option( self::OPTION_PREFIX . $job_id, $job_data, false );

		return $job_id;
	}

	/**
	 * Send the next batch of a job.
	 *
	 * If another request is already sending a batch of this job, nothing is
	 * sent and the job is returned as it stands.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Job data after the batch, or null if there is no such job.
	 */
	public function process_batch( string $job_id ): ?array {
		if ( ! $this->acquire_lock( $job_id ) ) {
			return $this->get_job( $job_id );
		}

		try {
			// Read the job only once the lock is held, so its progress is current.
			$job = $this->get_job( $job_id );

			if ( $job && self::is_unfinished( $job ) ) {
				$this->send_batch( $job_id, $job );
			}
		} finally {
			$this->release_lock( $job_id );
		}

		return $this->get_job( $job_id );
	}

	/**
	 * Send the next batch of a job and save its progress.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $job    Job data.
	 * @return void
	 */
	private function send_batch( string $job_id, array $job ): void {
		// Mark as processing.
		if ( 'pending' === $job['status'] ) {
			$job['status'] = 'processing';
			$this->update_job( $job_id, $job );
		}

		$competition_id = $job['competition_id'];
		$competition    = $this->competitions->find( $competition_id );

		if ( ! $competition ) {
			$this->fail_job( $job_id, $job, 'Competition not found' );
			return;
		}

		// Get next batch of unprocessed members.
		$remaining = array_diff( $job['member_ids'], $job['processed_ids'] );
		$batch     = array_slice( $remaining, 0, $this->get_batch_size() );
		$members   = $this->members->find_many( $batch );

		$this->rankings = array();

		// Process each member in batch.
		foreach ( $batch as $member_id ) {
			// Avoid duplicate processing.
			if ( in_array( $member_id, $job['processed_ids'], true ) ) {
				continue;
			}

			$member = $members[ (int) $member_id ] ?? null;

			// Deleted or deactivated since the job was created.
			if ( ! $member || ! (int) $member->active ) {
				--$job['total_count'];
				$job['processed_ids'][] = $member_id;
				continue;
			}

			if ( empty( $member->email ) ) {
				++$job['failed_count'];
				$job['error_log'][]     = sprintf( 'Member %d has no email address', $member_id );
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

			// Save after every send, so if the request dies partway through a
			// batch, retrying doesn't email the members already sent to.
			$this->update_job( $job_id, $job );
		}

		// Update job progress.
		$this->update_job( $job_id, $job );

		if ( count( $job['processed_ids'] ) >= count( $job['member_ids'] ) ) {
			$this->mark_job_complete( $job_id );
		}
	}

	/**
	 * Take the batch lock for a job.
	 *
	 * INSERT IGNORE rather than add_option(), which overwrites an existing
	 * row and so can't tell two requests apart. Same approach as
	 * WP_Upgrader::create_lock().
	 *
	 * @param string $job_id Job ID.
	 * @return bool Whether the lock was taken.
	 */
	private function acquire_lock( string $job_id ): bool {
		global $wpdb;

		$lock = 'photo_comp_email_lock_' . $job_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$taken = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)',
				$wpdb->options,
				$lock,
				time(),
				'off'
			)
		);

		if ( $taken ) {
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$locked_at = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $lock ) );

		if ( $locked_at > time() - self::LOCK_TIMEOUT ) {
			return false;
		}

		// The request holding the lock died. Take it over.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->update(
			$wpdb->options,
			array( 'option_value' => time() ),
			array(
				'option_name'  => $lock,
				'option_value' => $locked_at,
			)
		);
	}

	/**
	 * Release the batch lock for a job.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	private function release_lock( string $job_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'photo_comp_email_lock_' . $job_id ) );
	}

	/**
	 * Send one member their email for a job.
	 *
	 * @param array  $job         Job data.
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @return string|WP_Error 'sent', 'skipped' (e.g. rate limited, or the notification is off), or an error.
	 */
	private function send_to_member( array $job, object $competition, object $member ) {
		$args = $job['args'] ?? array();

		switch ( $job['type'] ) {
			case 'results_detailed':
				return $this->email_service->send( 'results_detailed', $member, $competition, array( '{results_table}' => $this->results_table( $competition, $member ) ) );

			case 'upload_reminder':
				return $this->upload_links->send_reminder( (int) $competition->id, (int) $member->id );

			case 'voting_opened':
				return $this->email_service->send(
					'voting_opened',
					$member,
					$competition,
					array(
						'{voting_page}' => Competition_Settings::page_url( 'voting_page', $competition ),
						'{close_date}'  => (string) $args['close_date'],
					)
				);

			case 'results_published':
				// {results_share_link} is the link's old name, kept for saved templates.
				return $this->email_service->send(
					'results_published',
					$member,
					$competition,
					array(
						'{results_page}'       => (string) $args['share_url'],
						'{results_share_link}' => (string) $args['share_url'],
					)
				);

			default:
				return new WP_Error( 'unknown_email_job_type', sprintf( 'Unknown email job type "%s"', $job['type'] ) );
		}
	}

	/**
	 * A member's results table: the rank, scores and votes for each of their entries.
	 *
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @return string HTML.
	 */
	private function results_table( object $competition, object $member ): string {
		$competition_id = (int) $competition->id;
		$member_id      = (int) $member->id;

		$settings   = Competition_Settings::parse( $competition->settings );
		$categories = Competition_Settings::get_categories( $settings );

		$member_results = array(
			'images' => array(),
		);

		foreach ( $categories as $category ) {
			$category_slug  = $category['slug'] ?? '';
			$category_label = $category['label'] ?? $category_slug;

			if ( empty( $category_slug ) ) {
				continue;
			}

			foreach ( $this->get_ranking( $competition_id, $category_slug ) as $group ) {
				foreach ( $group['entries'] as $entry ) {
					$image = $entry['image'];

					// A recorded entry removed since has no image or votes to report.
					if ( ! $image || (int) $image->member_id !== $member_id ) {
						continue;
					}

					$votes = $this->votes->find_by_image( (int) $image->id );

					// An ungraded entry has no position to report.
					$member_results['images'][] = array(
						'category_label' => $category_label,
						'image_number'   => $image->random_number,
						'rank'           => $group['ungraded'] ? null : $entry['position'],
						'total_in_grade' => count( $group['entries'] ),
						'grade'          => $group['label'],
						'thumbnail_url'  => $this->entries->urls( $competition, $image )['thumb'],
						'total_score'    => $entry['total_score'],
						'vote_count'     => $entry['vote_count'],
						'statistics'     => $this->analytics->get_vote_statistics( $votes ),
						'votes'          => $votes,
					);
				}
			}
		}

		return render_template( 'email/results-table.php', $member_results );
	}

	/**
	 * Get a category's ranking, loading it once per batch.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category_slug  Category slug.
	 * @return array<int, array> Grade groups from Results_Ranking::rank_category().
	 */
	private function get_ranking( int $competition_id, string $category_slug ): array {
		$key = $competition_id . ':' . $category_slug;

		if ( ! isset( $this->rankings[ $key ] ) ) {
			$this->rankings[ $key ] = $this->ranking->rank_category( $competition_id, $category_slug );
		}

		return $this->rankings[ $key ];
	}

	/**
	 * Get job data.
	 *
	 * @param string $job_id Job ID.
	 * @return array|null Job data or null if not found.
	 */
	public function get_job( string $job_id ): ?array {
		$job_data = get_option( self::OPTION_PREFIX . $job_id, null );

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
		$job_data['updated_at'] = utc_time();

		return update_option( self::OPTION_PREFIX . $job_id, $job_data, false );
	}

	/**
	 * Stop a job for good and say why.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $job    Job data.
	 * @param string $reason Why, added to the job's error log.
	 * @return void
	 */
	private function fail_job( string $job_id, array $job, string $reason ): void {
		$job['status']       = 'failed';
		$job['completed_at'] = utc_time();
		$job['error_log'][]  = $reason;

		$this->update_job( $job_id, $job );
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
	 * Unfinished jobs that haven't moved for a while, most likely because the
	 * page sending them was closed.
	 *
	 * @return array<string, array> Job data keyed by job ID.
	 */
	public function get_abandoned_jobs(): array {
		$cutoff    = time() - self::ABANDONED_AFTER;
		$abandoned = array();

		foreach ( self::get_all_jobs() as $job_id => $job ) {
			if ( ! self::is_unfinished( $job ) ) {
				continue;
			}

			// Jobs saved before updated_at existed only have started_at.
			$moved_at = strtotime( $job['updated_at'] ?? $job['started_at'] ?? '' );

			if ( $moved_at && $moved_at < $cutoff ) {
				$abandoned[ $job_id ] = $job;
			}
		}

		return $abandoned;
	}

	/**
	 * Stop an unfinished job for good. It's marked failed, so cleanup removes
	 * it and the same send can be queued again.
	 *
	 * Refused while a batch is being sent, as that request saves its own copy
	 * of the job after every member and would undo the discard.
	 *
	 * @param string $job_id Job ID.
	 * @param string $reason Why, added to the job's error log.
	 * @return bool Whether the job was discarded.
	 */
	public function discard_job( string $job_id, string $reason ): bool {
		if ( ! $this->acquire_lock( $job_id ) ) {
			return false;
		}

		try {
			$job = $this->get_job( $job_id );

			if ( ! $job || ! self::is_unfinished( $job ) ) {
				return false;
			}

			$this->fail_job( $job_id, $job, $reason );

			return true;
		} finally {
			$this->release_lock( $job_id );
		}
	}

	/**
	 * Discard every unfinished job for a competition. A job that's sending a
	 * batch right now is left alone, and its next batch fails once it finds
	 * the competition gone.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $reason         Why, added to each job's error log.
	 * @return int Number of jobs discarded.
	 */
	public function discard_competition_jobs( int $competition_id, string $reason ): int {
		$discarded = 0;

		foreach ( self::get_all_jobs() as $job_id => $job ) {
			if ( (int) $job['competition_id'] === $competition_id && self::is_unfinished( $job ) && $this->discard_job( (string) $job_id, $reason ) ) {
				++$discarded;
			}
		}

		return $discarded;
	}

	/**
	 * Whether a job still has members to send to.
	 *
	 * @param array $job Job data.
	 * @return bool
	 */
	public static function is_unfinished( array $job ): bool {
		return in_array( $job['status'] ?? '', array( 'pending', 'processing' ), true );
	}

	/**
	 * Clean up old completed jobs.
	 *
	 * @return int Number of jobs cleaned up.
	 */
	public function cleanup_old_jobs(): int {
		$cutoff_time = time() - $this->get_job_retention();

		$cleaned = 0;

		foreach ( self::get_all_jobs() as $job_id => $job_data ) {
			// Only clean up completed or failed jobs.
			if ( ! in_array( $job_data['status'], array( 'completed', 'failed' ), true ) ) {
				continue;
			}

			// Check if job is old enough to clean up.
			$completed_at = isset( $job_data['completed_at'] ) ? strtotime( $job_data['completed_at'] ) : 0;
			$started_at   = isset( $job_data['started_at'] ) ? strtotime( $job_data['started_at'] ) : 0;
			$job_time     = $completed_at ? $completed_at : $started_at;

			if ( $job_time && $job_time < $cutoff_time ) {
				delete_option( self::OPTION_PREFIX . $job_id );
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
		foreach ( self::get_all_jobs() as $job_id => $job ) {
			if (
				self::is_unfinished( $job )
				&& $job['type'] === $type
				&& (int) $job['competition_id'] === $competition_id
				&& ( $job['args'] ?? array() ) === $args
			) {
				return (string) $job_id;
			}
		}

		return null;
	}

	/**
	 * Load every stored email job.
	 *
	 * @since 0.4.0 Public and static, for the data upgrade that renames job types.
	 *
	 * @return array<string, array> Job data keyed by job ID.
	 */
	public static function get_all_jobs(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$job_options = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);

		$jobs = array();
		foreach ( $job_options as $option ) {
			$job_data = maybe_unserialize( $option->option_value );
			if ( is_array( $job_data ) ) {
				$jobs[ substr( $option->option_name, strlen( self::OPTION_PREFIX ) ) ] = $job_data;
			}
		}

		return $jobs;
	}
}
