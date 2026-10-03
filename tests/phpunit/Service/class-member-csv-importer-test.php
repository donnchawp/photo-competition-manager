<?php
/**
 * Tests for Member_CSV_Importer.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Member_CSV_Importer;
use WP_UnitTestCase;

class Member_CSV_Importer_Test extends WP_UnitTestCase {

	/**
	 * @var Member_CSV_Importer
	 */
	private $importer;

	/**
	 * @var Members_Repository
	 */
	private $members_repo;

	public function setUp(): void {
		parent::setUp();

		$this->members_repo = new Members_Repository();
		$this->importer     = new Member_CSV_Importer( $this->members_repo );
	}

	/**
	 * Create a temp CSV file and return a mock $_FILES array.
	 *
	 * @param string $content CSV content.
	 * @param string $name    Filename.
	 * @return array
	 */
	private function make_file( string $content, string $name = 'members.csv' ): array {
		$tmp = wp_tempnam( $name );
		file_put_contents( $tmp, $content );

		return array(
			'name'     => $name,
			'tmp_name' => $tmp,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $content ),
			'type'     => 'text/csv',
		);
	}

	// ---------------------------------------------------------------
	// import() — validation
	// ---------------------------------------------------------------

	public function test_import_rejects_missing_file(): void {
		$result = $this->importer->import( array( 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE ) );

		$this->assertWPError( $result );
		$this->assertSame( 'upload_failed', $result->get_error_code() );
	}

	public function test_import_rejects_non_csv_extension(): void {
		$file = $this->make_file( 'name,email', 'members.xlsx' );

		$result = $this->importer->import( $file );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_file_type', $result->get_error_code() );
	}

	public function test_import_rejects_empty_csv(): void {
		$file = $this->make_file( '' );

		$result = $this->importer->import( $file );

		$this->assertWPError( $result );
		$this->assertSame( 'empty_file', $result->get_error_code() );
	}

	// ---------------------------------------------------------------
	// import() — with header row
	// ---------------------------------------------------------------

	public function test_import_creates_new_members_with_header(): void {
		$csv  = "name,email,grade,active\n";
		$csv .= "Alice,alice@example.com,beginner,1\n";
		$csv .= "Bob,bob@example.com,advanced,1\n";

		$result = $this->importer->import( $this->make_file( $csv ) );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['imported'] );
		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 0, $result['skipped'] );

		$alice = $this->members_repo->find_by_email( 'alice@example.com' );
		$this->assertNotNull( $alice );
		$this->assertSame( 'Alice', $alice->name );
		$this->assertSame( 'beginner', $alice->grade );
	}

	public function test_import_updates_existing_member_by_email(): void {
		$this->members_repo->create(
			array(
				'name'   => 'Old Name',
				'email'  => 'alice@example.com',
				'grade'  => 'beginner',
				'active' => 1,
			)
		);

		$csv  = "name,email,grade,active\n";
		$csv .= "Alice Updated,alice@example.com,advanced,1\n";

		$result = $this->importer->import( $this->make_file( $csv ) );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['updated'] );

		$alice = $this->members_repo->find_by_email( 'alice@example.com' );
		$this->assertSame( 'Alice Updated', $alice->name );
		$this->assertSame( 'advanced', $alice->grade );
	}

	public function test_import_skips_rows_with_missing_name_or_email(): void {
		$csv  = "name,email,grade,active\n";
		$csv .= ",alice@example.com,beginner,1\n";
		$csv .= "Bob,,beginner,1\n";
		$csv .= "Charlie,charlie@example.com,beginner,1\n";

		$result = $this->importer->import( $this->make_file( $csv ) );

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 2, $result['skipped'] );
		$this->assertCount( 2, $result['errors'] );
	}

	public function test_import_skips_rows_with_invalid_email(): void {
		$csv  = "name,email,grade,active\n";
		$csv .= "Alice,not-an-email,beginner,1\n";

		$result = $this->importer->import( $this->make_file( $csv ) );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertCount( 1, $result['errors'] );
	}

	public function test_import_skips_empty_rows(): void {
		$csv  = "name,email,grade,active\n";
		$csv .= "Alice,alice@example.com,beginner,1\n";
		$csv .= ",,,\n";
		$csv .= "Bob,bob@example.com,beginner,1\n";

		$result = $this->importer->import( $this->make_file( $csv ) );

		$this->assertSame( 2, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
	}

	// ---------------------------------------------------------------
	// import() — without header row
	// ---------------------------------------------------------------

	public function test_import_handles_headerless_csv(): void {
		$csv  = "Alice,alice@example.com,beginner,1\n";
		$csv .= "Bob,bob@example.com,advanced,0\n";

		$result = $this->importer->import( $this->make_file( $csv ) );

		$this->assertSame( 2, $result['imported'] );

		$alice = $this->members_repo->find_by_email( 'alice@example.com' );
		$this->assertNotNull( $alice );
		$this->assertSame( 'Alice', $alice->name );
	}

	public function test_import_reactivates_deactivated_member_by_original_address(): void {
		$id = $this->members_repo->create(
			array(
				'name'   => 'Alice',
				'email'  => 'alice@example.com',
				'grade'  => 'beginner',
				'active' => 0,
			)
		);

		$csv  = "name,email,grade,active\n";
		$csv .= "Alice,alice@example.com,beginner,1\n";

		$result = $this->importer->import( $this->make_file( $csv ) );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['updated'] );

		$alice = $this->members_repo->find( $id );
		$this->assertSame( 'alice@example.com', $alice->email );
		$this->assertEquals( 1, $alice->active );
	}

	// ---------------------------------------------------------------
	// import() — active/committee normalization
	// ---------------------------------------------------------------

	public function test_import_normalizes_active_field(): void {
		$csv  = "name,email,grade,active\n";
		$csv .= "A,a@example.com,beginner,yes\n";
		$csv .= "B,b@example.com,beginner,true\n";
		$csv .= "C,c@example.com,beginner,0\n";
		$csv .= "D,d@example.com,beginner,no\n";

		$result = $this->importer->import( $this->make_file( $csv ) );

		$this->assertSame( 4, $result['imported'] );

		$a = $this->members_repo->find_by_email( 'a@example.com' );
		$c = $this->members_repo->find_by_email( 'c@example.com' );
		$this->assertEquals( 1, $a->active );
		$this->assertEquals( 0, $c->active );
	}

	public function test_import_normalizes_committee_field(): void {
		$csv  = "name,email,grade,active,committee\n";
		$csv .= "A,a@example.com,beginner,1,yes\n";
		$csv .= "B,b@example.com,beginner,1,0\n";

		$result = $this->importer->import( $this->make_file( $csv ) );

		$this->assertSame( 2, $result['imported'] );

		$a = $this->members_repo->find_by_email( 'a@example.com' );
		$b = $this->members_repo->find_by_email( 'b@example.com' );
		$this->assertEquals( 1, $a->committee );
		$this->assertEquals( 0, $b->committee );
	}

	// ---------------------------------------------------------------
	// import() — .txt extension
	// ---------------------------------------------------------------

	public function test_import_accepts_txt_extension(): void {
		$csv = "name,email,grade\nAlice,alice@example.com,beginner\n";

		$result = $this->importer->import( $this->make_file( $csv, 'members.txt' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['imported'] );
	}

	// ---------------------------------------------------------------
	// generate_sample_csv()
	// ---------------------------------------------------------------

	public function test_generate_sample_csv_contains_header_and_rows(): void {
		$csv   = $this->importer->generate_sample_csv();
		$lines = explode( "\n", trim( $csv ) );

		$this->assertSame( 'name,email,grade,active,committee', $lines[0] );
		$this->assertCount( 4, $lines ); // header + 3 sample rows
	}

	// ---------------------------------------------------------------
	// import() — grades
	// ---------------------------------------------------------------

	/**
	 * Create an existing member.
	 *
	 * @param string $grade Grade slug.
	 * @return int Member ID.
	 */
	private function seed_alice( string $grade = 'intermediate' ): int {
		return (int) $this->members_repo->create(
			array(
				'name'  => 'Alice',
				'email' => 'alice@example.com',
				'grade' => $grade,
			)
		);
	}

	public function test_import_matches_grade_label_or_slug_ignoring_case(): void {
		$csv  = "name,email,grade\n";
		$csv .= "A,a@example.com,Advanced\n";
		$csv .= "B,b@example.com,advanced\n";
		$csv .= "C,c@example.com,ADVANCED\n";

		$result = $this->importer->import( $this->make_file( $csv ) );

		$this->assertSame( 3, $result['imported'] );
		foreach ( array( 'a', 'b', 'c' ) as $who ) {
			$this->assertSame( 'advanced', $this->members_repo->find_by_email( "$who@example.com" )->grade );
		}
	}

	public function test_sample_csv_imports_cleanly(): void {
		$result = $this->importer->import( $this->make_file( $this->importer->generate_sample_csv() ) );

		$this->assertSame( 3, $result['imported'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 'intermediate', $this->members_repo->find_by_email( 'bob.johnson@example.com' )->grade );
	}

	public function test_import_skips_new_member_without_grade(): void {
		$csv  = "name,email,grade\n";
		$csv .= "Alice,alice@example.com,\n";
		$csv .= "Bob,bob@example.com,beginner\n";

		$result = $this->importer->import( $this->make_file( $csv ) );

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertNull( $this->members_repo->find_by_email( 'alice@example.com' ) );
		$this->assertCount( 1, $result['errors'] );
		$this->assertStringContainsString( 'Row 2', $result['errors'][0] );
	}

	public function test_import_skips_new_member_when_file_has_no_grade_column(): void {
		$result = $this->importer->import( $this->make_file( "name,email\nAlice,alice@example.com\n" ) );

		$this->assertSame( 0, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertNull( $this->members_repo->find_by_email( 'alice@example.com' ) );
	}

	public function test_import_skips_new_member_with_unknown_grade(): void {
		$csv  = "name,email,grade\n";
		$csv .= "Bob,bob@example.com,beginner\n";
		$csv .= "Alice,alice@example.com,Expert\n";

		$result = $this->importer->import( $this->make_file( $csv ) );

		$this->assertSame( 1, $result['imported'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertNull( $this->members_repo->find_by_email( 'alice@example.com' ) );
		$this->assertStringContainsString( 'Row 3', $result['errors'][0] );
		$this->assertStringContainsString( 'Expert', $result['errors'][0] );
	}

	public function test_import_keeps_existing_members_grade_when_cell_is_blank(): void {
		$id = $this->seed_alice();

		$result = $this->importer->import( $this->make_file( "name,email,grade\nAlice Renamed,alice@example.com,\n" ) );

		$this->assertSame( 1, $result['updated'] );
		$alice = $this->members_repo->find( $id );
		$this->assertSame( 'Alice Renamed', $alice->name );
		$this->assertSame( 'intermediate', $alice->grade );
	}

	public function test_import_keeps_existing_members_grade_when_file_has_no_grade_column(): void {
		$id = $this->seed_alice();

		$result = $this->importer->import( $this->make_file( "name,email\nAlice Renamed,alice@example.com\n" ) );

		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 'intermediate', $this->members_repo->find( $id )->grade );
	}

	public function test_import_skips_existing_member_with_unknown_grade(): void {
		$id = $this->seed_alice();

		$result = $this->importer->import( $this->make_file( "name,email,grade\nAlice Renamed,alice@example.com,Expert\n" ) );

		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertStringContainsString( 'Row 2', $result['errors'][0] );
		$alice = $this->members_repo->find( $id );
		$this->assertSame( 'Alice', $alice->name );
		$this->assertSame( 'intermediate', $alice->grade );
	}
}
