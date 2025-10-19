<?php
/**
 * Admin interface hooks.
 *
 * @package ClubCompetitions\Admin
 */

namespace ClubCompetitions\Admin;

use ClubCompetitions\Repository\Competitions_Repository;
use ClubCompetitions\Repository\Images_Repository;
use ClubCompetitions\Repository\Members_Repository;
use ClubCompetitions\Repository\Votes_Repository;
use ClubCompetitions\Support\Competition_Settings;

/**
 * Manage Club Competitions admin menus, screens, and actions.
 *
 * @since 0.1.0
 */
class Admin_Screen {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository|null $competitions Competition repository.
	 * @param Members_Repository|null      $members      Member repository.
	 * @param Images_Repository|null       $images       Images repository.
	 * @param Votes_Repository|null        $votes        Votes repository.
	 */
	public function __construct( ?Competitions_Repository $competitions = null, ?Members_Repository $members = null, ?Images_Repository $images = null, ?Votes_Repository $votes = null ) {
		$this->competitions = $competitions ?? new Competitions_Repository();
		$this->members      = $members ?? new Members_Repository();
		$this->images       = $images ?? new Images_Repository();
		$this->votes        = $votes ?? new Votes_Repository();
	}

	/**
	 * Attach admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Register primary plugin menu.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'Club Competitions', 'club-competitions' ),
			__( 'Competitions', 'club-competitions' ),
			'manage_options',
			'club-competitions',
			array( $this, 'render_dashboard' ),
			'dashicons-camera'
		);

		add_submenu_page(
			'club-competitions',
			__( 'Members', 'club-competitions' ),
			__( 'Members', 'club-competitions' ),
			'manage_options',
			'club-competitions-members',
			array( $this, 'render_members_page' )
		);

		add_submenu_page(
			'club-competitions',
			__( 'Submissions', 'club-competitions' ),
			__( 'Submissions', 'club-competitions' ),
			'manage_options',
			'club-competitions-submissions',
			array( $this, 'render_submissions_page' )
		);

		add_submenu_page(
			'club-competitions',
			__( 'Voting Controls', 'club-competitions' ),
			__( 'Voting Controls', 'club-competitions' ),
			'manage_options',
			'club-competitions-voting',
			array( $this, 'render_voting_controls_page' )
		);

		add_submenu_page(
			'club-competitions',
			__( 'Settings', 'club-competitions' ),
			__( 'Settings', 'club-competitions' ),
			'manage_options',
			'club-competitions-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render admin dashboard overview.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view; actions enforce nonces during processing.
		$action_query = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view; actions enforce nonces during processing.
		$competition_query = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

		if ( 'edit' === $action_query && $competition_query ) {
			$this->render_edit_screen( $competition_query );
			return;
		}

		settings_errors( 'club_competitions' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars for filtering only; no data mutation.
		$view         = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'active';
		$competitions = $this->competitions->all( 10, 'archived' === $view, 'archived' === $view );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Club Competitions Dashboard', 'club-competitions' ) . '</h1>';

		$this->render_competition_table( $competitions, $view );

		$this->render_create_form();

		echo '</div>';
	}

	/**
	 * Render members list.
	 *
	 * @return void
	 */
	public function render_members_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'club-competitions' ) );
		}

		settings_errors( 'club_competitions_members' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view.
		$member_action = isset( $_GET['member_action'] ) ? sanitize_text_field( wp_unslash( $_GET['member_action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view.
		$member_id = isset( $_GET['member'] ) ? absint( wp_unslash( $_GET['member'] ) ) : 0;
		$current   = null;

		if ( 'edit' === $member_action && $member_id ) {
			$current = $this->members->find( $member_id );
		}

		$members = $this->members->all( false );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Members', 'club-competitions' ) . '</h1>';

		if ( 'edit' === $member_action ) {
			$this->render_member_edit_form( $current );
		} else {
			$this->render_member_create_form();
		}

		if ( empty( $members ) ) {
			echo '<p>' . esc_html__( 'No members recorded yet. Import or create members to get started.', 'club-competitions' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Name', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Grade', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Joined', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'club-competitions' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $members as $member ) {
			$edit_link    = add_query_arg(
				array(
					'page'          => 'club-competitions-members',
					'member_action' => 'edit',
					'member'        => (int) $member->id,
				),
				admin_url( 'admin.php' )
			);
			$status_label = $member->active ? __( 'Active', 'club-competitions' ) : __( 'Inactive', 'club-competitions' );

			echo '<tr>';
			echo '<td>' . esc_html( $member->name ) . '</td>';
			echo '<td>' . esc_html( $member->email ) . '</td>';
			echo '<td>' . esc_html( $member->grade ) . '</td>';
			echo '<td>' . esc_html( $status_label ) . '</td>';
			echo '<td>' . esc_html( $member->created_at ) . '</td>';
			echo '<td><a href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Edit', 'club-competitions' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Render submissions viewer.
	 *
	 * @return void
	 */
	public function render_submissions_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'club-competitions' ) );
		}

		settings_errors( 'club_competitions_submissions' );

		$competitions = $this->competitions->all( 200, true );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Submissions', 'club-competitions' ) . '</h1>';

		static $styles_output = false;
		if ( ! $styles_output ) {
			echo '<style id="club-competitions-submissions-css">.club-competitions-thumbnail img{max-width:120px;height:auto;border:1px solid #ccd0d4;padding:2px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,0.08);} .club-competitions-thumbnail{width:140px;}</style>';
			$styles_output = true;
		}

		if ( empty( $competitions ) ) {
			echo '<p>' . esc_html__( 'No competitions available yet. Create a competition first.', 'club-competitions' ) . '</p>';
			echo '</div>';
			return;
		}

		$competition_lookup = array();
		foreach ( $competitions as $competition ) {
			$competition_lookup[ (int) $competition->id ] = $competition;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter input for list table.
		$competition_id = isset( $_GET['competition_id'] ) ? absint( wp_unslash( $_GET['competition_id'] ) ) : 0;
		if ( ! $competition_id || ! isset( $competition_lookup[ $competition_id ] ) ) {
			$first          = reset( $competitions );
			$competition_id = $first ? (int) $first->id : 0;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading filter input for list table.
		$member_id = isset( $_GET['member_id'] ) ? absint( wp_unslash( $_GET['member_id'] ) ) : 0;

		$members    = $this->members->all( false );
		$member_map = array();
		foreach ( $members as $member ) {
			$member_map[ (int) $member->id ] = $member;
		}

		$submissions = array();
		$scores_data = array();
		if ( $competition_id ) {
			$member_filter = $member_id > 0 ? $member_id : null;
			$submissions   = $this->images->find_by_competition( $competition_id, null, $member_filter );

			// Get average scores and vote counts for all submissions.
			$scores_data = $this->votes->calculate_averages( $competition_id );
		}

		$selected_competition = $competition_id && isset( $competition_lookup[ $competition_id ] ) ? $competition_lookup[ $competition_id ] : null;

		echo '<form method="get" class="club-competitions-filters">';
		echo '<input type="hidden" name="page" value="club-competitions-submissions" />';
		echo '<label for="competition_id" class="screen-reader-text">' . esc_html__( 'Competition', 'club-competitions' ) . '</label>';
		echo '<select name="competition_id" id="competition_id">';
		foreach ( $competitions as $competition ) {
			$label = $competition->title;
			if ( ! empty( $competition->deleted_at ) || 'archived' === $competition->status ) {
				$label .= ' ' . esc_html__( '(Archived)', 'club-competitions' );
			}

			printf(
				'<option value="%1$d" %3$s>%2$s</option>',
				(int) $competition->id,
				esc_html( $label ),
				selected( $competition_id, $competition->id, false )
			);
		}
		echo '</select> ';

		echo '<label for="member_id" class="screen-reader-text">' . esc_html__( 'Member', 'club-competitions' ) . '</label>';
		echo '<select name="member_id" id="member_id">';
		echo '<option value="0">' . esc_html__( 'All Members', 'club-competitions' ) . '</option>';
		foreach ( $members as $member ) {
			printf(
				'<option value="%1$d" %3$s>%2$s</option>',
				(int) $member->id,
				esc_html( $member->name ),
				selected( $member_id, $member->id, false )
			);
		}
		echo '</select> ';

		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'club-competitions' ) . '</button>';
		echo '</form>';

		if ( $selected_competition ) {
			printf(
				'<h2>%s</h2>',
				esc_html( $selected_competition->title )
			);

			// Add regenerate numbers button.
			echo '<form method="post" style="margin-bottom: 15px;">';
			wp_nonce_field( 'club_competitions_regenerate_numbers_' . $competition_id, '_wpnonce' );
			echo '<input type="hidden" name="action" value="regenerate_numbers" />';
			echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition_id ) . '" />';
			echo '<button type="submit" class="button" onclick="return confirm(\'' . esc_js( __( 'Are you sure you want to regenerate random numbers? Each member will still have the same number across all their images, but the numbers will be reassigned.', 'club-competitions' ) ) . '\');">';
			echo esc_html__( 'Regenerate Random Numbers', 'club-competitions' );
			echo '</button>';
			echo ' <span class="description">' . esc_html__( 'Reassign random numbers to members in this competition (each member keeps one consistent number).', 'club-competitions' ) . '</span>';
			echo '</form>';
		}

		if ( empty( $submissions ) ) {
			echo '<p>' . esc_html__( 'No submissions found for the selected filters.', 'club-competitions' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Member', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Category', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Image', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Filename', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Random #', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Total Score', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Votes', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Submitted', 'club-competitions' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $submissions as $submission ) {
			$member_name = isset( $member_map[ $submission->member_id ] )
				? $member_map[ $submission->member_id ]->name
				: sprintf(
					/* translators: %d: Numeric member identifier when the name is unavailable. */
					__( 'Member #%d', 'club-competitions' ),
					(int) $submission->member_id
				);

			$current_competition = $selected_competition ?? ( $competition_lookup[ $submission->competition_id ] ?? null );
			$urls                = $this->get_submission_urls( $current_competition, $submission );
			$thumb_url           = ! empty( $urls['thumb'] ) ? $urls['thumb'] : $urls['full'];

			// Get score data for this submission.
			$image_id    = (int) $submission->id;
			$total_score = '—';
			$vote_count  = 0;

			if ( isset( $scores_data[ $image_id ] ) ) {
				$score_info  = $scores_data[ $image_id ];
				$total_score = number_format( $score_info['average_score'], 0 );
				$vote_count  = $score_info['vote_count'];
			}

			echo '<tr>';
			echo '<td>' . esc_html( $member_name ) . '</td>';
			echo '<td>' . esc_html( $submission->category ) . '</td>';
			if ( $urls['full'] ) {
				echo '<td class="club-competitions-thumbnail"><a href="' . esc_url( $urls['full'] ) . '" target="_blank" rel="noopener noreferrer">';
				echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $submission->filename ) . '" width="120" height="120" loading="lazy" />';
				echo '</a></td>';
			} else {
				echo '<td>' . esc_html__( 'Unavailable', 'club-competitions' ) . '</td>';
			}
			echo '<td>' . esc_html( $submission->filename ) . '</td>';
			echo '<td>' . esc_html( (string) $submission->random_number ) . '</td>';
			echo '<td>' . esc_html( $total_score ) . '</td>';
			echo '<td>' . esc_html( (string) $vote_count ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $submission->created_at ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Render voting controls page.
	 *
	 * @return void
	 */
	public function render_voting_controls_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
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

		foreach ( $active_competitions as $competition ) {
			$settings        = Competition_Settings::parse( $competition->settings );
			$open_categories = Competition_Settings::get_open_voting_categories( $settings );

			if ( ! empty( $open_categories ) ) {
				$voting_open_globally = true;
				$open_competition_id  = (int) $competition->id;
				$open_category_slug   = $open_categories[0];
				break;
			}
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
	 * Handle admin post actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = '';

		if ( isset( $_POST['club_competitions_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['club_competitions_action'] ) );
		} elseif ( isset( $_POST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; mutations require explicit nonce checks below.
			$action = sanitize_key( wp_unslash( $_POST['action'] ) );
		} elseif ( isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; mutations require explicit nonce checks below.
			$action = sanitize_key( wp_unslash( $_GET['action'] ) );
		}

		if ( '' === $action ) {
			return;
		}

		if ( 'create_competition' === $action ) {
			check_admin_referer( 'club_competitions_create', 'club_competitions_nonce' );

			$title_raw       = $this->get_post_string( 'competition_title' );
			$slug_raw        = $this->get_post_string( 'competition_slug' );
			$status_raw      = $this->get_post_string( 'competition_status', 'draft' );
			$open_date_raw   = $this->get_post_string( 'competition_open_date' );
			$close_date_raw  = $this->get_post_string( 'competition_close_date' );
			$voting_open_raw = $this->get_post_string( 'competition_voting_open' );

			$title  = sanitize_text_field( $title_raw );
			$slug   = sanitize_title( $slug_raw );
			$status = sanitize_key( $status_raw );

			$allowed_statuses = array( 'draft', 'scheduled', 'active', 'closed' );
			if ( ! in_array( $status, $allowed_statuses, true ) ) {
				$status = 'draft';
			}

			$data = array(
				'title'       => $title,
				'slug'        => $slug,
				'status'      => $status,
				'open_date'   => $this->parse_date_input( $open_date_raw ),
				'close_date'  => $this->parse_date_input( $close_date_raw ),
				'voting_open' => $this->parse_date_input( $voting_open_raw ),
				'settings'    => $this->get_global_settings(),
			);

			$result = $this->competitions->create( $data );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'club_competitions',
					'created',
					__( 'Competition created successfully.', 'club-competitions' ),
					'updated'
				);
			}

			wp_safe_redirect( $this->dashboard_url() );
			exit;
		}

		if ( 'update_competition' === $action ) {
			$competition_id = absint( $this->get_post_string( 'competition_id' ) );

			check_admin_referer( 'club_competitions_update_' . $competition_id, 'club_competitions_nonce' );

			$title_raw       = $this->get_post_string( 'competition_title' );
			$slug_raw        = $this->get_post_string( 'competition_slug' );
			$status_raw      = $this->get_post_string( 'competition_status', 'draft' );
			$open_date_raw   = $this->get_post_string( 'competition_open_date' );
			$close_date_raw  = $this->get_post_string( 'competition_close_date' );
			$voting_open_raw = $this->get_post_string( 'competition_voting_open' );

			$title  = sanitize_text_field( $title_raw );
			$slug   = sanitize_title( $slug_raw );
			$status = sanitize_key( $status_raw );

			$allowed_statuses = array( 'draft', 'scheduled', 'active', 'closed' );
			if ( ! in_array( $status, $allowed_statuses, true ) ) {
				$status = 'draft';
			}

			$data = array(
				'title'       => $title,
				'slug'        => $slug,
				'status'      => $status,
				'open_date'   => $this->parse_date_input( $open_date_raw ),
				'close_date'  => $this->parse_date_input( $close_date_raw ),
				'voting_open' => $this->parse_date_input( $voting_open_raw ),
			);

			$result = $this->competitions->update( $competition_id, $data );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);

				wp_safe_redirect(
					add_query_arg(
						array(
							'page'        => 'club-competitions',
							'action'      => 'edit',
							'competition' => $competition_id,
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			add_settings_error(
				'club_competitions',
				'updated',
				__( 'Competition updated successfully.', 'club-competitions' ),
				'updated'
			);

			wp_safe_redirect( $this->dashboard_url() );
			exit;
		}

		if ( in_array( $action, array( 'archive', 'restore', 'send_emails' ), true ) && isset( $_GET['competition'] ) ) {
			$competition_id = absint( wp_unslash( $_GET['competition'] ) );
			$nonces         = array(
				'send_emails' => 'club_competitions_send_emails_',
				'archive'     => 'club_competitions_archive_',
				'restore'     => 'club_competitions_restore_',
			);
			$nonce_action   = $nonces[ $action ];

			check_admin_referer( $nonce_action . $competition_id );

			if ( 'send_emails' === $action ) {
				$result = $this->competitions->send_submission_reminder_emails( $competition_id );

				if ( is_wp_error( $result ) ) {
					add_settings_error(
						'club_competitions',
						$result->get_error_code(),
						$result->get_error_message(),
						'error'
					);
				} else {
					$emails_sent = is_int( $result ) ? $result : 0;
					$message     = sprintf(
						/* translators: %d: Number of emails sent */
						_n( '%d reminder email sent to members.', '%d reminder emails sent to members.', $emails_sent, 'club-competitions' ),
						$emails_sent
					);

					add_settings_error(
						'club_competitions',
						'emails_sent',
						$message,
						'updated'
					);
				}

				wp_safe_redirect( $this->dashboard_url() );
				exit;
			}

			$result = 'archive' === $action
				? $this->competitions->archive( $competition_id )
				: $this->competitions->restore( $competition_id );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				$message = 'archive' === $action
					? __( 'Competition archived.', 'club-competitions' )
					: __( 'Competition restored.', 'club-competitions' );

				add_settings_error(
					'club_competitions',
					'archive' === $action ? 'archived' : 'restored',
					$message,
					'updated'
				);
			}

			$redirect = 'restore' === $action
				? add_query_arg(
					array(
						'page' => 'club-competitions',
						'view' => 'archived',
					),
					admin_url( 'admin.php' )
				)
				: $this->dashboard_url();

			wp_safe_redirect( $redirect );
			exit;
		}

		if ( 'create_member' === $action ) {
			check_admin_referer( 'club_competitions_member_create', 'club_competitions_member_nonce' );

			$name_raw  = $this->get_post_string( 'member_name' );
			$email_raw = $this->get_post_string( 'member_email' );
			$grade_raw = $this->get_post_string( 'member_grade' );
			$is_active = isset( $_POST['member_active'] );
			$name      = sanitize_text_field( $name_raw );
			$email     = sanitize_email( $email_raw );
			$grade     = sanitize_text_field( $grade_raw );

			$data = array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => $grade,
				'active' => $is_active ? 1 : 0,
			);

			$result = $this->members->create( $data );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions_members',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'club_competitions_members',
					'member_created',
					__( 'Member created successfully.', 'club-competitions' ),
					'updated'
				);
			}

			wp_safe_redirect( $this->members_url() );
			exit;
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

		if ( 'update_member' === $action ) {
			$member_id = absint( $this->get_post_string( 'member_id' ) );

			check_admin_referer( 'club_competitions_member_update_' . $member_id, 'club_competitions_member_nonce' );

			$name_raw  = $this->get_post_string( 'member_name' );
			$email_raw = $this->get_post_string( 'member_email' );
			$grade_raw = $this->get_post_string( 'member_grade' );
			$is_active = isset( $_POST['member_active'] );
			$name      = sanitize_text_field( $name_raw );
			$email     = sanitize_email( $email_raw );
			$grade     = sanitize_text_field( $grade_raw );

			$data = array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => $grade,
				'active' => $is_active ? 1 : 0,
			);

			$result = $this->members->update( $member_id, $data );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions_members',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);

				wp_safe_redirect(
					add_query_arg(
						array(
							'page'          => 'club-competitions-members',
							'member_action' => 'edit',
							'member'        => $member_id,
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			add_settings_error(
				'club_competitions_members',
				'member_updated',
				__( 'Member updated successfully.', 'club-competitions' ),
				'updated'
			);

			wp_safe_redirect( $this->members_url() );
			exit;
		}

		if ( 'update_global_settings' === $action ) {
			check_admin_referer( 'club_competitions_global_settings', 'club_competitions_nonce' );

			$categories = $this->get_post_array( 'categories' );
			$grades     = $this->get_post_array( 'grades' );

			$sanitized_categories = array();
			foreach ( $categories as $category ) {
				if ( ! isset( $category['label'], $category['slug'], $category['quota'] ) ) {
					continue;
				}

				$sanitized_categories[] = array(
					'label' => sanitize_text_field( $category['label'] ),
					'slug'  => sanitize_title( $category['slug'] ),
					'quota' => absint( $category['quota'] ),
				);
			}

			$sanitized_grades = array();
			foreach ( $grades as $grade ) {
				if ( ! isset( $grade['label'], $grade['slug'] ) ) {
					continue;
				}

				$sanitized_grades[] = array(
					'label' => sanitize_text_field( $grade['label'] ),
					'slug'  => sanitize_title( $grade['slug'] ),
				);
			}

			$score_matrix_raw = sanitize_text_field( $this->get_post_string( 'score_matrix' ) );
			$score_matrix     = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $score_matrix_raw ) ), 'is_numeric' ) );

			if ( empty( $score_matrix ) ) {
				$score_matrix = array( 9, 8, 7, 6, 5 );
			}

			// Get existing settings to preserve open_categories (controlled via Voting Controls page).
			$existing_settings        = $this->get_global_settings();
			$existing_open_categories = $existing_settings['voting']['open_categories'] ?? array();

			$auth_mode_input = sanitize_text_field( $this->get_post_string( 'voting_auth_mode', 'password' ) );
			if ( ! in_array( $auth_mode_input, array( 'password', 'token' ), true ) ) {
				$auth_mode_input = 'password';
			}

			$voting_password = sanitize_text_field( $this->get_post_string( 'voting_password' ) );

			$upload_page_url = sanitize_url( $this->get_post_string( 'upload_page_url', '' ) );
			$voting_page_url = sanitize_url( $this->get_post_string( 'voting_page_url', '' ) );

			$settings = array(
				'categories'      => $sanitized_categories,
				'grades'          => $sanitized_grades,
				'upload'          => array(
					'max_file_size_mb' => absint( $this->get_post_string( 'max_file_size_mb', '5' ) ),
					'max_width'        => absint( $this->get_post_string( 'max_width', '1920' ) ),
					'max_height'       => absint( $this->get_post_string( 'max_height', '1920' ) ),
					'allowed_formats'  => array( 'jpg', 'jpeg' ),
				),
				'voting'          => array(
					'score_matrix'    => $score_matrix,
					'auto_open'       => isset( $_POST['auto_open_voting'] ),
					'open_categories' => $existing_open_categories,
					'auth_mode'       => $auth_mode_input,
					'password'        => $voting_password,
				),
				'slideshow'       => array(
					'duration_seconds' => 10,
				),
				'email_reminders' => array(
					'enabled'                => true,
					'days_before_open'       => 7,
					'days_before_close'      => 1,
					'include_qr_code_voting' => true,
				),
				'urls'            => array(
					'upload_page' => $upload_page_url,
					'voting_page' => $voting_page_url,
				),
			);

			$validation = Competition_Settings::validate( $settings );

			if ( is_wp_error( $validation ) ) {
				add_settings_error(
					'club_competitions_settings',
					$validation->get_error_code(),
					$validation->get_error_message(),
					'error'
				);
			} else {
				$this->save_global_settings( $settings );

				add_settings_error(
					'club_competitions_settings',
					'settings_saved',
					__( 'Default settings saved successfully.', 'club-competitions' ),
					'updated'
				);
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'club-competitions-settings',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'update_competition_settings' === $action ) {
			$competition_id = absint( $this->get_post_string( 'competition_id' ) );

			check_admin_referer( 'club_competitions_update_settings_' . $competition_id, 'club_competitions_nonce' );

			// Get existing competition to preserve open_categories (controlled via Voting Controls page).
			$existing_competition     = $this->competitions->find( $competition_id );
			$existing_settings        = $existing_competition ? Competition_Settings::parse( $existing_competition->settings ) : array();
			$existing_open_categories = $existing_settings['voting']['open_categories'] ?? array();

			$categories = $this->get_post_array( 'categories' );
			$grades     = $this->get_post_array( 'grades' );

			$sanitized_categories = array();
			foreach ( $categories as $category ) {
				if ( ! isset( $category['label'], $category['slug'], $category['quota'] ) ) {
					continue;
				}

				$sanitized_categories[] = array(
					'label' => sanitize_text_field( $category['label'] ),
					'slug'  => sanitize_title( $category['slug'] ),
					'quota' => absint( $category['quota'] ),
				);
			}

			$sanitized_grades = array();
			foreach ( $grades as $grade ) {
				if ( ! isset( $grade['label'], $grade['slug'] ) ) {
					continue;
				}

				$sanitized_grades[] = array(
					'label' => sanitize_text_field( $grade['label'] ),
					'slug'  => sanitize_title( $grade['slug'] ),
				);
			}

			$score_matrix_raw = sanitize_text_field( $this->get_post_string( 'score_matrix' ) );
			$score_matrix     = array_map( 'intval', array_filter( array_map( 'trim', explode( ',', $score_matrix_raw ) ), 'is_numeric' ) );

			if ( empty( $score_matrix ) ) {
				$score_matrix = array( 9, 8, 7, 6, 5 );
			}

			$auth_mode_input = sanitize_text_field( $this->get_post_string( 'voting_auth_mode', 'password' ) );
			if ( ! in_array( $auth_mode_input, array( 'password', 'token' ), true ) ) {
				$auth_mode_input = 'password';
			}

			$voting_password = sanitize_text_field( $this->get_post_string( 'voting_password' ) );

			$upload_page_url = sanitize_url( $this->get_post_string( 'upload_page_url', '' ) );
			$voting_page_url = sanitize_url( $this->get_post_string( 'voting_page_url', '' ) );

			$settings = array(
				'categories'      => $sanitized_categories,
				'grades'          => $sanitized_grades,
				'upload'          => array(
					'max_file_size_mb' => absint( $this->get_post_string( 'max_file_size_mb', '5' ) ),
					'max_width'        => absint( $this->get_post_string( 'max_width', '1920' ) ),
					'max_height'       => absint( $this->get_post_string( 'max_height', '1920' ) ),
					'allowed_formats'  => array( 'jpg', 'jpeg' ),
				),
				'voting'          => array(
					'score_matrix'    => $score_matrix,
					'auto_open'       => isset( $_POST['auto_open_voting'] ),
					'open_categories' => $existing_open_categories,
					'auth_mode'       => $auth_mode_input,
					'password'        => $voting_password,
				),
				'slideshow'       => array(
					'duration_seconds' => 10,
				),
				'email_reminders' => array(
					'enabled'                => true,
					'days_before_open'       => 7,
					'days_before_close'      => 1,
					'include_qr_code_voting' => true,
				),
				'urls'            => array(
					'upload_page' => $upload_page_url,
					'voting_page' => $voting_page_url,
				),
			);

			$validation = Competition_Settings::validate( $settings );

			if ( is_wp_error( $validation ) ) {
				add_settings_error(
					'club_competitions',
					$validation->get_error_code(),
					$validation->get_error_message(),
					'error'
				);

				wp_safe_redirect(
					add_query_arg(
						array(
							'page'        => 'club-competitions',
							'action'      => 'edit',
							'competition' => $competition_id,
							'tab'         => 'settings',
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$result = $this->competitions->update(
				$competition_id,
				array(
					'settings' => $settings,
				)
			);

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'club_competitions',
					'settings_updated',
					__( 'Competition settings updated successfully.', 'club-competitions' ),
					'updated'
				);
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'        => 'club-competitions',
						'action'      => 'edit',
						'competition' => $competition_id,
						'tab'         => 'settings',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( 'regenerate_numbers' === $action ) {
			$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;

			check_admin_referer( 'club_competitions_regenerate_numbers_' . $competition_id );

			if ( ! $competition_id ) {
				add_settings_error(
					'club_competitions_submissions',
					'invalid_competition',
					__( 'Invalid competition.', 'club-competitions' ),
					'error'
				);
				wp_safe_redirect( admin_url( 'admin.php?page=club-competitions-submissions' ) );
				exit;
			}

			$result = $this->images->regenerate_member_numbers( $competition_id );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'club_competitions_submissions',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'club_competitions_submissions',
					'numbers_regenerated',
					__( 'Random numbers regenerated successfully.', 'club-competitions' ),
					'updated'
				);
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => 'club-competitions-submissions',
						'competition_id' => $competition_id,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Render the edit competition screen.
	 *
	 * @param int $competition_id Competition ID.
	 * @return void
	 */
	private function render_edit_screen( int $competition_id ): void {
		settings_errors( 'club_competitions' );

		$competition = $this->competitions->find( $competition_id );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Edit Competition', 'club-competitions' ) . '</h1>';

		if ( ! $competition ) {
			echo '<p>' . esc_html__( 'Competition not found. Return to the list and try again.', 'club-competitions' ) . '</p>';
			printf(
				'<a class="button" href="%s">%s</a>',
				esc_url( $this->dashboard_url() ),
				esc_html__( 'Back to competitions', 'club-competitions' )
			);
			echo '</div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query var used to switch tabs only.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

		$this->render_competition_tabs( $competition_id, $current_tab );

		if ( 'settings' === $current_tab ) {
			$this->render_competition_settings_form( $competition );
		} else {
			$this->render_competition_general_form( $competition );
		}

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( $this->dashboard_url() ),
			esc_html__( 'Back to competitions', 'club-competitions' )
		);

		echo '</div>';
	}

	/**
	 * Render tabs for competition edit screen.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $current_tab    Current active tab.
	 * @return void
	 */
	private function render_competition_tabs( int $competition_id, string $current_tab ): void {
		$tabs = array(
			'general'  => __( 'General', 'club-competitions' ),
			'settings' => __( 'Settings', 'club-competitions' ),
		);

		echo '<h2 class="nav-tab-wrapper">';

		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page'        => 'club-competitions',
					'action'      => 'edit',
					'competition' => $competition_id,
					'tab'         => $slug,
				),
				admin_url( 'admin.php' )
			);

			$active_class = $slug === $current_tab ? 'nav-tab-active' : '';

			printf(
				'<a href="%s" class="nav-tab %s">%s</a>',
				esc_url( $url ),
				esc_attr( $active_class ),
				esc_html( $label )
			);
		}

		echo '</h2>';
	}

	/**
	 * Render general competition form.
	 *
	 * @param object $competition Competition data.
	 * @return void
	 */
	private function render_competition_general_form( object $competition ): void {
		echo '<form method="post" class="card" style="max-width: 720px; padding: 16px;">';
		wp_nonce_field( 'club_competitions_update_' . (int) $competition->id, 'club_competitions_nonce' );
		echo '<input type="hidden" name="club_competitions_action" value="update_competition" />';
		echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition->id ) . '" />';

		echo '<p>';
		echo '<label for="competition_title">' . esc_html__( 'Title', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="competition_title" name="competition_title" class="regular-text" required value="' . esc_attr( $competition->title ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_slug">' . esc_html__( 'Slug', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="competition_slug" name="competition_slug" class="regular-text" value="' . esc_attr( $competition->slug ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_status">' . esc_html__( 'Status', 'club-competitions' ) . '</label><br />';
		echo '<select id="competition_status" name="competition_status">';

		$statuses = array(
			'draft'     => __( 'Draft', 'club-competitions' ),
			'scheduled' => __( 'Scheduled', 'club-competitions' ),
			'active'    => __( 'Active', 'club-competitions' ),
			'closed'    => __( 'Closed', 'club-competitions' ),
		);

		foreach ( $statuses as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $competition->status, $value, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		echo '</p>';

		$label_format = $this->get_ui_date_label();
		echo '<p>';
		echo '<label for="competition_open_date">' . esc_html__( 'Open Date', 'club-competitions' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_open_date" name="competition_open_date" value="' . esc_attr( $this->format_date_for_input( $competition->open_date ) ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_close_date">' . esc_html__( 'Close Date', 'club-competitions' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_close_date" name="competition_close_date" value="' . esc_attr( $this->format_date_for_input( $competition->close_date ) ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_voting_open">' . esc_html__( 'Voting Opens', 'club-competitions' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_voting_open" name="competition_voting_open" value="' . esc_attr( $this->format_date_for_input( $competition->voting_open ) ) . '" />';
		echo '</p>';

		submit_button( __( 'Update Competition', 'club-competitions' ) );

		echo '</form>';
	}

	/**
	 * Render competition settings form.
	 *
	 * @param object $competition Competition data.
	 * @return void
	 */
	private function render_competition_settings_form( object $competition ): void {
		$settings   = Competition_Settings::parse( $competition->settings );
		$categories = Competition_Settings::get_categories( $settings );
		$grades     = Competition_Settings::get_grades( $settings );
		$upload     = Competition_Settings::get_upload_constraints( $settings );
		$voting     = Competition_Settings::get_voting_config( $settings );

		echo '<form method="post" class="card" style="max-width: 720px; padding: 16px;">';
		wp_nonce_field( 'club_competitions_update_settings_' . (int) $competition->id, 'club_competitions_nonce' );
		echo '<input type="hidden" name="club_competitions_action" value="update_competition_settings" />';
		echo '<input type="hidden" name="competition_id" value="' . esc_attr( $competition->id ) . '" />';

		echo '<h3>' . esc_html__( 'Categories', 'club-competitions' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Define competition categories and upload quotas. Members can upload up to the specified number of images per category.', 'club-competitions' ) . '</p>';

		echo '<div id="categories-container">';
		foreach ( $categories as $index => $category ) {
			$this->render_category_field( $index, $category );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-category" class="button">' . esc_html__( 'Add Category', 'club-competitions' ) . '</button>';
		echo '</p>';

		echo '<h3>' . esc_html__( 'Grades', 'club-competitions' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Define member grade levels for results grouping.', 'club-competitions' ) . '</p>';

		echo '<div id="grades-container">';
		foreach ( $grades as $index => $grade ) {
			$this->render_grade_field( $index, $grade );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-grade" class="button">' . esc_html__( 'Add Grade', 'club-competitions' ) . '</button>';
		echo '</p>';

		echo '<h3>' . esc_html__( 'Upload Constraints', 'club-competitions' ) . '</h3>';

		echo '<p>';
		echo '<label for="max_file_size_mb">' . esc_html__( 'Max File Size (MB)', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" id="max_file_size_mb" name="max_file_size_mb" min="1" max="50" value="' . esc_attr( $upload['max_file_size_mb'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_width">' . esc_html__( 'Max Width (pixels)', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" id="max_width" name="max_width" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_width'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_height">' . esc_html__( 'Max Height (pixels)', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" id="max_height" name="max_height" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_height'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<h3>' . esc_html__( 'Voting Configuration', 'club-competitions' ) . '</h3>';

		$auth_mode = $voting['auth_mode'] ?? 'password';

		echo '<p>';
		echo '<label for="voting_auth_mode">' . esc_html__( 'Voting Authentication Mode', 'club-competitions' ) . '</label><br />';
		echo '<select id="voting_auth_mode" name="voting_auth_mode">';
		echo '<option value="password"' . selected( $auth_mode, 'password', false ) . '>' . esc_html__( 'Password-based (traditional)', 'club-competitions' ) . '</option>';
		echo '<option value="token"' . selected( $auth_mode, 'token', false ) . '>' . esc_html__( 'Email Magic Links (anonymous)', 'club-competitions' ) . '</option>';
		echo '</select><br />';
		echo '<span class="description">' . esc_html__( 'Choose how voters authenticate. Password mode allows voters to enter their name and optional password. Token mode sends secure one-time voting links via email for anonymous voting.', 'club-competitions' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_password">' . esc_html__( 'Voting Password (for password mode)', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="voting_password" name="voting_password" value="' . esc_attr( $voting['password'] ) . '" class="regular-text" />';
		echo '<span class="description">' . esc_html__( 'Voters must enter this password before submitting votes. Leave blank to disable. Only used when auth mode is "Password-based".', 'club-competitions' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="score_matrix">' . esc_html__( 'Score Matrix (comma-separated)', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="score_matrix" name="score_matrix" value="' . esc_attr( implode( ', ', $voting['score_matrix'] ) ) . '" class="regular-text" />';
		echo '<span class="description">' . esc_html__( 'E.g., 9, 8, 7, 6, 5', 'club-competitions' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="auto_open_voting" value="1"' . checked( $voting['auto_open'], true, false ) . ' /> ';
		echo esc_html__( 'Automatically open voting at scheduled time', 'club-competitions' );
		echo '</label>';
		echo '</p>';

		echo '<h3>' . esc_html__( 'URLs', 'club-competitions' ) . '</h3>';

		$urls = $settings['urls'] ?? array(
			'upload_page' => '',
			'voting_page' => '',
		);

		echo '<p>';
		echo '<label for="upload_page_url">' . esc_html__( 'Upload Page URL', 'club-competitions' ) . '</label><br />';
		echo '<input type="url" id="upload_page_url" name="upload_page_url" value="' . esc_attr( $urls['upload_page'] ) . '" class="regular-text" placeholder="https://example.com/upload" />';
		echo '<br /><span class="description">' . esc_html__( 'The page where members can upload their images. This URL will be included in email notifications with the member\'s upload token.', 'club-competitions' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_page_url">' . esc_html__( 'Voting Page URL', 'club-competitions' ) . '</label><br />';
		echo '<input type="url" id="voting_page_url" name="voting_page_url" value="' . esc_attr( $urls['voting_page'] ) . '" class="regular-text" placeholder="https://example.com/vote" />';
		echo '<br /><span class="description">' . esc_html__( 'The page where members can vote on images. This URL will be included in voting notification emails.', 'club-competitions' ) . '</span>';
		echo '</p>';

		submit_button( __( 'Save Settings', 'club-competitions' ) );

		echo '</form>';

		$this->render_settings_javascript();
	}

	/**
	 * Render category field row.
	 *
	 * @param int   $index    Category index.
	 * @param array $category Category data.
	 * @return void
	 */
	private function render_category_field( int $index, array $category ): void {
		echo '<div class="category-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Label', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" name="categories[' . esc_attr( $index ) . '][label]" value="' . esc_attr( $category['label'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Slug', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" name="categories[' . esc_attr( $index ) . '][slug]" value="' . esc_attr( $category['slug'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Upload Quota', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" name="categories[' . esc_attr( $index ) . '][quota]" value="' . esc_attr( $category['quota'] ) . '" min="1" max="10" class="small-text" required />';
		echo '</p>';

		echo '<button type="button" class="button remove-category" style="color: #b32d2e;">' . esc_html__( 'Remove', 'club-competitions' ) . '</button>';

		echo '</div>';
	}

	/**
	 * Render grade field row.
	 *
	 * @param int   $index Grade index.
	 * @param array $grade Grade data.
	 * @return void
	 */
	private function render_grade_field( int $index, array $grade ): void {
		echo '<div class="grade-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Label', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" name="grades[' . esc_attr( $index ) . '][label]" value="' . esc_attr( $grade['label'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Slug', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" name="grades[' . esc_attr( $index ) . '][slug]" value="' . esc_attr( $grade['slug'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<button type="button" class="button remove-grade" style="color: #b32d2e;">' . esc_html__( 'Remove', 'club-competitions' ) . '</button>';

		echo '</div>';
	}

	/**
	 * Render JavaScript for dynamic settings fields.
	 *
	 * @return void
	 */
	private function render_settings_javascript(): void {
		?>
		<script>
		(function() {
			let categoryIndex = document.querySelectorAll('.category-row').length;
			let gradeIndex = document.querySelectorAll('.grade-row').length;

			document.getElementById('add-category')?.addEventListener('click', function() {
				const container = document.getElementById('categories-container');
				const row = document.createElement('div');
				row.className = 'category-row';
				row.style.cssText = 'margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;';
				row.innerHTML = `
					<p style="margin: 5px 0;">
						<label><?php echo esc_js( __( 'Label', 'club-competitions' ) ); ?></label><br />
						<input type="text" name="categories[${categoryIndex}][label]" class="regular-text" required />
					</p>
					<p style="margin: 5px 0;">
						<label><?php echo esc_js( __( 'Slug', 'club-competitions' ) ); ?></label><br />
						<input type="text" name="categories[${categoryIndex}][slug]" class="regular-text" required />
					</p>
					<p style="margin: 5px 0;">
						<label><?php echo esc_js( __( 'Upload Quota', 'club-competitions' ) ); ?></label><br />
						<input type="number" name="categories[${categoryIndex}][quota]" value="1" min="1" max="10" class="small-text" required />
					</p>
					<button type="button" class="button remove-category" style="color: #b32d2e;"><?php echo esc_js( __( 'Remove', 'club-competitions' ) ); ?></button>
				`;
				container.appendChild(row);
				categoryIndex++;
			});

			document.getElementById('add-grade')?.addEventListener('click', function() {
				const container = document.getElementById('grades-container');
				const row = document.createElement('div');
				row.className = 'grade-row';
				row.style.cssText = 'margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;';
				row.innerHTML = `
					<p style="margin: 5px 0;">
						<label><?php echo esc_js( __( 'Label', 'club-competitions' ) ); ?></label><br />
						<input type="text" name="grades[${gradeIndex}][label]" class="regular-text" required />
					</p>
					<p style="margin: 5px 0;">
						<label><?php echo esc_js( __( 'Slug', 'club-competitions' ) ); ?></label><br />
						<input type="text" name="grades[${gradeIndex}][slug]" class="regular-text" required />
					</p>
					<button type="button" class="button remove-grade" style="color: #b32d2e;"><?php echo esc_js( __( 'Remove', 'club-competitions' ) ); ?></button>
				`;
				container.appendChild(row);
				gradeIndex++;
			});

			document.addEventListener('click', function(e) {
				if (e.target.classList.contains('remove-category')) {
					e.target.closest('.category-row').remove();
				}
				if (e.target.classList.contains('remove-grade')) {
					e.target.closest('.grade-row').remove();
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render competitions list table.
	 *
	 * @param array<int, object> $competitions Competitions.
	 * @param string             $view         Current view.
	 * @return void
	 */
	private function render_competition_table( array $competitions, string $view ): void {
		echo '<h2 class="screen-reader-text">' . esc_html__( 'Competition List', 'club-competitions' ) . '</h2>';

		$total_active   = $this->competitions->count( false );
		$total_archived = $this->competitions->count( true );

		echo '<ul class="subsubsub">';
		$views = array(
			'active'   => array(
				'label' => __( 'Active', 'club-competitions' ),
				'count' => $total_active,
			),
			'archived' => array(
				'label' => __( 'Archived', 'club-competitions' ),
				'count' => max( 0, $total_archived ),
			),
		);

		$index = 0;
		foreach ( $views as $slug => $data ) {
			$url = add_query_arg(
				array(
					'page' => 'club-competitions',
					'view' => $slug,
				),
				admin_url( 'admin.php' )
			);

			echo '<li><a href="' . esc_url( $url ) . '"' . ( $slug === $view ? ' class="current"' : '' ) . '>' . esc_html( $data['label'] ) . ' <span class="count">(' . esc_html( (string) $data['count'] ) . ')</span></a>';
			if ( ++$index < count( $views ) ) {
				echo ' | ';
			}
			echo '</li>';
		}
		echo '</ul>';

		if ( empty( $competitions ) ) {
			echo '<p>' . esc_html__( 'No competitions found for this view.', 'club-competitions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Title', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Opens', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Closes', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Last Updated', 'club-competitions' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'club-competitions' ) . '</th>';
		echo '</tr></thead>';
		echo '<tbody>';

		foreach ( $competitions as $competition ) {
			$is_archived = ! empty( $competition->deleted_at );

			$edit_link = add_query_arg(
				array(
					'page'        => 'club-competitions',
					'action'      => 'edit',
					'competition' => (int) $competition->id,
				),
				admin_url( 'admin.php' )
			);

			echo '<tr>';
			echo '<td>' . esc_html( $competition->title ) . '</td>';
			echo '<td>' . esc_html( $competition->status ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $competition->open_date ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $competition->close_date ) ) . '</td>';
			$last_updated = ! empty( $competition->updated_at ) ? $competition->updated_at : $competition->created_at;
			echo '<td>' . esc_html( $this->format_datetime( $last_updated ) ) . '</td>';

			$actions = array(
				sprintf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html__( 'Edit', 'club-competitions' ) ),
			);

			if ( 'active' === $competition->status && ! $is_archived ) {
				$send_email_link = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'club-competitions',
							'action'      => 'send_emails',
							'competition' => (int) $competition->id,
						),
						admin_url( 'admin.php' )
					),
					'club_competitions_send_emails_' . (int) $competition->id
				);

				$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( $send_email_link ), esc_html__( 'Send Upload Emails', 'club-competitions' ) );
			} else {
				$actions[] = sprintf( '<span title="Send only on active competitions" style="color: #888;">%s</span>', esc_html__( 'Send Upload Emails', 'club-competitions' ) );
			}

			if ( $is_archived ) {
				$restore_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'club-competitions',
							'action'      => 'restore',
							'competition' => (int) $competition->id,
						),
						admin_url( 'admin.php' )
					),
					'club_competitions_restore_' . (int) $competition->id
				);

				$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( $restore_url ), esc_html__( 'Restore', 'club-competitions' ) );
			} else {
				$archive_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'club-competitions',
							'action'      => 'archive',
							'competition' => (int) $competition->id,
						),
						admin_url( 'admin.php' )
					),
					'club_competitions_archive_' . (int) $competition->id
				);

				$actions[] = sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $archive_url ), esc_html__( 'Archive', 'club-competitions' ) );
			}

			echo '<td>' . wp_kses_post( implode( ' | ', $actions ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
	}
	/**
	 * Format datetime for display using site locale.
	 *
	 * @param string|null $datetime Datetime value.
	 * @return string
	 */
	private function format_datetime( ?string $datetime ): string {
		if ( empty( $datetime ) ) {
			return '—';
		}

		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return '—';
		}

		$date_format = $this->get_display_date_format();
		$time_format = get_option( 'time_format' );
		$format      = $date_format . ( ! empty( $time_format ) ? ' ' . $time_format : '' );

		return wp_date( $format, $timestamp );
	}

	/**
	 * Determine the date format to display, accounting for locale defaults.
	 *
	 * @return string
	 */
	private function get_display_date_format(): string {
		$locale = get_locale();

		if ( in_array( $locale, array( 'en_GB', 'en_AU', 'en_NZ', 'en_IE', 'en_ZA' ), true ) ) {
			return 'd/m/Y';
		}

		$format = get_option( 'date_format' );

		if ( empty( $format ) ) {
			$format = 'F j, Y';
		}

		return $format;
	}

	/**
	 * UI label format (human readable).
	 *
	 * @return string
	 */
	private function get_ui_date_label(): string {
		$locale = get_locale();

		if ( in_array( $locale, array( 'en_GB', 'en_AU', 'en_NZ', 'en_IE', 'en_ZA' ), true ) ) {
			return 'dd/mm/yyyy';
		}

		return 'yyyy-mm-dd';
	}

	/**
	 * Format stored datetime for HTML date inputs.
	 *
	 * @param string|null $datetime Datetime value.
	 * @return string
	 */
	private function format_date_for_input( ?string $datetime ): string {
		if ( empty( $datetime ) ) {
			return '';
		}

		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return '';
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Parse user input to normalized Y-m-d format.
	 *
	 * @param string $raw Raw input.
	 * @return string|null
	 */
	private function parse_date_input( string $raw ): ?string {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return null;
		}

		$tz = wp_timezone();

		$dt = \DateTime::createFromFormat( 'Y-m-d', $raw, $tz );

		if ( $dt instanceof \DateTimeInterface ) {
			return $dt->format( 'Y-m-d' );
		}

		$format = $this->get_display_date_format();
		$dt     = \DateTime::createFromFormat( $format, $raw, $tz );

		if ( $dt instanceof \DateTimeInterface ) {
			return gmdate( 'Y-m-d', $dt->getTimestamp() );
		}

		$timestamp = strtotime( $raw );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Retrieve a POST value as an unslashed string.
	 *
	 * @param string $key      POST key.
	 * @param string $fallback Fallback value if key not present.
	 * @return string
	 */
	private function get_post_string( string $key, string $fallback = '' ): string {
		if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonces validated in calling context.
			return (string) wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonces validated in calling context.
		}

		return $fallback;
	}

	/**
	 * Retrieve a POST value as an unslashed array.
	 *
	 * @param string $key POST key.
	 * @return array
	 */
	private function get_post_array( string $key ): array {
		// phpcs:ignore -- Nonces verified before calling this helper.
		if ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonces validated in calling context.
			$value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonces validated in calling context.
			return is_array( $value ) ? $value : array();
		}

		return array();
	}

	/**
	 * Build URLs for submission assets.
	 *
	 * @param object|null $competition Competition object.
	 * @param object      $submission Submission record.
	 * @return array{full:string,thumb:string}
	 */
	private function get_submission_urls( ?object $competition, object $submission ): array {
		if ( ! $competition || empty( $competition->slug ) || empty( $submission->filename ) ) {
			return array(
				'full'  => '',
				'thumb' => '',
			);
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return array(
				'full'  => '',
				'thumb' => '',
			);
		}

		$base = trailingslashit( $uploads['baseurl'] ) . 'competitions/';
		$slug = sanitize_file_name( (string) $competition->slug );
		$cat  = sanitize_file_name( (string) $submission->category );

		$folder_url  = trailingslashit( $base . rawurlencode( $slug ) . '/' . rawurlencode( $cat ) );
		$folder_path = trailingslashit( trailingslashit( $uploads['basedir'] ) . 'competitions/' . $slug . '/' . $cat );

		$filename   = $submission->filename;
		$thumb_name = $this->get_thumbnail_filename( $filename );

		$full_path  = $folder_path . $filename;
		$thumb_path = $folder_path . $thumb_name;

		$full_url  = file_exists( $full_path ) ? $folder_url . rawurlencode( $filename ) : '';
		$thumb_url = file_exists( $thumb_path ) ? $folder_url . rawurlencode( $thumb_name ) : '';

		return array(
			'full'  => $full_url,
			'thumb' => $thumb_url,
		);
	}

	/**
	 * Determine thumbnail filename from base filename.
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
	 * Render the create competition form.
	 *
	 * @return void
	 */
	private function render_create_form(): void {
		$label_format = $this->get_ui_date_label();

		echo '<form method="post" class="card" style="max-width: 720px; margin-bottom: 24px; padding: 16px;">';
		echo '<h2>' . esc_html__( 'Create Competition', 'club-competitions' ) . '</h2>';

		wp_nonce_field( 'club_competitions_create', 'club_competitions_nonce' );
		echo '<input type="hidden" name="club_competitions_action" value="create_competition" />';

		echo '<p>';
		echo '<label for="competition_title">' . esc_html__( 'Title', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="competition_title" name="competition_title" class="regular-text" required />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_slug">' . esc_html__( 'Slug (optional)', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="competition_slug" name="competition_slug" class="regular-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_status">' . esc_html__( 'Status', 'club-competitions' ) . '</label><br />';
		echo '<select id="competition_status" name="competition_status">';
		echo '<option value="draft">' . esc_html__( 'Draft', 'club-competitions' ) . '</option>';
		echo '<option value="scheduled">' . esc_html__( 'Scheduled', 'club-competitions' ) . '</option>';
		echo '<option value="active">' . esc_html__( 'Active', 'club-competitions' ) . '</option>';
		echo '<option value="closed">' . esc_html__( 'Closed', 'club-competitions' ) . '</option>';
		echo '</select>';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_open_date">' . esc_html__( 'Open Date', 'club-competitions' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_open_date" name="competition_open_date" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_close_date">' . esc_html__( 'Close Date', 'club-competitions' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_close_date" name="competition_close_date" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="competition_voting_open">' . esc_html__( 'Voting Opens', 'club-competitions' ) . ' (' . esc_html( $label_format ) . ')</label><br />';
		echo '<input type="date" id="competition_voting_open" name="competition_voting_open" />';
		echo '</p>';

		submit_button( __( 'Create Competition', 'club-competitions' ) );

		echo '</form>';
	}

	/**
	 * Render create member form.
	 *
	 * @return void
	 */
	private function render_member_create_form(): void {
		echo '<form method="post" class="card" style="max-width: 520px; margin-bottom: 24px; padding: 16px;">';
		echo '<h2>' . esc_html__( 'Add Member', 'club-competitions' ) . '</h2>';

		wp_nonce_field( 'club_competitions_member_create', 'club_competitions_member_nonce' );

		echo '<input type="hidden" name="club_competitions_action" value="create_member" />';

		echo '<p>';
		echo '<label for="member_name">' . esc_html__( 'Name', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="member_name" name="member_name" class="regular-text" required />';
		echo '</p>';

		echo '<p>';
		echo '<label for="member_email">' . esc_html__( 'Email', 'club-competitions' ) . '</label><br />';
		echo '<input type="email" id="member_email" name="member_email" class="regular-text" required />';
		echo '</p>';

		echo '<p>';
		echo '<label for="member_grade">' . esc_html__( 'Grade', 'club-competitions' ) . '</label><br />';
		echo '<select id="member_grade" name="member_grade" class="regular-text" required>';
		echo '<option value="">' . esc_html__( 'Select grade', 'club-competitions' ) . '</option>';
		foreach ( $this->get_grade_options() as $grade_slug => $grade_label ) {
			echo '<option value="' . esc_attr( $grade_slug ) . '">' . esc_html( $grade_label ) . '</option>';
		}
		echo '</select>';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="member_active" value="1" checked /> ';
		echo esc_html__( 'Active', 'club-competitions' );
		echo '</label>';
		echo '</p>';

		submit_button( __( 'Add Member', 'club-competitions' ) );

		echo '</form>';
	}

	/**
	 * Render edit member form.
	 *
	 * @param object|null $member Member row.
	 * @return void
	 */
	private function render_member_edit_form( $member ): void {
		if ( ! $member ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Member not found. Return to the list to continue.', 'club-competitions' ) . '</p></div>';
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( $this->members_url() ),
				esc_html__( 'Back to members', 'club-competitions' )
			);
			return;
		}

		echo '<form method="post" class="card" style="max-width: 520px; margin-bottom: 24px; padding: 16px;">';
		echo '<h2>' . esc_html__( 'Edit Member', 'club-competitions' ) . '</h2>';

		wp_nonce_field( 'club_competitions_member_update_' . (int) $member->id, 'club_competitions_member_nonce' );

		echo '<input type="hidden" name="club_competitions_action" value="update_member" />';
		echo '<input type="hidden" name="member_id" value="' . esc_attr( $member->id ) . '" />';

		echo '<p>';
		echo '<label for="member_name">' . esc_html__( 'Name', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="member_name" name="member_name" class="regular-text" required value="' . esc_attr( $member->name ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="member_email">' . esc_html__( 'Email', 'club-competitions' ) . '</label><br />';
		echo '<input type="email" id="member_email" name="member_email" class="regular-text" required value="' . esc_attr( $member->email ) . '" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="member_grade">' . esc_html__( 'Grade', 'club-competitions' ) . '</label><br />';
		echo '<select id="member_grade" name="member_grade" class="regular-text" required>';
		foreach ( $this->get_grade_options() as $grade_slug => $grade_label ) {
			echo '<option value="' . esc_attr( $grade_slug ) . '"' . selected( $member->grade, $grade_slug, false ) . '>' . esc_html( $grade_label ) . '</option>';
		}
		echo '</select>';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="member_active" value="1"' . checked( (bool) $member->active, true, false ) . ' /> ';
		echo esc_html__( 'Active', 'club-competitions' );
		echo '</label>';
		echo '</p>';

		submit_button( __( 'Update Member', 'club-competitions' ) );

		echo '</form>';

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( $this->members_url() ),
			esc_html__( 'Back to members', 'club-competitions' )
		);
	}

	/**
	 * Retrieve grade options from default settings.
	 *
	 * @return array<string, string>
	 */
	private function get_grade_options(): array {
		$settings = $this->get_global_settings();
		$grades   = Competition_Settings::get_grades( $settings );

		$options = array();
		foreach ( $grades as $grade ) {
			if ( isset( $grade['slug'], $grade['label'] ) ) {
				$options[ $grade['slug'] ] = $grade['label'];
			}
		}

		return $options;
	}

	/**
	 * Dashboard URL.
	 *
	 * @return string
	 */
	private function dashboard_url(): string {
		return add_query_arg(
			array(
				'page' => 'club-competitions',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Members page URL.
	 *
	 * @return string
	 */
	private function members_url(): string {
		return add_query_arg(
			array(
				'page' => 'club-competitions-members',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render global settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'club-competitions' ) );
		}

		settings_errors( 'club_competitions_settings' );

		$settings   = $this->get_global_settings();
		$categories = Competition_Settings::get_categories( $settings );
		$grades     = Competition_Settings::get_grades( $settings );
		$upload     = Competition_Settings::get_upload_constraints( $settings );
		$voting     = Competition_Settings::get_voting_config( $settings );
		$urls       = $settings['urls'] ?? array(
			'upload_page' => '',
			'voting_page' => '',
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Default Competition Settings', 'club-competitions' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'These settings will be used as defaults when creating new competitions. Individual competitions can override these settings.', 'club-competitions' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="card" style="max-width: 720px; padding: 16px; margin-top: 20px;">';
		wp_nonce_field( 'club_competitions_global_settings', 'club_competitions_nonce' );
		echo '<input type="hidden" name="club_competitions_action" value="update_global_settings" />';
		echo '<input type="hidden" name="page" value="club-competitions-settings" />';

		echo '<h2>' . esc_html__( 'Categories', 'club-competitions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Define default categories and upload quotas.', 'club-competitions' ) . '</p>';

		echo '<div id="categories-container">';
		foreach ( $categories as $index => $category ) {
			$this->render_category_field( $index, $category );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-category" class="button">' . esc_html__( 'Add Category', 'club-competitions' ) . '</button>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Grades', 'club-competitions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Define default member grade levels.', 'club-competitions' ) . '</p>';

		echo '<div id="grades-container">';
		foreach ( $grades as $index => $grade ) {
			$this->render_grade_field( $index, $grade );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-grade" class="button">' . esc_html__( 'Add Grade', 'club-competitions' ) . '</button>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Upload Constraints', 'club-competitions' ) . '</h2>';

		echo '<p>';
		echo '<label for="max_file_size_mb">' . esc_html__( 'Max File Size (MB)', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" id="max_file_size_mb" name="max_file_size_mb" min="1" max="50" value="' . esc_attr( $upload['max_file_size_mb'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_width">' . esc_html__( 'Max Width (pixels)', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" id="max_width" name="max_width" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_width'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_height">' . esc_html__( 'Max Height (pixels)', 'club-competitions' ) . '</label><br />';
		echo '<input type="number" id="max_height" name="max_height" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_height'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Voting Configuration', 'club-competitions' ) . '</h2>';

		$auth_mode = $voting['auth_mode'] ?? 'password';

		echo '<p>';
		echo '<label for="voting_auth_mode">' . esc_html__( 'Voting Authentication Mode', 'club-competitions' ) . '</label><br />';
		echo '<select id="voting_auth_mode" name="voting_auth_mode">';
		echo '<option value="password"' . selected( $auth_mode, 'password', false ) . '>' . esc_html__( 'Password-based (traditional)', 'club-competitions' ) . '</option>';
		echo '<option value="token"' . selected( $auth_mode, 'token', false ) . '>' . esc_html__( 'Email Magic Links (anonymous)', 'club-competitions' ) . '</option>';
		echo '</select><br />';
		echo '<span class="description">' . esc_html__( 'Choose how voters authenticate. Password mode allows voters to enter their name and optional password. Token mode sends secure one-time voting links via email for anonymous voting.', 'club-competitions' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_password">' . esc_html__( 'Voting Password (for password mode)', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="voting_password" name="voting_password" value="' . esc_attr( $voting['password'] ) . '" class="regular-text" />';
		echo '<span class="description">' . esc_html__( 'Voters must enter this password before submitting votes. Leave blank to disable by default. Only used when auth mode is "Password-based".', 'club-competitions' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="score_matrix">' . esc_html__( 'Score Matrix (comma-separated)', 'club-competitions' ) . '</label><br />';
		echo '<input type="text" id="score_matrix" name="score_matrix" value="' . esc_attr( implode( ', ', $voting['score_matrix'] ) ) . '" class="regular-text" />';
		echo '<span class="description">' . esc_html__( 'E.g., 9, 8, 7, 6, 5', 'club-competitions' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="auto_open_voting" value="1"' . checked( $voting['auto_open'], true, false ) . ' /> ';
		echo esc_html__( 'Automatically open voting at scheduled time', 'club-competitions' );
		echo '</label>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'URLs', 'club-competitions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Default pages used in upload and voting notifications.', 'club-competitions' ) . '</p>';

		echo '<p>';
		echo '<label for="upload_page_url">' . esc_html__( 'Upload Page URL', 'club-competitions' ) . '</label><br />';
		echo '<input type="url" id="upload_page_url" name="upload_page_url" value="' . esc_attr( $urls['upload_page'] ?? '' ) . '" class="regular-text" placeholder="https://example.com/upload" />';
		echo '<br /><span class="description">' . esc_html__( 'Members receive this link when requesting upload tokens.', 'club-competitions' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_page_url">' . esc_html__( 'Voting Page URL', 'club-competitions' ) . '</label><br />';
		echo '<input type="url" id="voting_page_url" name="voting_page_url" value="' . esc_attr( $urls['voting_page'] ?? '' ) . '" class="regular-text" placeholder="https://example.com/vote" />';
		echo '<br /><span class="description">' . esc_html__( 'Voters receive this link in voting invitation emails.', 'club-competitions' ) . '</span>';
		echo '</p>';

		submit_button( __( 'Save Default Settings', 'club-competitions' ) );

		echo '</form>';
		echo '</div>';

		$this->render_settings_javascript();
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

	/**
	 * Save global default settings.
	 *
	 * @param array<string, mixed> $settings Settings to save.
	 * @return void
	 */
	private function save_global_settings( array $settings ): void {
		update_option( 'club_competitions_default_settings', Competition_Settings::encode( $settings ) );
	}

	/**
	 * Enqueue admin assets for slideshow.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		// Only load on voting controls page.
		if ( 'competitions_page_club-competitions-voting' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'club-competitions-admin-slideshow',
			plugins_url( 'assets/css/admin-slideshow.css', dirname( __DIR__ ) . '/club-competitions.php' ),
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'club-competitions-admin-slideshow',
			plugins_url( 'assets/js/admin-slideshow.js', dirname( __DIR__ ) . '/club-competitions.php' ),
			array( 'jquery' ),
			'1.0.0',
			true
		);

		// Pass AJAX URL and nonce to JavaScript.
		wp_localize_script(
			'club-competitions-admin-slideshow',
			'clubCompeteSlideshow',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'club_compete_admin_slideshow' ),
			)
		);
	}
}
