<?php
/**
 * Results controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Date_Formatting;
use PhotoCompetitionManager\Admin\Traits\Email_Job_Notice;
use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Email_Job_Manager;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Service\Results_Analytics;
use PhotoCompetitionManager\Service\Results_Ranking;
use PhotoCompetitionManager\Support\Competition_Settings;
use function PhotoCompetitionManager\Support\sanitize_csv_row;

/**
 * Manage results dashboard page.
 *
 * @since 0.1.0
 */
class Results_Controller {

	use Date_Formatting;
	use Email_Job_Notice;
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
	 * Results ranking service.
	 *
	 * @var Results_Ranking
	 */
	private $ranking;

	/**
	 * Email job manager.
	 *
	 * @var Email_Job_Manager
	 */
	private $email_job_manager;

	/**
	 * Entries module.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository $competitions      Competitions repository.
	 * @param Images_Repository       $images            Images repository.
	 * @param Members_Repository      $members           Members repository.
	 * @param Votes_Repository        $votes             Votes repository.
	 * @param Results_Analytics       $analytics         Results analytics service.
	 * @param Results_Ranking         $ranking           Results ranking service.
	 * @param Email_Job_Manager       $email_job_manager Email job manager.
	 * @param Entries|null            $entries           Entries module.
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Images_Repository $images,
		Members_Repository $members,
		Votes_Repository $votes,
		Results_Analytics $analytics,
		Results_Ranking $ranking,
		Email_Job_Manager $email_job_manager,
		?Entries $entries = null
	) {
		$this->competitions      = $competitions;
		$this->images            = $images;
		$this->members           = $members;
		$this->votes             = $votes;
		$this->analytics         = $analytics;
		$this->ranking           = $ranking;
		$this->email_job_manager = $email_job_manager;
		$this->entries           = $entries ?? new Entries( $competitions, $images, $members );
		$this->workflow          = new Competition_Workflow( $competitions, $images, $votes, null, $ranking );
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

		if ( 'email_results' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

			check_admin_referer( 'photo_competition_email_results_' . $competition_id );

			$redirect_url = add_query_arg(
				array(
					'page'        => 'photo-competition-manager-results',
					'competition' => $competition_id,
				),
				admin_url( 'admin.php' )
			);

			// Members are told their positions only once they're read from the record.
			$competition = $this->competitions->find( $competition_id );
			if ( $competition && ! $this->workflow->reads_recorded_results( $competition ) ) {
				add_settings_error(
					'photo_competition_results',
					'results_not_published',
					__( 'Results can be emailed once they\'re shown, or once the competition has closed, so every member is told the positions that are recorded.', 'photo-competition-manager' ),
					'error'
				);

				$this->redirect_with_settings_errors( $redirect_url );
			}

			// Queue a background job for email sending.
			$job_id = $this->email_job_manager->queue_results( $competition_id );

			if ( ! $job_id ) {
				add_settings_error(
					'photo_competition_results',
					'email_job_failed',
					__( 'Failed to create email job. No members found with submissions.', 'photo-competition-manager' ),
					'error'
				);

				$this->redirect_with_settings_errors( $redirect_url );
			}

			// Redirect to results page with job status.
			wp_safe_redirect(
				add_query_arg(
					array(
						'job_id' => $job_id,
						'status' => 'processing',
					),
					$redirect_url
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
			}

			// The committee checks results before they're public, so only their link goes early.
			if ( 'send_results_all' === $action && ! $this->workflow->reads_recorded_results( $competition ) ) {
				add_settings_error(
					'photo_competition_results',
					'results_not_published',
					__( 'The results link can be sent to every member once results are shown, or once the competition has closed. The committee can be sent it before then.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $redirect_url );
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
			}

			$results_page_url = Competition_Settings::page_url( 'results_page', $competition );
			if ( '' === $results_page_url ) {
				add_settings_error(
					'photo_competition_results',
					'no_results_page',
					__( 'No results page is set. Set the results page in Settings, or publish a page with the [competition_results] shortcode.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $redirect_url );
			}

			$share_url = add_query_arg( 'share', $share_hash, $results_page_url );

			if ( 'send_results_committee' === $action ) {
				$recipients = $this->members->find_committee_members();
			} else {
				$recipients = $this->members->find_active_members();
			}

			$member_ids = array();
			foreach ( $recipients as $member ) {
				if ( ! empty( $member->email ) ) {
					$member_ids[] = (int) $member->id;
				}
			}

			$job_id = $this->email_job_manager->queue(
				'results_published',
				$competition_id,
				$member_ids,
				array( 'share_url' => $share_url )
			);

			if ( ! $job_id ) {
				add_settings_error(
					'photo_competition_results',
					'no_recipients',
					__( 'No members with an email address to send the results link to.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $redirect_url );
			}

			wp_safe_redirect( add_query_arg( 'job_id', $job_id, $redirect_url ) );
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
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_email_job_notice( $this->email_job_manager );

		// Get selected competition.
		$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$competitions = $this->competitions->all( 100, false, false );

		if ( empty( $competitions ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_template( 'admin/results/notice-no-competitions.php' );
			return;
		}

		// Default to the current competition, then the one that opened most
		// recently, so next month's competition, created early, doesn't win.
		$competition = 0 === $competition_id
			? ( $this->competitions->find_current_or_latest_opened() ?? $competitions[0] )
			: $this->competitions->find( $competition_id );
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

		$summary = $this->analytics->get_competition_summary( (int) $competition->id );

		// Get selected category or default to first.
		$selected_category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $selected_category ) && ! empty( $categories ) ) {
			$selected_category = $categories[0]['slug'] ?? '';
		}

		// Rank every category: the ungraded warning covers the whole competition, not just the open tab.
		$rankings = array();
		foreach ( $categories as $category ) {
			$cat_slug              = $category['slug'] ?? '';
			$rankings[ $cat_slug ] = $this->ranking->rank_category( (int) $competition->id, $cat_slug );
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

			$groups             = $rankings[ $selected_category ] ?? $this->ranking->rank_category( (int) $competition->id, $selected_category );
			$results_table_html = $this->render_results_table( $competition, $selected_category, $groups );
		}

		// Action buttons.
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
		$results_page = Competition_Settings::page_url( 'results_page', $competition );

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
				'competition_options'  => $competition_options,
				'summary_cards_html'   => $summary_cards_html,
				'ungraded_notice_html' => $this->render_ungraded_notice( $categories, $rankings ),
				'category_tabs'        => $category_tabs,
				'selected_category'    => $selected_category,
				'breakdown'            => $breakdown,
				'results_table_html'   => $results_table_html,
				'export_url'           => $export_url,
				'email_url'            => $email_url,
				'share_hash'           => $share_hash,
				'results_page'         => $results_page,
				'share_url'            => $share_url,
				'send_committee_url'   => $send_committee_url,
				'send_all_url'         => $send_all_url,
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
	 * Render the warning listing ungraded entries in any category, or '' when there are none.
	 *
	 * @param array<int, array>    $categories Category definitions.
	 * @param array<string, array> $rankings   Category slug => Results_Ranking::rank_category() groups.
	 * @return string
	 */
	private function render_ungraded_notice( array $categories, array $rankings ): string {
		$members = array();
		$orphans = array();

		foreach ( $categories as $category ) {
			foreach ( $rankings[ $category['slug'] ?? '' ] as $group ) {
				if ( ! $group['ungraded'] ) {
					continue;
				}

				foreach ( $group['entries'] as $entry ) {
					// A recorded entry removed since has nothing left to fix.
					if ( ! $entry['image'] ) {
						continue;
					}

					if ( $entry['member'] ) {
						$members[ (int) $entry['member']->id ] = array(
							'name'  => $entry['member']->name,
							'email' => $entry['member']->email,
						);
					} else {
						$orphans[] = array(
							'image_number' => (int) $entry['image']->random_number,
							'category'     => $category['label'] ?? $category['slug'] ?? '',
						);
					}
				}
			}
		}

		if ( empty( $members ) && empty( $orphans ) ) {
			return '';
		}

		return $this->render_template(
			'admin/results/notice-ungraded-entries.php',
			array(
				'members' => array_values( $members ),
				'orphans' => $orphans,
			)
		);
	}

