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
use PhotoCompetitionManager\Support\Image_Processor;
use function PhotoCompetitionManager\Support\sanitize_csv_row;

/**
 * Manage results dashboard page.
 *
 * @since 0.1.0
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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts for results page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( string $hook_suffix ): void {
		// Only load on results page.
		if ( 'competitions_page_photo-competition-manager-results' !== $hook_suffix ) {
			return;
		}

		// Enqueue a dummy script handle to attach inline script to.
		wp_register_script( 'photo-comp-results-select', '', array(), PHOTO_COMPETITION_MANAGER_VERSION, true );
		wp_enqueue_script( 'photo-comp-results-select' );

		$inline_script = "
		document.addEventListener('DOMContentLoaded', function() {
			var competitionSelect = document.getElementById('competition-select');
			if (competitionSelect) {
				competitionSelect.addEventListener('change', function() {
					window.location.href = this.value;
				});
			}
		});
		";

		wp_add_inline_script( 'photo-comp-results-select', $inline_script );
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

			$redirect_url = add_query_arg(
				array(
					'page'        => 'photo-competition-manager-results',
					'competition' => $competition_id,
				),
				admin_url( 'admin.php' )
			);

			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'photo_competition_results',
					'competition_not_found',
					__( 'Competition not found.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $redirect_url );
				return;
			}

			// Score_Calculator::calculate_scores() always returns an array{updated, errors};
			// it has no whole-run failure mode, so there is no WP_Error path to handle here.
			$result = $this->calculator->calculate_scores( $competition_id );

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

			$this->redirect_with_settings_errors( $redirect_url );
			return;
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

		if ( 'send_results_committee' === $action || 'send_results_all' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

			check_admin_referer( 'photo_competition_' . $action . '_' . $competition_id );

			$redirect_url = add_query_arg(
				array(
					'page'        => 'photo-competition-manager-results',
					'competition' => $competition_id,
				),
				admin_url( 'admin.php' )
			);

			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'photo_competition_results',
					'competition_not_found',
					__( 'Competition not found.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $redirect_url );
				return;
			}

			$share_hash = $competition->share_hash ?? '';

			if ( empty( $share_hash ) ) {
				add_settings_error(
					'photo_competition_results',
					'no_share_hash',
					__( 'No share hash exists. Generate a results link first from the Competitions page.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $redirect_url );
				return;
			}

			$settings         = Competition_Settings::parse( $competition->settings );
			$results_page_url = $settings['urls']['results_page'] ?? '';
			if ( empty( $results_page_url ) ) {
				add_settings_error(
					'photo_competition_results',
					'no_results_page',
					__( 'No results page URL configured. Set one in competition settings.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $redirect_url );
				return;
			}

			$share_url = add_query_arg( 'share', $share_hash, $results_page_url );

			if ( 'send_results_committee' === $action ) {
				$recipients = $this->members->find_committee_members();
			} else {
				$recipients = $this->members->find_active_members();
			}

			$sent_count = 0;

			foreach ( $recipients as $member ) {
				if ( ! empty( $member->email ) ) {
					$sent = $this->email_service->send_results_share_link(
						$member->email,
						$member->name,
						$competition->title,
						$share_url,
						$competition_id
					);
					if ( $sent ) {
						++$sent_count;
					}
				}
			}

			$audience_label = 'send_results_committee' === $action
				? __( 'committee members', 'photo-competition-manager' )
				: __( 'active members', 'photo-competition-manager' );

			add_settings_error(
				'photo_competition_results',
				'results_link_sent',
				sprintf(
					/* translators: 1: number of emails sent, 2: audience label */
					__( 'Results link sent to %1$d %2$s.', 'photo-competition-manager' ),
					$sent_count,
					$audience_label
				),
				'updated'
			);

			$this->redirect_with_settings_errors( $redirect_url );
			return;
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
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_template( 'admin/results/notice-no-competitions.php' );
			return;
		}

		// Default to first competition if none selected.
		if ( 0 === $competition_id ) {
			$competition_id = (int) $competitions[0]->id;
		}

		$competition = $this->competitions->find( $competition_id );
		if ( ! $competition ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_template( 'admin/results/notice-competition-not-found.php' );
			return;
		}

		// Check if we're viewing image details.
		$image_id = isset( $_GET['image'] ) ? absint( wp_unslash( $_GET['image'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $image_id > 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_image_details( $image_id, $competition );
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_overview( $competition, $competitions );
	}

	/**
	 * Render overview page.
	 *
	 * @param object             $competition   Selected competition.
	 * @param array<int, object> $competitions  All competitions.
	 * @return string
	 */
	private function render_overview( object $competition, array $competitions ): string {
		$settings   = Competition_Settings::parse( $competition->settings );
		$categories = Competition_Settings::get_categories( $settings );
		$grades     = Competition_Settings::get_grades( $settings );

		$summary = $this->analytics->get_competition_summary( (int) $competition->id );

		// Get selected category or default to first.
		$selected_category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $selected_category ) && ! empty( $categories ) ) {
			$selected_category = $categories[0]['slug'] ?? '';
		}

		// Competition selector options.
		$competition_options = array();
		foreach ( $competitions as $comp ) {
			$url = add_query_arg(
				array(
					'page'        => 'photo-competition-manager-results',
					'competition' => (int) $comp->id,
				),
				admin_url( 'admin.php' )
			);

			$competition_options[] = array(
				'url'      => $url,
				'selected' => ( (int) $comp->id === (int) $competition->id ),
				'title'    => $comp->title,
			);
		}

		// Summary cards.
		$summary_cards_html  = $this->render_summary_card( __( 'Total Images', 'photo-competition-manager' ), number_format( $summary['total_images'], 0 ), 'dashicons-format-image' );
		$summary_cards_html .= $this->render_summary_card( __( 'Total Votes', 'photo-competition-manager' ), number_format( $summary['total_votes'], 0 ), 'dashicons-yes' );
		$summary_cards_html .= $this->render_summary_card( __( 'Participants', 'photo-competition-manager' ), number_format( $summary['total_members'], 0 ), 'dashicons-groups' );
		$summary_cards_html .= $this->render_summary_card( __( 'Avg Score', 'photo-competition-manager' ), number_format( (float) $summary['average_score'], 2 ), 'dashicons-star-filled' );

		// Category tabs.
		$category_tabs = array();
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

			$category_tabs[] = array(
				'url'    => $cat_url,
				'label'  => $cat_label,
				'active' => ( $cat_slug === $selected_category ),
				'count'  => $summary['categories'][ $cat_slug ]['images'] ?? 0,
			);
		}

		// Category breakdown + results table.
		$breakdown          = array();
		$results_table_html = '';
		if ( ! empty( $selected_category ) ) {
			$breakdown = $this->analytics->get_category_breakdown( (int) $competition->id, $selected_category );

			$results_table_html = $this->render_results_table( (int) $competition->id, $selected_category, $grades );
		}

		// Action buttons.
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

		// Share results section.
		$share_hash   = $competition->share_hash ?? '';
		$results_page = $settings['urls']['results_page'] ?? '';

		$share_url          = '';
		$send_committee_url = '';
		$send_all_url       = '';

		if ( ! empty( $share_hash ) && ! empty( $results_page ) ) {
			$share_url = add_query_arg( 'share', $share_hash, $results_page );

			$send_committee_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager-results',
						'action'      => 'send_results_committee',
						'competition' => (int) $competition->id,
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_send_results_committee_' . (int) $competition->id
			);

			$send_all_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager-results',
						'action'      => 'send_results_all',
						'competition' => (int) $competition->id,
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_send_results_all_' . (int) $competition->id
			);
		}

		return $this->render_template(
			'admin/results/overview.php',
			array(
				'competition_options' => $competition_options,
				'summary_cards_html'  => $summary_cards_html,
				'category_tabs'       => $category_tabs,
				'selected_category'   => $selected_category,
				'breakdown'           => $breakdown,
				'results_table_html'  => $results_table_html,
				'recalculate_url'     => $recalculate_url,
				'export_url'          => $export_url,
				'email_url'           => $email_url,
				'share_hash'          => $share_hash,
				'results_page'        => $results_page,
				'share_url'           => $share_url,
				'send_committee_url'  => $send_committee_url,
				'send_all_url'        => $send_all_url,
			)
		);
	}

	/**
	 * Render a summary card.
	 *
	 * @param string $label Label text.
	 * @param mixed  $value Value to display.
	 * @param string $icon  Dashicon class.
	 * @return string
	 */
	private function render_summary_card( string $label, $value, string $icon ): string {
		return $this->render_template(
			'admin/results/summary-card.php',
			array(
				'label' => $label,
				'value' => $value,
				'icon'  => $icon,
			)
		);
	}

	/**
	 * Render results table grouped by grade.
	 *
	 * @param int               $competition_id Competition ID.
	 * @param string            $category       Category slug.
	 * @param array<int, array> $grades         Grade definitions.
	 * @return string
	 */
	private function render_results_table( int $competition_id, string $category, array $grades ): string {
		$results        = $this->calculator->get_results( $competition_id, $category );
		$members_lookup = array();

		// Build members lookup.
		$all_members = $this->members->all( 10000, false );
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

		$grade_tables = array();

		foreach ( $grades as $grade ) {
			$grade_slug  = $grade['slug'] ?? '';
			$grade_label = $grade['label'] ?? $grade_slug;

			$grade_results = $results_by_grade[ $grade_slug ] ?? array();

			if ( empty( $grade_results ) ) {
				continue;
			}

			// Assign ranks with tie handling (dense ranking).
			$rank           = 0;
			$previous_score = null;
			$rows           = array();

			foreach ( $grade_results as $result ) {
				// Advance rank only when score changes.
				if ( null === $previous_score || (int) $result->total_score !== $previous_score ) {
					++$rank;
					$previous_score = (int) $result->total_score;
				}

				$member    = $members_lookup[ $result->member_id ] ?? null;
				$image_url = $this->get_image_url( $competition_id, $category, $result->filename );

				$detail_url = add_query_arg(
					array(
						'page'        => 'photo-competition-manager-results',
						'competition' => $competition_id,
						'category'    => rawurlencode( $category ),
						'image'       => (int) $result->id,
					),
					admin_url( 'admin.php' )
				);

				$rows[] = array(
					'rank'        => $rank,
					'image_url'   => $image_url,
					'member_name' => $member ? $member->name : null,
					'total_score' => $result->total_score,
					'vote_count'  => $result->vote_count,
					'detail_url'  => $detail_url,
				);
			}

			$grade_tables[] = array(
				'label' => $grade_label,
				'rows'  => $rows,
			);
		}

		return $this->render_template( 'admin/results/results-table.php', array( 'grade_tables' => $grade_tables ) );
	}

	/**
	 * Render image details page.
	 *
	 * @param int    $image_id    Image ID.
	 * @param object $competition Competition object.
	 * @return string
	 */
	private function render_image_details( int $image_id, object $competition ): string {
		$details = $this->analytics->get_image_details( $image_id );

		if ( ! $details['image'] ) {
			return $this->render_template( 'admin/results/notice-image-not-found.php' );
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

		$image_url = $this->get_image_url( (int) $competition->id, $category, $image->filename );

		$vote_rows = array();
		foreach ( $votes as $vote ) {
			$voter_name = $vote->voter_name ? $vote->voter_name : 'Token #' . $vote->voting_token_id;

			$vote_rows[] = array(
				'voter_name' => $voter_name,
				'score'      => $vote->score,
				'created_at' => $this->format_datetime( $vote->created_at ),
			);
		}

		return $this->render_template(
			'admin/results/image-details.php',
			array(
				'back_url'       => $back_url,
				'image_url'      => $image_url,
				'member_name'    => $member ? $member->name : __( 'Unknown', 'photo-competition-manager' ),
				'member_email'   => ( $member && ! empty( $member->email ) ) ? $member->email : '',
				'member_grade'   => $member ? $member->grade : '',
				'has_member'     => (bool) $member,
				'category_label' => $category_label,
				'image_number'   => $image->random_number,
				'statistics'     => $statistics,
				'vote_rows'      => $vote_rows,
			)
		);
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
		$all_members    = $this->members->all( 10000, false );
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
							number_format( $result->total_score, 0 ),
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
		$thumb_name = Image_Processor::get_thumbnail_filename( $filename );
		$thumb_path = $folder_path . $thumb_name;

		// Return thumbnail URL if it exists, otherwise null.
		return file_exists( $thumb_path ) ? $folder_url . rawurlencode( $thumb_name ) : null;
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
