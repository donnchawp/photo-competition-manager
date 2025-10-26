<?php
/**
 * Member CSV import service.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

use PhotoCompetitionManager\Repository\Members_Repository;
use WP_Error;

/**
 * Handle CSV import of members.
 *
 * @since 0.1.0
 */
class Member_CSV_Importer {

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Constructor.
	 *
	 * @param Members_Repository $members Members repository.
	 */
	public function __construct( Members_Repository $members ) {
		$this->members = $members;
	}

	/**
	 * Import members from uploaded CSV file.
	 *
	 * Expected CSV format:
	 * - Header row: name,email,grade,active
	 * - Data rows: "John Doe",john@example.com,Beginner,1
	 *
	 * @param array<string, mixed> $file Uploaded file array from $_FILES.
	 * @return array<string, mixed>|WP_Error Array with import stats on success, WP_Error on failure.
	 */
	public function import( array $file ) {
		// Validate file upload.
		if ( empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'upload_failed', __( 'File upload failed.', 'photo-competition-manager' ) );
		}

		// Validate file type by extension.
		$filename  = isset( $file['name'] ) ? $file['name'] : '';
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'csv', 'txt' ), true ) ) {
			return new WP_Error( 'invalid_file_type', __( 'Please upload a CSV file (.csv or .txt).', 'photo-competition-manager' ) );
		}

		// Open and read CSV file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $file['tmp_name'], 'r' );
		if ( false === $handle ) {
			return new WP_Error( 'file_read_failed', __( 'Could not read CSV file.', 'photo-competition-manager' ) );
		}

		$stats = array(
			'total'    => 0,
			'imported' => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'errors'   => array(),
		);

		// Read first row.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
		$first_row = fgetcsv( $handle );
		if ( false === $first_row ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
			return new WP_Error( 'empty_file', __( 'CSV file is empty.', 'photo-competition-manager' ) );
		}

		// Detect if first row is a header or data.
		// If first row contains 'name' and 'email', treat as header.
		// Otherwise, assume first row is data (name,email format without header).
		$first_row_normalized = array_map( 'trim', $first_row );
		$first_row_normalized = array_map( 'strtolower', $first_row_normalized );

		$has_header = in_array( 'name', $first_row_normalized, true ) && in_array( 'email', $first_row_normalized, true );

		if ( $has_header ) {
			// First row is a header.
			$header = $first_row_normalized;

			// Get column indexes.
			$col_name   = array_search( 'name', $header, true );
			$col_email  = array_search( 'email', $header, true );
			$col_grade  = array_search( 'grade', $header, true );
			$col_active = array_search( 'active', $header, true );

			$row_number = 1; // Start at 1 (header is row 0).
		} else {
			// First row is data - assume format: name,email or name,email,grade,active.
			// Rewind to process first row as data.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind
			rewind( $handle );

			// Assume columns: name, email, grade (optional), active (optional).
			$col_name   = 0;
			$col_email  = 1;
			$col_grade  = 2;
			$col_active = 3;

			$row_number = 0; // Start at 0 since there's no header.
		}

		// Process each data row.
		$row = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv -- Standard CSV reading pattern.
		while ( false !== $row ) {
			++$row_number;
			++$stats['total'];

			// Skip empty rows.
			if ( empty( array_filter( $row ) ) ) {
				++$stats['skipped'];
				$row = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
				continue;
			}

			// Extract and sanitize data.
			$name   = isset( $row[ $col_name ] ) ? sanitize_text_field( trim( $row[ $col_name ] ) ) : '';
			$email  = isset( $row[ $col_email ] ) ? sanitize_email( trim( $row[ $col_email ] ) ) : '';
			$grade  = false !== $col_grade && isset( $row[ $col_grade ] ) ? sanitize_text_field( trim( $row[ $col_grade ] ) ) : '';
			$active = false !== $col_active && isset( $row[ $col_active ] ) ? trim( $row[ $col_active ] ) : '1';

			// Validate required fields.
			if ( empty( $name ) || empty( $email ) ) {
				$stats['errors'][] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: Missing name or email.', 'photo-competition-manager' ),
					$row_number
				);
				++$stats['skipped'];
				$row = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
				continue;
			}

			// Validate email format.
			if ( ! is_email( $email ) ) {
				$stats['errors'][] = sprintf(
					/* translators: 1: row number, 2: email address */
					__( 'Row %1$d: Invalid email address: %2$s', 'photo-competition-manager' ),
					$row_number,
					$email
				);
				++$stats['skipped'];
				$row = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
				continue;
			}

			// Normalize active field (1, yes, true, active = true; others = false).
			$active_normalized = in_array( strtolower( $active ), array( '1', 'yes', 'true', 'active' ), true ) ? 1 : 0;

			// Check if member already exists by email.
			$existing = $this->members->find_by_email( $email );

			$data = array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => $grade,
				'active' => $active_normalized,
			);

			if ( $existing ) {
				// Update existing member.
				$result = $this->members->update( $existing->id, $data );

				if ( is_wp_error( $result ) ) {
					$stats['errors'][] = sprintf(
						/* translators: 1: row number, 2: error message */
						__( 'Row %1$d: Update failed: %2$s', 'photo-competition-manager' ),
						$row_number,
						$result->get_error_message()
					);
					++$stats['skipped'];
				} else {
					++$stats['updated'];
				}
			} else {
				// Create new member.
				$result = $this->members->create( $data );

				if ( is_wp_error( $result ) ) {
					$stats['errors'][] = sprintf(
						/* translators: 1: row number, 2: error message */
						__( 'Row %1$d: Creation failed: %2$s', 'photo-competition-manager' ),
						$row_number,
						$result->get_error_message()
					);
					++$stats['skipped'];
				} else {
					++$stats['imported'];
				}
			}
			$row = fgetcsv( $handle );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return $stats;
	}

	/**
	 * Generate a sample CSV file for download.
	 *
	 * @return string CSV content.
	 */
	public function generate_sample_csv(): string {
		$csv  = "name,email,grade,active\n";
		$csv .= '"John Doe",john.doe@example.com,Beginner,1' . "\n";
		$csv .= '"Jane Smith",jane.smith@example.com,Advanced,1' . "\n";
		$csv .= '"Bob Johnson",bob.johnson@example.com,Intermediate,0' . "\n";

		return $csv;
	}
}
