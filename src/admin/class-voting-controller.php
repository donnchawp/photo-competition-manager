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
use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
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
	 * Constructor.
	 *
	 * @param Competitions_Repository $competitions Competitions repository.
	 * @param Images_Repository       $images       Images repository.
	 * @param Members_Repository|null $members      Members repository.
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Images_Repository $images,
		?Members_Repository $members = null
	) {
		$this->competitions = $competitions;
		$this->images       = $images;
		$this->members      = $members ?? new Members_Repository();
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
	 * Open voting for a single category.
	 *
	 * Enforces the global constraint that only one category may have voting open
	 * across all active competitions, then opens the requested category at step 3
	 * and notifies members.
	 *
	 * @param string $focus Focus-panel key to preserve across the redirect.
	 * @return void
	 */
	private function handle_open_category_voting( string $focus ): void {
		$competition_id = $this->query_int( 'competition' );
		$category_slug  = $this->query_text( 'category' );

		// Global constraint: no other ACTIVE competition may have a category open.
		$all_competitions = $this->competitions->all( 100, false, false );
		foreach ( $all_competitions as $comp ) {
			if ( ! $this->competitions->is_open( $comp ) ) {
				continue;
			}

			$comp_settings = Competition_Settings::parse( $comp->settings );
			if ( ! empty( Competition_Settings::get_open_voting_categories( $comp_settings ) ) ) {
				$this->fail_voting(
					'voting_already_open',
					__( 'Cannot open voting. Another category already has voting open. Close it first.', 'photo-competition-manager' )
				);
			}
		}

		$competition = $this->load_competition_or_fail( $competition_id );

		$settings                              = Competition_Settings::parse( $competition->settings );
		$settings['voting']['open_categories'] = array( $category_slug );
		$settings['voting']['category_steps'][ $category_slug ] = 3;

		$this->finish_voting_update(
			$competition_id,
			$settings,
			'voting_opened',
			__( 'Voting opened successfully.', 'photo-competition-manager' ),
			$focus,
			function () use ( $competition ) {
				$this->send_voting_opened_notifications( $competition );
			}
		);
	}

	/**
	 * Close voting for a single category.
	 *
	 * Clears the open category, advances it to step 5, and records it as voted.
	 *
	 * @param string $focus Focus-panel key to preserve across the redirect.
	 * @return void
	 */
	private function handle_close_category_voting( string $focus ): void {
		$competition_id = $this->query_int( 'competition' );
		$category_slug  = $this->query_text( 'category' );

		$competition = $this->load_competition_or_fail( $competition_id );

		$settings                              = Competition_Settings::parse( $competition->settings );
		$settings['voting']['open_categories'] = array();
		$settings['voting']['category_steps'][ $category_slug ] = 5;

		// Track voted categories - add this category to the list if not already there.
		$category_key     = $competition_id . '_' . $category_slug;
		$voted_categories = $settings['voting']['voted_categories'] ?? array();
		if ( ! in_array( $category_key, $voted_categories, true ) ) {
			$voted_categories[]                     = $category_key;
			$settings['voting']['voted_categories'] = $voted_categories;
		}

		$this->finish_voting_update(
			$competition_id,
			$settings,
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

		$competition = $this->load_competition_or_fail( $competition_id );

		$settings = Competition_Settings::parse( $competition->settings );

		// Close voting if currently open for this category.
		$open_categories = Competition_Settings::get_open_voting_categories( $settings );
		if ( in_array( $category_slug, $open_categories, true ) ) {
			$settings['voting']['open_categories'] = array();
		}

		// Reset step back to 1.
		$settings['voting']['category_steps'][ $category_slug ] = 1;

		// Remove from voted_categories if present.
		$category_key                           = $competition_id . '_' . $category_slug;
		$voted_categories                       = $settings['voting']['voted_categories'] ?? array();
		$voted_categories                       = array_values( array_diff( $voted_categories, array( $category_key ) ) );
		$settings['voting']['voted_categories'] = $voted_categories;

		$message = $clear_votes
			? __( 'Category reset to step 1 and all votes cleared.', 'photo-competition-manager' )
			: __( 'Category reset to step 1. Existing votes were kept.', 'photo-competition-manager' );

		$on_success = $clear_votes
			? function () use ( $competition_id, $category_slug ) {
				$votes_repo = new Votes_Repository();
				$votes_repo->delete_by_competition_and_category( $competition_id, $category_slug );

				$token_repo = new Voting_Token_Repository();
				$token_repo->delete_by_competition_and_category( $competition_id, $category_slug );
			}
			: null;

		$this->finish_voting_update(
			$competition_id,
			$settings,
			'category_reset',
			$message,
			$focus,
			$on_success
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

		$competition = $this->load_competition_or_fail( $competition_id );

		$settings                               = Competition_Settings::parse( $competition->settings );
		$settings['results']['results_visible'] = true;

		$this->finish_voting_update(
			$competition_id,
			$settings,
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

		$competition = $this->load_competition_or_fail( $competition_id );

		$settings                               = Competition_Settings::parse( $competition->settings );
		$settings['results']['results_visible'] = false;

		$this->finish_voting_update(
			$competition_id,
			$settings,
			'results_hidden',
			__( 'Results are now hidden from the public.', 'photo-competition-manager' ),
			$focus
		);
	}

	/**
	 * Load a competition or add a not-found error and redirect to the voting page.
	 *
	 * The redirect terminates the request, so callers can treat the return value
	 * as a guaranteed competition object.
	 *
	 * @param int $competition_id Competition ID.
	 * @return object Competition object.
	 */
	private function load_competition_or_fail( int $competition_id ): object {
		$competition = $this->competitions->find( $competition_id );

		if ( ! $competition ) {
			$this->fail_voting(
				'competition_not_found',
				__( 'Competition not found.', 'photo-competition-manager' )
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
	 * Persist settings, register the outcome, and redirect back to the category.
	 *
	 * On a repository error the error message is surfaced; on success the given
	 * success message is registered, the optional side-effect runs, and the
	 * request redirects to the voting page focused on the active category.
	 *
	 * @param int           $competition_id  Competition ID.
	 * @param array         $settings        Settings array to persist.
	 * @param string        $success_code    Settings-error code for the success notice.
	 * @param string        $success_message Human-readable success message.
	 * @param string        $focus           Focus-panel key to preserve across the redirect.
	 * @param callable|null $on_success      Optional side-effect to run only on success.
	 * @return void
	 */
	private function finish_voting_update( int $competition_id, array $settings, string $success_code, string $success_message, string $focus, ?callable $on_success = null ): void {
		$result = $this->competitions->update( $competition_id, array( 'settings' => $settings ) );

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

			if ( $on_success ) {
				$on_success();
			}
		}

		$redirect_args = array( 'page' => 'photo-competition-manager-voting' );
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

		echo '<div class="wrap photo-comp-voting-controls">';
		echo '<h1>' . esc_html__( 'Voting Controls', 'photo-competition-manager' ) . '</h1>';

		// Get all open competitions.
		$all_competitions  = $this->competitions->all( 100, false, false );
		$open_competitions = array_filter(
			$all_competitions,
			function ( $comp ) {
				return $this->competitions->is_open( $comp );
			}
		);

		if ( empty( $open_competitions ) ) {
			echo '<div class="notice notice-warning inline">';
			echo '<p>' . esc_html__( 'No open competitions found. Create a competition with open and close dates to enable voting controls.', 'photo-competition-manager' ) . '</p>';
			echo '</div>';
			echo '</div>';
			return;
		}

		// Check for members with submissions but no grades.
		$members_without_grades = $this->check_members_without_grades( $open_competitions );
		if ( ! empty( $members_without_grades ) ) {
			echo '<div class="notice notice-error">';
			echo '<p><strong>' . esc_html__( 'ERROR: Some members have submitted images but do not have grades assigned!', 'photo-competition-manager' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'The following members need grades assigned before voting can proceed. Results will not display correctly without grades.', 'photo-competition-manager' ) . '</p>';
			echo '<ul style="list-style: disc; margin-left: 20px;">';
			foreach ( $members_without_grades as $member_info ) {
				echo '<li>';
				echo esc_html( $member_info['name'] ) . ' (' . esc_html( $member_info['email'] ) . ')';
				$image_count = isset( $member_info['image_count'] ) ? (int) $member_info['image_count'] : 0;
				$image_text  = sprintf(
					/* translators: %s: number of images */
					_n( '%s image', '%s images', $image_count, 'photo-competition-manager' ),
					number_format_i18n( $image_count )
				);
				echo ' - ' . esc_html( $image_text );
				echo '</li>';
			}
			echo '</ul>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager-members' ) ) . '" class="button button-primary">' . esc_html__( 'Go to Members Page to Assign Grades', 'photo-competition-manager' ) . '</a></p>';
			echo '</div>';
			echo '</div>'; // Close wrap div.
			return; // Stop rendering the rest of the page.
		}

		// Get the first open competition (there should only be one).
		$active_competition = reset( $open_competitions );
		$active_settings    = Competition_Settings::parse( $active_competition->settings );
		$global_settings    = Competition_Settings::global_settings();

		// Check that required pages are configured.
		$voting_page  = $active_settings['urls']['voting_page'] ?? $global_settings['urls']['voting_page'] ?? '';
		$results_page = $global_settings['urls']['results_page'] ?? '';
		if ( empty( $voting_page ) || empty( $results_page ) ) {
			$missing = array();
			if ( empty( $voting_page ) ) {
				$missing[] = __( 'Voting', 'photo-competition-manager' );
			}
			if ( empty( $results_page ) ) {
				$missing[] = __( 'Results', 'photo-competition-manager' );
			}
			echo '<div class="notice notice-warning">';
			echo '<p>';
			printf(
				/* translators: %s: comma-separated list of missing page types */
				esc_html__( 'Missing pages: %s. Voting controls require these pages to be created.', 'photo-competition-manager' ),
				'<strong>' . esc_html( implode( ', ', $missing ) ) . '</strong>'
			);
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager-setup' ) ) . '">' . esc_html__( 'Run Setup Wizard', 'photo-competition-manager' ) . '</a>';
			echo ' | <a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager-settings' ) ) . '">' . esc_html__( 'Configure in Settings', 'photo-competition-manager' ) . '</a>';
			echo '</p>';
			echo '</div>';
		}

		// Check if any category has voting open globally.
		$voting_open_globally = false;
		$open_competition_id  = null;
		$open_category_slug   = null;

		foreach ( $open_competitions as $competition ) {
			$settings        = Competition_Settings::parse( $competition->settings );
			$open_categories = Competition_Settings::get_open_voting_categories( $settings );

			if ( ! empty( $open_categories ) ) {
				$voting_open_globally = true;
				$open_competition_id  = (int) $competition->id;
				$open_category_slug   = $open_categories[0];
				break;
			}
		}

		// Build list of all available categories with images.
		$all_categories = array();
		foreach ( $open_competitions as $comp ) {
			$settings   = Competition_Settings::parse( $comp->settings );
			$categories = Competition_Settings::get_categories( $settings );

			foreach ( $categories as $cat ) {
				$cat_slug    = $cat['slug'] ?? '';
				$images      = $this->images->find_by_competition( (int) $comp->id, $cat_slug );
				$image_count = count( $images );

				if ( $image_count > 0 ) {
					$all_categories[] = array(
						'competition' => $comp,
						'settings'    => $settings,
						'category'    => $cat,
						'image_count' => $image_count,
						'key'         => $comp->id . '_' . $cat_slug,
					);
				}
			}
		}

		if ( empty( $all_categories ) ) {
			echo '<div class="notice notice-warning inline">';
			echo '<p>' . esc_html__( 'No images found in any category. Upload images before managing voting.', 'photo-competition-manager' ) . '</p>';
			echo '</div>';
			echo '</div>';
			return;
		}

		// Page load recovery: live state wins over stored step.
		foreach ( $all_categories as &$cat_data ) {
			$cat_slug     = $cat_data['category']['slug'] ?? '';
			$cat_comp_id  = (int) $cat_data['competition']->id;
			$cat_key      = $cat_comp_id . '_' . $cat_slug;
			$cat_settings = $cat_data['settings'];

			$stored_step = $cat_settings['voting']['category_steps'][ $cat_slug ] ?? 1;

			// If voting is currently open for this category and step < 3, jump to 3.
			$open_cats = Competition_Settings::get_open_voting_categories( $cat_settings );
			if ( in_array( $cat_slug, $open_cats, true ) && $stored_step < 3 ) {
				$stored_step = 3;
			}

			// If category is in voted_categories and step < 5, jump to 5.
			$voted = $cat_settings['voting']['voted_categories'] ?? array();
			if ( in_array( $cat_key, $voted, true ) && $stored_step < 5 ) {
				$stored_step = 5;
			}

			$cat_data['current_step'] = $stored_step;
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
		if ( ! $active_category_data && $voting_open_globally && $open_competition_id ) {
			foreach ( $all_categories as $cat_data ) {
				if ( (int) $cat_data['competition']->id === $open_competition_id && ( $cat_data['category']['slug'] ?? '' ) === $open_category_slug ) {
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

		// Get voted categories from settings.
		$voted_categories = $active_settings['voting']['voted_categories'] ?? array();

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
		echo $this->render_competition_status_bar( $active_competition, $active_settings );

		// Determine readiness.
		$uploads_closed  = $active_settings['upload']['uploads_closed'] ?? false;
		$results_visible = $active_settings['results']['results_visible'] ?? false;
		$is_ready        = $uploads_closed && ! $results_visible;

		// Render category tabs (attached to the workflow card postbox).
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_category_tabs( $all_categories, $current_key, $voting_open_globally, $open_competition_id, $open_category_slug, $voted_categories );

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
			echo $this->render_competition_complete( $active_competition, $all_categories, $voted_categories, $active_settings, $global_settings );
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			echo $this->render_workflow_steps( $active_category_data, $is_ready, $voting_open_globally, $open_competition_id, $open_category_slug, $global_settings, count( $all_categories ) );
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
	 * Send voting opened notifications to all active members.
	 *
	 * @param object $competition Competition object.
	 * @return void
	 */
	private function send_voting_opened_notifications( object $competition ): void {
		// Get voting page URL from global settings.
		$global_settings = Competition_Settings::global_settings();
		$voting_page_url = $global_settings['urls']['voting_page'] ?? '';

		if ( empty( $voting_page_url ) ) {
			return; // No voting page URL configured, skip sending.
		}

		// Get all active members.
		$members = $this->members->all( 10000, true );

		if ( empty( $members ) ) {
			return;
		}

		// Format close date.
		$close_date = '';
		if ( ! empty( $competition->close_date ) ) {
			$close_date = wp_date( get_option( 'date_format' ), strtotime( $competition->close_date ) );
		}

		$email_service = new Email_Service();

		foreach ( $members as $member ) {
			if ( ! empty( $member->email ) ) {
				$email_service->send_voting_opened_notification(
					$member->email,
					$member->name,
					$competition->title,
					$voting_page_url,
					$close_date
				);
			}
		}
	}


	/**
	 * Render results page links section.
	 *
	 * @return void
	 */
	/**
	 * Render the competition status bar with competition-wide controls.
	 *
	 * @param object $competition The active competition object.
	 * @param array  $settings    Parsed competition settings.
	 * @return string
	 */
	private function render_competition_status_bar( object $competition, array $settings ): string {
		$uploads_closed  = $settings['upload']['uploads_closed'] ?? false;
		$results_visible = $settings['results']['results_visible'] ?? false;

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
	 * @param array       $voted_categories     Array of category keys that have been voted.
	 * @return string
	 */
	private function render_category_tabs( array $all_categories, string $current_key, bool $voting_open_globally, ?int $open_competition_id, ?string $open_category_slug, array $voted_categories = array() ): string {
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
				'voted_categories'     => $voted_categories,
			)
		);
	}

	/**
	 * Render the 5-step workflow for a category inside a postbox.
	 *
	 * Replaces the old render_category_control_panel() method.
	 *
	 * @param array       $category_data        Category data array with competition, settings, etc.
	 * @param bool        $is_ready             Whether uploads are closed and results hidden.
	 * @param bool        $voting_open_globally Whether voting is open for any category.
	 * @param int|null    $open_competition_id  Competition ID with voting open.
	 * @param string|null $open_category_slug   Category slug with voting open.
	 * @param array       $global_settings      Global default settings.
	 * @param int         $total_categories     Total number of categories (for single-category heading).
	 * @return string
	 */
	private function render_workflow_steps( array $category_data, bool $is_ready, bool $voting_open_globally, ?int $open_competition_id, ?string $open_category_slug, array $global_settings, int $total_categories = 1 ): string {
		$competition    = $category_data['competition'];
		$category       = $category_data['category'];
		$image_count    = $category_data['image_count'];
		$category_slug  = $category['slug'] ?? '';
		$category_label = $category['label'] ?? '';
		$current_step   = $category_data['current_step'] ?? 1;
		$settings       = $category_data['settings'];
		$comp_id        = (int) $competition->id;

		// Duration defaults from global settings.
		$preview_duration  = $global_settings['slideshow']['preview_duration'] ?? 10;
		$voting_duration   = $global_settings['slideshow']['voting_duration'] ?? 15;
		$critique_duration = $global_settings['slideshow']['critique_duration'] ?? 0;

		// Check if another category has voting open (blocks step 2).
		$another_cat_voting = $voting_open_globally
			&& ! ( $open_competition_id === $comp_id && $open_category_slug === $category_slug );

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

		// Is voting currently open for THIS category?
		$voting_open_here = $voting_open_globally
			&& $open_competition_id === $comp_id
			&& $open_category_slug === $category_slug;

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
				'competition'        => $competition,
				'category_slug'      => $category_slug,
				'category_label'     => $category_label,
				'image_count'        => $image_count,
				'current_step'       => $current_step,
				'settings'           => $settings,
				'comp_id'            => $comp_id,
				'is_ready'           => $is_ready,
				'total_categories'   => $total_categories,
				'another_cat_voting' => $another_cat_voting,
				'voting_open_here'   => $voting_open_here,
				'open_voting_url'    => $open_voting_url,
				'close_voting_url'   => $close_voting_url,
				'reset_url'          => $reset_url,
				'steps'              => $steps,
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
	 * @param array  $voted_categories  Array of voted category keys.
	 * @param array  $settings          Parsed competition settings.
	 * @param array  $global_settings   Global settings.
	 * @return string
	 */
	private function render_competition_complete( object $competition, array $all_categories, array $voted_categories, array $settings, array $global_settings ): string {
		$results_visible = $settings['results']['results_visible'] ?? false;
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
	 * Check for members with submissions but no grades.
	 *
	 * @param array $competitions Array of competition objects.
	 * @return array Array of member info with missing grades.
	 */
	private function check_members_without_grades( array $competitions ): array {
		$members_without_grades = array();

		foreach ( $competitions as $competition ) {
			// Get all images for this competition.
			$images = $this->images->find_by_competition( (int) $competition->id );

			if ( empty( $images ) ) {
				continue;
			}

			// Get unique member IDs from images.
			$member_ids = array_unique( array_map( fn( $img ) => (int) $img->member_id, $images ) );

			// Get member details.
			$members = $this->members->find_many( $member_ids );

			// Check each member for missing grade.
			foreach ( $members as $member_id => $member ) {
				if ( empty( $member->grade ) ) {
					// Count images for this member.
					$image_count = count( array_filter( $images, fn( $img ) => (int) $img->member_id === $member_id ) );

					$members_without_grades[ $member_id ] = array(
						'name'        => $member->name,
						'email'       => $member->email,
						'image_count' => $image_count,
					);
				}
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

		$competition = $this->competitions->find( $competition_id );
		if ( ! $competition ) {
			wp_send_json_error( array( 'message' => __( 'Competition not found.', 'photo-competition-manager' ) ) );
		}

		$settings = Competition_Settings::parse( $competition->settings );
		$settings['voting']['category_steps'][ $category_slug ] = $step;

		// Step 6 = category complete. Also write to voted_categories for backward compat.
		if ( 6 === $step ) {
			$category_key = $competition_id . '_' . $category_slug;
			if ( ! in_array( $category_key, $settings['voting']['voted_categories'] ?? array(), true ) ) {
				$settings['voting']['voted_categories'][] = $category_key;
			}
		}

		$result = $this->competitions->update( $competition_id, array( 'settings' => $settings ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'step' => $step ) );
	}
}
