<?php
/**
 * Golden-master snapshot tests for the results email's {results_table}.
 *
 * Pins the exact HTML the detailed results email fills {results_table} with,
 * captured before the markup moved into a template partial (#177).
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
use PhotoCompetitionManager\Service\Results_Ranking;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use WP_UnitTestCase;

/**
 * @covers \PhotoCompetitionManager\Service\Email_Job_Manager
 */
class Results_Table_Render_Test extends WP_UnitTestCase {

	const SLUG = 'autumn-open';

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
	 * @var Votes_Repository
	 */
	private $votes;

	/**
	 * Email service that records the merge tags each send was given.
	 *
	 * @var Email_Service
	 */
	private $email;

	/**
	 * @var int
	 */
	private $competition_id;

	/**
	 * Files written to the uploads directory, removed in tear_down().
	 *
	 * @var array<int, string>
	 */
	private $written_files = array();

	public function set_up(): void {
		parent::set_up();
		Activator::activate();

		$competitions  = new Competitions_Repository();
		$this->images  = new Images_Repository();
		$this->members = new Members_Repository();
		$this->votes   = new Votes_Repository();

		$this->email = new class() extends Email_Service {
			/**
			 * Merge tags given to send(), in order.
			 *
			 * @var array<int, array<string, string>>
			 */
			public $sent_tags = array();

			public function send( string $kind, object $member, ?object $competition, array $tags ) {
				$this->sent_tags[] = $tags;
				return 'sent';
			}
		};

		$this->manager = new Email_Job_Manager(
			$competitions,
			$this->images,
			$this->members,
			$this->votes,
			new Results_Analytics( $competitions, $this->images, $this->members, $this->votes ),
			new Results_Ranking( $this->images, $this->votes, $this->members ),
			$this->email
		);

		$this->competition_id = (int) $competitions->create(
			array(
				'title'      => 'Autumn Open',
				'slug'       => self::SLUG,
				'open_date'  => null,
				'close_date' => null,
				'settings'   => array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
						),
						array(
							'slug'  => 'mono',
							'label' => 'Black & White',
						),
					),
				),
			)
		);
	}

	public function tear_down(): void {
		foreach ( $this->written_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
		$this->written_files = array();
		parent::tear_down();
	}

	public function test_results_table_for_a_member_with_entries_in_several_categories(): void {
		$member = $this->seed_member( 'Ada', 'ada@example.com' );
		$rival  = $this->seed_member( 'Grace', 'grace@example.com' );

		$colour = $this->seed_image( $member, 'colour', 'ada-colour.jpg', 3 );
		$this->write_thumbnail_file( 'colour', 'ada-colour.jpg' );
		$mono       = $this->seed_image( $member, 'mono', 'ada-mono.jpg', 7 );
		$rival_shot = $this->seed_image( $rival, 'colour', 'grace-colour.jpg', 4 );

		$this->vote( 'colour', $colour, 'Judge A', 9, '2026-01-01 10:00:00' );
		$this->vote( 'colour', $colour, 'Judge B', 7, '2026-01-01 10:01:00' );
		$this->vote( 'colour', $colour, 'Judge C', 6, '2026-01-01 10:02:00' );
		$this->vote( 'colour', $rival_shot, 'Judge A', 8, '2026-01-01 10:03:00' );
		$this->vote( 'colour', $rival_shot, 'Judge B', 8, '2026-01-01 10:04:00' );
		$this->vote( 'colour', $rival_shot, 'Judge C', 8, '2026-01-01 10:05:00' );
		$this->vote( 'mono', $mono, 'Judge A', 5, '2026-01-01 10:06:00' );
		$this->vote( 'mono', $mono, 'Judge B', 4, '2026-01-01 10:07:00' );

		$this->assert_matches_snapshot( 'entries-in-several-categories', $this->results_table_for( $member ) );
	}

	public function test_results_table_for_an_ungraded_member_leaves_out_the_rank(): void {
		// A grade the club no longer lists puts the entry in the ungraded group.
		$member = Member_Fixtures::insert_with_grade( 'Ada', 'ada@example.com', 'retired' );

		$colour = $this->seed_image( $member, 'colour', 'ada-colour.jpg', 3 );
		$this->vote( 'colour', $colour, 'Judge A', 9, '2026-01-01 10:00:00' );
		$this->vote( 'colour', $colour, 'Judge B', 6, '2026-01-01 10:01:00' );

		$this->assert_matches_snapshot( 'ungraded-entry', $this->results_table_for( $member ) );
	}

	public function test_results_table_for_a_member_with_no_entries(): void {
		$member = $this->seed_member( 'Ada', 'ada@example.com' );

		$this->assert_matches_snapshot( 'no-entries', $this->results_table_for( $member ) );
	}

	/**
	 * Send a member the detailed results email and return its {results_table}.
	 *
	 * @param int $member_id Member ID.
	 * @return string HTML.
	 */
	private function results_table_for( int $member_id ): string {
		$this->manager->process_batch( $this->manager->queue( 'results_detailed', $this->competition_id, array( $member_id ) ) );

		$this->assertCount( 1, $this->email->sent_tags );

		return $this->email->sent_tags[0]['{results_table}'];
	}

	/**
	 * Compare HTML against its stored snapshot, writing the snapshot when it's missing.
	 *
	 * @param string $scenario Snapshot name.
	 * @param string $html     Rendered HTML.
	 */
	private function assert_matches_snapshot( string $scenario, string $html ): void {
		$dir  = __DIR__ . '/../fixtures/results-email-render';
		$file = $dir . '/' . $scenario . '.html';

		if ( ! file_exists( $file ) ) {
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			}
			if ( false === file_put_contents( $file, $html ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				self::fail( "Could not write snapshot file {$file}." );
			}
			$this->markTestSkipped( "Snapshot written for {$scenario}; re-run to assert." );
		}

		$this->assertSame( file_get_contents( $file ), $html, "Results table markup drifted for scenario {$scenario}." ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Seed a member and return their ID.
	 *
	 * @param string $name  Name.
	 * @param string $email Email.
	 * @return int
	 */
	private function seed_member( string $name, string $email ): int {
		return (int) $this->members->create(
			array(
				'name'  => $name,
				'email' => $email,
				'grade' => 'beginner',
			)
		);
	}

	/**
	 * Seed an entry and return its ID.
	 *
	 * @param int    $member_id     Member ID.
	 * @param string $category      Category slug.
	 * @param string $filename      Filename.
	 * @param int    $random_number Entry number.
	 * @return int
	 */
	private function seed_image( int $member_id, string $category, string $filename, int $random_number ): int {
		return (int) $this->images->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $member_id,
				'category'       => $category,
				'filename'       => $filename,
				'random_number'  => $random_number,
			)
		);
	}

	/**
	 * Record a vote at a fixed time, so the votes list in a fixed order.
	 *
	 * @param string $category   Category slug.
	 * @param int    $image_id   Image ID.
	 * @param string $voter      Voter name.
	 * @param int    $score      Score.
	 * @param string $created_at Vote time.
	 */
	private function vote( string $category, int $image_id, string $voter, int $score, string $created_at ): void {
		global $wpdb;

		$vote_id = $this->votes->create( $this->competition_id, $category, $voter, $image_id, $score );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->votes->table(), array( 'created_at' => $created_at ), array( 'id' => $vote_id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Write an entry's thumbnail to the uploads dir so the table shows it.
	 *
	 * @param string $category Category slug.
	 * @param string $filename Image filename.
	 */
	private function write_thumbnail_file( string $category, string $filename ): void {
		$uploads = wp_upload_dir();
		$folder  = trailingslashit( $uploads['basedir'] ) . 'competitions/' . self::SLUG . '/' . $category;
		wp_mkdir_p( $folder );

		$thumb_path = $folder . '/' . pathinfo( $filename, PATHINFO_FILENAME ) . '-thumb.' . pathinfo( $filename, PATHINFO_EXTENSION );
		file_put_contents( $thumb_path, 'thumb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		// A fixed modified time, so the URL's ?v= doesn't change the snapshot.
		touch( $thumb_path, 1767225600 );

		$this->written_files[] = $thumb_path;
	}
}
