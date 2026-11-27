<?php
/**
 * Member CSV import service.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competition_Types_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use function PhotoCompetitionManager\Support\is_http_upload;
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
	 * The grade may be a club grade's label or slug, in any case. A blank
	 * grade, or no grade column, keeps an existing member's grade.
	 *
	 * An optional `type` column (`types` in older files) holds one competition type
	 * name, in any case; a file without a header has it sixth, after committee, when
	 * the club has several types (with one, a sixth column is ignored). It replaces
	 * the member's type. A blank cell, or no such column, leaves an existing member's
	 * type alone, and a new member gets the club's only type, if it has one. A member
	 * has one type, so a row naming several, separated by `;`, is skipped.
	 *
	 * @since 0.6.0 One type per member, in a `type` column.
	 *
	 * @param array<string, mixed> $file Uploaded file array from $_FILES.
	 * @return array<string, mixed>|WP_Error Import stats (total, imported, updated, skipped, untyped: active
	 *                                     new members left with no type, and errors), or WP_Error on failure.
	 */
	public function import( array $file ) {
		// Validate file upload.
		if ( empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'upload_failed', __( 'File upload failed.', 'photo-competition-manager' ) );
		}

		// Security: Verify file was uploaded via HTTP POST (not a local file reference).
		if ( ! is_http_upload( $file['tmp_name'] ) ) {
			return new WP_Error( 'security_check_failed', __( 'Security check failed.', 'photo-competition-manager' ) );
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
			'untyped'  => 0,
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
			$col_name      = array_search( 'name', $header, true );
			$col_email     = array_search( 'email', $header, true );
			$col_grade     = array_search( 'grade', $header, true );
			$col_active    = array_search( 'active', $header, true );
			$col_committee = array_search( 'committee', $header, true );
			$col_type      = array_search( 'type', $header, true );
			$col_type      = false !== $col_type ? $col_type : array_search( 'types', $header, true );

			$row_number = 1; // The header is row 1, as in a spreadsheet.
		} else {
			// First row is data - assume format: name,email or name,email,grade,active,committee.
			// Rewind to process first row as data.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind
			rewind( $handle );

			// Assume columns: name, email, grade, active, committee and type, all but the first two optional.
			$col_name      = 0;
			$col_email     = 1;
			$col_grade     = 2;
			$col_active    = 3;
			$col_committee = 4;
			$col_type      = 5;

			$row_number = 0; // Start at 0 since there's no header.
		}

		$type_ids_by_name = array();
		foreach ( ( new Competition_Types_Repository() )->all() as $type ) {
			$type_ids_by_name[ strtolower( trim( $type->name ) ) ] = (int) $type->id;
		}

		// With one type there's no type column to fill in, so a headerless file's sixth column is something else.
		if ( ! $has_header && count( $type_ids_by_name ) < 2 ) {
			$col_type = false;
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
			$name      = isset( $row[ $col_name ] ) ? sanitize_text_field( trim( $row[ $col_name ] ) ) : '';
			$email     = isset( $row[ $col_email ] ) ? sanitize_email( trim( $row[ $col_email ] ) ) : '';
			$grade     = false !== $col_grade && isset( $row[ $col_grade ] ) ? sanitize_text_field( trim( $row[ $col_grade ] ) ) : '';
			$active    = false !== $col_active && isset( $row[ $col_active ] ) ? trim( $row[ $col_active ] ) : '1';
			$committee = false !== $col_committee && isset( $row[ $col_committee ] ) ? trim( $row[ $col_committee ] ) : '0';

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

			// Resolve the grade cell to a club grade's slug.
			$grade_slug = Competition_Settings::find_club_grade_slug( $grade );
			if ( '' !== $grade && null === $grade_slug ) {
				$stats['errors'][] = sprintf(
					/* translators: 1: row number, 2: grade from the CSV file */
					__( 'Row %1$d: "%2$s" isn\'t one of the club\'s grades.', 'photo-competition-manager' ),
					$row_number,
					$grade
				);
				++$stats['skipped'];
				$row = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
				continue;
			}

			// Resolve the type name to a type ID.
			$type_id    = null;
			$type_names = false !== $col_type && isset( $row[ $col_type ] ) ? array_values( array_filter( array_map( 'trim', explode( ';', $row[ $col_type ] ) ), 'strlen' ) ) : array();
			if ( count( $type_names ) > 1 ) {
				$stats['errors'][] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: A member has one competition type, and this row names several.', 'photo-competition-manager' ),
					$row_number
				);
				++$stats['skipped'];
				$row = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
				continue;
			}
			if ( $type_names ) {
				$type_id = $type_ids_by_name[ strtolower( sanitize_text_field( $type_names[0] ) ) ] ?? null;
				if ( null === $type_id ) {
					$stats['errors'][] = sprintf(
						/* translators: 1: row number, 2: type name from the CSV file */
						__( 'Row %1$d: "%2$s" isn\'t a competition type.', 'photo-competition-manager' ),
						$row_number,
						sanitize_text_field( $type_names[0] )
					);
					++$stats['skipped'];
					$row = fgetcsv( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgetcsv
					continue;
				}
			}

			// Normalize active field (1, yes, true, active = true; others = false).
			$active_normalized    = in_array( strtolower( $active ), array( '1', 'yes', 'true', 'active' ), true ) ? 1 : 0;
			$committee_normalized = in_array( strtolower( $committee ), array( '1', 'yes', 'true' ), true ) ? 1 : 0;

			// Check if member already exists by email.
			$existing = $this->members->find_by_email( $email );

			$data = array(
				'name'      => $name,
				'email'     => $email,
				'active'    => $active_normalized,
				'committee' => $committee_normalized,
			);

			// Leaving the grade out of the update keeps an existing member's grade.
			if ( null !== $grade_slug ) {
				$data['grade'] = $grade_slug;
			}

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
					if ( null !== $type_id ) {
						$this->members->set_type( (int) $existing->id, $type_id );
					}
					++$stats['updated'];
				}
			} elseif ( null === $grade_slug ) {
				$stats['errors'][] = sprintf(
					/* translators: %d: row number */
					__( 'Row %d: A new member needs a grade.', 'photo-competition-manager' ),
					$row_number
				);
				++$stats['skipped'];
			} else {
				// Create new member.
				if ( null !== $type_id ) {
					$data['type_id'] = $type_id;
				}

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
					// With one type a new member gets it; with several, none unless the row names one.
					// Only active ones count: an inactive member gets no links anyway.
					if ( null === $type_id && count( $type_ids_by_name ) > 1 && $active_normalized ) {
						++$stats['untyped'];
					}
				}
			}
			$row = fgetcsv( $handle );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );

		return $stats;
	}

	/**
	 * The notice an import shows: what it did, how many new members it left with
	 * no type, and the first few row errors.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string, mixed> $result import()'s stats.
	 * @return string
	 */
	public static function summary( array $result ): string {
		$message = sprintf(
			/* translators: 1: imported count, 2: updated count, 3: skipped count */
			__( 'Import complete: %1$d new members, %2$d updated, %3$d skipped.', 'photo-competition-manager' ),
			$result['imported'],
			$result['updated'],
			$result['skipped']
		);

		if ( ! empty( $result['untyped'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of new members with no competition type */
				_n(
					'%d new member has no competition type, so they get no links for competitions open to all until you add them to one.',
					'%d new members have no competition type, so they get no links for competitions open to all until you add them to one.',
					$result['untyped'],
					'photo-competition-manager'
				),
				$result['untyped']
			);
		}

		if ( ! empty( $result['errors'] ) ) {
			$message .= ' ' . __( 'Errors:', 'photo-competition-manager' ) . ' ' . implode( ' ', array_slice( $result['errors'], 0, 5 ) );
			if ( count( $result['errors'] ) > 5 ) {
				$message .= sprintf(
					/* translators: %d: number of additional errors */
					__( ' ...and %d more errors.', 'photo-competition-manager' ),
					count( $result['errors'] ) - 5
				);
			}
		}

		return $message;
	}

	/**
	 * Generate a sample CSV file for download, using the club's grades and,
	 * when it has several, its competition types.
	 *
	 * @return string CSV content.
	 */
	public function generate_sample_csv(): string {
		$labels = wp_list_pluck( Competition_Settings::club_grades(), 'label' );
		$types  = wp_list_pluck( ( new Competition_Types_Repository() )->all(), 'name' );
		$rows   = array(
			array( 'John Doe', 'john.doe@example.com', '1', '0' ),
			array( 'Jane Smith', 'jane.smith@example.com', '1', '1' ),
			array( 'Bob Johnson', 'bob.johnson@example.com', '0', '0' ),
		);

		// The first type, then the second, then the first.
		$sample_types = count( $types ) > 1 ? array( $types[0], $types[1], $types[0] ) : array();

		$csv = 'name,email,grade,active,committee' . ( $sample_types ? ',type' : '' ) . "\n";
		foreach ( $rows as $index => $row ) {
			list( $name, $email, $active, $committee ) = $row;

			$line = sprintf( '"%s",%s,"%s",%s,%s', $name, $email, $this->csv_quoted( $labels[ $index % count( $labels ) ] ), $active, $committee );
			if ( $sample_types ) {
				$line .= ',"' . $this->csv_quoted( $sample_types[ $index ] ) . '"';
			}
			$csv .= $line . "\n";
		}

		return $csv;
	}

	/**
	 * A value ready to go between double quotes in a CSV cell, with any quotes inside it doubled.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private function csv_quoted( string $value ): string {
		return str_replace( '"', '""', $value );
	}
}
