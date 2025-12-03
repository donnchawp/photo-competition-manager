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
		add_action( 'wp_ajax_photo_comp_toggle_workflow', array( $this, 'ajax_toggle_workflow' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue inline scripts for voting page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'competitions_page_photo-competition-manager-voting' !== $hook ) {
			return;
		}

		$inline_js = "
		jQuery(document).ready(function($) {
			$('#photo-comp-workflow-toggle').on('click', function() {
				var \$content = $('#photo-comp-workflow-content');
				var \$icon = $(this).find('.dashicons');
				var isExpanded = \$content.is(':visible');

				// Toggle display.
				\$content.slideToggle(200);

				// Update icon.
				if (isExpanded) {
					\$icon.removeClass('dashicons-arrow-down').addClass('dashicons-arrow-right');
				} else {
					\$icon.removeClass('dashicons-arrow-right').addClass('dashicons-arrow-down');
				}

				// Save preference via AJAX.
				$.post(ajaxurl, {
					action: 'photo_comp_toggle_workflow',
					expanded: isExpanded ? '0' : '1',
					nonce: '" . esc_js( wp_create_nonce( 'photo_comp_workflow_toggle' ) ) . "'
				});
			});
		});
		";
		wp_add_inline_script( 'jquery', $inline_js );
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

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Voting Controls', 'photo-competition-manager' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Open or close voting for competition categories. Only one category across all competitions can have voting open at a time.', 'photo-competition-manager' ) . '</p>';

		// Competition Night Workflow Guide.
		$this->render_workflow_guide();

		// Get all open competitions.
		$all_competitions  = $this->competitions->all( 100, false, false );
		$open_competitions = array_filter(
			$all_competitions,
			function ( $comp ) {
				return $this->competitions->is_open( $comp );
			}
		);

		if ( empty( $open_competitions ) ) {
			echo '<p>' . esc_html__( 'No open competitions found. Create a competition with open and close dates to enable voting controls.', 'photo-competition-manager' ) . '</p>';
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

		// Check if any category has voting open globally.
		$voting_open_globally = false;
		$open_competition_id  = null;
		$open_category_slug   = null;
		$open_competition     = null;

		foreach ( $open_competitions as $competition ) {
			$settings        = Competition_Settings::parse( $competition->settings );
			$open_categories = Competition_Settings::get_open_voting_categories( $settings );

			if ( ! empty( $open_categories ) ) {
				$voting_open_globally = true;
				$open_competition_id  = (int) $competition->id;
				$open_category_slug   = $open_categories[0];
				$open_competition     = $competition;
				break;
			}
		}

		$voting_page_url    = '';
		$voting_page_source = '';
		$voting_category    = '';
		$voting_competition = '';

		if ( $open_competition ) {
			$open_settings = Competition_Settings::parse( $open_competition->settings );
			$urls          = $open_settings['urls'] ?? array();

			if ( ! empty( $urls['voting_page'] ) ) {
				$voting_page_url    = $urls['voting_page'];
				$voting_page_source = 'competition';
				$voting_competition = $open_competition->title;
			}

			if ( $open_category_slug ) {
				$categories = Competition_Settings::get_categories( $open_settings );

				foreach ( $categories as $category ) {
					if ( ( $category['slug'] ?? '' ) === $open_category_slug ) {
						$voting_category = $category['label'] ?? '';
						break;
					}
				}
			}
		}

		if ( empty( $voting_page_url ) ) {
			$global_settings = $this->get_global_settings();
			$global_urls     = $global_settings['urls'] ?? array();

			if ( ! empty( $global_urls['voting_page'] ) ) {
				$voting_page_url    = $global_urls['voting_page'];
				$voting_page_source = 'default';
			}
		}

		if ( ! empty( $voting_page_url ) ) {
			echo '<div class="photo-comp-qr-card" data-voting-url="' . esc_attr( $voting_page_url ) . '">';
			echo '<div class="photo-comp-qr-image" role="img" aria-label="' . esc_attr__( 'Voting page QR code', 'photo-competition-manager' ) . '">';
			echo '<div class="photo-comp-qr-canvas"></div>';
			echo '</div>';
			echo '<div class="photo-comp-qr-details">';
			echo '<h2>' . esc_html__( 'Voting Page QR Code', 'photo-competition-manager' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Display this code so voters can quickly open the voting page on their devices.', 'photo-competition-manager' ) . '</p>';

			if ( 'competition' === $voting_page_source && ! empty( $voting_competition ) ) {
				echo '<p><strong>' . esc_html__( 'Competition:', 'photo-competition-manager' ) . '</strong> ' . esc_html( $voting_competition ) . '</p>';

				if ( ! empty( $voting_category ) ) {
					echo '<p><strong>' . esc_html__( 'Category:', 'photo-competition-manager' ) . '</strong> ' . esc_html( $voting_category ) . '</p>';
				}
			} elseif ( 'default' === $voting_page_source ) {
				echo '<p class="description">' . esc_html__( 'No competition-specific voting page is configured. Using the default voting page URL.', 'photo-competition-manager' ) . '</p>';
			}

			echo '<p><a href="' . esc_url( $voting_page_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $voting_page_url ) . '</a></p>';
			echo '</div>';
			echo '</div>';
		} else {
			echo '<div class="notice notice-warning inline" style="max-width: 900px; margin-top: 20px;">';
			echo '<p><strong>' . esc_html__( 'Voting page not configured.', 'photo-competition-manager' ) . '</strong> ';
			echo esc_html__( 'Add a voting page URL to the competition or default settings to show a QR code here.', 'photo-competition-manager' );
			echo '</p>';
			echo '</div>';
		}

		// Render the Focus Panel for streamlined competition night workflow.
		$this->render_focus_panel( $open_competitions, $voting_open_globally, $open_competition_id, $open_category_slug );

		echo '<table class="widefat striped" style="max-width: 1200px; margin-top: 20px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Competition', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Category', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Uploads', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Results', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Voting', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Slideshow', 'photo-competition-manager' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $open_competitions as $competition ) {
			$settings        = Competition_Settings::parse( $competition->settings );
			$categories      = Competition_Settings::get_categories( $settings );
			$open_categories = Competition_Settings::get_open_voting_categories( $settings );
			$uploads_closed  = $settings['upload']['uploads_closed'] ?? false;
			$results_visible = $settings['results']['results_visible'] ?? false;

			if ( empty( $categories ) ) {
				echo '<tr>';
				echo '<td>' . esc_html( $competition->title ) . '</td>';
				echo '<td colspan="5"><em>' . esc_html__( 'No categories configured', 'photo-competition-manager' ) . '</em></td>';
				echo '</tr>';
				continue;
			}

			$category_count = count( $categories );
			$category_index = 0;

			foreach ( $categories as $category ) {
				$category_slug  = $category['slug'] ?? '';
				$category_label = $category['label'] ?? '';
				$is_open        = in_array( $category_slug, $open_categories, true );

				// Check if category has images.
				$images      = $this->images->find_by_competition( (int) $competition->id, $category_slug );
				$image_count = count( $images );

				echo '<tr>';
				echo '<td>' . esc_html( $competition->title ) . '</td>';
				echo '<td>' . esc_html( $category_label ) . '</td>';

				if ( $is_open ) {
					echo '<td><strong style="color: #2271b1;">' . esc_html__( 'Voting Open', 'photo-competition-manager' ) . '</strong></td>';
				} else {
					echo '<td>' . esc_html__( 'Closed', 'photo-competition-manager' ) . '</td>';
				}

				// Uploads column - only show on first category row with rowspan.
				if ( 0 === $category_index ) {
					echo '<td rowspan="' . esc_attr( $category_count ) . '">';

					if ( $uploads_closed ) {
						echo '<strong style="color: #d63638;">' . esc_html__( 'Closed', 'photo-competition-manager' ) . '</strong><br>';

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

						echo '<a href="' . esc_url( $open_uploads_url ) . '" class="button" style="margin-top: 5px;">' . esc_html__( 'Reopen Uploads', 'photo-competition-manager' ) . '</a>';
					} else {
						echo '<strong style="color: #00a32a;">' . esc_html__( 'Open', 'photo-competition-manager' ) . '</strong><br>';

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

						echo '<a href="' . esc_url( $close_uploads_url ) . '" class="button button-primary" style="margin-top: 5px;">' . esc_html__( 'Close Uploads', 'photo-competition-manager' ) . '</a>';
					}

					echo '</td>';

					// Results column - only show on first category row with rowspan.
					echo '<td rowspan="' . esc_attr( $category_count ) . '">';

					if ( $results_visible ) {
						echo '<strong style="color: #00a32a;">' . esc_html__( 'Visible', 'photo-competition-manager' ) . '</strong><br>';

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

						echo '<a href="' . esc_url( $hide_results_url ) . '" class="button" style="margin-top: 5px;">' . esc_html__( 'Hide Results', 'photo-competition-manager' ) . '</a>';
					} else {
						echo '<strong style="color: #d63638;">' . esc_html__( 'Hidden', 'photo-competition-manager' ) . '</strong><br>';

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

						echo '<a href="' . esc_url( $show_results_url ) . '" class="button button-primary" style="margin-top: 5px;">' . esc_html__( 'Display Results', 'photo-competition-manager' ) . '</a>';
					}

					echo '</td>';
				}

				// Voting column.
				if ( $is_open ) {
					$close_url = wp_nonce_url(
						add_query_arg(
							array(
								'page'        => 'photo-competition-manager-voting',
								'action'      => 'close_category_voting',
								'competition' => (int) $competition->id,
								'category'    => $category_slug,
							),
							admin_url( 'admin.php' )
						),
						'photo_competition_close_voting_' . (int) $competition->id . '_' . $category_slug
					);

					echo '<td><a href="' . esc_url( $close_url ) . '" class="button">' . esc_html__( 'Close Voting', 'photo-competition-manager' ) . '</a></td>';
				} elseif ( $voting_open_globally ) {
						echo '<td><button class="button" disabled title="' . esc_attr__( 'Another category already has voting open', 'photo-competition-manager' ) . '">' . esc_html__( 'Open Voting', 'photo-competition-manager' ) . '</button></td>';
				} else {
					$open_url = wp_nonce_url(
						add_query_arg(
							array(
								'page'        => 'photo-competition-manager-voting',
								'action'      => 'open_category_voting',
								'competition' => (int) $competition->id,
								'category'    => $category_slug,
							),
							admin_url( 'admin.php' )
						),
						'photo_competition_open_voting_' . (int) $competition->id . '_' . $category_slug
					);

					echo '<td><a href="' . esc_url( $open_url ) . '" class="button button-primary">' . esc_html__( 'Open Voting', 'photo-competition-manager' ) . '</a></td>';
				}

				++$category_index;

				// Slideshow button.
				if ( $image_count > 0 ) {
					echo '<td>';
					// Only allow slideshow if this category has voting open OR no category has voting open.
					$can_start_slideshow = $is_open || ! $voting_open_globally;

					if ( $can_start_slideshow ) {
						echo '<button type="button" class="button photo-competition-manager-start-slideshow" ';
						echo 'data-competition-id="' . esc_attr( $competition->id ) . '" ';
						echo 'data-competition-slug="' . esc_attr( $competition->slug ) . '" ';
						echo 'data-category="' . esc_attr( $category_slug ) . '" ';
						echo 'data-category-label="' . esc_attr( $category_label ) . '">';
						echo esc_html(
							sprintf(
							/* translators: %d: number of images */
								_n(
									'Start Slideshow (%d image)',
									'Start Slideshow (%d images)',
									$image_count,
									'photo-competition-manager'
								),
								$image_count
							)
						);
						echo '</button>';
					} else {
						echo '<button type="button" class="button" disabled title="' . esc_attr__( 'Close voting in other category first', 'photo-competition-manager' ) . '">';
						echo esc_html(
							sprintf(
							/* translators: %d: number of images */
								_n(
									'Start Slideshow (%d image)',
									'Start Slideshow (%d images)',
									$image_count,
									'photo-competition-manager'
								),
								$image_count
							)
						);
						echo '</button>';
					}
					echo '</td>';
				} else {
					echo '<td><em>' . esc_html__( 'No images', 'photo-competition-manager' ) . '</em></td>';
				}

				echo '</tr>';
			}
		}

		echo '</tbody>';
		echo '</table>';

		if ( $voting_open_globally ) {
			echo '<div class="notice notice-info inline" style="max-width: 900px; margin-top: 20px;">';
			echo '<p><strong>' . esc_html__( 'Note:', 'photo-competition-manager' ) . '</strong> ';
			echo esc_html__( 'Voting is currently open for one category. Close it before opening voting for another category.', 'photo-competition-manager' );
			echo '</p>';
			echo '</div>';
		}

		// Slideshow settings.
		echo '<div class="slideshow-settings-panel" style="max-width: 900px; margin-top: 30px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">';
		echo '<h3 style="margin-top: 0;">' . esc_html__( 'Slideshow Settings', 'photo-competition-manager' ) . '</h3>';
		echo '<p>';
		echo '<label for="slideshow-duration-setting" style="display: inline-block; min-width: 250px;">';
		echo esc_html__( 'Display duration per image (seconds):', 'photo-competition-manager' );
		echo '</label>';
		echo '<input type="number" id="slideshow-duration-setting" min="0" max="60" value="10" step="1" style="width: 80px;" />';
		echo ' <span class="description">' . esc_html__( 'How long each image is shown before advancing to the next.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';
		echo '<p class="description" style="margin-top: 5px; color: #666;">';
		echo esc_html__( 'Set to 0 for manual mode: the slideshow will only advance when you press Space or the right arrow key.', 'photo-competition-manager' );
		echo '</p>';
		echo '</div>';

		// Slideshow container (hidden by default).
		$this->render_slideshow_container();

		// Results page links at the end.
		$this->render_results_links();

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
	 * AJAX handler for toggling workflow guide display preference.
	 *
	 * @return void
	 */
	public function ajax_toggle_workflow(): void {
		check_ajax_referer( 'photo_comp_workflow_toggle', 'nonce' );

		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$expanded = isset( $_POST['expanded'] ) ? sanitize_text_field( wp_unslash( $_POST['expanded'] ) ) : '1';
		$user_id  = get_current_user_id();

		update_user_meta( $user_id, 'photo_comp_workflow_expanded', $expanded );

		wp_send_json_success();
	}

	/**
	 * Render competition night workflow guide.
	 *
	 * @return void
	 */
	private function render_workflow_guide(): void {
		$user_id     = get_current_user_id();
		$is_expanded = get_user_meta( $user_id, 'photo_comp_workflow_expanded', true );

		// Default to expanded on first view.
		if ( '' === $is_expanded ) {
			$is_expanded = '1';
		}

		$is_expanded_bool = '1' === $is_expanded;
		?>
		<div class="notice notice-info photo-comp-workflow-guide" style="position: relative; padding-right: 38px;">
			<h3 style="margin-top: 0.5em; margin-bottom: 0.5em; cursor: pointer;" id="photo-comp-workflow-toggle">
				<span class="dashicons dashicons-arrow-<?php echo $is_expanded_bool ? 'down' : 'right'; ?>" style="color: #2271b1;"></span>
				<?php esc_html_e( 'Competition Night Workflow Instructions', 'photo-competition-manager' ); ?>
			</h3>
			<div id="photo-comp-workflow-content" style="display: <?php echo $is_expanded_bool ? 'block' : 'none'; ?>;">
				<p><?php esc_html_e( 'Follow these steps for each category during your competition meeting:', 'photo-competition-manager' ); ?></p>
				<ol style="margin-left: 2em; line-height: 1.8;">
					<li>
						<strong><?php esc_html_e( 'Close Uploads', 'photo-competition-manager' ); ?></strong>
						<span style="color: #666;"> — <?php esc_html_e( 'Prevent last-minute submissions', 'photo-competition-manager' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Hide Results', 'photo-competition-manager' ); ?></strong>
						<span style="color: #666;"> — <?php esc_html_e( 'Ensure results page is not visible yet', 'photo-competition-manager' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Preview Slideshow', 'photo-competition-manager' ); ?></strong>
						<span style="color: #666;"> — <?php esc_html_e( 'Click "Slideshow" to preview images before voting', 'photo-competition-manager' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Open Voting', 'photo-competition-manager' ); ?></strong>
						<span style="color: #666;"> — <?php esc_html_e( 'Click "Open Voting" and display QR code for members', 'photo-competition-manager' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Start Slideshow', 'photo-competition-manager' ); ?></strong>
						<span style="color: #666;"> — <?php esc_html_e( 'Members view and vote simultaneously', 'photo-competition-manager' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Close Voting', 'photo-competition-manager' ); ?></strong>
						<span style="color: #666;"> — <?php esc_html_e( 'Wait 1-2 minutes after slideshow ends, then click "Close Voting"', 'photo-competition-manager' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Repeat for Next Category', 'photo-competition-manager' ); ?></strong>
						<span style="color: #666;"> — <?php esc_html_e( 'Continue steps 3-6 for each remaining category', 'photo-competition-manager' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Display Results', 'photo-competition-manager' ); ?></strong>
						<span style="color: #666;"> — <?php esc_html_e( 'After all voting is complete, click "Display Results" to make results public', 'photo-competition-manager' ); ?></span>
					</li>
				</ol>
				<p style="margin-bottom: 0.5em;">
					<em><?php esc_html_e( 'Tip: Only one category can have voting open at a time to avoid confusion.', 'photo-competition-manager' ); ?></em>
				</p>
			</div>
		</div>
		<?php
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
	 * Render the active category focus panel for streamlined competition night workflow.
	 *
	 * @param array       $open_competitions    Array of open competition objects.
	 * @param bool        $voting_open_globally Whether voting is open for any category.
	 * @param int|null    $open_competition_id  ID of competition with voting open.
	 * @param string|null $open_category_slug   Slug of category with voting open.
	 * @return void
	 */
	private function render_focus_panel( array $open_competitions, bool $voting_open_globally, ?int $open_competition_id, ?string $open_category_slug ): void {
		// Build list of all available categories across competitions.
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
			return; // No categories with images found.
		}

		// Determine which category to show - check URL parameter first, then voting open, then first available.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display parameter.
		$selected_key = isset( $_GET['focus'] ) ? sanitize_text_field( wp_unslash( $_GET['focus'] ) ) : '';

		$active_competition = null;
		$active_category    = null;
		$active_settings    = null;
		$image_count        = 0;

		// Try to find the selected category from URL parameter.
		if ( ! empty( $selected_key ) ) {
			foreach ( $all_categories as $cat_data ) {
				if ( $cat_data['key'] === $selected_key ) {
					$active_competition = $cat_data['competition'];
					$active_settings    = $cat_data['settings'];
					$active_category    = $cat_data['category'];
					$image_count        = $cat_data['image_count'];
					break;
				}
			}
		}

		// If voting is open for a category, use that (unless explicitly overridden by URL).
		if ( ! $active_competition && $voting_open_globally && $open_competition_id ) {
			foreach ( $all_categories as $cat_data ) {
				if ( (int) $cat_data['competition']->id === $open_competition_id && ( $cat_data['category']['slug'] ?? '' ) === $open_category_slug ) {
					$active_competition = $cat_data['competition'];
					$active_settings    = $cat_data['settings'];
					$active_category    = $cat_data['category'];
					$image_count        = $cat_data['image_count'];
					break;
				}
			}
		}

		// Fall back to first available category.
		if ( ! $active_competition ) {
			$active_competition = $all_categories[0]['competition'];
			$active_settings    = $all_categories[0]['settings'];
			$active_category    = $all_categories[0]['category'];
			$image_count        = $all_categories[0]['image_count'];
		}

		if ( ! $active_competition || ! $active_category ) {
			return; // No suitable category found.
		}

		$category_slug  = $active_category['slug'] ?? '';
		$category_label = $active_category['label'] ?? '';
		$current_key    = $active_competition->id . '_' . $category_slug;

		$is_voting_open = $voting_open_globally && $open_competition_id === (int) $active_competition->id && $open_category_slug === $category_slug;

		// Get competition-level settings.
		$uploads_closed  = $active_settings['upload']['uploads_closed'] ?? false;
		$results_visible = $active_settings['results']['results_visible'] ?? false;

		// Build action URLs with focus parameter preserved.
		$focus_args = array(
			'page'  => 'photo-competition-manager-voting',
			'focus' => $current_key,
		);

		$close_uploads_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					$focus_args,
					array(
						'action'      => 'close_uploads',
						'competition' => (int) $active_competition->id,
					)
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_close_uploads_' . (int) $active_competition->id
		);

		$open_uploads_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					$focus_args,
					array(
						'action'      => 'open_uploads',
						'competition' => (int) $active_competition->id,
					)
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_open_uploads_' . (int) $active_competition->id
		);

		$show_results_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					$focus_args,
					array(
						'action'      => 'show_results',
						'competition' => (int) $active_competition->id,
					)
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_show_results_' . (int) $active_competition->id
		);

		$hide_results_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					$focus_args,
					array(
						'action'      => 'hide_results',
						'competition' => (int) $active_competition->id,
					)
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_hide_results_' . (int) $active_competition->id
		);

		$open_voting_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					$focus_args,
					array(
						'action'      => 'open_category_voting',
						'competition' => (int) $active_competition->id,
						'category'    => $category_slug,
					)
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_open_voting_' . (int) $active_competition->id . '_' . $category_slug
		);

		$close_voting_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					$focus_args,
					array(
						'action'      => 'close_category_voting',
						'competition' => (int) $active_competition->id,
						'category'    => $category_slug,
					)
				),
				admin_url( 'admin.php' )
			),
			'photo_competition_close_voting_' . (int) $active_competition->id . '_' . $category_slug
		);

		?>
		<div id="focus-panel" class="photo-comp-focus-panel" data-competition-id="<?php echo esc_attr( $active_competition->id ); ?>" data-category="<?php echo esc_attr( $category_slug ); ?>">
			<?php if ( count( $all_categories ) > 1 ) : ?>
			<div class="focus-panel-category-tabs">
				<?php
				foreach ( $all_categories as $cat_data ) :
					$tab_key        = $cat_data['key'];
					$tab_comp       = $cat_data['competition'];
					$tab_cat        = $cat_data['category'];
					$tab_count      = $cat_data['image_count'];
					$tab_is_active  = ( $tab_key === $current_key );
					$tab_has_voting = $voting_open_globally && (int) $tab_comp->id === $open_competition_id && ( $tab_cat['slug'] ?? '' ) === $open_category_slug;
					$tab_url        = add_query_arg(
						array(
							'page'  => 'photo-competition-manager-voting',
							'focus' => $tab_key,
						),
						admin_url( 'admin.php' )
					) . '#focus-panel';
					?>
					<a href="<?php echo esc_url( $tab_url ); ?>" class="category-tab <?php echo $tab_is_active ? 'active' : ''; ?> <?php echo $tab_has_voting ? 'has-voting' : ''; ?>">
						<span class="tab-label"><?php echo esc_html( $tab_cat['label'] ?? '' ); ?></span>
						<span class="tab-count"><?php echo (int) $tab_count; ?></span>
						<?php if ( $tab_has_voting ) : ?>
							<span class="tab-voting-indicator" title="<?php esc_attr_e( 'Voting open', 'photo-competition-manager' ); ?>"></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<div class="focus-panel-header">
				<div class="focus-panel-title">
					<span class="focus-panel-label"><?php esc_html_e( 'Active Category', 'photo-competition-manager' ); ?></span>
					<h2><?php echo esc_html( $active_competition->title ); ?> &mdash; <?php echo esc_html( $category_label ); ?></h2>
					<span class="focus-panel-image-count">
						<?php
						printf(
							/* translators: %d: number of images */
							esc_html( _n( '%d image', '%d images', $image_count, 'photo-competition-manager' ) ),
							(int) $image_count
						);
						?>
					</span>
				</div>
				<div class="focus-panel-status">
					<?php if ( $is_voting_open ) : ?>
						<span class="status-badge status-voting-open">
							<span class="dashicons dashicons-yes-alt"></span>
							<?php esc_html_e( 'Voting Open', 'photo-competition-manager' ); ?>
						</span>
					<?php else : ?>
						<span class="status-badge status-ready">
							<span class="dashicons dashicons-clock"></span>
							<?php esc_html_e( 'Ready', 'photo-competition-manager' ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>

			<!-- Uploads & Results Row -->
			<div class="focus-panel-controls focus-panel-controls-setup">
				<!-- Uploads Control -->
				<div class="control-group uploads-controls">
					<label class="control-label"><?php esc_html_e( 'Uploads', 'photo-competition-manager' ); ?></label>
					<div class="control-row">
						<?php if ( $uploads_closed ) : ?>
							<span class="status-indicator status-closed">
								<span class="dashicons dashicons-lock"></span>
								<?php esc_html_e( 'Closed', 'photo-competition-manager' ); ?>
							</span>
							<a href="<?php echo esc_url( $open_uploads_url ); ?>" class="button button-small">
								<?php esc_html_e( 'Reopen', 'photo-competition-manager' ); ?>
							</a>
						<?php else : ?>
							<span class="status-indicator status-open">
								<span class="dashicons dashicons-unlock"></span>
								<?php esc_html_e( 'Open', 'photo-competition-manager' ); ?>
							</span>
							<a href="<?php echo esc_url( $close_uploads_url ); ?>" class="button button-primary button-small">
								<?php esc_html_e( 'Close', 'photo-competition-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<!-- Results Control -->
				<div class="control-group results-controls">
					<label class="control-label"><?php esc_html_e( 'Results', 'photo-competition-manager' ); ?></label>
					<div class="control-row">
						<?php if ( $results_visible ) : ?>
							<span class="status-indicator status-visible">
								<span class="dashicons dashicons-visibility"></span>
								<?php esc_html_e( 'Visible', 'photo-competition-manager' ); ?>
							</span>
							<a href="<?php echo esc_url( $hide_results_url ); ?>" class="button button-small">
								<?php esc_html_e( 'Hide', 'photo-competition-manager' ); ?>
							</a>
						<?php else : ?>
							<span class="status-indicator status-hidden">
								<span class="dashicons dashicons-hidden"></span>
								<?php esc_html_e( 'Hidden', 'photo-competition-manager' ); ?>
							</span>
							<a href="<?php echo esc_url( $show_results_url ); ?>" class="button button-small">
								<?php esc_html_e( 'Show', 'photo-competition-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- Slideshow, Voting & Critique Row -->
			<div class="focus-panel-controls">
				<!-- Slideshow Controls -->
				<div class="control-group slideshow-controls">
					<label class="control-label"><?php esc_html_e( 'Slideshow', 'photo-competition-manager' ); ?></label>
					<div class="control-row">
						<div class="duration-presets">
							<button type="button" class="button duration-preset" data-duration="5"><?php esc_html_e( '5s', 'photo-competition-manager' ); ?></button>
							<button type="button" class="button duration-preset active" data-duration="10"><?php esc_html_e( '10s', 'photo-competition-manager' ); ?></button>
							<button type="button" class="button duration-preset" data-duration="15"><?php esc_html_e( '15s', 'photo-competition-manager' ); ?></button>
							<button type="button" class="button duration-preset" data-duration="0" title="<?php esc_attr_e( 'Manual: advance with Space or arrow keys', 'photo-competition-manager' ); ?>"><?php esc_html_e( 'Manual', 'photo-competition-manager' ); ?></button>
						</div>
						<?php if ( $image_count > 0 ) : ?>
							<button type="button" class="button button-primary button-large photo-competition-manager-start-slideshow focus-panel-slideshow-btn"
								data-competition-id="<?php echo esc_attr( $active_competition->id ); ?>"
								data-competition-slug="<?php echo esc_attr( $active_competition->slug ); ?>"
								data-category="<?php echo esc_attr( $category_slug ); ?>"
								data-category-label="<?php echo esc_attr( $category_label ); ?>">
								<span class="dashicons dashicons-slides"></span>
								<?php esc_html_e( 'Start Slideshow', 'photo-competition-manager' ); ?>
							</button>
						<?php else : ?>
							<button type="button" class="button button-large" disabled>
								<span class="dashicons dashicons-slides"></span>
								<?php esc_html_e( 'No Images', 'photo-competition-manager' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>

				<!-- Voting Controls -->
				<div class="control-group voting-controls">
					<label class="control-label"><?php esc_html_e( 'Voting', 'photo-competition-manager' ); ?></label>
					<div class="control-row">
						<?php if ( $is_voting_open ) : ?>
							<a href="<?php echo esc_url( $close_voting_url ); ?>" class="button button-large focus-panel-voting-btn voting-close">
								<span class="dashicons dashicons-lock"></span>
								<?php esc_html_e( 'Close Voting', 'photo-competition-manager' ); ?>
							</a>
						<?php else : ?>
							<a href="<?php echo esc_url( $open_voting_url ); ?>" class="button button-primary button-large focus-panel-voting-btn voting-open">
								<span class="dashicons dashicons-unlock"></span>
								<?php esc_html_e( 'Open Voting', 'photo-competition-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>
				</div>

				<!-- Critique Mode -->
				<div class="control-group critique-controls">
					<label class="control-label"><?php esc_html_e( 'Critique', 'photo-competition-manager' ); ?></label>
					<div class="control-row">
						<?php if ( $image_count > 0 ) : ?>
							<button type="button" class="button button-large photo-competition-manager-start-critique"
								data-competition-id="<?php echo esc_attr( $active_competition->id ); ?>"
								data-competition-slug="<?php echo esc_attr( $active_competition->slug ); ?>"
								data-category="<?php echo esc_attr( $category_slug ); ?>"
								data-category-label="<?php echo esc_attr( $category_label ); ?>"
								title="<?php esc_attr_e( 'Manual slideshow for discussion - advance with Space or arrow keys', 'photo-competition-manager' ); ?>">
								<span class="dashicons dashicons-format-chat"></span>
								<?php esc_html_e( 'Critique Mode', 'photo-competition-manager' ); ?>
							</button>
						<?php else : ?>
							<button type="button" class="button button-large" disabled>
								<span class="dashicons dashicons-format-chat"></span>
								<?php esc_html_e( 'No Images', 'photo-competition-manager' ); ?>
							</button>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<p class="focus-panel-hint">
				<?php if ( $is_voting_open ) : ?>
					<span class="dashicons dashicons-info"></span>
					<?php esc_html_e( 'Members can now vote. Start slideshow to display images, then close voting when done.', 'photo-competition-manager' ); ?>
				<?php elseif ( ! $uploads_closed || $results_visible ) : ?>
					<span class="dashicons dashicons-warning"></span>
					<?php esc_html_e( 'Before voting: close uploads and hide results.', 'photo-competition-manager' ); ?>
				<?php else : ?>
					<span class="dashicons dashicons-info"></span>
					<?php esc_html_e( 'Ready to vote. Preview slideshow, then open voting. Use critique mode after voting for discussion.', 'photo-competition-manager' ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
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
