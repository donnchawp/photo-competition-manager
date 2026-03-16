<?php
/**
 * Voting controller for admin interface.
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
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Manage voting controls page.
 *
 * @since 0.1.0
 */
class Voting_Controller {

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

		$action = '';

		if ( isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; mutations require explicit nonce checks below.
			$action = sanitize_key( wp_unslash( $_GET['action'] ) );
		}

		// Preserve focus parameter for redirects.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only for redirect preservation.
		$focus_param = isset( $_GET['focus'] ) ? sanitize_text_field( wp_unslash( $_GET['focus'] ) ) : '';

		if ( 'open_category_voting' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;
			$category_slug  = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';

			check_admin_referer( 'photo_competition_open_voting_' . $competition_id . '_' . $category_slug );

			// Global constraint validation: ensure no other category has voting open in ACTIVE competitions only.
			$all_competitions = $this->competitions->all( 100, false, false );
			foreach ( $all_competitions as $comp ) {
				// Skip closed/inactive competitions.
				if ( ! $this->competitions->is_open( $comp ) ) {
					continue;
				}

				$comp_settings  = Competition_Settings::parse( $comp->settings );
				$comp_open_cats = Competition_Settings::get_open_voting_categories( $comp_settings );

				if ( ! empty( $comp_open_cats ) ) {
					add_settings_error(
						'photo_competition_voting',
						'voting_already_open',
						__( 'Cannot open voting. Another category already has voting open. Close it first.', 'photo-competition-manager' ),
						'error'
					);

					$this->redirect_with_settings_errors(
						add_query_arg(
							array( 'page' => 'photo-competition-manager-voting' ),
							admin_url( 'admin.php' )
						)
					);
				}
			}

			// Open voting for this category.
			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'photo_competition_voting',
					'competition_not_found',
					__( 'Competition not found.', 'photo-competition-manager' ),
					'error'
				);

