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
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Email_Job_Manager;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Service\Results_Analytics;
use PhotoCompetitionManager\Service\Results_Ranking;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use WP_UnitTestCase;

class Email_Job_Manager_Test extends WP_UnitTestCase {

	const SHARE_ARGS = array( 'share_url' => 'https://example.com/results?share=abc' );

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

	/**
	 * Message bodies wp_mail() was asked to send, keyed by recipient.
	 *
	 * @var array<string, string>
	 */
	private $bodies = array();

	/**
	 * Ranking handed to the manager. Records the categories
	 * rank_category() was called for in $calls.
	 *
	 * @var Results_Ranking
	 */
	private $ranking;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$competitions  = new Competitions_Repository();
		$this->images  = new Images_Repository();
		$this->members = new Members_Repository();
		$votes         = new Votes_Repository();

		$this->ranking = new class( $this->images, $votes, $this->members ) extends Results_Ranking {
			/**
			 * @var array<int, string>
			 */
			public $calls = array();

			public function rank_category( int $competition_id, string $category ): array {
				$this->calls[] = $category;
				return parent::rank_category( $competition_id, $category );
			}
		};

		$this->manager = new Email_Job_Manager(
			$competitions,
			$this->images,
			$this->members,
			$votes,
			new Results_Analytics( $competitions, $this->images, $this->members, $votes ),
			$this->ranking,
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
		$this->bodies     = array();
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
		$to                  = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to'];
		$this->recipients[]  = $to;
		$this->bodies[ $to ] = (string) $atts['message'];
		return true;
	}

	/**
	 * Seed a member who submitted an image.
	 *
	 * @param string $email Member email.
	 * @param string $grade Member grade.
	 * @return int Member ID.
	 */
	private function seed_entrant( string $email, string $grade = 'beginner' ): int {
		$member_id = (int) $this->members->create(
			array(
				'name'  => 'Entrant',
				'email' => $email,
				'grade' => $grade,
			)
		);

		$this->add_entry( $member_id );

		return $member_id;
	}

	/**
	 * Seed an entrant whose grade isn't in the club's list.
	 *
	 * @param string $email Member email.
	 * @param string $grade Invalid grade, or '' for none.
	 * @return int Member ID.
	 */
	private function seed_entrant_with_bad_grade( string $email, string $grade ): int {
		$member_id = Member_Fixtures::insert_with_grade( 'Entrant', $email, $grade );

		$this->add_entry( $member_id );

		return $member_id;
	}

