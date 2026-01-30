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

		$current_key    = $active_category_data['key'];
		$is_voting_open = $voting_open_globally && (int) $active_category_data['competition']->id === $open_competition_id && ( $active_category_data['category']['slug'] ?? '' ) === $open_category_slug;

		// Get voted categories from settings.
		$voted_categories = $active_settings['voting']['voted_categories'] ?? array();

		// Check if all categories have been voted.
		$all_voted = ! empty( $voted_categories ) && count( $voted_categories ) >= count( $all_categories );
		foreach ( $all_categories as $cat_data ) {
			if ( ! in_array( $cat_data['key'], $voted_categories, true ) ) {
				$all_voted = false;
				break;
			}
		}

		// Get voting page URL.
		$voting_page_url = '';
		$comp_urls       = $active_settings['urls'] ?? array();
		if ( ! empty( $comp_urls['voting_page'] ) ) {
			$voting_page_url = $comp_urls['voting_page'];
		} elseif ( ! empty( $global_settings['urls']['voting_page'] ) ) {
			$voting_page_url = $global_settings['urls']['voting_page'];
		}

		// Render the Competition Status Bar.
		$this->render_competition_status_bar( $active_competition, $active_settings );

		// Render Category Tabs.
		$this->render_category_tabs( $all_categories, $current_key, $voting_open_globally, $open_competition_id, $open_category_slug, $voted_categories );

		// Render Competition Complete panel OR Category Control Panel.
		if ( $all_voted && ! $voting_open_globally ) {
			$this->render_competition_complete( $active_competition, $all_categories, $voted_categories, $active_settings, $global_settings );
		} else {
			$this->render_category_control_panel(
				$active_category_data['competition'],
				$active_category_data['category'],
				$active_category_data['image_count'],
				$is_voting_open,
				$voting_open_globally,
				$active_category_data['settings']
			);
		}

		// Render Quick Actions.
		$this->render_quick_actions( $voting_page_url, $global_settings );

		// Hidden duration setting for slideshow.
		echo '<input type="hidden" id="slideshow-duration-setting" value="20" />';

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

		// Determine ready state - uploads closed AND results hidden.
		$is_ready = $uploads_closed && ! $results_visible;
		?>
		<div class="competition-status-bar">
			<div class="status-bar-title">
				<h2><?php echo esc_html( $competition->title ); ?></h2>
			</div>
			<div class="status-bar-controls">
				<div class="status-control uploads-control">
					<span class="status-control-label"><?php esc_html_e( 'Uploads', 'photo-competition-manager' ); ?></span>
					<?php if ( $uploads_closed ) : ?>
						<span class="status-pill status-pill-success">
							<span class="dashicons dashicons-yes"></span>
							<?php esc_html_e( 'Closed', 'photo-competition-manager' ); ?>
						</span>
						<a href="<?php echo esc_url( $open_uploads_url ); ?>" class="button button-small"><?php esc_html_e( 'Reopen', 'photo-competition-manager' ); ?></a>
					<?php else : ?>
						<span class="status-pill status-pill-warning">
							<span class="dashicons dashicons-warning"></span>
							<?php esc_html_e( 'Open', 'photo-competition-manager' ); ?>
						</span>
						<a href="<?php echo esc_url( $close_uploads_url ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Close', 'photo-competition-manager' ); ?></a>
					<?php endif; ?>
				</div>
				<div class="status-control results-control">
					<span class="status-control-label"><?php esc_html_e( 'Results', 'photo-competition-manager' ); ?></span>
					<?php if ( $results_visible ) : ?>
						<span class="status-pill status-pill-warning">
							<span class="dashicons dashicons-warning"></span>
							<?php esc_html_e( 'Visible', 'photo-competition-manager' ); ?>
						</span>
						<a href="<?php echo esc_url( $hide_results_url ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Hide', 'photo-competition-manager' ); ?></a>
					<?php else : ?>
						<span class="status-pill status-pill-success">
							<span class="dashicons dashicons-yes"></span>
							<?php esc_html_e( 'Hidden', 'photo-competition-manager' ); ?>
						</span>
						<a href="<?php echo esc_url( $show_results_url ); ?>" class="button button-small"><?php esc_html_e( 'Show', 'photo-competition-manager' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( ! $is_ready ) : ?>
			<div class="status-bar-warning">
				<span class="dashicons dashicons-info"></span>
				<?php esc_html_e( 'Close uploads and hide results before opening voting.', 'photo-competition-manager' ); ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render category tabs for switching between categories.
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
			return; // No need for tabs with only one category.
		}
		?>
		<div class="category-tabs-bar">
			<?php
			foreach ( $all_categories as $cat_data ) :
				$tab_key        = $cat_data['key'];
				$tab_comp       = $cat_data['competition'];
				$tab_cat        = $cat_data['category'];
				$tab_count      = $cat_data['image_count'];
				$tab_is_active  = ( $tab_key === $current_key );
				$tab_has_voting = $voting_open_globally && (int) $tab_comp->id === $open_competition_id && ( $tab_cat['slug'] ?? '' ) === $open_category_slug;
				$tab_is_voted   = in_array( $tab_key, $voted_categories, true );
				$tab_url        = add_query_arg(
					array(
						'page'  => 'photo-competition-manager-voting',
						'focus' => $tab_key,
					),
					admin_url( 'admin.php' )
				);
				?>
				<a href="<?php echo esc_url( $tab_url ); ?>" class="category-tab <?php echo $tab_is_active ? 'active' : ''; ?> <?php echo $tab_has_voting ? 'has-voting' : ''; ?> <?php echo $tab_is_voted ? 'is-voted' : ''; ?>">
					<?php if ( $tab_is_voted ) : ?>
						<span class="tab-check dashicons dashicons-yes-alt"></span>
					<?php endif; ?>
					<span class="tab-label"><?php echo esc_html( $tab_cat['label'] ?? '' ); ?></span>
					<span class="tab-count"><?php echo (int) $tab_count; ?></span>
					<?php if ( $tab_has_voting ) : ?>
						<span class="tab-voting-indicator" title="<?php esc_attr_e( 'Voting open', 'photo-competition-manager' ); ?>"></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render the category control panel with slideshow and voting controls.
	 *
	 * @param object $competition          Competition object.
	 * @param array  $category             Category data.
	 * @param int    $image_count          Number of images in category.
	 * @param bool   $is_voting_open       Whether voting is open for this category.
	 * @param bool   $voting_open_globally Whether voting is open for any category.
	 * @param array  $settings             Parsed competition settings.
	 * @return void
	 */
	private function render_category_control_panel( object $competition, array $category, int $image_count, bool $is_voting_open, bool $voting_open_globally, array $settings ): void {
		$category_slug  = $category['slug'] ?? '';
		$category_label = $category['label'] ?? '';
		$current_key    = $competition->id . '_' . $category_slug;

		// Check readiness state.
		$uploads_closed  = $settings['upload']['uploads_closed'] ?? false;
		$results_visible = $settings['results']['results_visible'] ?? false;
		$is_ready        = $uploads_closed && ! $results_visible;

		// Build action URLs with focus parameter preserved.
		$focus_args = array(
			'page'  => 'photo-competition-manager-voting',
			'focus' => $current_key,
		);

		$open_voting_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					$focus_args,
					array(
						'action'      => 'open_category_voting',
						'competition' => (int) $competition->id,
						'category'    => $category_slug,
					)
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_open_voting_' . (int) $competition->id . '_' . $category_slug
		);

		$close_voting_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					$focus_args,
					array(
						'action'      => 'close_category_voting',
						'competition' => (int) $competition->id,
						'category'    => $category_slug,
					)
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_close_voting_' . (int) $competition->id . '_' . $category_slug
		);

		?>
		<div id="focus-panel" class="category-control-panel" data-competition-id="<?php echo esc_attr( $competition->id ); ?>" data-category="<?php echo esc_attr( $category_slug ); ?>">
			<div class="control-panel-header">
				<div class="control-panel-title">
					<h3><?php echo esc_html( $category_label ); ?></h3>
					<span class="control-panel-meta">
						<?php
						printf(
							/* translators: %d: number of images */
							esc_html__( '%d images', 'photo-competition-manager' ),
							(int) $image_count
						);
						?>
					</span>
				</div>
				<div class="control-panel-status">
					<?php if ( $is_voting_open ) : ?>
						<span class="status-badge status-badge-voting">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Voting Open', 'photo-competition-manager' ); ?>
						</span>
					<?php elseif ( ! $is_ready ) : ?>
						<span class="status-badge status-badge-setup">
							<span class="dashicons dashicons-admin-settings"></span>
							<?php esc_html_e( 'Setup Required', 'photo-competition-manager' ); ?>
						</span>
					<?php else : ?>
						<span class="status-badge status-badge-ready">
							<span class="dashicons dashicons-yes"></span>
							<?php esc_html_e( 'Ready', 'photo-competition-manager' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<div class="control-panel-body">
				<!-- Slideshow Section -->
				<div class="control-section slideshow-section">
					<div class="section-header">
						<span class="section-label"><?php esc_html_e( 'Slideshow', 'photo-competition-manager' ); ?></span>
					</div>
					<div class="section-content">
						<div class="duration-presets">
							<button type="button" class="button duration-preset" data-duration="5"><?php esc_html_e( '5s', 'photo-competition-manager' ); ?></button>
							<button type="button" class="button duration-preset" data-duration="10"><?php esc_html_e( '10s', 'photo-competition-manager' ); ?></button>
							<button type="button" class="button duration-preset" data-duration="15"><?php esc_html_e( '15s', 'photo-competition-manager' ); ?></button>
							<button type="button" class="button duration-preset active" data-duration="20"><?php esc_html_e( '20s', 'photo-competition-manager' ); ?></button>
							<button type="button" class="button duration-preset" data-duration="25"><?php esc_html_e( '25s', 'photo-competition-manager' ); ?></button>
							<button type="button" class="button duration-preset" data-duration="30"><?php esc_html_e( '30s', 'photo-competition-manager' ); ?></button>
							<button type="button" class="button duration-preset" data-duration="0" title="<?php esc_attr_e( 'Manual: advance with Space or arrow keys', 'photo-competition-manager' ); ?>"><?php esc_html_e( 'Manual', 'photo-competition-manager' ); ?></button>
						</div>
						<div class="slideshow-buttons">
							<?php if ( $image_count > 0 ) : ?>
								<button type="button" class="button button-primary photo-competition-manager-start-slideshow"
									data-competition-id="<?php echo esc_attr( $competition->id ); ?>"
									data-competition-slug="<?php echo esc_attr( $competition->slug ); ?>"
									data-category="<?php echo esc_attr( $category_slug ); ?>"
									data-category-label="<?php echo esc_attr( $category_label ); ?>">
									<span class="dashicons dashicons-slides"></span>
									<?php esc_html_e( 'Start Slideshow', 'photo-competition-manager' ); ?>
								</button>
								<button type="button" class="button photo-competition-manager-start-critique"
									data-competition-id="<?php echo esc_attr( $competition->id ); ?>"
									data-competition-slug="<?php echo esc_attr( $competition->slug ); ?>"
									data-category="<?php echo esc_attr( $category_slug ); ?>"
									data-category-label="<?php echo esc_attr( $category_label ); ?>"
									title="<?php esc_attr_e( 'Manual slideshow for discussion - advance with Space or arrow keys', 'photo-competition-manager' ); ?>">
									<span class="dashicons dashicons-format-chat"></span>
									<?php esc_html_e( 'Critique Mode', 'photo-competition-manager' ); ?>
								</button>
							<?php else : ?>
								<button type="button" class="button" disabled>
									<span class="dashicons dashicons-slides"></span>
									<?php esc_html_e( 'No Images', 'photo-competition-manager' ); ?>
								</button>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<!-- Voting Section -->
				<div class="control-section voting-section">
					<div class="section-header">
						<span class="section-label">
							<?php if ( $is_voting_open ) : ?>
								<?php esc_html_e( 'Voting is Open', 'photo-competition-manager' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Voting is Closed', 'photo-competition-manager' ); ?>
							<?php endif; ?>
						</span>
					</div>
					<div class="section-content">
						<?php if ( $is_voting_open ) : ?>
							<a href="<?php echo esc_url( $close_voting_url ); ?>" class="button button-hero voting-button voting-close">
								<span class="dashicons dashicons-lock"></span>
								<?php esc_html_e( 'Close Voting', 'photo-competition-manager' ); ?>
							</a>
						<?php elseif ( ! $is_ready ) : ?>
							<button type="button" class="button button-hero voting-button" disabled title="<?php esc_attr_e( 'Close uploads and hide results first', 'photo-competition-manager' ); ?>">
								<span class="dashicons dashicons-unlock"></span>
								<?php esc_html_e( 'Open Voting', 'photo-competition-manager' ); ?>
							</button>
						<?php elseif ( $voting_open_globally ) : ?>
							<button type="button" class="button button-hero voting-button" disabled title="<?php esc_attr_e( 'Another category has voting open', 'photo-competition-manager' ); ?>">
								<span class="dashicons dashicons-unlock"></span>
								<?php esc_html_e( 'Open Voting', 'photo-competition-manager' ); ?>
							</button>
						<?php else : ?>
							<a href="<?php echo esc_url( $open_voting_url ); ?>" class="button button-primary button-hero voting-button voting-open">
								<span class="dashicons dashicons-unlock"></span>
								<?php esc_html_e( 'Open Voting', 'photo-competition-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- Contextual hint -->
			<div class="control-panel-hint">
				<?php if ( $is_voting_open ) : ?>
					<span class="dashicons dashicons-info"></span>
					<?php esc_html_e( 'Voting is open. Start slideshow to display images, then close voting when members are done.', 'photo-competition-manager' ); ?>
				<?php elseif ( ! $is_ready ) : ?>
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Close uploads and hide results in the status bar above before opening voting.', 'photo-competition-manager' ); ?>
				<?php elseif ( $voting_open_globally ) : ?>
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Close voting in the other category before opening voting here.', 'photo-competition-manager' ); ?>
				<?php else : ?>
					<span class="dashicons dashicons-info"></span>
					<?php esc_html_e( 'Preview slideshow recommended before opening voting. Use Critique Mode after voting for discussion.', 'photo-competition-manager' ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render collapsible quick actions bar.
	 *
	 * @param string $voting_page_url The voting page URL for QR code.
	 * @param array  $settings        Global settings.
	 * @return void
	 */
	private function render_quick_actions( string $voting_page_url, array $settings ): void {
		$results_url = $settings['urls']['results_page'] ?? '';
		$top3_url    = $settings['urls']['top3_page'] ?? '';
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
		<div class="competition-complete-panel">
			<div class="complete-header">
				<span class="dashicons dashicons-yes-alt"></span>
				<h2><?php esc_html_e( 'All Categories Complete', 'photo-competition-manager' ); ?></h2>
			</div>
			<div class="complete-body">
				<p class="complete-competition"><?php echo esc_html( $competition->title ); ?></p>
				<div class="complete-slideshow-section">
					<div class="duration-presets">
						<button type="button" class="button duration-preset" data-duration="5"><?php esc_html_e( '5s', 'photo-competition-manager' ); ?></button>
						<button type="button" class="button duration-preset" data-duration="10"><?php esc_html_e( '10s', 'photo-competition-manager' ); ?></button>
						<button type="button" class="button duration-preset" data-duration="15"><?php esc_html_e( '15s', 'photo-competition-manager' ); ?></button>
						<button type="button" class="button duration-preset active" data-duration="20"><?php esc_html_e( '20s', 'photo-competition-manager' ); ?></button>
						<button type="button" class="button duration-preset" data-duration="25"><?php esc_html_e( '25s', 'photo-competition-manager' ); ?></button>
						<button type="button" class="button duration-preset" data-duration="30"><?php esc_html_e( '30s', 'photo-competition-manager' ); ?></button>
						<button type="button" class="button duration-preset" data-duration="0" title="<?php esc_attr_e( 'Manual: advance with Space or arrow keys', 'photo-competition-manager' ); ?>"><?php esc_html_e( 'Manual', 'photo-competition-manager' ); ?></button>
					</div>
				</div>

				<ul class="complete-categories">
					<?php foreach ( $all_categories as $cat_data ) : ?>
						<li class="complete-category-item">
							<span class="dashicons dashicons-yes"></span>
							<span class="category-name"><?php echo esc_html( $cat_data['category']['label'] ?? '' ); ?></span>
							<span class="category-count">(<?php echo (int) $cat_data['image_count']; ?> <?php esc_html_e( 'images', 'photo-competition-manager' ); ?>)</span>
							<span class="category-slideshow-actions">
								<button type="button" class="button button-small photo-competition-manager-start-slideshow"
									data-competition-id="<?php echo esc_attr( $competition->id ); ?>"
									data-competition-slug="<?php echo esc_attr( $competition->slug ); ?>"
									data-category="<?php echo esc_attr( $cat_data['category']['slug'] ?? '' ); ?>"
									data-category-label="<?php echo esc_attr( $cat_data['category']['label'] ?? '' ); ?>">
									<span class="dashicons dashicons-slides"></span> <?php esc_html_e( 'Slideshow', 'photo-competition-manager' ); ?>
								</button>
								<button type="button" class="button button-small photo-competition-manager-start-critique"
									data-competition-id="<?php echo esc_attr( $competition->id ); ?>"
									data-competition-slug="<?php echo esc_attr( $competition->slug ); ?>"
									data-category="<?php echo esc_attr( $cat_data['category']['slug'] ?? '' ); ?>"
									data-category-label="<?php echo esc_attr( $cat_data['category']['label'] ?? '' ); ?>"
									title="<?php esc_attr_e( 'Manual slideshow for discussion', 'photo-competition-manager' ); ?>">
									<span class="dashicons dashicons-format-chat"></span> <?php esc_html_e( 'Critique', 'photo-competition-manager' ); ?>
								</button>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>

				<div class="complete-actions">
					<div class="results-status">
						<span class="results-label"><?php esc_html_e( 'Results:', 'photo-competition-manager' ); ?></span>
						<?php if ( $results_visible ) : ?>
							<span class="status-pill status-pill-success">
								<span class="dashicons dashicons-yes"></span>
								<?php esc_html_e( 'Visible', 'photo-competition-manager' ); ?>
							</span>
						<?php else : ?>
							<span class="status-pill status-pill-warning">
								<span class="dashicons dashicons-hidden"></span>
								<?php esc_html_e( 'Hidden', 'photo-competition-manager' ); ?>
							</span>
							<a href="<?php echo esc_url( $show_results_url ); ?>" class="button button-primary button-hero">
								<span class="dashicons dashicons-visibility"></span>
								<?php esc_html_e( 'Show Results', 'photo-competition-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $results_url ) || ! empty( $top3_url ) ) : ?>
					<div class="results-links">
						<?php if ( ! empty( $results_url ) ) : ?>
							<a href="<?php echo esc_url( $results_url ); ?>" class="button button-large" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-chart-bar"></span>
								<?php esc_html_e( 'View Full Results', 'photo-competition-manager' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( ! empty( $top3_url ) ) : ?>
							<a href="<?php echo esc_url( $top3_url ); ?>" class="button button-large" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-awards"></span>
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
}
