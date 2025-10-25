<?php
/**
 * Voting controller for admin interface.
 *
 * @package ClubCompetitions\Admin
 */

namespace ClubCompetitions\Admin;

use ClubCompetitions\Admin\Traits\Date_Formatting;
use ClubCompetitions\Repository\Competitions_Repository;
use ClubCompetitions\Repository\Images_Repository;
use ClubCompetitions\Support\Competition_Settings;

/**
 * Manage voting controls page.
 *
 * @since 0.1.0
 */
class Voting_Controller {

	use Date_Formatting;

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
	 * Constructor.
	 *
	 * @param Competitions_Repository $competitions Competitions repository.
	 * @param Images_Repository       $images       Images repository.
	 */
	public function __construct(
		Competitions_Repository $competitions,
		Images_Repository $images
	) {
		$this->competitions = $competitions;
		$this->images       = $images;
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
		if ( ! current_user_can( 'publish_posts' ) ) {
			return;
		}

		$action = '';

		if ( isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; mutations require explicit nonce checks below.
			$action = sanitize_key( wp_unslash( $_GET['action'] ) );
		}

		if ( 'open_category_voting' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;
			$category_slug  = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';

			check_admin_referer( 'club_competitions_open_voting_' . $competition_id . '_' . $category_slug );

			// Global constraint validation: ensure no other category has voting open.
			$all_competitions = $this->competitions->all( 100, false, false );
			foreach ( $all_competitions as $comp ) {
				$comp_settings  = Competition_Settings::parse( $comp->settings );
				$comp_open_cats = Competition_Settings::get_open_voting_categories( $comp_settings );

				if ( ! empty( $comp_open_cats ) ) {
					add_settings_error(
						'club_competitions_voting',
						'voting_already_open',
						__( 'Cannot open voting. Another category already has voting open. Close it first.', 'club-competitions' ),
						'error'
					);

					wp_safe_redirect(
						add_query_arg(
							array( 'page' => 'club-competitions-voting' ),
							admin_url( 'admin.php' )
						)
					);
						exit;
				}
			}

			// Open voting for this category.
			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'club_competitions_voting',
					'competition_not_found',
					__( 'Competition not found.', 'club-competitions' ),
					'error'
				);

				wp_safe_redirect(
					add_query_arg(
						array( 'page' => 'club-competitions-voting' ),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$settings                              = Competition_Settings::parse( $competition->settings );
			$settings['voting']['open_categories'] = array( $category_slug );

			$result = $this->competitions->update(
				$competition_id,
				array( 'settings' => $settings )
			);

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions_voting',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'club_competitions_voting',
					'voting_opened',
					__( 'Voting opened successfully.', 'club-competitions' ),
					'updated'
				);
			}

			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'club-competitions-voting' ),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'close_category_voting' === $action ) {
			$competition_id = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;
			$category_slug  = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';

			check_admin_referer( 'club_competitions_close_voting_' . $competition_id . '_' . $category_slug );

			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'club_competitions_voting',
					'competition_not_found',
					__( 'Competition not found.', 'club-competitions' ),
					'error'
				);

				wp_safe_redirect(
					add_query_arg(
						array( 'page' => 'club-competitions-voting' ),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$settings                              = Competition_Settings::parse( $competition->settings );
			$settings['voting']['open_categories'] = array();

			$result = $this->competitions->update(
				$competition_id,
				array( 'settings' => $settings )
			);

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions_voting',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'club_competitions_voting',
					'voting_closed',
					__( 'Voting closed successfully.', 'club-competitions' ),
					'updated'
				);
			}

			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'club-competitions-voting' ),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Render voting controls page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'publish_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'club-competitions' ) );
		}

		settings_errors( 'club_competitions_voting' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Voting Controls', 'club-competitions' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Open or close voting for competition categories. Only one category across all competitions can have voting open at a time.', 'club-competitions' ) . '</p>';

		// Get all active competitions.
		$all_competitions    = $this->competitions->all( 100, false, false );
		$active_competitions = array_filter(
			$all_competitions,
			function ( $comp ) {
				return 'active' === $comp->status;
			}
		);

		if ( empty( $active_competitions ) ) {
			echo '<p>' . esc_html__( 'No active competitions found. Set a competition status to "Active" to enable voting controls.', 'club-competitions' ) . '</p>';
			echo '</div>';
			return;
		}

		// Check if any category has voting open globally.
		$voting_open_globally = false;
		$open_competition_id  = null;
		$open_category_slug   = null;
		$open_competition     = null;

		foreach ( $active_competitions as $competition ) {
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
			echo '<div class="club-compete-qr-card" data-voting-url="' . esc_attr( $voting_page_url ) . '">';
			echo '<div class="club-compete-qr-image" role="img" aria-label="' . esc_attr__( 'Voting page QR code', 'club-competitions' ) . '">';
			echo '<div class="club-compete-qr-canvas"></div>';
			echo '</div>';
			echo '<div class="club-compete-qr-details">';
			echo '<h2>' . esc_html__( 'Voting Page QR Code', 'club-competitions' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Display this code so voters can quickly open the voting page on their devices.', 'club-competitions' ) . '</p>';

			if ( 'competition' === $voting_page_source && ! empty( $voting_competition ) ) {
				echo '<p><strong>' . esc_html__( 'Competition:', 'club-competitions' ) . '</strong> ' . esc_html( $voting_competition ) . '</p>';

				if ( ! empty( $voting_category ) ) {
					echo '<p><strong>' . esc_html__( 'Category:', 'club-competitions' ) . '</strong> ' . esc_html( $voting_category ) . '</p>';
				}
			} elseif ( 'default' === $voting_page_source ) {
				echo '<p class="description">' . esc_html__( 'No competition-specific voting page is configured. Using the default voting page URL.', 'club-competitions' ) . '</p>';
			}

			echo '<p><a href="' . esc_url( $voting_page_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $voting_page_url ) . '</a></p>';
			echo '</div>';
			echo '</div>';
		} else {
			echo '<div class="notice notice-warning inline" style="max-width: 900px; margin-top: 20px;">';
			echo '<p><strong>' . esc_html__( 'Voting page not configured.', 'club-competitions' ) . '</strong> ';
			echo esc_html__( 'Add a voting page URL to the competition or default settings to show a QR code here.', 'club-competitions' );
			echo '</p>';
			echo '</div>';
		}

		echo '<table class="widefat striped" style="max-width: 1100px; margin-top: 20px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Competition', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Category', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Voting', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Slideshow', 'club-competitions' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $active_competitions as $competition ) {
			$settings        = Competition_Settings::parse( $competition->settings );
			$categories      = Competition_Settings::get_categories( $settings );
			$open_categories = Competition_Settings::get_open_voting_categories( $settings );

			if ( empty( $categories ) ) {
				echo '<tr>';
				echo '<td>' . esc_html( $competition->title ) . '</td>';
				echo '<td colspan="4"><em>' . esc_html__( 'No categories configured', 'club-competitions' ) . '</em></td>';
				echo '</tr>';
				continue;
			}

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
					echo '<td><strong style="color: #2271b1;">' . esc_html__( 'Voting Open', 'club-competitions' ) . '</strong></td>';

					$close_url = wp_nonce_url(
						add_query_arg(
							array(
								'page'        => 'club-competitions-voting',
								'action'      => 'close_category_voting',
								'competition' => (int) $competition->id,
								'category'    => rawurlencode( $category_slug ),
							),
							admin_url( 'admin.php' )
						),
						'club_competitions_close_voting_' . (int) $competition->id . '_' . $category_slug
					);

					echo '<td><a href="' . esc_url( $close_url ) . '" class="button">' . esc_html__( 'Close Voting', 'club-competitions' ) . '</a></td>';
				} else {
					echo '<td>' . esc_html__( 'Closed', 'club-competitions' ) . '</td>';

					if ( $voting_open_globally ) {
						echo '<td><button class="button" disabled title="' . esc_attr__( 'Another category already has voting open', 'club-competitions' ) . '">' . esc_html__( 'Open Voting', 'club-competitions' ) . '</button></td>';
					} else {
						$open_url = wp_nonce_url(
							add_query_arg(
								array(
									'page'        => 'club-competitions-voting',
									'action'      => 'open_category_voting',
									'competition' => (int) $competition->id,
									'category'    => rawurlencode( $category_slug ),
								),
								admin_url( 'admin.php' )
							),
							'club_competitions_open_voting_' . (int) $competition->id . '_' . $category_slug
						);

						echo '<td><a href="' . esc_url( $open_url ) . '" class="button button-primary">' . esc_html__( 'Open Voting', 'club-competitions' ) . '</a></td>';
					}
				}

				// Slideshow button.
				if ( $image_count > 0 ) {
					echo '<td>';
					// Only allow slideshow if this category has voting open OR no category has voting open.
					$can_start_slideshow = $is_open || ! $voting_open_globally;

					if ( $can_start_slideshow ) {
						echo '<button type="button" class="button club-compete-start-slideshow" ';
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
									'club-competitions'
								),
								$image_count
							)
						);
						echo '</button>';
					} else {
						echo '<button type="button" class="button" disabled title="' . esc_attr__( 'Close voting in other category first', 'club-competitions' ) . '">';
						echo esc_html(
							sprintf(
							/* translators: %d: number of images */
								_n(
									'Start Slideshow (%d image)',
									'Start Slideshow (%d images)',
									$image_count,
									'club-competitions'
								),
								$image_count
							)
						);
						echo '</button>';
					}
					echo '</td>';
				} else {
					echo '<td><em>' . esc_html__( 'No images', 'club-competitions' ) . '</em></td>';
				}

				echo '</tr>';
			}
		}

		echo '</tbody>';
		echo '</table>';

		if ( $voting_open_globally ) {
			echo '<div class="notice notice-info inline" style="max-width: 900px; margin-top: 20px;">';
			echo '<p><strong>' . esc_html__( 'Note:', 'club-competitions' ) . '</strong> ';
			echo esc_html__( 'Voting is currently open for one category. Close it before opening voting for another category.', 'club-competitions' );
			echo '</p>';
			echo '</div>';
		}

		// Slideshow settings.
		echo '<div class="slideshow-settings-panel" style="max-width: 900px; margin-top: 30px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">';
		echo '<h3 style="margin-top: 0;">' . esc_html__( 'Slideshow Settings', 'club-competitions' ) . '</h3>';
		echo '<p>';
		echo '<label for="slideshow-duration-setting" style="display: inline-block; min-width: 250px;">';
		echo esc_html__( 'Display duration per image (seconds):', 'club-competitions' );
		echo '</label>';
		echo '<input type="number" id="slideshow-duration-setting" min="3" max="60" value="10" step="1" style="width: 80px;" />';
		echo ' <span class="description">' . esc_html__( 'How long each image is shown before advancing to the next.', 'club-competitions' ) . '</span>';
		echo '</p>';
		echo '</div>';

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
		<div id="club-compete-slideshow-modal" class="slideshow-display" style="display: none;">
			<div class="slideshow-image-container">
				<img src="" alt="" class="slideshow-current-image" />
				<div class="slideshow-image-info">
					<span class="image-number"></span>
				</div>
			</div>
			<div class="slideshow-progress">
				<div class="progress-bar" style="width: 0%;"></div>
			</div>
			<button type="button" class="slideshow-exit" aria-label="<?php esc_attr_e( 'Exit slideshow', 'club-competitions' ); ?>">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
			<div class="slideshow-controls-overlay">
				<button type="button" class="button button-large slideshow-pause">
		<?php esc_html_e( 'Pause', 'club-competitions' ); ?>
				</button>
				<button type="button" class="button button-large slideshow-resume" style="display: none;">
		<?php esc_html_e( 'Resume', 'club-competitions' ); ?>
				</button>
				<button type="button" class="button button-large button-primary slideshow-stop">
		<?php esc_html_e( 'Stop Slideshow', 'club-competitions' ); ?>
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
		$saved = get_option( 'club_competitions_default_settings', '' );
		return Competition_Settings::parse( $saved );
	}
}
