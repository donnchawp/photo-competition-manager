<?php
/**
 * Tests for exporting a member's personal data.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Service\Event_Logger;
use PhotoCompetitionManager\Service\Named_Voter;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

/**
 * WordPress's personal data export covers everything the club holds about a member.
 *
 * @covers \PhotoCompetitionManager\Service\Member_Export
 */
class Member_Export_Test extends WP_UnitTestCase {

	/**
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Entry files a test wrote, to remove afterwards.
	 *
	 * @var string[]
	 */
	private $files = array();

	public function setUp(): void {
		parent::setUp();

		$this->members = new Members_Repository();
	}

	public function tearDown(): void {
		foreach ( $this->files as $file ) {
			wp_delete_file( $file );
			rmdir( dirname( $file ) );
			rmdir( dirname( $file, 2 ) );
		}

		parent::tearDown();
	}

	public function test_exporting_a_member_gives_their_record(): void {
		$this->create_member( 'Jane Doe', 'jane@example.com' );

		$items = $this->export( 'jane@example.com' );

		$this->assertSame(
			array(
				'Name'   => 'Jane Doe',
				'Email'  => 'jane@example.com',
				'Grade'  => 'Beginner',
				'Active' => 'Yes',
			),
			$this->only( $items, 'photo-competition-member' )[0]
		);
	}

	public function test_exporting_a_deactivated_members_real_address_finds_their_marked_record(): void {
		$this->create_member( 'Jane Doe', 'jane@example.com', 0 );

		$items = $this->export( 'jane@example.com' );

		$member = $this->only( $items, 'photo-competition-member' );
		$this->assertCount( 1, $member );
		$this->assertSame( 'jane@example.com', $member[0]['Email'] );
		$this->assertSame( 'No', $member[0]['Active'] );
	}

	public function test_exporting_a_member_gives_their_entries_with_the_original_while_its_kept(): void {
		$competition_id = $this->create_competition( 'export-entries', 'Spring Open' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$attachment_id  = self::factory()->attachment->create_object(
			array(
				'file'           => 'jane-original.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$entries        = new Images_Repository();
		$colour         = (int) $entries->create(
			array(
				'competition_id'         => $competition_id,
				'member_id'              => $jane_id,
				'category'               => 'colour',
				'filename'               => 'jane-doe-colour.jpg',
				'original_attachment_id' => $attachment_id,
			)
		);
		$mono           = (int) $entries->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $jane_id,
				'category'       => 'mono',
				'filename'       => 'jane-doe-mono.jpg',
			)
		);
		Entry_Fixtures::insert_entry( $competition_id, 'colour', $john_id, array() );
		$this->put_entry_file( 'export-entries', 'colour', 'jane-doe-colour.jpg' );

		$exported = $this->only( $this->export( 'jane@example.com' ), 'photo-competition-entries' );

		$this->assertCount( 2, $exported );
		$by_id = array_column( $exported, null, 'Entry ID' );
		$this->assertSame( 'Spring Open', $by_id[ (string) $colour ]['Competition'] );
		$this->assertSame( 'Colour', $by_id[ (string) $colour ]['Category'] );
		$this->assertSame( $entries->find( $colour )->created_at, $by_id[ (string) $colour ]['Uploaded'] );
		$this->assertStringContainsString( '/export-entries/colour/jane-doe-colour.jpg', $by_id[ (string) $colour ]['Image'] );
		$this->assertStringEndsWith( '/jane-original.jpg', $by_id[ (string) $colour ]['Original'] );
		$this->assertSame( 'Mono', $by_id[ (string) $mono ]['Category'] );
		// The mono file and its original are gone, so neither is offered.
		$this->assertArrayNotHasKey( 'Image', $by_id[ (string) $mono ] );
		$this->assertArrayNotHasKey( 'Original', $by_id[ (string) $mono ] );
	}

	public function test_exporting_a_large_original_gives_the_full_size_file_not_the_scaled_copy(): void {
		$competition_id = $this->create_competition( 'export-scaled', 'Spring Open' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		// WordPress keeps a 2560px -scaled copy of a larger upload as the attached file.
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'jane-original-scaled.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'           => 'jane-original-scaled.jpg',
				'original_image' => 'jane-original.jpg',
			)
		);
		( new Images_Repository() )->create(
			array(
				'competition_id'         => $competition_id,
				'member_id'              => $jane_id,
				'category'               => 'colour',
				'filename'               => 'jane-doe-colour.jpg',
				'original_attachment_id' => $attachment_id,
			)
		);

		$exported = $this->only( $this->export( 'jane@example.com' ), 'photo-competition-entries' );

		$this->assertStringEndsWith( '/jane-original.jpg', $exported[0]['Original'] );
	}

