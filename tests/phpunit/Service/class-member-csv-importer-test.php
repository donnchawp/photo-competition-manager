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
		$csv = "name,email\nAlice,alice@example.com\n";

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
}