	/**
	 * Add a colour entry for a member.
	 *
	 * @param int $member_id Member ID.
	 */
	private function add_entry( int $member_id ): void {
		$this->images->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $member_id,
				'category'       => 'colour',
				'filename'       => 'photo-' . $member_id . '-' . wp_generate_password( 6, false ) . '.jpg',
			)
		);
	}

	public function test_queue_results_leaves_out_inactive_entrants(): void {
		$active   = $this->seed_entrant( 'active@example.com' );
		$inactive = $this->seed_entrant( 'gone@example.com' );
		$this->members->set_active( $inactive, false );

		$job = $this->manager->get_job( $this->manager->queue_results( $this->competition_id ) );

		$this->assertSame( array( $active ), array_map( 'intval', $job['member_ids'] ) );
		$this->assertSame( 1, $job['total_count'] );
	}

	public function test_results_batch_loads_each_category_results_once(): void {
		$this->seed_entrant( 'one@example.com' );
		$this->seed_entrant( 'two@example.com' );
		$this->seed_entrant( 'three@example.com' );
		$this->manager->process_batch( $this->manager->queue_results( $this->competition_id ) );

		$calls = $this->ranking->calls;
		$this->assertCount( 3, $this->recipients );
		$this->assertNotEmpty( $calls );
		$this->assertSame( array_values( array_unique( $calls ) ), $calls );
	}

	public function test_results_are_reloaded_for_each_batch(): void {
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->seed_entrant( "entrant-$i@example.com" );
		}
		$job_id = $this->manager->queue_results( $this->competition_id );

		$this->manager->process_batch( $job_id );
		$first_batch = $this->ranking->calls;
		$this->manager->process_batch( $job_id );

		$this->assertCount( 6, $this->recipients );
		$this->assertNotEmpty( $first_batch );
		$this->assertSame( array_merge( $first_batch, $first_batch ), $this->ranking->calls );
	}

	public function test_results_email_ranks_within_the_members_grade(): void {
		$this->vote_for( $this->seed_entrant( 'beginner-a@example.com', 'beginner' ), 5 );
		$this->vote_for( $this->seed_entrant( 'beginner-b@example.com', 'beginner' ), 9 );
		$this->vote_for( $this->seed_entrant( 'advanced@example.com', 'advanced' ), 1 );
		$this->vote_for( $this->seed_entrant_with_bad_grade( 'ungraded@example.com', '' ), 7 );

		$this->manager->process_batch( $this->manager->queue_results( $this->competition_id ) );

		// The ungraded entry isn't counted in any grade.
		$this->assertStringContainsString( '2 of 2 (Beginner)', $this->bodies['beginner-a@example.com'] );
		$this->assertStringContainsString( '1 of 2 (Beginner)', $this->bodies['beginner-b@example.com'] );
		$this->assertStringContainsString( '1 of 1 (Advanced)', $this->bodies['advanced@example.com'] );
	}

	public function test_results_email_to_an_ungraded_member_sends_without_a_position(): void {
		$this->vote_for( $this->seed_entrant( 'graded@example.com' ), 5 );
		$this->vote_for( $this->seed_entrant_with_bad_grade( 'ungraded@example.com', '' ), 9 );

		$this->manager->process_batch( $this->manager->queue_results( $this->competition_id ) );

		$this->assertArrayHasKey( 'ungraded@example.com', $this->bodies );
		$this->assertStringContainsString( 'Final Score:', $this->bodies['ungraded@example.com'] );
		$this->assertStringNotContainsString( 'Rank:', $this->bodies['ungraded@example.com'] );
		$this->assertStringContainsString( 'Rank:', $this->bodies['graded@example.com'] );
	}

	public function test_results_email_uses_club_grade_labels(): void {
		update_option(
			'photo_comp_default_settings',
			Competition_Settings::encode(
				array(
					'grades' => array(
						array(
							'slug'  => 'beginner',
							'label' => 'Club Starters',
						),
					),
				)
			)
		);
		$this->vote_for( $this->seed_entrant( 'starter@example.com' ), 9 );

		$this->manager->process_batch( $this->manager->queue_results( $this->competition_id ) );

		$this->assertStringContainsString( '1 of 1 (Club Starters)', $this->bodies['starter@example.com'] );
	}

	public function test_results_email_tied_entries_share_a_position(): void {
		$this->vote_for( $this->seed_entrant( 'tied-a@example.com' ), 9 );
		$this->vote_for( $this->seed_entrant( 'tied-b@example.com' ), 9 );
		$this->vote_for( $this->seed_entrant( 'behind@example.com' ), 7 );

		$this->manager->process_batch( $this->manager->queue_results( $this->competition_id ) );

		$this->assertStringContainsString( '1 of 3 (Beginner)', $this->bodies['tied-a@example.com'] );
		$this->assertStringContainsString( '1 of 3 (Beginner)', $this->bodies['tied-b@example.com'] );
		$this->assertStringContainsString( '2 of 3 (Beginner)', $this->bodies['behind@example.com'] );
	}

	public function test_results_email_gives_each_of_a_members_entries_its_own_position(): void {
		$member_id = $this->seed_entrant( 'two-entries@example.com' );
		$this->add_entry( $member_id );
		$this->vote_for( $member_id, 9 );
		$this->vote_for( $member_id, 5, 1 );
		$this->vote_for( $this->seed_entrant( 'rival@example.com' ), 9 );

		$this->manager->process_batch( $this->manager->queue_results( $this->competition_id ) );

		$this->assertStringContainsString( '1 of 3 (Beginner)', $this->bodies['two-entries@example.com'] );
		$this->assertStringContainsString( '2 of 3 (Beginner)', $this->bodies['two-entries@example.com'] );
	}

	public function test_results_email_shows_the_entrys_vote_statistics(): void {
		$member_id = $this->seed_entrant( 'scored@example.com' );
		foreach ( array( 9, 7, 6, 5 ) as $i => $score ) {
			$this->vote_for( $member_id, $score, 0, "Judge $i" );
		}

		$this->manager->process_batch( $this->manager->queue_results( $this->competition_id ) );

		$text = preg_replace( '/\s+/', ' ', wp_strip_all_tags( $this->bodies['scored@example.com'] ) );
		$this->assertStringContainsString( 'Final Score: 27 Total Votes: 4 Average Score: 6.75 Median Score: 6.50 Score Range: 5 - 9', $text );
		$this->assertMatchesRegularExpression( '/Vote # Score( \d \d){4} /', $text );
	}

	public function test_results_email_does_not_reload_the_entry_or_member(): void {
		global $wpdb;

		$this->vote_for( $this->seed_entrant( 'one@example.com' ), 9 );
		$this->vote_for( $this->seed_entrant( 'two@example.com' ), 7 );
		$job_id = $this->manager->queue_results( $this->competition_id );

		$queries = array();
		$record  = function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $record );
		$this->manager->process_batch( $job_id );
		remove_filter( 'query', $record );

		$this->assertCount( 2, $this->recipients );
		$this->assertNotEmpty( preg_grep( '/photocomp_votes/', $queries ), 'No queries were recorded' );
		foreach ( array( 'photocomp_images', 'photocomp_members' ) as $table ) {
			$by_id = preg_grep( '/FROM `' . $wpdb->prefix . $table . '` WHERE id = \d+/', $queries );
			$this->assertSame( array(), array_values( $by_id ), "$table rows were loaded one at a time" );
		}
	}

	/**
	 * Score a member's colour entry.
	 *
	 * @param int    $member_id Member ID.
	 * @param int    $score     Score to give the entry.
	 * @param int    $entry     Which of the member's entries to score.
	 * @param string $voter     Voter name.
	 */
	private function vote_for( int $member_id, int $score, int $entry = 0, string $voter = 'Judge' ): void {
		$image = $this->images->find_by_competition( $this->competition_id, 'colour', $member_id )[ $entry ];
		( new Votes_Repository() )->create( $this->competition_id, 'colour', $voter, (int) $image->id, $score );
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

	public function test_process_batch_skips_member_deleted_after_job_created(): void {
		$this->seed_entrant( 'stays@example.com' );
		$leaver = $this->seed_entrant( 'leaver@example.com' );
		$job_id = $this->manager->queue_results( $this->competition_id );

		( new Entries() )->remove_member_entries( Actor::admin(), $leaver );
		$this->members->delete( $leaver );
		$this->manager->process_batch( $job_id );

		$job = $this->manager->get_job( $job_id );
		$this->assertSame( array( 'stays@example.com' ), $this->recipients );
		$this->assertSame( 0, $job['failed_count'] );
		$this->assertSame( 1, $job['total_count'] );
		$this->assertSame( array(), $job['error_log'] );
		$this->assertSame( 'completed', $job['status'] );
	}

	public function test_inactive_member_without_email_is_skipped_not_failed(): void {
		$member_id = $this->seed_member( 'gone@example.com' );
		$job_id    = $this->manager->create_job( 'results_published', $this->competition_id, array( $member_id ), self::SHARE_ARGS );

		global $wpdb;
		$wpdb->update(
			$this->members->table(),
			array(
				'email'  => '',
				'active' => 0,
			),
			array( 'id' => $member_id )
		);
		$this->manager->process_batch( $job_id );

		$job = $this->manager->get_job( $job_id );
		$this->assertSame( 0, $job['failed_count'] );
		$this->assertSame( 0, $job['total_count'] );
	}

	public function test_job_for_a_missing_competition_records_when_it_stopped(): void {
		$job_id = $this->manager->create_job( 'results_published', 999999, array( $this->seed_member( 'a@example.com' ) ), self::SHARE_ARGS );

		$this->manager->process_batch( $job_id );

		$job = $this->manager->get_job( $job_id );
		$this->assertSame( 'failed', $job['status'] );
		$this->assertSame( array( 'Competition not found' ), $job['error_log'] );
		$this->assertNotNull( $job['completed_at'] );
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
				'grade' => 'beginner',
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

	public function test_queue_sends_nothing_until_a_batch_is_processed(): void {
		$job_id = $this->manager->queue( 'results_published', $this->competition_id, array( $this->seed_member( 'a@example.com' ) ), self::SHARE_ARGS );

		$this->assertSame( 'pending', $this->manager->get_job( $job_id )['status'] );
		$this->assertSame( array(), $this->recipients );
	}

	public function test_queue_returns_running_job_instead_of_duplicating_it(): void {
		// A second click while the first send is still running must not email everyone twice.
		$member_id = $this->seed_member( 'a@example.com' );

		$first  = $this->manager->queue( 'results_published', $this->competition_id, array( $member_id ), self::SHARE_ARGS );
		$second = $this->manager->queue( 'results_published', $this->competition_id, array( $member_id ), self::SHARE_ARGS );

		$this->assertSame( $first, $second );

		$this->manager->process_batch( $first );
		$this->assertSame( array( 'a@example.com' ), $this->recipients );
	}

	public function test_queue_starts_new_job_once_previous_one_finished(): void {
		$member_id = $this->seed_member( 'a@example.com' );

		$first = $this->manager->queue( 'results_published', $this->competition_id, array( $member_id ), self::SHARE_ARGS );
		$this->manager->process_batch( $first );
		$second = $this->manager->queue( 'results_published', $this->competition_id, array( $member_id ), self::SHARE_ARGS );

		$this->assertNotSame( $first, $second );
	}

	public function test_queue_with_different_args_is_not_a_duplicate(): void {
		$member_id = $this->seed_member( 'a@example.com' );

		$first  = $this->manager->queue( 'results_published', $this->competition_id, array( $member_id ), array( 'share_url' => 'https://example.com/r?share=a' ) );
		$second = $this->manager->queue( 'results_published', $this->competition_id, array( $member_id ), array( 'share_url' => 'https://example.com/r?share=b' ) );

		$this->assertNotSame( $first, $second );
	}

	public function test_a_kind_of_email_that_isnt_sent_in_bulk_cant_be_queued(): void {
		$this->setExpectedIncorrectUsage( 'PhotoCompetitionManager\\Service\\Email_Job_Manager::create_job' );

		$this->assertFalse( $this->manager->create_job( 'voting_link', $this->competition_id, array( $this->seed_member( 'a@example.com' ) ) ) );
	}

	public function test_queue_with_no_recipients_returns_false(): void {
		$this->assertFalse( $this->manager->queue( 'results_published', $this->competition_id, array(), self::SHARE_ARGS ) );
	}

	public function test_process_batch_sends_one_batch_per_call(): void {
		$member_ids = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$member_ids[] = $this->seed_member( "m{$i}@example.com" );
		}
		$job_id = $this->manager->create_job( 'results_published', $this->competition_id, $member_ids, self::SHARE_ARGS );

		$job = $this->manager->process_batch( $job_id );

		$this->assertCount( 5, $this->recipients );
		$this->assertSame( 'processing', $job['status'] );
		$this->assertCount( 5, $job['processed_ids'] );

		$this->manager->process_batch( $job_id );
		$job = $this->manager->process_batch( $job_id );

		$this->assertCount( 12, $this->recipients );
		$this->assertSame( 12, $job['sent_count'] );
		$this->assertSame( 'completed', $job['status'] );
	}

	public function test_upload_link_job_sends_then_skips_rate_limited_members(): void {
		$alice = $this->seed_member( 'alice@example.com' );
		$bob   = $this->seed_member( 'bob@example.com' );
		$args  = array();
		update_option( 'photo_comp_default_settings', wp_json_encode( array( 'urls' => array( 'upload_page' => 'https://example.com/upload/' ) ) ) );

		$first = $this->manager->create_job( 'upload_reminder', $this->competition_id, array( $alice, $bob ), $args );
		$this->manager->process_batch( $first );

		$second = $this->manager->create_job( 'upload_reminder', $this->competition_id, array( $alice, $bob ), $args );
		$this->manager->process_batch( $second );

		$this->assertSame( array( 'alice@example.com', 'bob@example.com' ), $this->recipients );
		$this->assertSame( 2, $this->manager->get_job( $first )['sent_count'] );
		$this->assertSame( 0, $this->manager->get_job( $second )['sent_count'] );
		$this->assertSame( 2, $this->manager->get_job( $second )['skipped_count'] );
	}

	public function test_results_published_job_sends_the_results_link(): void {
		$member_id = $this->seed_member( 'a@example.com' );
		$job_id    = $this->manager->create_job( 'results_published', $this->competition_id, array( $member_id ), array( 'share_url' => 'https://example.com/results?share=abc' ) );

		$this->manager->process_batch( $job_id );

		$this->assertSame( array( 'a@example.com' ), $this->recipients );
		$this->assertStringContainsString( 'href="https://example.com/results?share=abc"', $this->bodies['a@example.com'] );
		$this->assertSame( 1, $this->manager->get_job( $job_id )['sent_count'] );
	}

	public function test_results_published_job_fills_the_results_links_old_name_too(): void {
		update_option(
			'photo_comp_email_templates',
			array(
				'results_published' => array(
					'subject' => 'Results',
					'body'    => 'See {results_share_link}',
				),
			)
		);
		$job_id = $this->manager->create_job( 'results_published', $this->competition_id, array( $this->seed_member( 'a@example.com' ) ), self::SHARE_ARGS );

		$this->manager->process_batch( $job_id );

		$this->assertStringContainsString( 'See https://example.com/results?share=abc', $this->bodies['a@example.com'] );
	}

	public function test_voting_opened_job_sends_notification(): void {
		update_option( 'photo_comp_email_templates', array( 'voting_opened' => array( 'enabled' => true ) ) );
		$member_id = $this->seed_member( 'a@example.com' );
		$job_id    = $this->manager->create_job(
			'voting_opened',
			$this->competition_id,
			array( $member_id ),
			array(
				'voting_page_url' => 'https://example.com/vote/',
				'close_date'      => 'Friday 3 April',
			)
		);

		$this->manager->process_batch( $job_id );

		$this->assertSame( array( 'a@example.com' ), $this->recipients );
		$this->assertStringContainsString( 'href="https://example.com/vote/"', $this->bodies['a@example.com'] );
		$this->assertStringContainsString( 'Voting closes on Friday 3 April.', $this->bodies['a@example.com'] );
		$this->assertSame( 1, $this->manager->get_job( $job_id )['sent_count'] );
	}

	public function test_voting_opened_switched_off_partway_through_skips_the_remaining_members(): void {
		$this->enable_template( 'voting_opened' );
		$job_id = $this->manager->create_job(
			'voting_opened',
			$this->competition_id,
			array( $this->seed_member( 'a@example.com' ), $this->seed_member( 'b@example.com' ) ),
			array(
				'voting_page_url' => 'https://example.com/vote/',
				'close_date'      => '',
			)
		);

		delete_option( 'photo_comp_email_templates' );
		$this->manager->process_batch( $job_id );

		$job = $this->manager->get_job( $job_id );
		$this->assertSame( array(), $this->recipients );
		$this->assertSame( 'completed', $job['status'] );
		$this->assertSame( 2, $job['skipped_count'] );
		$this->assertSame( 0, $job['failed_count'] );
	}

	public function test_failed_send_is_counted_and_logged(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
		add_filter( 'pre_wp_mail', '__return_false' );
		$member_id = $this->seed_member( 'a@example.com' );
		$job_id    = $this->manager->create_job( 'results_published', $this->competition_id, array( $member_id ), array( 'share_url' => 'https://example.com/r' ) );

		$this->manager->process_batch( $job_id );

		$job = $this->manager->get_job( $job_id );
		$this->assertSame( 0, $job['sent_count'] );
		$this->assertSame( 1, $job['failed_count'] );
		$this->assertStringContainsString( 'a@example.com', $job['error_log'][0] );
		$this->assertSame( 'completed', $job['status'] );
	}

	public function test_a_results_job_stored_under_its_old_type_completes_once_upgraded(): void {
		$this->seed_entrant( 'active@example.com' );
		$job_id      = $this->manager->queue_results( $this->competition_id );
		$job         = $this->manager->get_job( $job_id );
		$job['type'] = 'results';
		update_option( 'photo_comp_email_job_' . $job_id, $job, false );
		update_option( 'photo_comp_db_version', 4 );

		Activator::maybe_upgrade();

		$this->manager->process_batch( $job_id );

		$this->assertSame( array( 'active@example.com' ), $this->recipients );
		$this->assertSame( 'completed', $this->manager->get_job( $job_id )['status'] );
	}

	public function test_process_batch_sends_nothing_while_another_request_holds_the_lock(): void {
		// A second tab on the same job must not send the batch the first tab is sending.
		$job_id = $this->manager->create_job( 'results_published', $this->competition_id, array( $this->seed_member( 'a@example.com' ) ), self::SHARE_ARGS );
		add_option( 'photo_comp_email_lock_' . $job_id, time(), '', false );

		$job = $this->manager->process_batch( $job_id );

		$this->assertSame( array(), $this->recipients );
		$this->assertSame( 'pending', $job['status'] );
	}

	public function test_process_batch_takes_over_an_abandoned_lock(): void {
		$job_id = $this->manager->create_job( 'results_published', $this->competition_id, array( $this->seed_member( 'a@example.com' ) ), self::SHARE_ARGS );
		add_option( 'photo_comp_email_lock_' . $job_id, time() - Email_Job_Manager::LOCK_TIMEOUT - 1, '', false );

		$job = $this->manager->process_batch( $job_id );

		$this->assertSame( array( 'a@example.com' ), $this->recipients );
		$this->assertSame( 'completed', $job['status'] );
		global $wpdb;
		$lock_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", 'photo_comp_email_lock_' . $job_id ) );
		$this->assertSame( 0, $lock_rows, 'The lock is released after the batch.' );
	}

	public function test_process_batch_on_finished_job_sends_nothing(): void {
		$job_id = $this->manager->create_job( 'results_published', $this->competition_id, array( $this->seed_member( 'a@example.com' ) ), self::SHARE_ARGS );
		$this->manager->process_batch( $job_id );

		$job = $this->manager->process_batch( $job_id );

		$this->assertSame( array( 'a@example.com' ), $this->recipients );
		$this->assertSame( 'completed', $job['status'] );
	}

	public function test_request_dying_mid_batch_keeps_progress_of_members_already_sent(): void {
		$first  = $this->seed_member( 'first@example.com' );
		$second = $this->seed_member( 'second@example.com' );
		$job_id = $this->manager->create_job( 'results_published', $this->competition_id, array( $first, $second ), self::SHARE_ARGS );
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, $atts ) {
				if ( 'second@example.com' === $atts['to'] ) {
					throw new \RuntimeException( 'Request died' );
				}
				return $short_circuit;
			},
			5,
			2
		);

		try {
			$this->manager->process_batch( $job_id );
			$this->fail( 'Expected the send to die.' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$job = $this->manager->get_job( $job_id );
		$this->assertSame( array( $first ), array_map( 'intval', $job['processed_ids'] ) );
		$this->assertSame( 1, $job['sent_count'] );
	}

	public function test_process_batch_returns_null_for_unknown_job(): void {
		$this->assertNull( $this->manager->process_batch( 'email_job_missing' ) );
	}

	public function test_cleanup_old_jobs_deletes_only_old_finished_jobs(): void {
		$member_id = $this->seed_member( 'a@example.com' );
		$old       = $this->manager->create_job( 'results_published', $this->competition_id, array( $member_id ) );
		$recent    = $this->manager->create_job( 'results_published', $this->competition_id, array( $member_id ) );
		$running   = $this->manager->create_job( 'results_published', $this->competition_id, array( $member_id ) );

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

	/**
	 * Make a job look as if it was last saved some time ago.
	 *
	 * @param string      $job_id  Job ID.
	 * @param int         $seconds How long ago.
	 * @param string|null $status  New status, or null to keep it.
	 * @return void
	 */
	private function age_job( string $job_id, int $seconds, ?string $status = null ): void {
		$job               = $this->manager->get_job( $job_id );
		$job['updated_at'] = gmdate( 'Y-m-d H:i:s', time() - $seconds );
		if ( $status ) {
			$job['status'] = $status;
		}
		update_option( 'photo_comp_email_job_' . $job_id, $job, false );
	}

	public function test_saving_a_job_records_when_it_moved(): void {
		$job_id = $this->manager->queue( 'results_published', $this->competition_id, array( $this->seed_member( 'a@example.com' ), $this->seed_member( 'b@example.com' ) ), self::SHARE_ARGS );
		$this->assertNotEmpty( $this->manager->get_job( $job_id )['updated_at'] );

		$this->age_job( $job_id, 600 );
		$this->manager->process_batch( $job_id );

		$updated_at = strtotime( $this->manager->get_job( $job_id )['updated_at'] );
		$this->assertGreaterThan( time() - 10, $updated_at );
	}

	public function test_get_abandoned_jobs_lists_unfinished_jobs_idle_for_five_minutes(): void {
		$member_id = $this->seed_member( 'a@example.com' );
		$stale     = $this->manager->create_job( 'results_published', $this->competition_id, array( $member_id ) );
		$moving    = $this->manager->create_job( 'upload_reminder', $this->competition_id, array( $member_id ) );
		$finished  = $this->manager->create_job( 'voting_opened', $this->competition_id, array( $member_id ) );

		$this->age_job( $stale, 301, 'processing' );
		$this->age_job( $moving, 240, 'processing' );
		$this->age_job( $finished, 600, 'completed' );

		$this->assertSame( array( $stale ), array_keys( $this->manager->get_abandoned_jobs() ) );
	}

	public function test_get_abandoned_jobs_falls_back_to_started_at(): void {
		// Jobs saved before updated_at existed only have started_at.
		$job_id = $this->manager->create_job( 'results_published', $this->competition_id, array( $this->seed_member( 'a@example.com' ) ) );
		$job    = $this->manager->get_job( $job_id );
		unset( $job['updated_at'] );
		$job['started_at'] = gmdate( 'Y-m-d H:i:s', time() - 600 );
		update_option( 'photo_comp_email_job_' . $job_id, $job, false );

		$this->assertSame( array( $job_id ), array_keys( $this->manager->get_abandoned_jobs() ) );
	}

	public function test_discard_job_fails_it_so_the_same_send_can_start_fresh(): void {
		$member_id = $this->seed_member( 'a@example.com' );
		$first     = $this->manager->queue( 'results_published', $this->competition_id, array( $member_id ), self::SHARE_ARGS );

		$this->assertTrue( $this->manager->discard_job( $first, 'Discarded by admin' ) );

		$job = $this->manager->get_job( $first );
		$this->assertSame( 'failed', $job['status'] );
		$this->assertContains( 'Discarded by admin', $job['error_log'] );
		$this->assertNotSame( $first, $this->manager->queue( 'results_published', $this->competition_id, array( $member_id ), self::SHARE_ARGS ) );
	}

	public function test_discard_job_refuses_while_a_batch_is_sending(): void {
		// The sending request saves its own copy of the job after every member, which would undo the discard.
		$job_id = $this->manager->create_job( 'results_published', $this->competition_id, array( $this->seed_member( 'a@example.com' ) ), self::SHARE_ARGS );
		add_option( 'photo_comp_email_lock_' . $job_id, time(), '', false );

		$this->assertFalse( $this->manager->discard_job( $job_id, 'Discarded by admin' ) );
		$this->assertSame( 'pending', $this->manager->get_job( $job_id )['status'] );
		$this->assertNotFalse( get_option( 'photo_comp_email_lock_' . $job_id ), 'The sending request still holds its lock.' );
	}

	public function test_discard_job_leaves_finished_and_unknown_jobs_alone(): void {
		$job_id = $this->manager->create_job( 'results_published', $this->competition_id, array( $this->seed_member( 'a@example.com' ) ), self::SHARE_ARGS );
		$this->manager->process_batch( $job_id );

		$this->assertFalse( $this->manager->discard_job( $job_id, 'Discarded by admin' ) );
		$this->assertSame( 'completed', $this->manager->get_job( $job_id )['status'] );
		$this->assertFalse( $this->manager->discard_job( 'email_job_missing', 'Discarded by admin' ) );
	}

	public function test_discard_competition_jobs_stops_only_that_competitions_unfinished_jobs(): void {
		$member_id = $this->seed_member( 'a@example.com' );
		$other     = (int) ( new Competitions_Repository() )->create(
			array(
				'title'      => 'Other',
				'slug'       => 'other-' . wp_generate_password( 6, false ),
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-02-01 00:00:00',
				'settings'   => array(),
			)
		);

		$pending   = $this->manager->create_job( 'results_published', $this->competition_id, array( $member_id ) );
		$running   = $this->manager->create_job( 'upload_reminder', $this->competition_id, array( $member_id ) );
		$elsewhere = $this->manager->create_job( 'results_published', $other, array( $member_id ) );
		$finished  = $this->manager->create_job( 'voting_opened', $this->competition_id, array( $member_id ) );
		$this->age_job( $running, 0, 'processing' );
		$this->age_job( $finished, 0, 'completed' );

		$this->assertSame( 2, $this->manager->discard_competition_jobs( $this->competition_id, 'Competition deleted' ) );

		$this->assertSame( 'failed', $this->manager->get_job( $pending )['status'] );
		$this->assertSame( 'failed', $this->manager->get_job( $running )['status'] );
		$this->assertContains( 'Competition deleted', $this->manager->get_job( $running )['error_log'] );
		$this->assertSame( 'pending', $this->manager->get_job( $elsewhere )['status'] );
		$this->assertSame( 'completed', $this->manager->get_job( $finished )['status'] );
	}
}
