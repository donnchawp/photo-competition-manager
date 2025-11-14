<?php
/**
 * Results controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Date_Formatting;
use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Email_Results_Job_Manager;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Service\Results_Analytics;
use PhotoCompetitionManager\Service\Score_Calculator;
use PhotoCompetitionManager\Support\Competition_Settings;
use function PhotoCompetitionManager\Support\sanitize_csv_row;

/**
 * Manage results dashboard page.
 *
 * @since 1.0.0
 */
class Results_Controller {

	use Date_Formatting;
	use Form_Rendering;

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
	 * Email job manager.
	 *
	 * @var Email_Results_Job_Manager
	 */
	private $email_job_manager;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository   $competitions      Competitions repository.
	 * @param Images_Repository         $images            Images repository.
	 * @param Members_Repository        $members           Members repository.
	 * @param Votes_Repository          $votes             Votes repository.
	 * @param Results_Analytics         $analytics         Results analytics service.
	 * @param Score_Calculator          $calculator        Score calculator service.
	 * @param Email_Service             $email_service     Email service.
	 * @param Email_Results_Job_Manager $email_job_manager Email job manager.
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Images_Repository $images,
		Members_Repository $members,
		Votes_Repository $votes,
		Results_Analytics $analytics,
		Score_Calculator $calculator,
		Email_Service $email_service,
		Email_Results_Job_Manager $email_job_manager
	) {
		$this->competitions      = $competitions;
		$this->images            = $images;
		$this->members           = $members;
		$this->votes             = $votes;
		$this->analytics         = $analytics;
		$this->calculator        = $calculator;
		$this->email_service     = $email_service;
		$this->email_job_manager = $email_job_manager;
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

		if ( 'recalculate_scores' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

			check_admin_referer( 'photo_competition_recalculate_scores_' . $competition_id );

			$result = $this->calculator->calculate_scores( $competition_id );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_results',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'photo_competition_results',
					'scores_recalculated',
					sprintf(
						/* translators: %d: number of images updated */
						__( 'Scores recalculated successfully. %d images updated.', 'photo-competition-manager' ),
						$result['updated']
					),
					'updated'
				);
			}

			$this->redirect_with_settings_errors(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager-results',
						'competition' => $competition_id,
					),
					admin_url( 'admin.php' )
				)
			);
		}

		if ( 'email_results' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

			check_admin_referer( 'photo_competition_email_results_' . $competition_id );

			// Create background job for email sending.
			$job_id = $this->email_job_manager->create_job( $competition_id );

			if ( ! $job_id ) {
				add_settings_error(
					'photo_competition_results',
					'email_job_failed',
					__( 'Failed to create email job. No members found with submissions.', 'photo-competition-manager' ),
					'error'
				);

				$this->redirect_with_settings_errors(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager-results',
							'competition' => $competition_id,
						),
						admin_url( 'admin.php' )
					)
				);
			}

			// Schedule first batch immediately.
			$this->email_job_manager->schedule_next_batch( $job_id, 0 );

			// Redirect to results page with job status.
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager-results',
						'competition' => $competition_id,
						'job_id'      => $job_id,
						'status'      => 'processing',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'export_results_csv' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

			check_admin_referer( 'photo_competition_export_results_' . $competition_id );

			$this->export_results_csv( $competition_id );
			exit;
		}
	}

	/**
	 * Render results dashboard page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( 'photo_competition_results' );

		// Display email job progress if present.
		$this->display_email_job_notice();

		// Get selected competition or default to most recent.
		$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$competitions = $this->competitions->all( 100, false, false );

		if ( empty( $competitions ) ) {
			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Results Dashboard', 'photo-competition-manager' ) . '</h1>';
			echo '<p>' . esc_html__( 'No competitions found. Create a competition first.', 'photo-competition-manager' ) . '</p>';
			echo '</div>';
			return;
		}

		// Default to first competition if none selected.
		if ( 0 === $competition_id ) {
			$competition_id = (int) $competitions[0]->id;
		}

		$competition = $this->competitions->find( $competition_id );
		if ( ! $competition ) {
			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Results Dashboard', 'photo-competition-manager' ) . '</h1>';
			echo '<p>' . esc_html__( 'Competition not found.', 'photo-competition-manager' ) . '</p>';
			echo '</div>';
			return;
		}

		// Check if we're viewing image details.
		$image_id = isset( $_GET['image'] ) ? absint( wp_unslash( $_GET['image'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $image_id > 0 ) {
			$this->render_image_details( $image_id, $competition );
			return;
		}

		$this->render_overview( $competition, $competitions );
	}

	/**
	 * Render overview page.
	 *
	 * @param object             $competition   Selected competition.
	 * @param array<int, object> $competitions  All competitions.
	 * @return void
	 */
	private function render_overview( object $competition, array $competitions ): void {
		$settings   = Competition_Settings::parse( $competition->settings );
		$categories = Competition_Settings::get_categories( $settings );
		$grades     = Competition_Settings::get_grades( $settings );

		$summary = $this->analytics->get_competition_summary( (int) $competition->id );

		// Get selected category or default to first.
		$selected_category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $selected_category ) && ! empty( $categories ) ) {
			$selected_category = $categories[0]['slug'] ?? '';
		}

		echo '<div class="wrap photo-competition-manager-results-dashboard">';
		echo '<h1>' . esc_html__( 'Results Dashboard', 'photo-competition-manager' ) . '</h1>';

		// Competition selector.
		echo '<div class="competition-selector" style="margin: 20px 0;">';
		echo '<label for="competition-select" style="margin-right: 10px;"><strong>' . esc_html__( 'Competition:', 'photo-competition-manager' ) . '</strong></label>';
		echo '<select id="competition-select" onchange="window.location.href=this.value">';
		foreach ( $competitions as $comp ) {
			$url      = add_query_arg(
				array(
					'page'        => 'photo-competition-manager-results',
					'competition' => (int) $comp->id,
				),
				admin_url( 'admin.php' )
			);
			$selected = ( (int) $comp->id === (int) $competition->id ) ? ' selected' : '';
			echo '<option value="' . esc_url( $url ) . '"' . esc_attr( $selected ) . '>' . esc_html( $comp->title ) . '</option>';
		}
		echo '</select>';
		echo '</div>';

		// Summary cards.
		echo '<div class="photo-comp-summary-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">';

		$this->render_summary_card( __( 'Total Images', 'photo-competition-manager' ), number_format( $summary['total_images'], 0 ), 'dashicons-format-image' );
		$this->render_summary_card( __( 'Total Votes', 'photo-competition-manager' ), number_format( $summary['total_votes'], 0 ), 'dashicons-yes' );
		$this->render_summary_card( __( 'Participants', 'photo-competition-manager' ), number_format( $summary['total_members'], 0 ), 'dashicons-groups' );
		$this->render_summary_card( __( 'Avg Score', 'photo-competition-manager' ), number_format( (float) $summary['average_score'], 2 ), 'dashicons-star-filled' );

		echo '</div>';

		// Category tabs.
		if ( ! empty( $categories ) ) {
			echo '<div class="nav-tab-wrapper" style="margin: 20px 0;">';
			foreach ( $categories as $category ) {
				$cat_slug  = $category['slug'] ?? '';
				$cat_label = $category['label'] ?? $cat_slug;

				$cat_url = add_query_arg(
					array(
						'page'        => 'photo-competition-manager-results',
						'competition' => (int) $competition->id,
						'category'    => rawurlencode( $cat_slug ),
					),
					admin_url( 'admin.php' )
				);

				$active_class = ( $cat_slug === $selected_category ) ? ' nav-tab-active' : '';

				echo '<a href="' . esc_url( $cat_url ) . '" class="nav-tab' . esc_attr( $active_class ) . '">';
				echo esc_html( $cat_label );

				// Show count badge.
				$cat_count = $summary['categories'][ $cat_slug ]['images'] ?? 0;
				echo ' <span class="count">(' . absint( $cat_count ) . ')</span>';

				echo '</a>';
			}
			echo '</div>';
		}

		// Category breakdown.
		if ( ! empty( $selected_category ) ) {
			$breakdown = $this->analytics->get_category_breakdown( (int) $competition->id, $selected_category );

			echo '<div class="photo-comp-category-stats" style="background: #f9f9f9; padding: 15px; margin: 20px 0; border-left: 4px solid #2271b1;">';
			echo '<h3>' . esc_html__( 'Category Statistics', 'photo-competition-manager' ) . '</h3>';
			echo '<p>';
			echo '<strong>' . esc_html__( 'Images:', 'photo-competition-manager' ) . '</strong> ' . absint( $breakdown['images'] ) . ' | ';
			echo '<strong>' . esc_html__( 'Votes:', 'photo-competition-manager' ) . '</strong> ' . absint( $breakdown['votes'] ) . ' | ';
			echo '<strong>' . esc_html__( 'Avg Score:', 'photo-competition-manager' ) . '</strong> ' . esc_html( number_format( (float) $breakdown['average_score'], 2 ) ) . ' | ';
			echo '<strong>' . esc_html__( 'Range:', 'photo-competition-manager' ) . '</strong> ' . esc_html( number_format( (float) $breakdown['min_score'], 0 ) ) . ' - ' . esc_html( number_format( (float) $breakdown['max_score'], 0 ) ) . ' | ';
			echo '<strong>' . esc_html__( 'Participation:', 'photo-competition-manager' ) . '</strong> ' . esc_html( number_format( (float) $breakdown['participation_rate'], 1 ) ) . '%';
			echo '</p>';
			echo '</div>';

			// Results table grouped by grade.
			$this->render_results_table( (int) $competition->id, $selected_category, $grades );
		}

		// Action buttons.
		echo '<div class="photo-comp-actions" style="margin: 20px 0;">';

		$recalculate_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'photo-competition-manager-results',
					'action'      => 'recalculate_scores',
					'competition' => (int) $competition->id,
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_recalculate_scores_' . (int) $competition->id
		);

		echo '<a href="' . esc_url( $recalculate_url ) . '" class="button button-secondary">';
		echo '<span class="dashicons dashicons-update" style="margin-top: 3px;"></span> ';
		echo esc_html__( 'Recalculate Scores', 'photo-competition-manager' );
		echo '</a> ';

		$export_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'photo-competition-manager-results',
					'action'      => 'export_results_csv',
					'competition' => (int) $competition->id,
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_export_results_' . (int) $competition->id
		);

		echo '<a href="' . esc_url( $export_url ) . '" class="button button-primary">';
		echo '<span class="dashicons dashicons-download" style="margin-top: 3px;"></span> ';
		echo esc_html__( 'Export Results (CSV)', 'photo-competition-manager' );
		echo '</a> ';

		$email_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'photo-competition-manager-results',
					'action'      => 'email_results',
					'competition' => (int) $competition->id,
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_email_results_' . (int) $competition->id
		);

		echo '<a href="' . esc_url( $email_url ) . '" class="button button-secondary">';
		echo '<span class="dashicons dashicons-email" style="margin-top: 3px;"></span> ';
		echo esc_html__( 'Email Results', 'photo-competition-manager' );
		echo '</a>';

		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render a summary card.
	 *
	 * @param string $label Label text.
	 * @param mixed  $value Value to display.
	 * @param string $icon  Dashicon class.
	 * @return void
	 */
	private function render_summary_card( string $label, $value, string $icon ): void {
		echo '<div class="photo-comp-summary-card" style="background: white; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">';
		echo '<div style="display: flex; align-items: center; margin-bottom: 10px;">';
		echo '<span class="dashicons ' . esc_attr( $icon ) . '" style="font-size: 32px; color: #2271b1; margin-right: 10px;"></span>';
		echo '<div>';
		echo '<div style="font-size: 28px; font-weight: bold; color: #1d2327;">' . esc_html( $value ) . '</div>';
		echo '<div style="font-size: 13px; color: #646970;">' . esc_html( $label ) . '</div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render results table grouped by grade.
	 *
	 * @param int               $competition_id Competition ID.
	 * @param string            $category       Category slug.
	 * @param array<int, array> $grades         Grade definitions.
	 * @return void
	 */
	private function render_results_table( int $competition_id, string $category, array $grades ): void {
		$results        = $this->calculator->get_results( $competition_id, $category );
		$members_lookup = array();

		// Build members lookup.
		$all_members = $this->members->all( 9999, false, false );
		foreach ( $all_members as $member ) {
			$members_lookup[ $member->id ] = $member;
		}

		// Group by grade.
		$results_by_grade = array();
		foreach ( $results as $result ) {
			$member = $members_lookup[ $result->member_id ] ?? null;
			$grade  = $member ? $member->grade : 'unknown';

			if ( ! isset( $results_by_grade[ $grade ] ) ) {
				$results_by_grade[ $grade ] = array();
			}

			$results_by_grade[ $grade ][] = $result;
		}

		foreach ( $grades as $grade ) {
			$grade_slug  = $grade['slug'] ?? '';
			$grade_label = $grade['label'] ?? $grade_slug;

			$grade_results = $results_by_grade[ $grade_slug ] ?? array();

			if ( empty( $grade_results ) ) {
				continue;
			}

			echo '<h3>' . esc_html( $grade_label ) . '</h3>';

			echo '<table class="wp-list-table widefat fixed striped" style="margin-bottom: 30px;">';
			echo '<thead>';
			echo '<tr>';
			echo '<th style="width: 60px;">' . esc_html__( 'Rank', 'photo-competition-manager' ) . '</th>';
			echo '<th style="width: 80px;">' . esc_html__( 'Image', 'photo-competition-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Member', 'photo-competition-manager' ) . '</th>';
			echo '<th style="width: 100px;">' . esc_html__( 'Score', 'photo-competition-manager' ) . '</th>';
			echo '<th style="width: 80px;">' . esc_html__( 'Votes', 'photo-competition-manager' ) . '</th>';
			echo '<th style="width: 120px;">' . esc_html__( 'Actions', 'photo-competition-manager' ) . '</th>';
			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';

			$rank = 1;
			foreach ( $grade_results as $result ) {
				$member    = $members_lookup[ $result->member_id ] ?? null;
				$image_url = $this->get_image_url( $competition_id, $category, $result->filename );

				echo '<tr>';
				echo '<td><strong>' . absint( $rank ) . '</strong></td>';

				echo '<td>';
				if ( $image_url ) {
					echo '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr__( 'Image', 'photo-competition-manager' ) . '" style="max-width: 60px; height: auto; border: 1px solid #ddd;">';
				} else {
					echo '<span class="dashicons dashicons-format-image" style="font-size: 40px; color: #ddd;"></span>';
				}
				echo '</td>';

				echo '<td>';
				if ( $member ) {
					echo esc_html( $member->name );
				} else {
					echo '<em>' . esc_html__( 'Unknown', 'photo-competition-manager' ) . '</em>';
				}
				echo '</td>';

				// Calculate sum of votes (total points).
				$total_score = (float) $result->average_score * (int) $result->vote_count;
				echo '<td><strong>' . esc_html( number_format( $total_score, 0 ) ) . '</strong></td>';
				echo '<td>' . absint( $result->vote_count ) . '</td>';

				echo '<td>';
				$detail_url = add_query_arg(
					array(
						'page'        => 'photo-competition-manager-results',
						'competition' => $competition_id,
						'category'    => rawurlencode( $category ),
						'image'       => (int) $result->id,
					),
					admin_url( 'admin.php' )
				);
				echo '<a href="' . esc_url( $detail_url ) . '" class="button button-small">' . esc_html__( 'View Details', 'photo-competition-manager' ) . '</a>';
				echo '</td>';

				echo '</tr>';

				++$rank;
			}

			echo '</tbody>';
			echo '</table>';
		}
	}

	/**
	 * Render image details page.
	 *
	 * @param int    $image_id    Image ID.
	 * @param object $competition Competition object.
	 * @return void
	 */
	private function render_image_details( int $image_id, object $competition ): void {
		$details = $this->analytics->get_image_details( $image_id );

		if ( ! $details['image'] ) {
			echo '<div class="wrap">';
			echo '<h1>' . esc_html__( 'Image Details', 'photo-competition-manager' ) . '</h1>';
			echo '<p>' . esc_html__( 'Image not found.', 'photo-competition-manager' ) . '</p>';
			echo '</div>';
			return;
		}

		$image      = $details['image'];
		$member     = $details['member'];
		$votes      = $details['votes'];
		$statistics = $details['statistics'];

		$category       = $image->category;
		$category_label = $category; // Could be enriched from settings.

		$back_url = add_query_arg(
			array(
				'page'        => 'photo-competition-manager-results',
				'competition' => (int) $competition->id,
				'category'    => rawurlencode( $category ),
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="wrap photo-competition-manager-image-details">';
		echo '<h1>' . esc_html__( 'Image Details', 'photo-competition-manager' ) . '</h1>';

		echo '<p><a href="' . esc_url( $back_url ) . '" class="button">&larr; ' . esc_html__( 'Back to Results', 'photo-competition-manager' ) . '</a></p>';

		echo '<div style="display: grid; grid-template-columns: 300px 1fr; gap: 30px; margin: 20px 0;">';

		// Left column - Image.
		echo '<div>';
		$image_url = $this->get_image_url( (int) $competition->id, $category, $image->filename );
		if ( $image_url ) {
			echo '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr__( 'Competition Image', 'photo-competition-manager' ) . '" style="max-width: 100%; height: auto; border: 1px solid #ddd;">';
		}

		echo '<div style="margin-top: 15px; padding: 15px; background: #f9f9f9; border-left: 4px solid #2271b1;">';
		echo '<p><strong>' . esc_html__( 'Member:', 'photo-competition-manager' ) . '</strong><br>' . esc_html( $member ? $member->name : __( 'Unknown', 'photo-competition-manager' ) ) . '</p>';
		if ( $member && ! empty( $member->email ) ) {
			echo '<p><strong>' . esc_html__( 'Email:', 'photo-competition-manager' ) . '</strong><br>' . esc_html( $member->email ) . '</p>';
		}
		if ( $member ) {
			echo '<p><strong>' . esc_html__( 'Grade:', 'photo-competition-manager' ) . '</strong><br>' . esc_html( $member->grade ) . '</p>';
		}
		echo '<p><strong>' . esc_html__( 'Category:', 'photo-competition-manager' ) . '</strong><br>' . esc_html( $category_label ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Image #:', 'photo-competition-manager' ) . '</strong><br>' . absint( $image->random_number ) . '</p>';
		echo '</div>';

		echo '</div>';

		// Right column - Statistics and votes.
		echo '<div>';

		echo '<h2>' . esc_html__( 'Statistics', 'photo-competition-manager' ) . '</h2>';
		echo '<table class="widefat" style="margin-bottom: 30px;">';
		echo '<tr><th>' . esc_html__( 'Total Votes', 'photo-competition-manager' ) . '</th><td>' . absint( $statistics['count'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Average Score', 'photo-competition-manager' ) . '</th><td>' . esc_html( number_format( $statistics['average'], 2 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Median Score', 'photo-competition-manager' ) . '</th><td>' . esc_html( number_format( $statistics['median'], 2 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Min Score', 'photo-competition-manager' ) . '</th><td>' . esc_html( number_format( $statistics['min'], 0 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Max Score', 'photo-competition-manager' ) . '</th><td>' . esc_html( number_format( $statistics['max'], 0 ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Std Deviation', 'photo-competition-manager' ) . '</th><td>' . esc_html( number_format( $statistics['std_dev'], 2 ) ) . '</td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Individual Votes', 'photo-competition-manager' ) . '</h2>';
		if ( ! empty( $votes ) ) {
			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead>';
			echo '<tr>';
			echo '<th>' . esc_html__( 'Voter', 'photo-competition-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Score', 'photo-competition-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Timestamp', 'photo-competition-manager' ) . '</th>';
			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';

			foreach ( $votes as $vote ) {
				$voter_name = $vote->voter_name ? $vote->voter_name : 'Token #' . $vote->voting_token_id;

				echo '<tr>';
				echo '<td>' . esc_html( $voter_name ) . '</td>';
				echo '<td><strong>' . esc_html( number_format( (float) $vote->score, 0 ) ) . '</strong></td>';
				echo '<td>' . esc_html( $this->format_datetime( $vote->created_at ) ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody>';
			echo '</table>';
		} else {
			echo '<p>' . esc_html__( 'No votes recorded for this image.', 'photo-competition-manager' ) . '</p>';
		}

		echo '</div>';

		echo '</div>'; // End grid.

		echo '</div>';
	}

	/**
	 * Export results as CSV.
	 *
	 * @param int $competition_id Competition ID.
	 * @return void
	 */
	private function export_results_csv( int $competition_id ): void {
		$competition = $this->competitions->find( $competition_id );
		if ( ! $competition ) {
			wp_die( esc_html__( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$settings   = Competition_Settings::parse( $competition->settings );
		$categories = Competition_Settings::get_categories( $settings );

		$filename = 'results-' . sanitize_title( $competition->slug ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$output = fopen( 'php://output', 'w' );

		// CSV header.
		fputcsv(
			$output,
			sanitize_csv_row(
				array(
					'Competition',
					'Category',
					'Rank',
					'Image Number',
					'Member Name',
					'Member Email',
					'Grade',
					'Score',
					'Vote Count',
					'Filename',
				)
			)
		);

		$members_lookup = array();
		$all_members    = $this->members->all( 9999, false, false );
		foreach ( $all_members as $member ) {
			$members_lookup[ $member->id ] = $member;
		}

		foreach ( $categories as $category ) {
			$category_slug  = $category['slug'] ?? '';
			$category_label = $category['label'] ?? $category_slug;

			$results = $this->calculator->get_results( $competition_id, $category_slug );

			$rank = 1;
			foreach ( $results as $result ) {
				$member = $members_lookup[ $result->member_id ] ?? null;

				fputcsv(
					$output,
					sanitize_csv_row(
						array(
							$competition->title,
							$category_label,
							$rank,
							$result->random_number,
							$member ? $member->name : '',
							$member ? $member->email : '',
							$member ? $member->grade : '',
							number_format( (float) $result->average_score * (int) $result->vote_count, 0 ),
							$result->vote_count,
							$result->filename,
						)
					)
				);

				++$rank;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );
	}

	/**
	 * Get image URL for display.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @param string $filename       Image filename.
	 * @return string|null
	 */
	private function get_image_url( int $competition_id, string $category, string $filename ): ?string {
		$competition = $this->competitions->find( $competition_id );
		if ( ! $competition ) {
			return null;
		}

		$upload_dir  = wp_upload_dir();
		$folder_url  = trailingslashit( $upload_dir['baseurl'] ) . 'competitions/' . $competition->slug . '/' . $category . '/';
		$folder_path = trailingslashit( $upload_dir['basedir'] ) . 'competitions/' . $competition->slug . '/' . $category . '/';

		// Get thumbnail filename.
		$thumb_name = $this->get_thumbnail_filename( $filename );
		$thumb_path = $folder_path . $thumb_name;

		// Return thumbnail URL if it exists, otherwise null.
		return file_exists( $thumb_path ) ? $folder_url . rawurlencode( $thumb_name ) : null;
	}

	/**
	 * Get thumbnail filename from base filename.
	 *
	 * @param string $filename Base filename.
	 * @return string
	 */
	private function get_thumbnail_filename( string $filename ): string {
		$info = pathinfo( $filename );
		$base = $info['filename'] ?? $filename;
		$ext  = isset( $info['extension'] ) && '' !== $info['extension'] ? '.' . $info['extension'] : '';

		return $base . '-thumb' . $ext;
	}

	/**
	 * Get global settings.
	 *
	 * @return array<string, mixed>
	 */
	private function get_global_settings(): array {
		$settings = get_option( 'photo_comp_global_settings', array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Email results to all members who submitted images.
	 *
	 * @param int $competition_id Competition ID.
	 * @return array{success: bool, sent_count: int, total_count: int, message: string}
	 */
	private function email_results_to_members( int $competition_id ): array {
		$competition = $this->competitions->find( $competition_id );
		if ( ! $competition ) {
			return array(
				'success'     => false,
				'sent_count'  => 0,
				'total_count' => 0,
				'message'     => __( 'Competition not found.', 'photo-competition-manager' ),
			);
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

		$sent_count  = 0;
		$total_count = count( $member_ids );

		foreach ( array_keys( $member_ids ) as $member_id ) {
			$member = $this->members->find( (int) $member_id );
			if ( ! $member || empty( $member->email ) ) {
				continue;
			}

			// Build member results data.
			$member_results = array(
				'images' => array(),
			);

			foreach ( $categories as $category ) {
				$category_slug  = $category['slug'] ?? '';
				$category_label = $category['label'] ?? $category_slug;

				if ( empty( $category_slug ) ) {
					continue;
				}

				$results = $this->calculator->get_results( $competition_id, $category_slug );

				// Find this member's images in the results.
				$rank = 1;
				foreach ( $results as $result ) {
					if ( (int) $result->member_id === (int) $member_id ) {
						$image_details = $this->analytics->get_image_details( (int) $result->id );

						$member_results['images'][] = array(
							'category_label' => $category_label,
							'image_number'   => $result->random_number,
							'rank'           => $rank,
							'statistics'     => $image_details['statistics'],
							'votes'          => $image_details['votes'],
						);
					}
					++$rank;
				}
			}

			// Send email to member.
			$sent = $this->email_service->send_results_email(
				$member->email,
				$member->name,
				$competition->title,
				$member_results
			);

			if ( $sent ) {
				++$sent_count;
			}
		}

		return array(
			'success'     => true,
			'sent_count'  => $sent_count,
			'total_count' => $total_count,
			'message'     => sprintf(
				/* translators: %1$d: number of emails sent, %2$d: total number of members */
				__( 'Sent results to %1$d of %2$d members.', 'photo-competition-manager' ),
				$sent_count,
				$total_count
			),
		);
	}

	/**
	 * Display email job progress notice.
	 *
	 * @return void
	 */
	private function display_email_job_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['job_id'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$job_id = sanitize_text_field( wp_unslash( $_GET['job_id'] ) );
		$job    = $this->email_job_manager->get_job_status( $job_id );

		if ( ! $job ) {
			return;
		}

		if ( 'processing' === $job['status'] || 'pending' === $job['status'] ) {
			$progress_percent = $job['total_count'] > 0 ? ( count( $job['processed_ids'] ) / $job['total_count'] ) * 100 : 0;

			echo '<div class="notice notice-info">';
			echo '<p><strong>' . esc_html__( 'Sending results emails...', 'photo-competition-manager' ) . '</strong></p>';
			echo '<p>';
			printf(
				/* translators: 1: Sent count, 2: Total count, 3: Progress percentage */
				esc_html__( 'Progress: %1$d of %2$d emails sent (%3$d%%)', 'photo-competition-manager' ),
				esc_html( count( $job['processed_ids'] ) ),
				esc_html( $job['total_count'] ),
				absint( $progress_percent )
			);
			echo '</p>';
			echo '<p><em>' . esc_html__( 'This page will refresh automatically every 5 seconds.', 'photo-competition-manager' ) . '</em> ';
			echo '<a href="' . esc_url( remove_query_arg( array( 'job_id', 'status' ) ) ) . '">' . esc_html__( 'Refresh now', 'photo-competition-manager' ) . '</a></p>';
			echo '</div>';

			// Auto-refresh every 5 seconds.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<meta http-equiv="refresh" content="5">';
		} elseif ( 'completed' === $job['status'] ) {
			echo '<div class="notice notice-success is-dismissible">';
			echo '<p><strong>' . esc_html__( 'Email results sent successfully!', 'photo-competition-manager' ) . '</strong></p>';
			echo '<p>';
			printf(
				/* translators: 1: Sent count, 2: Total count */
				esc_html__( 'Sent %1$d of %2$d emails.', 'photo-competition-manager' ),
				esc_html( $job['sent_count'] ),
				esc_html( $job['total_count'] )
			);

			if ( $job['failed_count'] > 0 ) {
				echo ' ';
				printf(
					/* translators: %d: Failed count */
					esc_html__( '%d emails failed to send.', 'photo-competition-manager' ),
					esc_html( $job['failed_count'] )
				);
			}

			echo '</p>';
			echo '</div>';
		} elseif ( 'failed' === $job['status'] ) {
			echo '<div class="notice notice-error is-dismissible">';
			echo '<p><strong>' . esc_html__( 'Email job failed.', 'photo-competition-manager' ) . '</strong></p>';

			if ( ! empty( $job['error_log'] ) ) {
				echo '<ul>';
				foreach ( array_slice( $job['error_log'], 0, 5 ) as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}
				echo '</ul>';
			}

			echo '</div>';
		}
	}
}
