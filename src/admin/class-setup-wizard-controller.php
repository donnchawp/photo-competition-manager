<?php
/**
 * Setup Wizard controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

use PhotoCompetitionManager\Admin\Traits\Form_Rendering;

/**
 * Manage page setup wizard for creating upload and voting pages.
 *
 * @since 0.1.0
 */
class Setup_Wizard_Controller {

	use Form_Rendering;

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

		if ( isset( $_POST['photo_competition_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['photo_competition_action'] ) );
		} elseif ( isset( $_POST['action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['action'] ) );
		}

		if ( 'create_pages' !== $action ) {
			return;
		}

		check_admin_referer( 'photo_competition_create_pages', 'photo_competition_nonce' );

		$upload_page_title  = sanitize_text_field( $this->get_post_string( 'upload_page_title', 'Photo Upload' ) );
		$voting_page_title  = sanitize_text_field( $this->get_post_string( 'voting_page_title', 'Vote on Photos' ) );
		$results_page_title = sanitize_text_field( $this->get_post_string( 'results_page_title', 'Competition Results' ) );
		$top3_page_title    = sanitize_text_field( $this->get_post_string( 'top3_page_title', 'Top 3 Winners' ) );

		$create_upload_page  = isset( $_POST['create_upload_page'] ) && '1' === $_POST['create_upload_page'];
		$create_voting_page  = isset( $_POST['create_voting_page'] ) && '1' === $_POST['create_voting_page'];
		$create_results_page = isset( $_POST['create_results_page'] ) && '1' === $_POST['create_results_page'];
		$create_top3_page    = isset( $_POST['create_top3_page'] ) && '1' === $_POST['create_top3_page'];
		$results_hide_names  = isset( $_POST['results_hide_names'] ) && '1' === $_POST['results_hide_names'];

		$created_pages = array();
		$errors        = array();

		// Create upload page.
		if ( $create_upload_page ) {
			$upload_page_id = $this->create_page_with_shortcode(
				$upload_page_title,
				'[competition_upload]'
			);

			if ( is_wp_error( $upload_page_id ) ) {
				$errors[] = sprintf(
					/* translators: %s: error message */
					__( 'Upload page error: %s', 'photo-competition-manager' ),
					$upload_page_id->get_error_message()
				);
			} else {
				$created_pages['upload'] = $upload_page_id;
			}
		}

		// Create voting page.
		if ( $create_voting_page ) {
			$voting_page_id = $this->create_page_with_shortcode(
				$voting_page_title,
				'[competition_voting]'
			);

			if ( is_wp_error( $voting_page_id ) ) {
				$errors[] = sprintf(
					/* translators: %s: error message */
					__( 'Voting page error: %s', 'photo-competition-manager' ),
					$voting_page_id->get_error_message()
				);
			} else {
				$created_pages['voting'] = $voting_page_id;
			}
		}

		// Create results page.
		if ( $create_results_page ) {
			$results_shortcode = '[competition_results';
			if ( $results_hide_names ) {
				$results_shortcode .= ' hide_names="1"';
			}
			$results_shortcode .= ']';

			$results_page_id = $this->create_page_with_shortcode(
				$results_page_title,
				$results_shortcode
			);

			if ( is_wp_error( $results_page_id ) ) {
				$errors[] = sprintf(
					/* translators: %s: error message */
					__( 'Results page error: %s', 'photo-competition-manager' ),
					$results_page_id->get_error_message()
				);
			} else {
				$created_pages['results'] = $results_page_id;
			}
		}

		// Create top 3 page.
		if ( $create_top3_page ) {
			$top3_page_id = $this->create_page_with_shortcode(
				$top3_page_title,
				'[competition_top3]'
			);

			if ( is_wp_error( $top3_page_id ) ) {
				$errors[] = sprintf(
					/* translators: %s: error message */
					__( 'Top 3 page error: %s', 'photo-competition-manager' ),
					$top3_page_id->get_error_message()
				);
			} else {
				$created_pages['top3'] = $top3_page_id;
			}
		}

		// Update settings with created page URLs.
		if ( ! empty( $created_pages ) ) {
			// Flush rewrite rules to ensure permalinks work immediately.
			flush_rewrite_rules();
			$this->update_page_urls( $created_pages );
		}

		// Display feedback.
		if ( ! empty( $errors ) ) {
			foreach ( $errors as $error ) {
				add_settings_error(
					'photo_competition_setup',
					'page_creation_error',
					$error,
					'error'
				);
			}
		}

		if ( ! empty( $created_pages ) ) {
			$message = sprintf(
				/* translators: %d: number of pages created */
				_n( '%d page created successfully.', '%d pages created successfully.', count( $created_pages ), 'photo-competition-manager' ),
				count( $created_pages )
			);

			$message .= ' ' . __( 'Global settings have been updated with the new page URLs.', 'photo-competition-manager' );

			add_settings_error(
				'photo_competition_setup',
				'pages_created',
				$message,
				'updated'
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'photo-competition-manager-setup',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render setup wizard page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( 'photo_competition_setup' );

		// Get current settings to check if pages already exist.
		$settings   = get_option( 'photo_comp_default_settings', '' );
		$parsed     = \PhotoCompetitionManager\Support\Competition_Settings::parse( $settings );
		$urls       = $parsed['urls'] ?? array();
		$upload_url = $urls['upload_page'] ?? '';
		$voting_url = $urls['voting_page'] ?? '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Setup Wizard', 'photo-competition-manager' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Quickly create pages with the necessary shortcodes for your photo competitions.', 'photo-competition-manager' ) . '</p>';

		// Check if pages already configured.
		if ( ! empty( $upload_url ) || ! empty( $voting_url ) ) {
			echo '<div class="notice notice-info inline"><p>';
			echo esc_html__( 'You have already configured some pages in Settings. Creating new pages will update those URLs.', 'photo-competition-manager' );
			echo '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="card" style="max-width: 720px; padding: 24px; margin-top: 20px;">';
		wp_nonce_field( 'photo_competition_create_pages', 'photo_competition_nonce' );
		echo '<input type="hidden" name="photo_competition_action" value="create_pages" />';
		echo '<input type="hidden" name="page" value="photo-competition-manager-setup" />';

		echo '<h2>' . esc_html__( 'Pages to Create', 'photo-competition-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Select which pages you want to create. Each page will be created with the appropriate shortcode.', 'photo-competition-manager' ) . '</p>';

		// Upload page.
		echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">';
		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="create_upload_page" value="1" checked />';
		echo ' <strong>' . esc_html__( 'Upload Page', 'photo-competition-manager' ) . '</strong>';
		echo '</label>';
		echo '</p>';
		echo '<p>';
		echo '<label for="upload_page_title">' . esc_html__( 'Page Title', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="upload_page_title" name="upload_page_title" value="Photo Upload" class="regular-text" />';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Creates a page with the [competition_upload] shortcode for members to submit photos.', 'photo-competition-manager' ) . '</p>';
		echo '</div>';

		// Voting page.
		echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">';
		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="create_voting_page" value="1" checked />';
		echo ' <strong>' . esc_html__( 'Voting Page', 'photo-competition-manager' ) . '</strong>';
		echo '</label>';
		echo '</p>';
		echo '<p>';
		echo '<label for="voting_page_title">' . esc_html__( 'Page Title', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="voting_page_title" name="voting_page_title" value="Vote on Photos" class="regular-text" />';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Creates a page with the [competition_voting] shortcode for members to cast their votes.', 'photo-competition-manager' ) . '</p>';
		echo '</div>';

		// Results page.
		echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">';
		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="create_results_page" value="1" checked />';
		echo ' <strong>' . esc_html__( 'Results Page', 'photo-competition-manager' ) . '</strong>';
		echo '</label>';
		echo '</p>';
		echo '<p>';
		echo '<label for="results_page_title">' . esc_html__( 'Page Title', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="results_page_title" name="results_page_title" value="Competition Results" class="regular-text" />';
		echo '</p>';
		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="results_hide_names" value="1" checked />';
		echo ' ' . esc_html__( 'Hide photographer names on results page', 'photo-competition-manager' );
		echo '</label>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Creates a page with the [competition_results] shortcode to display competition winners.', 'photo-competition-manager' ) . '</p>';
		echo '</div>';

		// Top 3 page.
		echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9;">';
		echo '<p>';
		echo '<label>';
		echo '<input type="checkbox" name="create_top3_page" value="1" checked />';
		echo ' <strong>' . esc_html__( 'Top 3 Page', 'photo-competition-manager' ) . '</strong>';
		echo '</label>';
		echo '</p>';
		echo '<p>';
		echo '<label for="top3_page_title">' . esc_html__( 'Page Title', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="top3_page_title" name="top3_page_title" value="Top 3 Winners" class="regular-text" />';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Creates a page with the [competition_top3] shortcode to display the top 3 winners in each category and grade.', 'photo-competition-manager' ) . '</p>';
		echo '</div>';

		submit_button( __( 'Create Pages', 'photo-competition-manager' ), 'primary', 'submit', true );

		echo '</form>';

		// Show existing pages info.
		$this->render_existing_pages_info();

		echo '</div>';
	}

	/**
	 * Create a WordPress page with a shortcode.
	 *
	 * @param  string $title     Page title.
	 * @param  string $shortcode Shortcode to embed.
	 * @return int|\WP_Error Page ID on success, WP_Error on failure.
	 */
	private function create_page_with_shortcode( string $title, string $shortcode ) {
		// Check if a page with this title already exists.
		$query = new \WP_Query(
			array(
				'post_type'              => 'page',
				'title'                  => $title,
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);

		if ( ! empty( $query->posts ) ) {
			return new \WP_Error(
				'page_exists',
				sprintf(
					/* translators: %s: page title */
					__( 'A page with the title "%s" already exists.', 'photo-competition-manager' ),
					$title
				)
			);
		}

		$page_data = array(
			'post_title'   => $title,
			'post_content' => $shortcode,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => get_current_user_id(),
		);

		$page_id = wp_insert_post( $page_data );

		if ( is_wp_error( $page_id ) || 0 === $page_id ) {
			return new \WP_Error(
				'page_creation_failed',
				__( 'Failed to create page.', 'photo-competition-manager' )
			);
		}

		return $page_id;
	}

	/**
	 * Update global settings with created page URLs.
	 *
	 * @param  array<string, int> $created_pages Map of page type to page ID.
	 * @return void
	 */
	private function update_page_urls( array $created_pages ): void {
		$settings = get_option( 'photo_comp_default_settings', '' );
		$parsed   = \PhotoCompetitionManager\Support\Competition_Settings::parse( $settings );

		if ( ! isset( $parsed['urls'] ) ) {
			$parsed['urls'] = array();
		}

		// Update upload page URL.
		if ( isset( $created_pages['upload'] ) ) {
			$parsed['urls']['upload_page'] = get_permalink( $created_pages['upload'] );
		}

		// Update voting page URL.
		if ( isset( $created_pages['voting'] ) ) {
			$parsed['urls']['voting_page'] = get_permalink( $created_pages['voting'] );
		}

		// Update results page URL (stored separately for now).
		if ( isset( $created_pages['results'] ) ) {
			$parsed['urls']['results_page'] = get_permalink( $created_pages['results'] );
		}

		// Update top 3 page URL.
		if ( isset( $created_pages['top3'] ) ) {
			$parsed['urls']['top3_page'] = get_permalink( $created_pages['top3'] );
		}

		update_option( 'photo_comp_default_settings', \PhotoCompetitionManager\Support\Competition_Settings::encode( $parsed ) );
	}

	/**
	 * Render information about existing pages with shortcodes.
	 *
	 * @return void
	 */
	private function render_existing_pages_info(): void {
		global $wpdb;

		// Find pages with competition shortcodes.
		$shortcodes = array(
			'competition_upload'  => __( 'Upload', 'photo-competition-manager' ),
			'competition_voting'  => __( 'Voting', 'photo-competition-manager' ),
			'competition_results' => __( 'Results', 'photo-competition-manager' ),
			'competition_top3'    => __( 'Top 3', 'photo-competition-manager' ),
		);

		$found_pages = array();

		foreach ( $shortcodes as $shortcode => $label ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pages = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s",
					'%[' . $wpdb->esc_like( $shortcode ) . '%'
				)
			);

			if ( ! empty( $pages ) ) {
				$found_pages[ $label ] = $pages;
			}
		}

		if ( empty( $found_pages ) ) {
			return;
		}

		echo '<div style="margin-top: 30px; padding: 20px; border: 1px solid #ddd; background: #fff;">';
		echo '<h2>' . esc_html__( 'Existing Pages', 'photo-competition-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The following pages already contain competition shortcodes:', 'photo-competition-manager' ) . '</p>';

		foreach ( $found_pages as $type => $pages ) {
			echo '<h3>' . esc_html( $type ) . '</h3>';
			echo '<ul>';
			foreach ( $pages as $page ) {
				echo '<li>';
				echo '<a href="' . esc_url( get_permalink( $page->ID ) ) . '" target="_blank">';
				echo esc_html( $page->post_title );
				echo '</a>';
				echo ' <span class="description">— <a href="' . esc_url( get_edit_post_link( $page->ID ) ) . '">Edit</a></span>';
				echo '</li>';
			}
			echo '</ul>';
		}

		echo '</div>';
	}
}
