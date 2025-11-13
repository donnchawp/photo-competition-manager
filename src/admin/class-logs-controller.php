<?php
/**
 * Logs controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Date_Formatting;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;

/**
 * Manage logs dashboard page.
 *
 * @since 0.1.0
 */
class Logs_Controller {

	use Date_Formatting;

	/**
	 * Logs repository.
	 *
	 * @var Logs_Repository
	 */
	private $logs;

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Constructor.
	 *
	 * @param Logs_Repository         $logs         Logs repository.
	 * @param Competitions_Repository $competitions Competitions repository.
	 */
	public function __construct(
		Logs_Repository $logs,
		Competitions_Repository $competitions
	) {
		$this->logs         = $logs;
		$this->competitions = $competitions;
	}

	/**
	 * Register hooks for this controller.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Handle admin post actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			return;
		}

		$action = '';

		if ( isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( wp_unslash( $_GET['action'] ) );
		}

		if ( 'export_logs_csv' === $action ) {
			check_admin_referer( 'photo_competition_export_logs' );

			$this->export_logs_csv();
			exit;
		}
	}

	/**
	 * Render logs page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		// Get filter parameters.
		$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_category = isset( $_GET['event_category'] ) ? sanitize_key( wp_unslash( $_GET['event_category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_type     = isset( $_GET['event_type'] ) ? sanitize_key( wp_unslash( $_GET['event_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_from      = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to        = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_page   = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Build filters array.
		$filters = array();

		if ( $competition_id > 0 ) {
			$filters['competition_id'] = $competition_id;
		}

		if ( ! empty( $event_category ) ) {
			$filters['event_category'] = $event_category;
		}

		if ( ! empty( $event_type ) ) {
			$filters['event_type'] = $event_type;
		}

		if ( ! empty( $date_from ) ) {
			$filters['date_from'] = $date_from . ' 00:00:00';
		}

		if ( ! empty( $date_to ) ) {
			$filters['date_to'] = $date_to . ' 23:59:59';
		}

		// Pagination.
		$per_page = 50;
		$offset   = ( $current_page - 1 ) * $per_page;

		// Fetch logs.
		$logs        = $this->logs->paginate( $per_page, $offset, $filters );
		$total_logs  = $this->logs->count( $filters );
		$total_pages = ceil( $total_logs / $per_page );

		// Get all competitions for dropdown.
		$competitions = $this->competitions->all( 100, false, false );

		// Get distinct categories and types for filters.
		$event_categories = $this->logs->get_event_categories();
		$event_types      = $this->logs->get_event_types();

		$this->render_logs_page( $logs, $competitions, $event_categories, $event_types, $filters, $current_page, $total_pages, $total_logs );
	}

	/**
	 * Render logs page UI.
	 *
	 * @param array<int, object> $logs             Log entries.
	 * @param array<int, object> $competitions     All competitions.
	 * @param array<int, string> $event_categories Event categories.
	 * @param array<int, string> $event_types      Event types.
	 * @param array              $filters          Active filters.
	 * @param int                $current_page     Current page number.
	 * @param int                $total_pages      Total pages.
	 * @param int                $total_logs       Total logs count.
	 * @return void
	 */
	private function render_logs_page(
		array $logs,
		array $competitions,
		array $event_categories,
		array $event_types,
		array $filters,
		int $current_page,
		int $total_pages,
		int $total_logs
	): void {
		echo '<div class="wrap photo-competition-manager-logs">';
		echo '<h1>' . esc_html__( 'Competition Logs', 'photo-competition-manager' ) . '</h1>';

		// Filters form.
		$this->render_filters( $competitions, $event_categories, $event_types, $filters );

		// Log count.
		echo '<p class="log-count" style="margin: 20px 0;">';
		printf(
			/* translators: %s: Number of logs */
			esc_html__( 'Showing %s log entries', 'photo-competition-manager' ),
			'<strong>' . esc_html( number_format( $total_logs ) ) . '</strong>'
		);
		echo '</p>';

		// Export button.
		if ( ! empty( $logs ) ) {
			$export_url = wp_nonce_url(
				add_query_arg(
					array_merge(
						array(
							'page'   => 'photo-competition-manager-logs',
							'action' => 'export_logs_csv',
						),
						array_filter(
							array(
								'competition'    => $filters['competition_id'] ?? '',
								'event_category' => $filters['event_category'] ?? '',
								'event_type'     => $filters['event_type'] ?? '',
								'date_from'      => isset( $filters['date_from'] ) ? gmdate( 'Y-m-d', strtotime( $filters['date_from'] ) ) : '',
								'date_to'        => isset( $filters['date_to'] ) ? gmdate( 'Y-m-d', strtotime( $filters['date_to'] ) ) : '',
							)
						)
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_export_logs'
			);

			echo '<a href="' . esc_url( $export_url ) . '" class="button" style="margin-bottom: 20px;">';
			echo '<span class="dashicons dashicons-download" style="vertical-align: middle;"></span> ';
			echo esc_html__( 'Export to CSV', 'photo-competition-manager' );
			echo '</a>';
		}

		// Logs table.
		if ( empty( $logs ) ) {
			echo '<p>' . esc_html__( 'No log entries found.', 'photo-competition-manager' ) . '</p>';
		} else {
			$this->render_logs_table( $logs, $competitions );
		}

		// Pagination.
		if ( $total_pages > 1 ) {
			$this->render_pagination( $current_page, $total_pages, $filters );
		}

		echo '</div>';
	}

	/**
	 * Render filters form.
	 *
	 * @param array<int, object> $competitions     All competitions.
	 * @param array<int, string> $event_categories Event categories.
	 * @param array<int, string> $event_types      Event types.
	 * @param array              $filters          Active filters.
	 * @return void
	 */
	private function render_filters( array $competitions, array $event_categories, array $event_types, array $filters ): void {
		$selected_competition = $filters['competition_id'] ?? 0;
		$selected_category    = $filters['event_category'] ?? '';
		$selected_type        = $filters['event_type'] ?? '';
		$date_from            = isset( $filters['date_from'] ) ? gmdate( 'Y-m-d', strtotime( $filters['date_from'] ) ) : '';
		$date_to              = isset( $filters['date_to'] ) ? gmdate( 'Y-m-d', strtotime( $filters['date_to'] ) ) : '';

		echo '<form method="get" class="logs-filters" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin: 20px 0;">';
		echo '<input type="hidden" name="page" value="photo-competition-manager-logs" />';

		echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">';

		// Competition filter.
		echo '<div>';
		echo '<label for="competition-filter"><strong>' . esc_html__( 'Competition', 'photo-competition-manager' ) . '</strong></label><br>';
		echo '<select id="competition-filter" name="competition" style="width: 100%;">';
		echo '<option value="0">' . esc_html__( 'All Competitions', 'photo-competition-manager' ) . '</option>';
		foreach ( $competitions as $comp ) {
			$selected = ( (int) $comp->id === $selected_competition ) ? ' selected' : '';
			echo '<option value="' . esc_attr( $comp->id ) . '"' . esc_attr( $selected ) . '>' . esc_html( $comp->title ) . '</option>';
		}
		echo '</select>';
		echo '</div>';

		// Event category filter.
		echo '<div>';
		echo '<label for="event-category-filter"><strong>' . esc_html__( 'Category', 'photo-competition-manager' ) . '</strong></label><br>';
		echo '<select id="event-category-filter" name="event_category" style="width: 100%;">';
		echo '<option value="">' . esc_html__( 'All Categories', 'photo-competition-manager' ) . '</option>';
		foreach ( $event_categories as $category ) {
			$selected = ( $category === $selected_category ) ? ' selected' : '';
			$label    = $this->get_category_label( $category );
			echo '<option value="' . esc_attr( $category ) . '"' . esc_attr( $selected ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</div>';

		// Event type filter.
		echo '<div>';
		echo '<label for="event-type-filter"><strong>' . esc_html__( 'Event Type', 'photo-competition-manager' ) . '</strong></label><br>';
		echo '<select id="event-type-filter" name="event_type" style="width: 100%;">';
		echo '<option value="">' . esc_html__( 'All Types', 'photo-competition-manager' ) . '</option>';
		foreach ( $event_types as $type ) {
			$selected = ( $type === $selected_type ) ? ' selected' : '';
			$label    = $this->get_type_label( $type );
			echo '<option value="' . esc_attr( $type ) . '"' . esc_attr( $selected ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</div>';

		// Date from.
		echo '<div>';
		echo '<label for="date-from-filter"><strong>' . esc_html__( 'From Date', 'photo-competition-manager' ) . '</strong></label><br>';
		echo '<input type="date" id="date-from-filter" name="date_from" value="' . esc_attr( $date_from ) . '" style="width: 100%;" />';
		echo '</div>';

		// Date to.
		echo '<div>';
		echo '<label for="date-to-filter"><strong>' . esc_html__( 'To Date', 'photo-competition-manager' ) . '</strong></label><br>';
		echo '<input type="date" id="date-to-filter" name="date_to" value="' . esc_attr( $date_to ) . '" style="width: 100%;" />';
		echo '</div>';

		echo '</div>';

		// Buttons.
		echo '<div style="margin-top: 15px;">';
		submit_button( __( 'Filter', 'photo-competition-manager' ), 'primary', 'submit', false );
		echo ' ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager-logs' ) ) . '" class="button">' . esc_html__( 'Clear Filters', 'photo-competition-manager' ) . '</a>';
		echo '</div>';

		echo '</form>';
	}

	/**
	 * Render logs table.
	 *
	 * @param array<int, object> $logs         Log entries.
	 * @param array<int, object> $competitions All competitions.
	 * @return void
	 */
	private function render_logs_table( array $logs, array $competitions ): void {
		// Build competition lookup.
		$comp_lookup = array();
		foreach ( $competitions as $comp ) {
			$comp_lookup[ (int) $comp->id ] = $comp->title;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead>';
		echo '<tr>';
		echo '<th style="width: 150px;">' . esc_html__( 'Date/Time', 'photo-competition-manager' ) . '</th>';
		echo '<th style="width: 100px;">' . esc_html__( 'Category', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Description', 'photo-competition-manager' ) . '</th>';
		echo '<th style="width: 150px;">' . esc_html__( 'Actor', 'photo-competition-manager' ) . '</th>';
		echo '<th style="width: 200px;">' . esc_html__( 'Competition', 'photo-competition-manager' ) . '</th>';
		echo '<th style="width: 80px;">' . esc_html__( 'Details', 'photo-competition-manager' ) . '</th>';
		echo '</tr>';
		echo '</thead>';
		echo '<tbody>';

		foreach ( $logs as $log ) {
			$icon              = $this->get_category_icon( $log->event_category );
			$competition_title = $log->competition_id ? ( $comp_lookup[ (int) $log->competition_id ] ?? __( 'Unknown', 'photo-competition-manager' ) ) : __( 'Global', 'photo-competition-manager' );
			$has_metadata      = ! empty( $log->metadata ) && 'null' !== $log->metadata;

			echo '<tr>';

			// Date/Time.
			echo '<td>' . esc_html( $this->format_datetime( $log->created_at ) ) . '</td>';

			// Category with icon.
			echo '<td>';
			echo '<span class="dashicons ' . esc_attr( $icon ) . '" style="color: #2271b1;"></span> ';
			echo esc_html( $this->get_category_label( $log->event_category ) );
			echo '</td>';

			// Description.
			echo '<td>' . esc_html( $log->description ) . '</td>';

			// Actor.
			echo '<td>' . esc_html( $log->actor_name ) . '</td>';

			// Competition.
			echo '<td>' . esc_html( $competition_title ) . '</td>';

			// Details toggle.
			echo '<td>';
			if ( $has_metadata ) {
				echo '<button type="button" class="button-link log-metadata-toggle" data-log-id="' . esc_attr( $log->id ) . '">';
				echo esc_html__( 'View', 'photo-competition-manager' );
				echo '</button>';
				echo '<div id="log-metadata-' . esc_attr( $log->id ) . '" class="log-metadata" style="display: none; margin-top: 10px; padding: 10px; background: #f0f0f1; border: 1px solid #dcdcde;">';
				echo '<pre style="white-space: pre-wrap; word-wrap: break-word; font-size: 11px;">' . esc_html( $log->metadata ) . '</pre>';
				echo '</div>';
			} else {
				echo '—';
			}
			echo '</td>';

			echo '</tr>';

			// Metadata row (hidden by default).
			if ( $has_metadata ) {
				echo '<tr id="log-metadata-row-' . esc_attr( $log->id ) . '" class="log-metadata-row" style="display: none;">';
				echo '<td colspan="6">';
				echo '<div style="padding: 10px; background: #f0f0f1; border: 1px solid #dcdcde;">';
				echo '<strong>' . esc_html__( 'Metadata:', 'photo-competition-manager' ) . '</strong>';
				echo '<pre style="white-space: pre-wrap; word-wrap: break-word; font-size: 11px; margin-top: 10px;">' . esc_html( $log->metadata ) . '</pre>';
				echo '</div>';
				echo '</td>';
				echo '</tr>';
			}
		}

		echo '</tbody>';
		echo '</table>';

		// Add inline JavaScript for metadata toggle.
		?>
		<script type="text/javascript">
		document.addEventListener('DOMContentLoaded', function() {
			var toggles = document.querySelectorAll('.log-metadata-toggle');
			toggles.forEach(function(toggle) {
				toggle.addEventListener('click', function() {
					var logId = this.getAttribute('data-log-id');
					var metadataRow = document.getElementById('log-metadata-row-' + logId);
					if (metadataRow) {
						if (metadataRow.style.display === 'none') {
							metadataRow.style.display = '';
							this.textContent = '<?php echo esc_js( __( 'Hide', 'photo-competition-manager' ) ); ?>';
						} else {
							metadataRow.style.display = 'none';
							this.textContent = '<?php echo esc_js( __( 'View', 'photo-competition-manager' ) ); ?>';
						}
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Render pagination.
	 *
	 * @param int   $current_page Current page number.
	 * @param int   $total_pages  Total pages.
	 * @param array $filters      Active filters.
	 * @return void
	 */
	private function render_pagination( int $current_page, int $total_pages, array $filters ): void {
		echo '<div class="tablenav" style="margin: 20px 0;">';
		echo '<div class="tablenav-pages">';

		$base_url = add_query_arg(
			array_merge(
				array( 'page' => 'photo-competition-manager-logs' ),
				array_filter(
					array(
						'competition'    => $filters['competition_id'] ?? '',
						'event_category' => $filters['event_category'] ?? '',
						'event_type'     => $filters['event_type'] ?? '',
						'date_from'      => isset( $filters['date_from'] ) ? gmdate( 'Y-m-d', strtotime( $filters['date_from'] ) ) : '',
						'date_to'        => isset( $filters['date_to'] ) ? gmdate( 'Y-m-d', strtotime( $filters['date_to'] ) ) : '',
					)
				)
			),
			admin_url( 'admin.php' )
		);

		echo '<span class="displaying-num">';
		printf(
			/* translators: %s: Number of pages */
			esc_html__( 'Page %1$s of %2$s', 'photo-competition-manager' ),
			esc_html( number_format( $current_page ) ),
			esc_html( number_format( $total_pages ) )
		);
		echo '</span>';

		echo '<span class="pagination-links">';

		// First page.
		if ( $current_page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', 1, $base_url ) ) . '">&laquo; ' . esc_html__( 'First', 'photo-competition-manager' ) . '</a> ';
		}

		// Previous page.
		if ( $current_page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ) . '">&lsaquo; ' . esc_html__( 'Previous', 'photo-competition-manager' ) . '</a> ';
		}

		// Next page.
		if ( $current_page < $total_pages ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ) . '">' . esc_html__( 'Next', 'photo-competition-manager' ) . ' &rsaquo;</a> ';
		}

		// Last page.
		if ( $current_page < $total_pages ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $total_pages, $base_url ) ) . '">' . esc_html__( 'Last', 'photo-competition-manager' ) . ' &raquo;</a>';
		}

		echo '</span>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Export logs to CSV.
	 *
	 * @return void
	 */
	private function export_logs_csv(): void {
		// Get filter parameters (same as render method).
		$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_category = isset( $_GET['event_category'] ) ? sanitize_key( wp_unslash( $_GET['event_category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$event_type     = isset( $_GET['event_type'] ) ? sanitize_key( wp_unslash( $_GET['event_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_from      = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date_to        = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Build filters array.
		$filters = array();

		if ( $competition_id > 0 ) {
			$filters['competition_id'] = $competition_id;
		}

		if ( ! empty( $event_category ) ) {
			$filters['event_category'] = $event_category;
		}

		if ( ! empty( $event_type ) ) {
			$filters['event_type'] = $event_type;
		}

		if ( ! empty( $date_from ) ) {
			$filters['date_from'] = $date_from . ' 00:00:00';
		}

		if ( ! empty( $date_to ) ) {
			$filters['date_to'] = $date_to . ' 23:59:59';
		}

		// Fetch all logs with filters (no pagination for export).
		$logs = $this->logs->paginate( 10000, 0, $filters );

		// Get all competitions for lookup.
		$competitions = $this->competitions->all( 100, false, false );
		$comp_lookup  = array();
		foreach ( $competitions as $comp ) {
			$comp_lookup[ (int) $comp->id ] = $comp->title;
		}

		// Set headers for CSV download.
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=competition-logs-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Open output stream.
		$output = fopen( 'php://output', 'w' );

		// Write BOM for proper UTF-8 encoding in Excel.
		fprintf( $output, "\xEF\xBB\xBF" );

		// Write header row.
		fputcsv(
			$output,
			array(
				__( 'Date/Time', 'photo-competition-manager' ),
				__( 'Category', 'photo-competition-manager' ),
				__( 'Event Type', 'photo-competition-manager' ),
				__( 'Description', 'photo-competition-manager' ),
				__( 'Actor Type', 'photo-competition-manager' ),
				__( 'Actor Name', 'photo-competition-manager' ),
				__( 'Competition', 'photo-competition-manager' ),
				__( 'Metadata', 'photo-competition-manager' ),
			)
		);

		// Write data rows.
		foreach ( $logs as $log ) {
			$competition_title = $log->competition_id ? ( $comp_lookup[ (int) $log->competition_id ] ?? __( 'Unknown', 'photo-competition-manager' ) ) : __( 'Global', 'photo-competition-manager' );

			fputcsv(
				$output,
				array(
					$log->created_at,
					$this->get_category_label( $log->event_category ),
					$log->event_type,
					$log->description,
					$log->actor_type,
					$log->actor_name,
					$competition_title,
					$log->metadata ?? '',
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );
	}

	/**
	 * Get human-readable category label.
	 *
	 * @param string $category Category key.
	 * @return string
	 */
	private function get_category_label( string $category ): string {
		$labels = array(
			'email'       => __( 'Email', 'photo-competition-manager' ),
			'voting'      => __( 'Voting', 'photo-competition-manager' ),
			'upload'      => __( 'Upload', 'photo-competition-manager' ),
			'competition' => __( 'Competition', 'photo-competition-manager' ),
			'settings'    => __( 'Settings', 'photo-competition-manager' ),
		);

		return $labels[ $category ] ?? $category;
	}

	/**
	 * Get human-readable type label.
	 *
	 * @param string $type Type key.
	 * @return string
	 */
	private function get_type_label( string $type ): string {
		return str_replace( '_', ' ', ucwords( $type, '_' ) );
	}

	/**
	 * Get dashicon for category.
	 *
	 * @param string $category Category key.
	 * @return string
	 */
	private function get_category_icon( string $category ): string {
		$icons = array(
			'email'       => 'dashicons-email',
			'voting'      => 'dashicons-yes',
			'upload'      => 'dashicons-upload',
			'competition' => 'dashicons-awards',
			'settings'    => 'dashicons-admin-settings',
		);

		return $icons[ $category ] ?? 'dashicons-admin-generic';
	}
}