	/**
	 * Render results table grouped by grade.
	 *
	 * @param object            $competition Competition record.
	 * @param string            $category    Category slug.
	 * @param array<int, array> $groups      The category's Results_Ranking::rank_category() groups.
	 * @return string
	 */
	private function render_results_table( object $competition, string $category, array $groups ): string {
		$grade_tables = array();

		foreach ( $groups as $group ) {
			$rows = array();

			foreach ( $group['entries'] as $entry ) {
				$image  = $entry['image'];
				$member = $entry['member'];

				// A recorded entry removed since has no image or details.
				$detail_url = $image ? add_query_arg(
					array(
						'page'        => 'photo-competition-manager-results',
						'competition' => (int) $competition->id,
						'category'    => rawurlencode( $category ),
						'image'       => (int) $image->id,
					),
					admin_url( 'admin.php' )
				) : '';

				$rows[] = array(
					'rank'        => $entry['position'],
					'image_url'   => $image ? $this->entries->urls( $competition, $image )['thumb'] : '',
					'member_name' => $member ? $member->name : null,
					'total_score' => $entry['total_score'],
					'vote_count'  => $entry['vote_count'],
					'detail_url'  => $detail_url,
					'recorded'    => $entry['recorded'],
				);
			}

			$grade_tables[] = array(
				'label' => $group['label'],
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

		$image_url = $this->entries->urls( $competition, $image )['thumb'];

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

		$filename = 'results-' . sanitize_title( $competition->slug ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$output = fopen( 'php://output', 'w' );

		foreach ( $this->get_export_rows( $competition ) as $row ) {
			fputcsv( $output, sanitize_csv_row( $row ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $output );
	}

	/**
	 * Build the results CSV rows, header first.
	 *
	 * Rows are ordered by grade, then category, then rank; the rank restarts
	 * for each grade within each category, matching the results screen.
	 *
	 * @since 0.3.0
	 *
	 * @param object $competition Competition row.
	 * @return array<int, array<int, string|int>>
	 */
	public function get_export_rows( object $competition ): array {
		$settings   = Competition_Settings::parse( $competition->settings );
		$categories = Competition_Settings::get_categories( $settings );

		$rows = array(
			array(
				'Competition',
				'Grade',
				'Category',
				'Rank',
				'Image Number',
				'Member Name',
				'Member Email',
				'Score',
				'Vote Count',
				'Filename',
			),
		);

		// Bucket rows by grade (configured order, ungraded last) so grade is the outer grouping.
		$rows_by_grade     = array_fill_keys( array_column( Competition_Settings::club_grades(), 'slug' ), array() );
		$rows_by_grade[''] = array();

		foreach ( $categories as $category ) {
			$category_slug  = $category['slug'] ?? '';
			$category_label = $category['label'] ?? $category_slug;

			foreach ( $this->ranking->rank_category( (int) $competition->id, $category_slug ) as $group ) {
				foreach ( $group['entries'] as $entry ) {
					$image  = $entry['image'];
					$member = $entry['member'];

					$rows_by_grade[ $group['slug'] ][] = array(
						$competition->title,
						$group['label'],
						$category_label,
						$entry['position'],
						$image ? $image->random_number : '',
						$this->export_member_name( $entry ),
						$member ? $member->email : '',
						number_format( $entry['total_score'], 0 ),
						$entry['vote_count'],
						$image ? $image->filename : '',
					);
				}
			}
		}

		foreach ( $rows_by_grade as $grade_rows ) {
			array_push( $rows, ...$grade_rows );
		}

		return $rows;
	}

	/**
	 * The member name an exported entry gets. A recorded entry whose member
	 * was deleted is a former member's. Before results are recorded, a
	 * missing member is a data error, and the name is left blank.
	 *
	 * @since 0.4.0
	 *
	 * @param array{member: object|null, recorded: bool} $entry A Results_Ranking::rank_category() entry.
	 * @return string
	 */
	private function export_member_name( array $entry ): string {
		if ( $entry['member'] ) {
			return (string) $entry['member']->name;
		}

		return $entry['recorded'] ? __( 'Former member', 'photo-competition-manager' ) : '';
	}
}
