<?php
/**
 * Voting controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Admin_Action_Dispatcher;
use PhotoCompetitionManager\Admin\Traits\Date_Formatting;
use PhotoCompetitionManager\Admin\Traits\Email_Job_Notice;
use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Email_Job_Manager;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Manage voting controls page.
 *
 * @since 0.1.0
 */
class Voting_Controller {

	use Admin_Action_Dispatcher;
	use Date_Formatting;
	use Email_Job_Notice;
	use Form_Rendering;

	/**
	 * The step number the page shows for each voting stage.
	 */
	const STEP_STAGES = array(
		1 => Competition_Workflow::STAGE_NOT_STARTED,
		2 => Competition_Workflow::STAGE_PREVIEWED,
		3 => Competition_Workflow::STAGE_VOTING,
		4 => Competition_Workflow::STAGE_SLIDESHOW_SHOWN,
		5 => Competition_Workflow::STAGE_CRITIQUE,
		6 => Competition_Workflow::STAGE_DONE,
	);

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
	 * Email job queue.
	 *
	 * @var Email_Job_Manager
	 */
	private $email_jobs;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository $competitions Competitions repository.
	 * @param Images_Repository       $images       Images repository.
	 * @param Members_Repository|null $members      Members repository.
	 * @param Email_Job_Manager|null  $email_jobs   Email job queue.
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Images_Repository $images,
		?Members_Repository $members = null,
		?Email_Job_Manager $email_jobs = null
	) {
		$this->competitions = $competitions;
		$this->workflow     = new Competition_Workflow( $this->competitions );
		$this->images       = $images;
		$this->members      = $members ?? new Members_Repository();
		$this->email_jobs   = $email_jobs ?? ( new \PhotoCompetitionManager\Dependencies() )->email_job_manager;
	}

	/**
	 * Register hooks for this controller.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'wp_ajax_photo_comp_advance_voting_step', array( $this, 'handle_advance_step' ) );
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

		$focus = $this->query_text( 'focus' );

		$this->dispatch_action(
			array(
				'open_category_voting'  => array(
					'nonce'  => fn() => 'photo_competition_open_voting_' . $this->query_int( 'competition' ) . '_' . $this->query_text( 'category' ),
					'handle' => fn() => $this->handle_open_category_voting( $focus ),
				),
				'close_category_voting' => array(
					'nonce'  => fn() => 'photo_competition_close_voting_' . $this->query_int( 'competition' ) . '_' . $this->query_text( 'category' ),
					'handle' => fn() => $this->handle_close_category_voting( $focus ),
				),
				'reset_category'        => array(
					'nonce'  => fn() => 'photo_competition_reset_category_' . $this->query_int( 'competition' ) . '_' . $this->query_text( 'category' ),
					'handle' => fn() => $this->handle_reset_category( $focus ),
				),
				'show_results'          => array(
					'nonce'  => fn() => 'photo_competition_show_results_' . $this->query_int( 'competition' ),
					'handle' => fn() => $this->handle_show_results( $focus ),
				),
				'hide_results'          => array(
					'nonce'  => fn() => 'photo_competition_hide_results_' . $this->query_int( 'competition' ),
					'handle' => fn() => $this->handle_hide_results( $focus ),
				),
			)
		);
	}

	/**
	 * Open voting for a single category and notify members.
	 *
	 * @param string $focus Focus-panel key to preserve across the redirect.
	 * @return void
	 */
	private function handle_open_category_voting( string $focus ): void {
		$competition_id = $this->query_int( 'competition' );
		$category_slug  = $this->query_text( 'category' );
		$competition    = $this->load_competition_or_fail( $competition_id );

		$this->finish_voting_update(
			$this->workflow->open_voting( $competition_id, $category_slug ),
			'voting_opened',
			__( 'Voting opened successfully.', 'photo-competition-manager' ),
			$focus,
			function () use ( $competition ) {
				return $this->queue_voting_opened_notifications( $competition );
			}
		);
	}

	/**
	 * Close voting for a single category.
	 *
	 * @param string $focus Focus-panel key to preserve across the redirect.
	 * @return void
	 */
	private function handle_close_category_voting( string $focus ): void {
		$competition_id = $this->query_int( 'competition' );
		$category_slug  = $this->query_text( 'category' );
		$this->load_competition_or_fail( $competition_id );

		$this->finish_voting_update(
			$this->workflow->close_voting( $competition_id, $category_slug ),
			'voting_closed',
			__( 'Voting closed successfully.', 'photo-competition-manager' ),
			$focus
		);
	}

	/**
	 * Reset a category back to step 1, optionally clearing its votes and tokens.
	 *
	 * @param string $focus Focus-panel key to preserve across the redirect.
	 * @return void
	 */
	private function handle_reset_category( string $focus ): void {
		$competition_id = $this->query_int( 'competition' );
		$category_slug  = $this->query_text( 'category' );
		$clear_votes    = 1 === $this->query_int( 'clear_votes' );
		$this->load_competition_or_fail( $competition_id );

		$message = $clear_votes
			? __( 'Category reset to step 1 and all votes cleared.', 'photo-competition-manager' )
			: __( 'Category reset to step 1. Existing votes were kept.', 'photo-competition-manager' );

		$this->finish_voting_update(
			$this->workflow->reset_category( $competition_id, $category_slug, $clear_votes ),
			'category_reset',
			$message,
			$focus
		);
	}

	/**
	 * Make competition results visible to the public.
	 *
	 * @param string $focus Focus-panel key to preserve across the redirect.
	 * @return void
	 */
	private function handle_show_results( string $focus ): void {
		$competition_id = $this->query_int( 'competition' );
		$this->load_competition_or_fail( $competition_id );

		$this->finish_voting_update(
			$this->workflow->publish_results( $competition_id ),
			'results_shown',
			__( 'Results are now visible to the public.', 'photo-competition-manager' ),
			$focus
		);
	}

	/**
	 * Hide competition results from the public.
	 *
	 * @param string $focus Focus-panel key to preserve across the redirect.
	 * @return void
	 */
	private function handle_hide_results( string $focus ): void {
		$competition_id = $this->query_int( 'competition' );
		$this->load_competition_or_fail( $competition_id );

		$this->finish_voting_update(
			$this->workflow->unpublish_results( $competition_id ),
			'results_hidden',
			__( 'Results are now hidden from the public.', 'photo-competition-manager' ),
			$focus
		);
	}

	/**
	 * Load a competition or add an error and redirect to the voting page.
	 *
	 * The redirect terminates the request, so callers can treat the return value
	 * as a guaranteed competition object.
	 *
	 * @param int $competition_id Competition ID.
	 * @return object Competition object.
	 */
	private function load_competition_or_fail( int $competition_id ): object {
		$competition = $this->find_actionable_competition( $competition_id );

		if ( is_wp_error( $competition ) ) {
			$this->fail_voting( $competition->get_error_code(), $competition->get_error_message() );
		}

		return $competition;
	}

	/**
	 * Find the competition a voting action may act on.
	 *
	 * Voting Controls only shows the current competition, so an action for
	 * any other comes from a stale tab or link. When no competition is
	 * current, the last one shown can still be finished off: it may have
	 * reached its close date while the page was open.
	 *
	 * @since 0.3.0
	 *
	 * @param int $competition_id Competition ID from the request.
	 * @return object|\WP_Error The competition, or 'competition_not_found' or 'competition_not_current'.
	 */
	private function find_actionable_competition( int $competition_id ) {
		$competition = $this->competitions->find( $competition_id );

		if ( ! $competition ) {
			return new \WP_Error( 'competition_not_found', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$current = $this->competitions->find_current_active();

		if ( $current && (int) $current->id !== (int) $competition->id ) {
			return new \WP_Error(
				'competition_not_current',
				__( 'This isn\'t the current competition. Reload Voting Controls.', 'photo-competition-manager' )
			);
		}

		return $competition;
	}

	/**
	 * Register an error and redirect to the plain voting page (no focus/anchor).
	 *
	 * @param string $code    Settings-error code.
	 * @param string $message Human-readable error message.
	 * @return void
	 */
	private function fail_voting( string $code, string $message ): void {
		add_settings_error( 'photo_competition_voting', $code, $message, 'error' );

		$this->redirect_with_settings_errors(
			add_query_arg(
				array( 'page' => 'photo-competition-manager-voting' ),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Register a workflow change's outcome, and redirect back to the category.
	 *
	 * A refusal's message is surfaced; on success the given success message
	 * is registered, the optional side-effect runs, and the request
	 * redirects to the voting page focused on the active category.
	 *
	 * @param true|\WP_Error $result          The workflow's answer.
	 * @param string         $success_code    Settings-error code for the success notice.
	 * @param string         $success_message Human-readable success message.
	 * @param string         $focus           Focus-panel key to preserve across the redirect.
	 * @param callable|null  $on_success      Optional side-effect to run only on success. If it
	 *                                        returns an email job ID, the page shows the job's progress.
	 * @return void
	 */
	private function finish_voting_update( $result, string $success_code, string $success_message, string $focus, ?callable $on_success = null ): void {
		$job_id = null;

		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'photo_competition_voting',
				$result->get_error_code(),
				$result->get_error_message(),
				'error'
			);
		} else {
			add_settings_error(
				'photo_competition_voting',
				$success_code,
				$success_message,
				'updated'
			);

			$job_id = $on_success ? $on_success() : null;
		}

		$redirect_args = array( 'page' => 'photo-competition-manager-voting' );
		if ( ! empty( $job_id ) ) {
			$redirect_args['job_id'] = $job_id;
		}
		if ( ! empty( $focus ) ) {
			$redirect_args['focus'] = $focus;
		}

		$this->redirect_with_settings_errors(
			add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . '#focus-panel'
		);
	}

	/**
	 * Render voting controls page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( 'photo_competition_voting' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_email_job_notice( $this->email_jobs );

		echo '<div class="wrap photo-comp-voting-controls">';
		echo '<h1>' . esc_html__( 'Voting Controls', 'photo-competition-manager' ) . '</h1>';

		// Act on the same competition as the public voting and upload pages.
		$active_competition = $this->competitions->find_current_active();

		if ( ! $active_competition ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_template( 'admin/voting/notice-no-open-competitions.php' );
			return;
		}

		// Competitions saved before only one could be open may still overlap.
		$open_competitions = $this->competitions->all_open();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_multiple_open_notice( $open_competitions, true );

		$images = $this->images->find_by_competition( (int) $active_competition->id );

		// Check every entrant has a grade from the club's list.
		$members_without_grades = $this->check_ungraded_entrants( $images );
		if ( ! empty( $members_without_grades ) ) {
			$notice_data = array( 'members_without_grades' => $members_without_grades );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_template( 'admin/voting/notice-members-without-grades.php', $notice_data );
			return; // Stop rendering the rest of the page.
		}

		$active_settings = Competition_Settings::parse( $active_competition->settings );
		$global_settings = Competition_Settings::global_settings();

		// Check that required pages are configured. A competition that doesn't
		// override the voting page has its urls.voting_page SET to '' (not
		// unset), so `??` would return that empty string and shadow the
		// populated global default. Use an empty-aware fallback instead.
		$voting_page = '';
		if ( ! empty( $active_settings['urls']['voting_page'] ) ) {
			$voting_page = $active_settings['urls']['voting_page'];
		} elseif ( ! empty( $global_settings['urls']['voting_page'] ) ) {
			$voting_page = $global_settings['urls']['voting_page'];
		}
		$results_page = $global_settings['urls']['results_page'] ?? '';
		if ( empty( $voting_page ) || empty( $results_page ) ) {
			$missing = array();
			if ( empty( $voting_page ) ) {
				$missing[] = __( 'Voting', 'photo-competition-manager' );
			}
			if ( empty( $results_page ) ) {
				$missing[] = __( 'Results', 'photo-competition-manager' );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_template( 'admin/voting/notice-missing-pages.php', array( 'missing' => $missing ) );
		}

		// Find the category accepting votes, checking the current competition
		// first, since that's the voting members can reach. Only one
		// category in the club can accept votes, but competitions saved
		// before that rule may still overlap.
		$voting_open_globally = false;
		$open_competition_id  = null;
		$open_category_slug   = null;
		$open_here            = $this->workflow->categories_accepting_votes( $active_competition );
		$open                 = ! empty( $open_here )
			? array(
				'competition' => $active_competition,
				'category'    => $open_here[0],
			)
			: $this->workflow->category_accepting_votes();

		if ( $open ) {
			$voting_open_globally = true;
			$open_competition_id  = (int) $open['competition']->id;
			$open_category_slug   = $open['category'];

			if ( $open_competition_id !== (int) $active_competition->id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
				echo $this->render_template( 'admin/voting/notice-voting-open-elsewhere.php', array( 'title' => $open['competition']->title ) );
			}
		}

		// Build list of the competition's categories with images.
		$image_counts   = array_count_values( wp_list_pluck( $images, 'category' ) );
		$all_categories = array();
		foreach ( Competition_Settings::get_categories( $active_settings ) as $cat ) {
			$cat_slug    = $cat['slug'] ?? '';
			$image_count = $image_counts[ $cat_slug ] ?? 0;

			if ( $image_count > 0 ) {
				$all_categories[] = array(
					'competition' => $active_competition,
					'settings'    => $active_settings,
					'category'    => $cat,
					'image_count' => $image_count,
					'key'         => $active_competition->id . '_' . $cat_slug,
				);
			}
		}

		if ( empty( $all_categories ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_template( 'admin/voting/notice-no-images.php' );
			return;
		}

		$step_of = array_flip( self::STEP_STAGES );
		foreach ( $all_categories as &$cat_data ) {
			$cat_data['current_step'] = $step_of[ $this->workflow->stage( $active_competition, $cat_data['category']['slug'] ?? '' ) ];
		}
		unset( $cat_data );

		// Determine which category is active.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display parameter.
		$selected_key = isset( $_GET['focus'] ) ? sanitize_text_field( wp_unslash( $_GET['focus'] ) ) : '';

		$active_category_data = null;

		// Try URL parameter first.
		if ( ! empty( $selected_key ) ) {
			foreach ( $all_categories as $cat_data ) {
				if ( $cat_data['key'] === $selected_key ) {
					$active_category_data = $cat_data;
					break;
				}
			}
		}

		// Try voting open category.
		if ( ! $active_category_data && (int) $active_competition->id === $open_competition_id ) {
			foreach ( $all_categories as $cat_data ) {
				if ( ( $cat_data['category']['slug'] ?? '' ) === $open_category_slug ) {
					$active_category_data = $cat_data;
					break;
				}
			}
		}

		// Fall back to first category.
		if ( ! $active_category_data ) {
			$active_category_data = $all_categories[0];
		}

		$current_key = $active_category_data['key'];

		// Get voting page URL.
		$voting_page_url = '';
		$comp_urls       = $active_settings['urls'] ?? array();
		if ( ! empty( $comp_urls['voting_page'] ) ) {
			$voting_page_url = $comp_urls['voting_page'];
		} elseif ( ! empty( $global_settings['urls']['voting_page'] ) ) {
			$voting_page_url = $global_settings['urls']['voting_page'];
		}

		// Render Competition Status Bar.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_competition_status_bar( $active_competition );

		// Render category tabs (attached to the workflow card postbox).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_category_tabs( $all_categories, $current_key, $voting_open_globally, $open_competition_id, $open_category_slug );

		// Check completion: all categories must have completed critique (step 6).
		$all_complete = true;
		foreach ( $all_categories as $cat_data ) {
			$step = $cat_data['current_step'] ?? 1;
			if ( $step < 6 ) {
				$all_complete = false;
				break;
			}
		}

		if ( $all_complete && ! $voting_open_globally ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_competition_complete( $active_competition, $all_categories, $global_settings );
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_workflow_steps( $active_category_data, $global_settings, count( $all_categories ) );
		}

		// Render Quick Actions.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_quick_actions( $voting_page_url, $global_settings, $active_settings );

		// Hidden meter type setting for slideshow.
		$meter_type = $active_settings['slideshow']['progress_meter_type'] ?? 'bar';
		echo '<input type="hidden" id="slideshow-meter-type" value="' . esc_attr( $meter_type ) . '" />';

		// Slideshow container (hidden by default).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_slideshow_container();

		echo '</div>';
	}

	/**
	 * Render slideshow container for admin voting controls page.
	 *
	 * @return string
	 */
	private function render_slideshow_container(): string {
		return $this->render_template( 'admin/voting/slideshow-container.php', array() );
	}



	/**
	 * Queue voting opened notifications to all active members.
	 *
	 * @param object $competition Competition object.
	 * @return string|null Job ID, or null if nothing was queued.
	 */
	private function queue_voting_opened_notifications( object $competition ): ?string {
		if ( ! ( new Email_Service() )->is_template_enabled( 'voting_opened' ) ) {
			return null;
		}

		// Get voting page URL from global settings.
		$global_settings = Competition_Settings::global_settings();
		$voting_page_url = $global_settings['urls']['voting_page'] ?? '';

		if ( empty( $voting_page_url ) ) {
			return null; // No voting page URL configured, skip sending.
		}

		$member_ids = array();
		foreach ( $this->members->all( 10000, true ) as $member ) {
			if ( ! empty( $member->email ) ) {
				$member_ids[] = (int) $member->id;
			}
		}

		// Format close date.
		$close_date = '';
		if ( ! empty( $competition->close_date ) ) {
			$close_date = wp_date( get_option( 'date_format' ), strtotime( $competition->close_date ) );
		}

		$job_id = $this->email_jobs->queue(
			'voting_opened',
			(int) $competition->id,
			$member_ids,
			array(
				'voting_page_url' => $voting_page_url,
				'close_date'      => $close_date,
			)
		);

		return $job_id ? $job_id : null;
	}

	/**
	 * Render the competition status bar with competition-wide controls.
	 *
	 * @param object $competition The active competition object.
	 * @return string
	 */
	private function render_competition_status_bar( object $competition ): string {
		$uploads_closed  = $this->workflow->uploads_closed( $competition );
		$results_visible = $this->workflow->results_published( $competition );
		$reopen_check    = $uploads_closed ? $this->workflow->can_reopen_uploads( $competition ) : true;

		// Build action URLs.
		$toggle_uploads_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'photo-competition-manager',
					'action'      => 'toggle_uploads',
					'competition' => (int) $competition->id,
					'ref_page'    => 'voting',
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_toggle_uploads_' . (int) $competition->id
		);

		$show_results_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'photo-competition-manager-voting',
					'action'      => 'show_results',
					'competition' => (int) $competition->id,
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_show_results_' . (int) $competition->id
		);

		$hide_results_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'photo-competition-manager-voting',
					'action'      => 'hide_results',
					'competition' => (int) $competition->id,
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_hide_results_' . (int) $competition->id
		);

		return $this->render_template(
			'admin/voting/competition-status-bar.php',
			array(
				'competition'        => $competition,
				'uploads_closed'     => $uploads_closed,
				'reopen_refusal'     => is_wp_error( $reopen_check ) ? $reopen_check->get_error_message() : '',
				'results_visible'    => $results_visible,
				'toggle_uploads_url' => $toggle_uploads_url,
				'show_results_url'   => $show_results_url,
				'hide_results_url'   => $hide_results_url,
			)
		);
	}

	/**
	 * Render category tabs for switching between categories.
	 *
	 * Uses WordPress nav-tab-wrapper styling. Skips rendering if only 1 category.
	 *
	 * @param array       $all_categories       Array of category data with competition info.
	 * @param string      $current_key          Currently active category key.
	 * @param bool        $voting_open_globally Whether voting is open globally.
	 * @param int|null    $open_competition_id  Competition ID with voting open.
	 * @param string|null $open_category_slug   Category slug with voting open.
	 * @return string
	 */
	private function render_category_tabs( array $all_categories, string $current_key, bool $voting_open_globally, ?int $open_competition_id, ?string $open_category_slug ): string {
		if ( count( $all_categories ) < 2 ) {
			return ''; // Single category: no tabs.
		}
		return $this->render_template(
			'admin/voting/category-tabs.php',
			array(
				'all_categories'       => $all_categories,
				'current_key'          => $current_key,
				'voting_open_globally' => $voting_open_globally,
				'open_competition_id'  => $open_competition_id,
				'open_category_slug'   => $open_category_slug,
			)
		);
	}

	/**
	 * Render the 5-step workflow for a category inside a postbox.
	 *
	 * Replaces the old render_category_control_panel() method.
	 *
	 * @param array $category_data    Category data array with competition, settings, etc.
	 * @param array $global_settings  Global default settings.
	 * @param int   $total_categories Total number of categories (for single-category heading).
	 * @return string
	 */
	private function render_workflow_steps( array $category_data, array $global_settings, int $total_categories = 1 ): string {
		$competition    = $category_data['competition'];
		$category       = $category_data['category'];
		$image_count    = $category_data['image_count'];
		$category_slug  = $category['slug'] ?? '';
		$category_label = $category['label'] ?? '';
		$current_step   = $category_data['current_step'] ?? 1;
		$comp_id        = (int) $competition->id;

		// Duration defaults from global settings.
		$preview_duration  = $global_settings['slideshow']['preview_duration'] ?? 10;
		$voting_duration   = $global_settings['slideshow']['voting_duration'] ?? 15;
		$critique_duration = $global_settings['slideshow']['critique_duration'] ?? 0;

		// Why the workflow can't start, and why Open Voting is disabled.
		// Only the previewed step shows the Open Voting button.
		$ready_check      = $this->workflow->can_start_voting( $competition );
		$prereq_refusal   = is_wp_error( $ready_check ) ? $ready_check->get_error_message() : '';
		$open_check       = '' === $prereq_refusal && Competition_Workflow::STAGE_PREVIEWED === self::STEP_STAGES[ $current_step ]
			? $this->workflow->can_open_voting( $competition, $category_slug )
			: true;
		$open_voting_hint = is_wp_error( $open_check ) ? $open_check->get_error_message() : '';

		// Build action URLs for Open/Close voting.
		$focus_args = array(
			'page'  => 'photo-competition-manager-voting',
			'focus' => $comp_id . '_' . $category_slug,
		);

		$open_voting_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					$focus_args,
					array(
						'action'      => 'open_category_voting',
						'competition' => $comp_id,
						'category'    => $category_slug,
					)
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_open_voting_' . $comp_id . '_' . $category_slug
		);

		$close_voting_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					$focus_args,
					array(
						'action'      => 'close_category_voting',
						'competition' => $comp_id,
						'category'    => $category_slug,
					)
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_close_voting_' . $comp_id . '_' . $category_slug
		);

		$reset_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					$focus_args,
					array(
						'action'      => 'reset_category',
						'competition' => $comp_id,
						'category'    => $category_slug,
					)
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_reset_category_' . $comp_id . '_' . $category_slug
		);

		$voting_open_here = $this->workflow->is_accepting_votes( $competition, $category_slug );

		$steps = array(
			1 => array(
				'label'       => __( 'Preview Slideshow', 'photo-competition-manager' ),
				'description' => __( 'Show images to the room before opening voting', 'photo-competition-manager' ),
				'type'        => 'slideshow',
				'duration'    => $preview_duration,
				'optional'    => true,
			),
			2 => array(
				'label'       => __( 'Open Voting', 'photo-competition-manager' ),
				'description' => __( 'Members vote on their devices', 'photo-competition-manager' ),
				'type'        => 'voting_open',
				'optional'    => false,
			),
			3 => array(
				'label'       => __( 'Show Slideshow', 'photo-competition-manager' ),
				'description' => __( 'Display images on projector while members vote on their phones', 'photo-competition-manager' ),
				'type'        => 'slideshow',
				'duration'    => $voting_duration,
				'optional'    => true,
			),
			4 => array(
				'label'       => __( 'Close Voting', 'photo-competition-manager' ),
				'description' => __( 'Lock in votes for this category', 'photo-competition-manager' ),
				'type'        => 'voting_close',
				'optional'    => false,
			),
			5 => array(
				'label'       => __( 'Critique', 'photo-competition-manager' ),
				'description' => __( 'Manual slideshow for discussion', 'photo-competition-manager' ),
				'type'        => 'slideshow',
				'duration'    => $critique_duration,
				'optional'    => true,
			),
		);

		return $this->render_template(
			'admin/voting/workflow-steps.php',
			array(
				'competition'      => $competition,
				'category_slug'    => $category_slug,
				'category_label'   => $category_label,
				'image_count'      => $image_count,
				'current_step'     => $current_step,
				'comp_id'          => $comp_id,
				'prereq_refusal'   => $prereq_refusal,
				'total_categories' => $total_categories,
				'open_voting_hint' => $open_voting_hint,
				'voting_open_here' => $voting_open_here,
				'open_voting_url'  => $open_voting_url,
				'close_voting_url' => $close_voting_url,
				'reset_url'        => $reset_url,
				'steps'            => $steps,
			)
		);
	}

	/**
	 * Render collapsible quick actions bar.
	 *
	 * @param string $voting_page_url    The voting page URL for QR code.
	 * @param array  $settings           Global settings.
	 * @param array  $competition_settings Active competition settings.
	 * @return string
	 */
	private function render_quick_actions( string $voting_page_url, array $settings, array $competition_settings = array() ): string {
		$results_url = $settings['urls']['results_page'] ?? '';
		$top3_url    = $settings['urls']['top3_page'] ?? '';

		// Get voting password if it's stored as plaintext (not a legacy hash).
		$voting_password = '';
		$raw_password    = $competition_settings['voting']['password'] ?? '';
		if ( '' !== $raw_password && ! preg_match( '/^\$P\$|\$wp\$/', $raw_password ) ) {
			$voting_password = $raw_password;
		}
		return $this->render_template(
			'admin/voting/quick-actions.php',
			array(
				'voting_page_url' => $voting_page_url,
				'results_url'     => $results_url,
				'top3_url'        => $top3_url,
				'voting_password' => $voting_password,
			)
		);
	}

	/**
	 * Render competition complete panel when all categories have been voted.
	 *
	 * Uses WP postbox styling with two duration text inputs replacing the 7-button presets.
	 *
	 * @param object $competition       Competition object.
	 * @param array  $all_categories    All category data.
	 * @param array  $global_settings   Global settings.
	 * @return string
	 */
	private function render_competition_complete( object $competition, array $all_categories, array $global_settings ): string {
		$results_visible = $this->workflow->results_published( $competition );
		$results_url     = $global_settings['urls']['results_page'] ?? '';
		$top3_url        = $global_settings['urls']['top3_page'] ?? '';

		// Duration defaults for replay.
		$slideshow_replay_duration = $global_settings['slideshow']['voting_duration'] ?? 15;
		$critique_replay_duration  = $global_settings['slideshow']['critique_duration'] ?? 0;

		$show_results_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'photo-competition-manager-voting',
					'action'      => 'show_results',
					'competition' => (int) $competition->id,
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_show_results_' . (int) $competition->id
		);

		return $this->render_template(
			'admin/voting/competition-complete.php',
			array(
				'competition'               => $competition,
				'all_categories'            => $all_categories,
				'results_visible'           => $results_visible,
				'results_url'               => $results_url,
				'top3_url'                  => $top3_url,
				'slideshow_replay_duration' => $slideshow_replay_duration,
				'critique_replay_duration'  => $critique_replay_duration,
				'show_results_url'          => $show_results_url,
			)
		);
	}

	/**
	 * Check for entrants without a grade from the club's list.
	 *
	 * @param array<int, object> $images The competition's images.
	 * @return array Member info keyed by member ID: name, email, grade (as
	 *               stored, '' for none) and image_count.
	 */
	private function check_ungraded_entrants( array $images ): array {
		$members_without_grades = array();

		if ( empty( $images ) ) {
			return $members_without_grades;
		}

		// Get unique member IDs from images.
		$member_ids = array_unique( array_map( fn( $img ) => (int) $img->member_id, $images ) );

		foreach ( $this->members->find_many( $member_ids ) as $member_id => $member ) {
			if ( ! Competition_Settings::is_club_grade( (string) $member->grade ) ) {
				// Count images for this member.
				$image_count = count( array_filter( $images, fn( $img ) => (int) $img->member_id === $member_id ) );

				$members_without_grades[ $member_id ] = array(
					'name'        => $member->name,
					'email'       => $member->email,
					'grade'       => (string) $member->grade,
					'image_count' => $image_count,
				);
			}
		}

		return $members_without_grades;
	}

	/**
	 * AJAX handler for advancing the voting workflow step.
	 *
	 * @return void
	 */
	public function handle_advance_step(): void {
		check_ajax_referer( 'photo_comp_voting_step', '_wpnonce' );

		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'photo-competition-manager' ) ), 403 );
		}

		$competition_id = isset( $_POST['competition_id'] ) ? absint( wp_unslash( $_POST['competition_id'] ) ) : 0;
		$category_slug  = isset( $_POST['category_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['category_slug'] ) ) : '';
		$step           = isset( $_POST['step'] ) ? absint( wp_unslash( $_POST['step'] ) ) : 0;

		if ( ! $competition_id || '' === $category_slug || $step < 1 || $step > 6 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'photo-competition-manager' ) ) );
		}

		$competition = $this->find_actionable_competition( $competition_id );
		if ( is_wp_error( $competition ) ) {
			wp_send_json_error( array( 'message' => $competition->get_error_message() ) );
		}

		$result = $this->workflow->advance( $competition_id, $category_slug, self::STEP_STAGES[ $step ] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'step' => $step ) );
	}
}