	public function test_exporting_a_member_gives_their_recorded_results(): void {
		$competition_id = $this->create_competition( 'export-results', 'Spring Open' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$janes_entry    = Entry_Fixtures::insert_entry( $competition_id, 'colour', $jane_id, array( 5, 5 ) );
		Entry_Fixtures::insert_entry( $competition_id, 'colour', $john_id, array( 3 ) );
		Workflow_Fixtures::publish_results( $competition_id );

		$this->assertSame(
			array(
				array(
					'Competition' => 'Spring Open',
					'Category'    => 'Colour',
					'Entry ID'    => (string) $janes_entry,
					'Total score' => '10',
					'Votes'       => '2',
					'Grade'       => 'Beginner',
					'Position'    => '1',
				),
			),
			$this->only( $this->export( 'jane@example.com' ), 'photo-competition-results' )
		);
	}

	public function test_exporting_a_member_gives_the_votes_they_cast_without_tokens_or_other_members(): void {
		$competition_id = $this->create_competition( 'export-votes', 'Spring Open' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$mary_id        = $this->create_member( 'Mary Byrne', 'mary@example.com' );
		$johns_colour   = Entry_Fixtures::insert_entry( $competition_id, 'colour', $john_id, array() );
		$johns_mono     = Entry_Fixtures::insert_entry( $competition_id, 'mono', $john_id, array() );
		$votes          = new Votes_Repository();
		$token_hash     = wp_hash( 'janes-voting-link' );
		$token_id       = (int) ( new Voting_Token_Repository() )->renew( $jane_id, $competition_id, 'colour', $token_hash, '2099-01-01 00:00:00' );
		$votes->create_anonymous_ballot( $competition_id, 'colour', $token_id, array( $johns_colour => 4 ) );
		// The name she typed differs in case, accents and spaces, as named voting allows.
		$votes->create_ballot( $competition_id, 'mono', ( new Named_Voter( ' JANE DOÉ ' ) )->name(), array( $johns_mono => 2 ) );
		$marys_token = (int) ( new Voting_Token_Repository() )->renew( $mary_id, $competition_id, 'colour', wp_hash( 'marys-voting-link' ), '2099-01-01 00:00:00' );
		$votes->create_anonymous_ballot( $competition_id, 'colour', $marys_token, array( $johns_colour => 1 ) );
		$votes->create_ballot( $competition_id, 'mono', 'Bob Smith', array( $johns_mono => 3 ) );
		$upload_token = ( new Upload_Token_Repository() )->find_or_create( $jane_id, $competition_id );

		$items = $this->export( 'jane@example.com' );

		$this->assertEqualSets(
			array(
				array(
					'Competition' => 'Spring Open',
					'Category'    => 'Colour',
					'Entry ID'    => (string) $johns_colour,
					'Score'       => '4',
				),
				array(
					'Competition' => 'Spring Open',
					'Category'    => 'Mono',
					'Entry ID'    => (string) $johns_mono,
					'Score'       => '2',
				),
			),
			$this->only( $items, 'photo-competition-votes' )
		);
		$everything = (string) wp_json_encode( $items );
		$this->assertStringNotContainsString( $token_hash, $everything );
		$this->assertStringNotContainsString( $upload_token->token, $everything );
		$this->assertStringNotContainsString( 'John Murphy', $everything );
		$this->assertStringNotContainsString( 'Mary Byrne', $everything );
	}

	public function test_exporting_a_member_gives_the_date_and_kind_of_each_log_row_about_them(): void {
		$competition_id = $this->create_competition( 'export-logs', 'Spring Open' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		$logger         = new Event_Logger();
		$logger->log_email_sent( $competition_id, 'voting_link', 'Jane Doe', array( 'email' => 'jane@example.com' ) );
		$logger->log_email_sent( $competition_id, 'upload_reminder', 'Jane Doe', array( 'email' => Members_Repository::mark_deactivated_email( 'jane@example.com' ) ) );
		$logger->log( $competition_id, 'category_change_failed', 'upload', 'A category change failed.', array( 'member_id' => $jane_id ) );
		$logger->log_email_sent( $competition_id, 'voting_link', 'John Murphy', array( 'email' => 'john@example.com' ) );
		$logger->log( $competition_id, 'category_change_failed', 'upload', 'A category change failed.', array( 'member_id' => $john_id ) );
		$logger->log( $competition_id, 'voting_opened', 'voting', 'Voting opened.' );
		$logged_at = ( new Logs_Repository() )->paginate( 1, 0, array( 'competition_id' => $competition_id ) )[0]->created_at;

		$exported = $this->only( $this->export( 'jane@example.com' ), 'photo-competition-emails' );

		$this->assertEqualSets(
			array(
				array(
					'Date' => $logged_at,
					'Kind' => 'Voting link',
				),
				array(
					'Date' => $logged_at,
					'Kind' => 'Upload link',
				),
				array(
					'Date' => $logged_at,
					'Kind' => 'category_change_failed',
				),
			),
			$exported
		);
	}

	public function test_exporting_a_member_with_everything_gives_all_five_groups(): void {
		$competition_id = $this->create_competition( 'export-all', 'Spring Open' );
		$jane_id        = $this->create_member( 'Jane Doe', 'jane@example.com' );
		$john_id        = $this->create_member( 'John Murphy', 'john@example.com' );
		Entry_Fixtures::insert_entry( $competition_id, 'colour', $jane_id, array( 5 ) );
		$johns_entry = Entry_Fixtures::insert_entry( $competition_id, 'colour', $john_id, array( 3 ) );
		( new Votes_Repository() )->create_ballot( $competition_id, 'colour', 'Jane Doe', array( $johns_entry => 4 ) );
		( new Event_Logger() )->log_email_sent( $competition_id, 'voting_link', 'Jane Doe', array( 'email' => 'jane@example.com' ) );
		Workflow_Fixtures::publish_results( $competition_id );

		$groups = array_unique( array_column( $this->export( 'jane@example.com' ), 'group_id' ) );

		$this->assertEqualSets(
			array( 'photo-competition-member', 'photo-competition-entries', 'photo-competition-results', 'photo-competition-votes', 'photo-competition-emails' ),
			$groups
		);
	}

	public function test_exporting_an_address_held_by_two_records_gives_both(): void {
		$this->create_member( 'Jane Doe', 'jane@example.com' );
		Member_Fixtures::insert_with_grade( 'Jane Byrne', 'jane@example.com', 'beginner', false );

		$names = array_column( $this->only( $this->export( 'jane@example.com' ), 'photo-competition-member' ), 'Name' );

		$this->assertEqualSets( array( 'Jane Doe', 'Jane Byrne' ), $names );
	}

	public function test_exporting_an_address_that_isnt_a_members_gives_nothing(): void {
		$this->create_member( 'Jane Doe', 'jane@example.com' );

		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$this->exporter()( 'nobody@example.com', 1 )
		);
	}

	/**
	 * The plugin's exporter callback, as WordPress registers it.
	 *
	 * @return callable
	 */
	private function exporter(): callable {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		$this->assertArrayHasKey( 'photo-competition-manager', $exporters );
		$this->assertNotEmpty( $exporters['photo-competition-manager']['exporter_friendly_name'] );

		return $exporters['photo-competition-manager']['callback'];
	}

	/**
	 * Run the plugin's exporter through every page, as Tools > Export Personal Data does.
	 *
	 * @param string $email Email address.
	 * @return array<int, array<string, mixed>> Every item exported.
	 */
	private function export( string $email ): array {
		$exporter = $this->exporter();
		$items    = array();

		for ( $page = 1; $page <= 50; $page++ ) {
			$response = $exporter( $email, $page );
			$items    = array_merge( $items, $response['data'] );
			if ( $response['done'] ) {
				return $items;
			}
		}

		$this->fail( 'The export never finished.' );
	}

	/**
	 * Each item in a group, as its fields' names and values.
	 *
	 * @param array<int, array<string, mixed>> $items    Exported items.
	 * @param string                           $group_id Group ID.
	 * @return array<int, array<string, string>>
	 */
	private function only( array $items, string $group_id ): array {
		$rows = array();
		foreach ( $items as $item ) {
			if ( $group_id !== $item['group_id'] ) {
				continue;
			}
			$this->assertNotEmpty( $item['group_label'] );
			$rows[] = array_column( $item['data'], 'value', 'name' );
		}

		return $rows;
	}

	/**
	 * Write an entry's image where the plugin keeps it.
	 *
	 * @param string $competition_slug Competition slug.
	 * @param string $category         Category slug.
	 * @param string $filename         File name.
	 * @return void
	 */
	private function put_entry_file( string $competition_slug, string $category, string $filename ): void {
		$directory = wp_upload_dir()['basedir'] . '/competitions/' . $competition_slug . '/' . $category;
		wp_mkdir_p( $directory );
		file_put_contents( $directory . '/' . $filename, 'jpeg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->files[] = $directory . '/' . $filename;
	}

	/**
	 * Create a competition with Colour and Mono categories.
	 *
	 * @param string $slug  Slug.
	 * @param string $title Title.
	 * @return int Competition ID.
	 */
	private function create_competition( string $slug, string $title ): int {
		return (int) ( new Competitions_Repository() )->create(
			array(
				'title'      => $title,
				'slug'       => $slug,
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => null,
				'settings'   => array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
							'quota' => 2,
						),
						array(
							'slug'  => 'mono',
							'label' => 'Mono',
							'quota' => 2,
						),
					),
				),
			)
		);
	}

	/**
	 * Create a member.
	 *
	 * @param string $name   Name.
	 * @param string $email  Email.
	 * @param int    $active Whether they're active.
	 * @return int Member ID.
	 */
	private function create_member( string $name, string $email, int $active = 1 ): int {
		return (int) $this->members->create(
			array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => $active,
			)
		);
	}
}
