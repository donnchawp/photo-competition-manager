<?php
/**
 * Voting controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

use PhotoCompetitionManager\Admin\Traits\Date_Formatting;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

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

			check_admin_referer( 'photo_competition_open_voting_' . $competition_id . '_' . $category_slug );

			// Global constraint validation: ensure no other category has voting open.
			$all_competitions = $this->competitions->all( 100, false, false );
			foreach ( $all_competitions as $comp ) {
				$comp_settings  = Competition_Settings::parse( $comp->settings );
				$comp_open_cats = Competition_Settings::get_open_voting_categories( $comp_settings );

				if ( ! empty( $comp_open_cats ) ) {
					add_settings_error(
						'photo_competition_voting',
						'voting_already_open',
						__( 'Cannot open voting. Another category already has voting open. Close it first.', 'photo-competition-manager' ),
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
					'photo_competition_voting',
					'competition_not_found',
					__( 'Competition not found.', 'photo-competition-manager' ),
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

			check_admin_referer( 'photo_competition_close_voting_' . $competition_id . '_' . $category_slug );

			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'photo_competition_voting',
					'competition_not_found',
					__( 'Competition not found.', 'photo-competition-manager' ),
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

			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'club-competitions-voting' ),
					admin_url( 'admin.php' )
				)
			);
			exit;
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

				wp_safe_redirect(
					add_query_arg(
						array( 'page' => 'club-competitions-voting' ),
						admin_url( 'admin.php' )
					)
				);
				exit;
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

			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'club-competitions-voting' ),
					admin_url( 'admin.php' )
				)
			);
			exit;
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

				wp_safe_redirect(
					add_query_arg(
						array( 'page' => 'club-competitions-voting' ),
						admin_url( 'admin.php' )
					)
				);
				exit;
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( 'photo_competition_voting' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Voting Controls', 'photo-competition-manager' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Open or close voting for competition categories. Only one category across all competitions can have voting open at a time.', 'photo-competition-manager' ) . '</p>';

		// Get all active competitions.
		$all_competitions    = $this->competitions->all( 100, false, false );
		$active_competitions = array_filter(
			$all_competitions,
			function ( $comp ) {
				return 'active' === $comp->status;
			}
		);

		if ( empty( $active_competitions ) ) {
			echo '<p>' . esc_html__( 'No active competitions found. Set a competition status to "Active" to enable voting controls.', 'photo-competition-manager' ) . '</p>';
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

		echo '<table class="widefat striped" style="max-width: 1200px; margin-top: 20px;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Competition', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Category', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Uploads', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Voting', 'photo-competition-manager' ) . '</th>';
		echo '<th>' . esc_html__( 'Slideshow', 'photo-competition-manager' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $active_competitions as $competition ) {
			$settings        = Competition_Settings::parse( $competition->settings );
			$categories      = Competition_Settings::get_categories( $settings );
			$open_categories = Competition_Settings::get_open_voting_categories( $settings );
			$uploads_closed  = $settings['upload']['uploads_closed'] ?? false;

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
									'page'        => 'club-competitions-voting',
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
									'page'        => 'club-competitions-voting',
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
				}

				// Voting column.
				if ( $is_open ) {
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
						'photo_competition_close_voting_' . (int) $competition->id . '_' . $category_slug
					);

					echo '<td><a href="' . esc_url( $close_url ) . '" class="button">' . esc_html__( 'Close Voting', 'photo-competition-manager' ) . '</a></td>';
				} elseif ( $voting_open_globally ) {
						echo '<td><button class="button" disabled title="' . esc_attr__( 'Another category already has voting open', 'photo-competition-manager' ) . '">' . esc_html__( 'Open Voting', 'photo-competition-manager' ) . '</button></td>';
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
		echo '<input type="number" id="slideshow-duration-setting" min="3" max="60" value="10" step="1" style="width: 80px;" />';
		echo ' <span class="description">' . esc_html__( 'How long each image is shown before advancing to the next.', 'photo-competition-manager' ) . '</span>';
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
}