				$this->redirect_with_settings_errors(
					add_query_arg(
						array( 'page' => 'photo-competition-manager-voting' ),
						admin_url( 'admin.php' )
					)
				);
			}

			$settings                              = Competition_Settings::parse( $competition->settings );
			$settings['voting']['open_categories'] = array( $category_slug );
			$settings['voting']['category_steps'][ $category_slug ] = 3;

			$result = $this->competitions->update(
				$competition_id,
				array( 'settings' => $settings )
			);

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
					'voting_opened',
					__( 'Voting opened successfully.', 'photo-competition-manager' ),
					'updated'
				);

				// Send voting opened notifications to active members.
				$this->send_voting_opened_notifications( $competition );
			}

			$redirect_args = array( 'page' => 'photo-competition-manager-voting' );
			if ( ! empty( $focus_param ) ) {
				$redirect_args['focus'] = $focus_param;
			}
			$this->redirect_with_settings_errors(
				add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . '#focus-panel'
			);
		}

		if ( 'close_category_voting' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;
			$category_slug  = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';

			check_admin_referer( 'photo_competition_close_voting_' . $competition_id . '_' . $category_slug );

			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'photo_competition_voting',
					'competition_not_found',
					__( 'Competition not found.', 'photo-competition-manager' ),
					'error'
				);

				$this->redirect_with_settings_errors(
					add_query_arg(
						array( 'page' => 'photo-competition-manager-voting' ),
						admin_url( 'admin.php' )
					)
				);
			}

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

			$result = $this->competitions->update(
				$competition_id,
				array( 'settings' => $settings )
			);

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
					'voting_closed',
					__( 'Voting closed successfully.', 'photo-competition-manager' ),
					'updated'
				);
			}

			$redirect_args = array( 'page' => 'photo-competition-manager-voting' );
			if ( ! empty( $focus_param ) ) {
				$redirect_args['focus'] = $focus_param;
			}
			$this->redirect_with_settings_errors(
				add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . '#focus-panel'
			);
		}

		if ( 'close_uploads' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

			check_admin_referer( 'photo_competition_close_uploads_' . $competition_id );

			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'photo_competition_voting',
					'competition_not_found',
					__( 'Competition not found.', 'photo-competition-manager' ),
					'error'
				);

				$this->redirect_with_settings_errors(
					add_query_arg(
						array( 'page' => 'photo-competition-manager-voting' ),
						admin_url( 'admin.php' )
					)
				);
			}

			$settings                             = Competition_Settings::parse( $competition->settings );
			$settings['upload']['uploads_closed'] = true;

			$result = $this->competitions->update(
				$competition_id,
				array( 'settings' => $settings )
			);

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
					'uploads_closed',
					__( 'Uploads closed successfully. Members can no longer upload or delete images.', 'photo-competition-manager' ),
					'updated'
				);
			}

			$redirect_args = array( 'page' => 'photo-competition-manager-voting' );
			if ( ! empty( $focus_param ) ) {
				$redirect_args['focus'] = $focus_param;
			}
			$this->redirect_with_settings_errors(
				add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . '#focus-panel'
			);
		}

		if ( 'open_uploads' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

			check_admin_referer( 'photo_competition_open_uploads_' . $competition_id );

			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'photo_competition_voting',
					'competition_not_found',
					__( 'Competition not found.', 'photo-competition-manager' ),
					'error'
				);

				$this->redirect_with_settings_errors(
					add_query_arg(
						array( 'page' => 'photo-competition-manager-voting' ),
						admin_url( 'admin.php' )
					)
				);
			}

			$settings                             = Competition_Settings::parse( $competition->settings );
			$settings['upload']['uploads_closed'] = false;

			$result = $this->competitions->update(
				$competition_id,
				array( 'settings' => $settings )
			);

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
					'uploads_opened',
					__( 'Uploads reopened successfully. Members can now upload and delete images again.', 'photo-competition-manager' ),
					'updated'
				);
			}

			$redirect_args = array( 'page' => 'photo-competition-manager-voting' );
			if ( ! empty( $focus_param ) ) {
				$redirect_args['focus'] = $focus_param;
			}
			$this->redirect_with_settings_errors(
				add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . '#focus-panel'
			);
		}

		if ( 'show_results' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

			check_admin_referer( 'photo_competition_show_results_' . $competition_id );

			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'photo_competition_voting',
					'competition_not_found',
					__( 'Competition not found.', 'photo-competition-manager' ),
					'error'
				);

				$this->redirect_with_settings_errors(
					add_query_arg(
						array( 'page' => 'photo-competition-manager-voting' ),
						admin_url( 'admin.php' )
					)
				);
			}

			$settings                               = Competition_Settings::parse( $competition->settings );
			$settings['results']['results_visible'] = true;

			$result = $this->competitions->update(
				$competition_id,
				array( 'settings' => $settings )
			);

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
					'results_shown',
					__( 'Results are now visible to the public.', 'photo-competition-manager' ),
					'updated'
				);
			}

			$redirect_args = array( 'page' => 'photo-competition-manager-voting' );
			if ( ! empty( $focus_param ) ) {
				$redirect_args['focus'] = $focus_param;
			}
			$this->redirect_with_settings_errors(
				add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . '#focus-panel'
			);
		}

		if ( 'hide_results' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

			check_admin_referer( 'photo_competition_hide_results_' . $competition_id );

			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'photo_competition_voting',
					'competition_not_found',
					__( 'Competition not found.', 'photo-competition-manager' ),
					'error'
				);

				$this->redirect_with_settings_errors(
					add_query_arg(
						array( 'page' => 'photo-competition-manager-voting' ),
						admin_url( 'admin.php' )
					)
				);
			}

			$settings                               = Competition_Settings::parse( $competition->settings );
			$settings['results']['results_visible'] = false;

			$result = $this->competitions->update(
				$competition_id,
				array( 'settings' => $settings )
			);

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
					'results_hidden',
					__( 'Results are now hidden from the public.', 'photo-competition-manager' ),
					'updated'
				);
			}

			$redirect_args = array( 'page' => 'photo-competition-manager-voting' );
			if ( ! empty( $focus_param ) ) {
				$redirect_args['focus'] = $focus_param;
			}
			$this->redirect_with_settings_errors(
				add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) . '#focus-panel'
			);
		}
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
		$global_settings    = $this->get_global_settings();

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
		$this->render_competition_status_bar( $active_competition, $active_settings );

		// Determine readiness.
		$uploads_closed  = $active_settings['upload']['uploads_closed'] ?? false;
		$results_visible = $active_settings['results']['results_visible'] ?? false;
		$is_ready        = $uploads_closed && ! $results_visible;

		// Render category tabs (attached to the workflow card postbox).
		$this->render_category_tabs( $all_categories, $current_key, $voting_open_globally, $open_competition_id, $open_category_slug, $voted_categories );

		// Check completion: category_steps >= 6 is primary, voted_categories is fallback for pre-upgrade data.
		$all_complete = true;
		foreach ( $all_categories as $cat_data ) {
			$step         = $cat_data['current_step'] ?? 1;
			$key_in_voted = in_array( $cat_data['key'], $voted_categories, true );
			if ( $step < 6 && ! $key_in_voted ) {
				$all_complete = false;
				break;
			}
		}

		if ( $all_complete && ! $voting_open_globally ) {
			$this->render_competition_complete( $active_competition, $all_categories, $voted_categories, $active_settings, $global_settings );
		} else {
			$this->render_workflow_steps( $active_category_data, $is_ready, $voting_open_globally, $open_competition_id, $open_category_slug, $global_settings, count( $all_categories ) );
		}

		// Render Quick Actions.
		$this->render_quick_actions( $voting_page_url, $global_settings, $active_settings );

		// Hidden meter type setting for slideshow.
		$meter_type = $active_settings['slideshow']['progress_meter_type'] ?? 'bar';
		echo '<input type="hidden" id="slideshow-meter-type" value="' . esc_attr( $meter_type ) . '" />';

		// Slideshow container (hidden by default).
		$this->render_slideshow_container();

		echo '</div>';
	}

	/**
	 * Render slideshow container for admin voting controls page.
	 *
	 * @return void
	 */
	private function render_slideshow_container(): void {
		?>
		<div id="photo-comp-slideshow-modal" class="slideshow-display" style="display: none;">
			<div class="slideshow-image-container">
				<img src="" alt="" class="slideshow-current-image" />
				<div class="slideshow-image-info">
					<span class="image-number"></span>
				</div>
			</div>
			<div class="slideshow-progress">
				<div class="progress-bar" style="width: 0%;"></div>
			</div>
			<button type="button" class="slideshow-exit" aria-label="<?php esc_attr_e( 'Exit slideshow', 'photo-competition-manager' ); ?>">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
			<div class="slideshow-controls-overlay">
				<button type="button" class="button button-large slideshow-pause">
		<?php esc_html_e( 'Pause', 'photo-competition-manager' ); ?>
				</button>
				<button type="button" class="button button-large slideshow-resume" style="display: none;">
		<?php esc_html_e( 'Resume', 'photo-competition-manager' ); ?>
				</button>
				<button type="button" class="button button-large button-primary slideshow-stop">
		<?php esc_html_e( 'Stop Slideshow', 'photo-competition-manager' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Get global default settings.
	 *
	 * @return array<string, mixed>
	 */
	private function get_global_settings(): array {
		$saved = get_option( 'photo_comp_default_settings', '' );
		return Competition_Settings::parse( $saved );
	}


	/**
	 * Send voting opened notifications to all active members.
	 *
	 * @param object $competition Competition object.
	 * @return void
	 */
	private function send_voting_opened_notifications( object $competition ): void {
		// Get voting page URL from global settings.
		$global_settings = $this->get_global_settings();
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
	private function render_results_links(): void {
		// Get global settings to find results page URLs.
		// URLs are auto-detected by Competition_Settings::parse() if not explicitly set.
		$global_settings = $this->get_global_settings();
		$results_url     = $global_settings['urls']['results_page'] ?? '';
		$top3_url        = $global_settings['urls']['top3_page'] ?? '';

		echo '<div style="max-width: 900px; margin-top: 40px; padding: 20px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;">';
		echo '<h2 style="margin-top: 0;">' . esc_html__( 'Results Pages', 'photo-competition-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Use these links when the competition is finished and you are ready to publish results.', 'photo-competition-manager' ) . '</p>';

		if ( ! empty( $results_url ) || ! empty( $top3_url ) ) {
			echo '<p>';

			if ( ! empty( $results_url ) ) {
				echo '<a href="' . esc_url( $results_url ) . '" class="button button-secondary" target="_blank" rel="noopener noreferrer">';
				echo '<span class="dashicons dashicons-awards" style="margin-top: 3px;"></span> ';
				echo esc_html__( 'View Full Results', 'photo-competition-manager' );
				echo '</a> ';
			}

			if ( ! empty( $top3_url ) ) {
				echo '<a href="' . esc_url( $top3_url ) . '" class="button button-secondary" target="_blank" rel="noopener noreferrer">';
				echo '<span class="dashicons dashicons-star-filled" style="margin-top: 3px;"></span> ';
				echo esc_html__( 'View Top 3 Results', 'photo-competition-manager' );
				echo '</a>';
			}

			echo '</p>';
		} else {
			echo '<div class="notice notice-warning inline">';
			echo '<p>';
			echo esc_html__( 'No results pages configured.', 'photo-competition-manager' ) . ' ';
			printf(
				/* translators: %s: link to global settings page */
				esc_html__( 'Add results page URLs in the %s.', 'photo-competition-manager' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=photo-competition-manager-settings' ) ) . '">' . esc_html__( 'Global Settings', 'photo-competition-manager' ) . '</a>'
			);
			echo '</p>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render the competition status bar with competition-wide controls.
	 *
	 * @param object $competition The active competition object.
	 * @param array  $settings    Parsed competition settings.
	 * @return void
	 */
	private function render_competition_status_bar( object $competition, array $settings ): void {
		$uploads_closed  = $settings['upload']['uploads_closed'] ?? false;
		$results_visible = $settings['results']['results_visible'] ?? false;

		// Build action URLs.
		$close_uploads_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'photo-competition-manager-voting',
					'action'      => 'close_uploads',
					'competition' => (int) $competition->id,
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_close_uploads_' . (int) $competition->id
		);

		$open_uploads_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'        => 'photo-competition-manager-voting',
					'action'      => 'open_uploads',
					'competition' => (int) $competition->id,
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_open_uploads_' . (int) $competition->id
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

		?>
		<div class="postbox photo-comp-status-bar">
			<div class="inside" style="margin: 0; padding: 10px 14px;">
				<div class="status-bar-layout">
					<strong class="status-bar-title"><?php echo esc_html( $competition->title ); ?></strong>
					<div class="status-bar-controls">
						<div class="status-control">
							<span class="status-control-label"><?php esc_html_e( 'Uploads', 'photo-competition-manager' ); ?></span>
							<?php if ( $uploads_closed ) : ?>
								<span class="photo-comp-badge photo-comp-badge-success"><?php esc_html_e( 'Closed', 'photo-competition-manager' ); ?></span>
								<a href="<?php echo esc_url( $open_uploads_url ); ?>" class="button button-small"><?php esc_html_e( 'Reopen', 'photo-competition-manager' ); ?></a>
							<?php else : ?>
								<span class="photo-comp-badge photo-comp-badge-warning"><?php esc_html_e( 'Open', 'photo-competition-manager' ); ?></span>
								<a href="<?php echo esc_url( $close_uploads_url ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Close Uploads', 'photo-competition-manager' ); ?></a>
							<?php endif; ?>
						</div>
						<div class="status-control">
							<span class="status-control-label"><?php esc_html_e( 'Results', 'photo-competition-manager' ); ?></span>
							<?php if ( $results_visible ) : ?>
								<span class="photo-comp-badge photo-comp-badge-warning"><?php esc_html_e( 'Visible', 'photo-competition-manager' ); ?></span>
								<a href="<?php echo esc_url( $hide_results_url ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Hide', 'photo-competition-manager' ); ?></a>
							<?php else : ?>
								<span class="photo-comp-badge photo-comp-badge-success"><?php esc_html_e( 'Hidden', 'photo-competition-manager' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
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
	 * @return void
	 */
	private function render_category_tabs( array $all_categories, string $current_key, bool $voting_open_globally, ?int $open_competition_id, ?string $open_category_slug, array $voted_categories = array() ): void {
		if ( count( $all_categories ) < 2 ) {
			return; // Single category: no tabs, heading rendered inside card.
		}
		?>
		<nav class="nav-tab-wrapper photo-comp-category-tabs">
			<?php foreach ( $all_categories as $cat_data ) :
				$tab_key         = $cat_data['key'];
				$tab_cat         = $cat_data['category'];
				$tab_count       = $cat_data['image_count'];
				$tab_is_active   = ( $tab_key === $current_key );
				$tab_has_voting  = $voting_open_globally && (int) $cat_data['competition']->id === $open_competition_id && ( $tab_cat['slug'] ?? '' ) === $open_category_slug;
				$tab_is_complete = ( $cat_data['current_step'] ?? 1 ) >= 6;
				$tab_url         = add_query_arg(
					array(
						'page'  => 'photo-competition-manager-voting',
						'focus' => $tab_key,
					),
					admin_url( 'admin.php' )
				);
				?>
				<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo $tab_is_active ? 'nav-tab-active' : ''; ?>">
					<?php if ( $tab_is_complete ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color: #00a32a; font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span>
					<?php endif; ?>
					<?php echo esc_html( $tab_cat['label'] ?? '' ); ?>
					<span class="photo-comp-tab-count">(<?php echo (int) $tab_count; ?>)</span>
					<?php if ( $tab_has_voting ) : ?>
						<span class="photo-comp-voting-dot" title="<?php esc_attr_e( 'Voting open', 'photo-competition-manager' ); ?>"></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
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
	 * @return void
	 */
	private function render_workflow_steps( array $category_data, bool $is_ready, bool $voting_open_globally, ?int $open_competition_id, ?string $open_category_slug, array $global_settings, int $total_categories = 1 ): void {
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
				array_merge( $focus_args, array(
					'action'      => 'open_category_voting',
					'competition' => $comp_id,
					'category'    => $category_slug,
				) ),
				admin_url( 'admin.php' )
			),
			'photo_competition_open_voting_' . $comp_id . '_' . $category_slug
		);

		$close_voting_url = wp_nonce_url(
			add_query_arg(
				array_merge( $focus_args, array(
					'action'      => 'close_category_voting',
					'competition' => $comp_id,
					'category'    => $category_slug,
				) ),
				admin_url( 'admin.php' )
			),
			'photo_competition_close_voting_' . $comp_id . '_' . $category_slug
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

		?>
		<div id="focus-panel" class="postbox photo-comp-workflow-card"
			data-competition-id="<?php echo esc_attr( $comp_id ); ?>"
			data-competition-slug="<?php echo esc_attr( $competition->slug ); ?>"
			data-category="<?php echo esc_attr( $category_slug ); ?>"
			data-category-label="<?php echo esc_attr( $category_label ); ?>">

			<div class="inside <?php echo ! $is_ready ? 'photo-comp-workflow-disabled' : ''; ?>">
				<?php if ( $total_categories < 2 ) : ?>
					<h2 class="photo-comp-single-category-heading">
						<?php echo esc_html( $category_label ); ?>
						<span class="photo-comp-tab-count">(<?php echo (int) $image_count; ?> <?php esc_html_e( 'images', 'photo-competition-manager' ); ?>)</span>
					</h2>
				<?php endif; ?>

				<?php if ( ! $is_ready ) : ?>
					<div class="notice notice-warning inline photo-comp-prereq-notice">
						<p>
						<?php
						$uploads_closed  = $settings['upload']['uploads_closed'] ?? false;
						$results_visible = $settings['results']['results_visible'] ?? false;
						if ( ! $uploads_closed ) {
							esc_html_e( 'Close uploads before starting the voting workflow.', 'photo-competition-manager' );
						} elseif ( $results_visible ) {
							esc_html_e( 'Hide results before starting the voting workflow.', 'photo-competition-manager' );
						}
						?>
						</p>
					</div>
				<?php endif; ?>

				<div class="photo-comp-steps">
					<?php foreach ( $steps as $step_num => $step ) :
						$is_completed = $current_step > $step_num;
						$is_active    = $current_step === $step_num && $is_ready;
						$is_upcoming  = $current_step < $step_num || ! $is_ready;
						?>
						<div class="photo-comp-step <?php echo $is_completed ? 'step-completed' : ''; ?> <?php echo $is_active ? 'step-active' : ''; ?> <?php echo $is_upcoming ? 'step-upcoming' : ''; ?>">
							<div class="step-indicator">
								<?php if ( $is_completed ) : ?>
									<span class="step-circle step-circle-done">&#10003;</span>
								<?php elseif ( $is_active ) : ?>
									<span class="step-circle step-circle-active"><?php echo (int) $step_num; ?></span>
								<?php else : ?>
									<span class="step-circle step-circle-upcoming"><?php echo (int) $step_num; ?></span>
								<?php endif; ?>
							</div>
							<div class="step-content">
								<div class="step-label">
									<?php if ( $is_completed ) : ?>
										<s><?php echo esc_html( $step['label'] ); ?></s>
									<?php else : ?>
										<?php echo esc_html( $step['label'] ); ?>
									<?php endif; ?>

									<?php // Show "Voting Open" badge on completed step 2 while voting is open. ?>
									<?php if ( 2 === $step_num && $is_completed && $voting_open_here ) : ?>
										<span class="photo-comp-badge photo-comp-badge-success"><?php esc_html_e( 'Voting Open', 'photo-competition-manager' ); ?></span>
									<?php endif; ?>
								</div>

								<?php if ( $is_active ) : ?>
									<div class="step-description"><?php echo esc_html( $step['description'] ); ?></div>
									<div class="step-actions">
										<?php if ( 'slideshow' === $step['type'] ) : ?>
											<button type="button" class="button button-primary photo-competition-manager-start-slideshow"
												data-competition-id="<?php echo esc_attr( $comp_id ); ?>"
												data-competition-slug="<?php echo esc_attr( $competition->slug ); ?>"
												data-category="<?php echo esc_attr( $category_slug ); ?>"
												data-category-label="<?php echo esc_attr( $category_label ); ?>">
												<?php
												if ( 1 === $step_num ) {
													esc_html_e( 'Start Preview', 'photo-competition-manager' );
												} elseif ( 5 === $step_num ) {
													esc_html_e( 'Start Critique', 'photo-competition-manager' );
												} else {
													esc_html_e( 'Start Slideshow', 'photo-competition-manager' );
												}
												?>
												&#9654;
											</button>
											<span class="step-separator">|</span>
											<label class="step-duration-label">
												<?php esc_html_e( 'Duration:', 'photo-competition-manager' ); ?>
												<input type="number" class="small-text photo-comp-step-duration" value="<?php echo esc_attr( $step['duration'] ); ?>" min="0" max="120" step="1" />s
											</label>
											<span class="step-separator">|</span>
											<button type="button" class="button photo-comp-continue-step"
												data-competition-id="<?php echo esc_attr( $comp_id ); ?>"
												data-category="<?php echo esc_attr( $category_slug ); ?>"
												data-next-step="<?php echo esc_attr( $step_num + 1 ); ?>">
												<?php esc_html_e( 'Continue', 'photo-competition-manager' ); ?> &rarr;
											</button>
										<?php elseif ( 'voting_open' === $step['type'] ) : ?>
											<?php if ( $another_cat_voting ) : ?>
												<button type="button" class="button" disabled title="<?php esc_attr_e( 'Close voting in the other category first', 'photo-competition-manager' ); ?>">
													<?php esc_html_e( 'Open Voting', 'photo-competition-manager' ); ?>
												</button>
												<span class="step-hint"><?php esc_html_e( 'Close voting in the other category first.', 'photo-competition-manager' ); ?></span>
											<?php else : ?>
												<a href="<?php echo esc_url( $open_voting_url ); ?>" class="button button-primary">
													<?php esc_html_e( 'Open Voting', 'photo-competition-manager' ); ?>
												</a>
											<?php endif; ?>
										<?php elseif ( 'voting_close' === $step['type'] ) : ?>
											<a href="<?php echo esc_url( $close_voting_url ); ?>" class="button button-primary">
												<?php esc_html_e( 'Close Voting', 'photo-competition-manager' ); ?>
											</a>
										<?php endif; ?>
									</div>
								<?php elseif ( $is_upcoming && ! $is_completed ) : ?>
									<span class="step-upcoming-desc"><?php echo esc_html( $step['description'] ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render collapsible quick actions bar.
	 *
	 * @param string $voting_page_url    The voting page URL for QR code.
	 * @param array  $settings           Global settings.
	 * @param array  $competition_settings Active competition settings.
	 * @return void
	 */
	private function render_quick_actions( string $voting_page_url, array $settings, array $competition_settings = array() ): void {
		$results_url = $settings['urls']['results_page'] ?? '';
		$top3_url    = $settings['urls']['top3_page'] ?? '';

		// Get voting password if it's stored as plaintext (not a legacy hash).
		$voting_password = '';
		$raw_password    = $competition_settings['voting']['password'] ?? '';
		if ( '' !== $raw_password && ! preg_match( '/^\$P\$|\$wp\$/', $raw_password ) ) {
			$voting_password = $raw_password;
		}
		?>
		<div class="quick-actions-bar" id="quick-actions">
			<button type="button" class="quick-actions-toggle" aria-expanded="false" aria-controls="quick-actions-content">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<?php esc_html_e( 'Quick Actions', 'photo-competition-manager' ); ?>
			</button>
			<div class="quick-actions-content" id="quick-actions-content" style="display: none;">
				<div class="quick-actions-buttons">
					<?php if ( ! empty( $voting_page_url ) ) : ?>
						<button type="button" class="button quick-action-qr" data-target="qr-code-panel">
							<span class="dashicons dashicons-smartphone"></span>
							<?php esc_html_e( 'Show QR Code', 'photo-competition-manager' ); ?>
						</button>
					<?php endif; ?>
					<?php if ( ! empty( $results_url ) ) : ?>
						<a href="<?php echo esc_url( $results_url ); ?>" class="button" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-chart-bar"></span>
							<?php esc_html_e( 'Full Results', 'photo-competition-manager' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( ! empty( $top3_url ) ) : ?>
						<a href="<?php echo esc_url( $top3_url ); ?>" class="button" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-awards"></span>
							<?php esc_html_e( 'Top 3 Results', 'photo-competition-manager' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $voting_page_url ) ) : ?>
				<div class="qr-code-panel" id="qr-code-panel" style="display: none;">
					<?php if ( '' !== $voting_password ) : ?>
					<div class="qr-code-password">
						<span class="qr-code-password-label"><?php esc_html_e( 'Voting Password:', 'photo-competition-manager' ); ?></span>
						<span class="qr-code-password-value"><?php echo esc_html( $voting_password ); ?></span>
					</div>
					<?php endif; ?>
					<div class="qr-code-container" data-voting-url="<?php echo esc_attr( $voting_page_url ); ?>">
						<div class="qr-code-canvas"></div>
						<div class="qr-code-details">
							<h4><?php esc_html_e( 'Voting Page', 'photo-competition-manager' ); ?></h4>
							<p><a href="<?php echo esc_url( $voting_page_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $voting_page_url ); ?></a></p>
							<button type="button" class="button button-small copy-url-btn" data-url="<?php echo esc_attr( $voting_page_url ); ?>">
								<span class="dashicons dashicons-clipboard"></span>
								<?php esc_html_e( 'Copy Link', 'photo-competition-manager' ); ?>
							</button>
						</div>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
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
	 * @return void
	 */
	private function render_competition_complete( object $competition, array $all_categories, array $voted_categories, array $settings, array $global_settings ): void {
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
		?>
		<div class="postbox photo-comp-workflow-card">
			<div class="inside">
				<div class="complete-header" style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
					<span class="dashicons dashicons-yes-alt" style="color: #00a32a; font-size: 24px; width: 24px; height: 24px;"></span>
					<h2 style="margin: 0;"><?php esc_html_e( 'All Categories Complete', 'photo-competition-manager' ); ?></h2>
				</div>

				<div class="complete-slideshow-section" style="margin-bottom: 16px;">
					<div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px;">
						<label class="step-duration-label">
							<?php esc_html_e( 'Slideshow duration:', 'photo-competition-manager' ); ?>
							<input type="number" class="small-text photo-comp-step-duration" id="replay-slideshow-duration" value="<?php echo esc_attr( $slideshow_replay_duration ); ?>" min="0" max="120" step="1" />s
						</label>
						<label class="step-duration-label">
							<?php esc_html_e( 'Critique duration:', 'photo-competition-manager' ); ?>
							<input type="number" class="small-text photo-comp-step-duration" id="replay-critique-duration" value="<?php echo esc_attr( $critique_replay_duration ); ?>" min="0" max="120" step="1" />s
						</label>
					</div>
				</div>

				<ul class="complete-categories" style="margin: 0 0 16px; padding: 0; list-style: none;">
					<?php foreach ( $all_categories as $cat_data ) : ?>
						<li class="complete-category-item" style="display: flex; align-items: center; gap: 8px; padding: 6px 0;">
							<span class="dashicons dashicons-yes" style="color: #00a32a;"></span>
							<span class="category-name" style="font-weight: 600;"><?php echo esc_html( $cat_data['category']['label'] ?? '' ); ?></span>
							<span class="category-count" style="color: #646970;">(<?php echo (int) $cat_data['image_count']; ?> <?php esc_html_e( 'images', 'photo-competition-manager' ); ?>)</span>
							<span class="category-slideshow-actions" style="margin-left: auto;">
								<button type="button" class="button button-small photo-competition-manager-start-slideshow"
									data-competition-id="<?php echo esc_attr( $competition->id ); ?>"
									data-competition-slug="<?php echo esc_attr( $competition->slug ); ?>"
									data-category="<?php echo esc_attr( $cat_data['category']['slug'] ?? '' ); ?>"
									data-category-label="<?php echo esc_attr( $cat_data['category']['label'] ?? '' ); ?>"
									data-duration-input="#replay-slideshow-duration">
									<span class="dashicons dashicons-slides"></span> <?php esc_html_e( 'Slideshow', 'photo-competition-manager' ); ?>
								</button>
								<button type="button" class="button button-small photo-competition-manager-start-slideshow"
									data-competition-id="<?php echo esc_attr( $competition->id ); ?>"
									data-competition-slug="<?php echo esc_attr( $competition->slug ); ?>"
									data-category="<?php echo esc_attr( $cat_data['category']['slug'] ?? '' ); ?>"
									data-category-label="<?php echo esc_attr( $cat_data['category']['label'] ?? '' ); ?>"
									data-duration-input="#replay-critique-duration"
									title="<?php esc_attr_e( 'Manual slideshow for discussion', 'photo-competition-manager' ); ?>">
									<span class="dashicons dashicons-format-chat"></span> <?php esc_html_e( 'Critique', 'photo-competition-manager' ); ?>
								</button>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>

				<div class="complete-actions">
					<?php if ( ! $results_visible ) : ?>
						<a href="<?php echo esc_url( $show_results_url ); ?>" class="button button-primary button-large">
							<span class="dashicons dashicons-visibility" style="margin-top: 4px;"></span>
							<?php esc_html_e( 'Show Results', 'photo-competition-manager' ); ?>
						</a>
					<?php endif; ?>

					<?php if ( ! empty( $results_url ) || ! empty( $top3_url ) ) : ?>
					<div class="results-links" style="margin-top: 12px;">
						<?php if ( ! empty( $results_url ) ) : ?>
							<a href="<?php echo esc_url( $results_url ); ?>" class="button" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-chart-bar" style="margin-top: 4px;"></span>
								<?php esc_html_e( 'View Full Results', 'photo-competition-manager' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( ! empty( $top3_url ) ) : ?>
							<a href="<?php echo esc_url( $top3_url ); ?>" class="button" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-awards" style="margin-top: 4px;"></span>
								<?php esc_html_e( 'View Top 3 Results', 'photo-competition-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
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
