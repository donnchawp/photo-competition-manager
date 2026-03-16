<?php
/**
 * Settings controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Date_Formatting;
use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Manage global default settings page.
 *
 * @since 0.1.0
 */
class Settings_Controller {

	use Date_Formatting;
	use Form_Rendering;

	/**
	 * Competitions repository.
	 *
	 * @var \PhotoCompetitionManager\Repository\Competitions_Repository
	 */
	private $competitions_repository;

	/**
	 * Members repository.
	 *
	 * @var \PhotoCompetitionManager\Repository\Members_Repository
	 */
	private $members_repository;

	/**
	 * Constructor.
	 *
	 * @param \PhotoCompetitionManager\Repository\Competitions_Repository $competitions_repository Competitions repository.
	 * @param \PhotoCompetitionManager\Repository\Members_Repository      $members_repository Members repository.
	 */
	public function __construct( $competitions_repository = null, $members_repository = null ) {
		$this->competitions_repository = $competitions_repository;
		$this->members_repository      = $members_repository;
	}

	/**
	 * Register hooks for this controller.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue inline scripts for settings page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'competitions_page_photo-competition-manager-settings' !== $hook ) {
			return;
		}

		// Same JavaScript as competitions controller for category/grade management.
		$inline_js = '
		document.addEventListener(\'DOMContentLoaded\', function() {
		(function() {
			let categoryIndex = document.querySelectorAll(\'.category-row\').length;
			let gradeIndex = document.querySelectorAll(\'.grade-row\').length;

			document.getElementById(\'add-category\')?.addEventListener(\'click\', function() {
				const container = document.getElementById(\'categories-container\');
				const row = document.createElement(\'div\');
				row.className = \'category-row\';
				row.style.cssText = \'margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;\';
				row.innerHTML = `
					<p style="margin: 5px 0;">
						<label>' . esc_js( __( 'Label', 'photo-competition-manager' ) ) . '</label><br />
						<input type="text" name="categories[${categoryIndex}][label]" class="regular-text" required />
					</p>
					<p style="margin: 5px 0;">
						<label>' . esc_js( __( 'Slug', 'photo-competition-manager' ) ) . '</label><br />
						<input type="text" name="categories[${categoryIndex}][slug]" class="regular-text" required />
					</p>
					<p style="margin: 5px 0;">
						<label>' . esc_js( __( 'Upload Quota', 'photo-competition-manager' ) ) . '</label><br />
						<input type="number" name="categories[${categoryIndex}][quota]" value="1" min="1" max="10" class="small-text" required />
					</p>
					<button type="button" class="button remove-category" style="color: #b32d2e;">' . esc_js( __( 'Remove', 'photo-competition-manager' ) ) . '</button>
				`;
				container.appendChild(row);
				categoryIndex++;
			});

			document.getElementById(\'add-grade\')?.addEventListener(\'click\', function() {
				const container = document.getElementById(\'grades-container\');
				const row = document.createElement(\'div\');
				row.className = \'grade-row\';
				row.style.cssText = \'margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;\';
				row.innerHTML = `
					<p style="margin: 5px 0;">
						<label>' . esc_js( __( 'Label', 'photo-competition-manager' ) ) . '</label><br />
						<input type="text" name="grades[${gradeIndex}][label]" class="regular-text" required />
					</p>
					<button type="button" class="button remove-grade" style="color: #b32d2e;">' . esc_js( __( 'Remove', 'photo-competition-manager' ) ) . '</button>
				`;
				container.appendChild(row);
				gradeIndex++;
			});

			document.addEventListener(\'click\', function(e) {
				if (e.target.classList.contains(\'remove-category\')) {
					e.target.closest(\'.category-row\').remove();
				}
				if (e.target.classList.contains(\'remove-grade\')) {
					e.target.closest(\'.grade-row\').remove();
				}
			});

			// Progress meter preview animations
			document.querySelectorAll(".progress-meter-card").forEach(function(card) {
				card.addEventListener("click", function() {
					document.querySelectorAll(".progress-meter-card").forEach(function(c) {
						c.classList.remove("active");
						c.style.borderColor = "#ddd";
					});
					card.classList.add("active");
					card.style.borderColor = "#0073aa";
				});
			});

			function animatePreviews() {
				var duration = 3000;
				var startTime = Date.now();

				function tick() {
					var elapsed = Date.now() - startTime;
					var progress = (elapsed % duration) / duration;

					document.querySelectorAll(".meter-preview").forEach(function(preview) {
						var type = preview.dataset.meterType;
						renderMeterPreview(preview, type, progress);
					});

					requestAnimationFrame(tick);
				}

				tick();
			}

			function renderMeterPreview(container, type, progress) {
				if (!container._initialized) {
					container._initialized = true;
					container.innerHTML = "";

					if (type === "bar") {
						container.style.display = "flex";
						container.style.alignItems = "flex-end";
						var track = document.createElement("div");
						track.style.cssText = "width:100%;height:8px;background:rgba(255,255,255,0.2);border-radius:0;";
						var fill = document.createElement("div");
						fill.style.cssText = "height:100%;background:#0073aa;transition:width 100ms linear;border-radius:0;";
						fill.className = "meter-fill";
						track.appendChild(fill);
						container.appendChild(track);
					} else if (type === "line") {
						container.style.display = "flex";
						container.style.alignItems = "flex-end";
						var track = document.createElement("div");
						track.style.cssText = "width:100%;height:3px;background:rgba(255,255,255,0.1);";
						var fill = document.createElement("div");
						fill.style.cssText = "height:100%;background:#fff;box-shadow:0 0 8px rgba(255,255,255,0.6);transition:width 100ms linear;";
						fill.className = "meter-fill";
						track.appendChild(fill);
						container.appendChild(track);
					} else if (type === "dots") {
						container.style.display = "flex";
						container.style.alignItems = "flex-end";
						container.style.justifyContent = "center";
						container.style.gap = "4px";
						container.style.paddingBottom = "4px";
						for (var i = 0; i < 15; i++) {
							var dot = document.createElement("div");
							dot.style.cssText = "width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,0.2);transition:background 0.2s,transform 0.2s;";
							dot.className = "meter-dot";
							container.appendChild(dot);
						}
					} else if (type === "radial") {
						container.style.display = "flex";
						container.style.alignItems = "center";
						container.style.justifyContent = "center";
						var svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
						svg.setAttribute("width", "40");
						svg.setAttribute("height", "40");
						svg.setAttribute("viewBox", "0 0 40 40");
						var bgCircle = document.createElementNS("http://www.w3.org/2000/svg", "circle");
						bgCircle.setAttribute("cx", "20");
						bgCircle.setAttribute("cy", "20");
						bgCircle.setAttribute("r", "16");
						bgCircle.setAttribute("fill", "none");
						bgCircle.setAttribute("stroke", "rgba(255,255,255,0.2)");
						bgCircle.setAttribute("stroke-width", "3");
						svg.appendChild(bgCircle);
						var circle = document.createElementNS("http://www.w3.org/2000/svg", "circle");
						circle.setAttribute("cx", "20");
						circle.setAttribute("cy", "20");
						circle.setAttribute("r", "16");
						circle.setAttribute("fill", "none");
						circle.setAttribute("stroke", "#0073aa");
						circle.setAttribute("stroke-width", "3");
						circle.setAttribute("stroke-linecap", "round");
						circle.setAttribute("transform", "rotate(-90 20 20)");
						var circumference = 2 * Math.PI * 16;
						circle.setAttribute("stroke-dasharray", circumference);
						circle.setAttribute("stroke-dashoffset", circumference);
						circle.className.baseVal = "meter-ring";
						svg.appendChild(circle);
						container.appendChild(svg);
					}
				}

				if (type === "bar" || type === "line") {
					var fill = container.querySelector(".meter-fill");
					if (fill) fill.style.width = (progress * 100) + "%";
				} else if (type === "dots") {
					var dots = container.querySelectorAll(".meter-dot");
					var filledCount = Math.floor(progress * dots.length);
					dots.forEach(function(dot, i) {
						if (i < filledCount) {
							dot.style.background = "#0073aa";
							dot.style.transform = "scale(1.3)";
						} else if (i === filledCount) {
							dot.style.background = "rgba(0,115,170,0.5)";
							dot.style.transform = "scale(1.1)";
						} else {
							dot.style.background = "rgba(255,255,255,0.2)";
							dot.style.transform = "scale(1)";
						}
					});
				} else if (type === "radial") {
					var ring = container.querySelector(".meter-ring");
					if (ring) {
						var circumference = 2 * Math.PI * 16;
						ring.setAttribute("stroke-dashoffset", circumference * (1 - progress));
					}
				}
			}

			animatePreviews();
		})();
		});
		';

		wp_register_script( 'photo-competition-manager-settings-js', false, array(), PHOTO_COMPETITION_MANAGER_VERSION, true );
		wp_enqueue_script( 'photo-competition-manager-settings-js' );
		wp_add_inline_script( 'photo-competition-manager-settings-js', $inline_js );
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
		} elseif ( isset( $_POST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; mutations require explicit nonce checks below.
			$action = sanitize_key( wp_unslash( $_POST['action'] ) );
		}

		if ( 'update_global_settings' !== $action ) {
			return;
		}

		check_admin_referer( 'photo_competition_global_settings', 'photo_competition_nonce' );

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
			if ( ! isset( $grade['label'] ) ) {
				continue;
			}

			$sanitized_grades[] = array(
				'label' => sanitize_text_field( $grade['label'] ),
				'slug'  => sanitize_title( $grade['label'] ),
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

		$voting_ui_type_input = sanitize_text_field( $this->get_post_string( 'voting_ui_type', 'buttons' ) );
		if ( ! in_array( $voting_ui_type_input, array( 'buttons', 'dropdown' ), true ) ) {
			$voting_ui_type_input = 'buttons';
		}

		$voting_password     = sanitize_text_field( $this->get_post_string( 'voting_password' ) );
		$click_image_to_zoom = isset( $_POST['click_image_to_zoom'] ) && '1' === $_POST['click_image_to_zoom'];

		$progress_meter_type_input = sanitize_text_field( $this->get_post_string( 'progress_meter_type', 'bar' ) );
		if ( ! in_array( $progress_meter_type_input, array( 'bar', 'line', 'dots', 'radial' ), true ) ) {
			$progress_meter_type_input = 'bar';
		}

		$preview_duration  = isset( $_POST['preview_duration'] ) ? absint( wp_unslash( $_POST['preview_duration'] ) ) : 10;
		$voting_duration   = isset( $_POST['voting_duration'] ) ? absint( wp_unslash( $_POST['voting_duration'] ) ) : 15;
		$critique_duration = isset( $_POST['critique_duration'] ) ? absint( wp_unslash( $_POST['critique_duration'] ) ) : 0;

		// Clamp to 0-120 range.
		$preview_duration  = min( 120, $preview_duration );
		$voting_duration   = min( 120, $voting_duration );
		$critique_duration = min( 120, $critique_duration );

		$upload_page_url  = sanitize_url( $this->get_post_string( 'upload_page_url', '' ) );
		$voting_page_url  = sanitize_url( $this->get_post_string( 'voting_page_url', '' ) );
		$results_page_url = sanitize_url( $this->get_post_string( 'results_page_url', '' ) );
		$top3_page_url    = sanitize_url( $this->get_post_string( 'top3_page_url', '' ) );

		$email_from_name  = sanitize_text_field( $this->get_post_string( 'email_from_name', '' ) );
		$email_from_email = sanitize_email( $this->get_post_string( 'email_from_email', '' ) );

		$settings   = array(
			'categories'      => $sanitized_categories,
			'grades'          => $sanitized_grades,
			'upload'          => array(
				'max_file_size_mb' => absint( $this->get_post_string( 'max_file_size_mb', '5' ) ),
				'max_width'        => absint( $this->get_post_string( 'max_width', '1920' ) ),
				'max_height'       => absint( $this->get_post_string( 'max_height', '1920' ) ),
				'allowed_formats'  => array( 'jpg', 'jpeg' ),
			),
			'voting'          => array(
				'score_matrix'        => $score_matrix,
				'open_categories'     => $existing_open_categories,
				'auth_mode'           => $auth_mode_input,
				'password'            => $voting_password,
				'click_image_to_zoom' => $click_image_to_zoom,
				'ui_type'             => 'default',
			),
			'slideshow'       => array(
				'progress_meter_type' => $progress_meter_type_input,
				'preview_duration'    => $preview_duration,
				'voting_duration'     => $voting_duration,
				'critique_duration'   => $critique_duration,
			),
			'email_reminders' => array(
				'enabled'                => true,
				'days_before_open'       => 7,
				'days_before_close'      => 1,
				'include_qr_code_voting' => true,
			),
			'email'           => array(
				'from_name'  => $email_from_name,
				'from_email' => $email_from_email,
			),
			'urls'            => array(
				'upload_page'  => $upload_page_url,
				'voting_page'  => $voting_page_url,
				'results_page' => $results_page_url,
				'top3_page'    => $top3_page_url,
			),
		);
		$validation = Competition_Settings::validate( $settings );

		if ( is_wp_error( $validation ) ) {
			add_settings_error(
				'photo_competition_settings',
				$validation->get_error_code(),
				$validation->get_error_message(),
				'error'
			);
		} else {
			// Get old grades before saving for mapping purposes.
			$old_grades = Competition_Settings::get_grades( $existing_settings );

			$this->save_global_settings( $settings );
			update_option( 'photo_comp_voting_ui_type', $voting_ui_type_input );

			// Sync grades to all existing competitions.
			$this->sync_grades_to_competitions( $sanitized_grades );

			// Sync grades to all members.
			$this->sync_grades_to_members( $old_grades, $sanitized_grades );

			add_settings_error(
				'photo_competition_settings',
				'settings_saved',
				__( 'Default settings saved successfully.', 'photo-competition-manager' ),
				'updated'
			);
		}

		$this->redirect_with_settings_errors(
			add_query_arg(
				array(
					'page' => 'photo-competition-manager-settings',
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Render global settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( 'photo_competition_settings' );

		$settings            = $this->get_global_settings();
		$categories          = Competition_Settings::get_categories( $settings );
		$grades              = Competition_Settings::get_grades( $settings );
		$upload              = Competition_Settings::get_upload_constraints( $settings );
		$voting              = Competition_Settings::get_voting_config( $settings );
		$slideshow           = $settings['slideshow'] ?? array();
		$progress_meter_type = $slideshow['progress_meter_type'] ?? 'bar';
		$preview_duration    = $slideshow['preview_duration'] ?? 10;
		$voting_duration     = $slideshow['voting_duration'] ?? 15;
		$critique_duration   = $slideshow['critique_duration'] ?? 0;
		$voting_ui_type      = get_option( 'photo_comp_voting_ui_type', 'buttons' );
		$urls                = $settings['urls'] ?? array(
			'upload_page' => '',
			'voting_page' => '',
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Default Competition Settings', 'photo-competition-manager' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'These settings will be used as defaults when creating new competitions. Individual competitions can override these settings.', 'photo-competition-manager' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="card" style="max-width: 720px; padding: 16px; margin-top: 20px;">';
		wp_nonce_field( 'photo_competition_global_settings', 'photo_competition_nonce' );
		echo '<input type="hidden" name="photo_competition_action" value="update_global_settings" />';
		echo '<input type="hidden" name="page" value="photo-competition-manager-settings" />';

		echo '<h2>' . esc_html__( 'Categories', 'photo-competition-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Define default categories and upload quotas.', 'photo-competition-manager' ) . '</p>';

		echo '<div id="categories-container">';
		foreach ( $categories as $index => $category ) {
			$this->render_category_field( $index, $category );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-category" class="button">' . esc_html__( 'Add Category', 'photo-competition-manager' ) . '</button>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Grades', 'photo-competition-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Define default member grade levels.', 'photo-competition-manager' ) . '</p>';

		echo '<div id="grades-container">';
		foreach ( $grades as $index => $grade ) {
			$this->render_grade_field( $index, $grade );
		}
		echo '</div>';

		echo '<p>';
		echo '<button type="button" id="add-grade" class="button">' . esc_html__( 'Add Grade', 'photo-competition-manager' ) . '</button>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Upload Constraints', 'photo-competition-manager' ) . '</h2>';

		echo '<p>';
		echo '<label for="max_file_size_mb">' . esc_html__( 'Max File Size (MB)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="number" id="max_file_size_mb" name="max_file_size_mb" min="1" max="50" value="' . esc_attr( $upload['max_file_size_mb'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_width">' . esc_html__( 'Max Width (pixels)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="number" id="max_width" name="max_width" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_width'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<p>';
		echo '<label for="max_height">' . esc_html__( 'Max Height (pixels)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="number" id="max_height" name="max_height" min="800" max="5000" step="10" value="' . esc_attr( $upload['max_height'] ) . '" class="small-text" />';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Voting Configuration', 'photo-competition-manager' ) . '</h2>';

		$auth_mode = $voting['auth_mode'] ?? 'password';

		echo '<p>';
		echo '<label for="voting_auth_mode">' . esc_html__( 'Voting Authentication Mode', 'photo-competition-manager' ) . '</label><br />';
		echo '<select id="voting_auth_mode" name="voting_auth_mode">';
		echo '<option value="password"' . selected( $auth_mode, 'password', false ) . '>' . esc_html__( 'Password-based (traditional)', 'photo-competition-manager' ) . '</option>';
		echo '<option value="token"' . selected( $auth_mode, 'token', false ) . '>' . esc_html__( 'Email Magic Links (anonymous)', 'photo-competition-manager' ) . '</option>';
		echo '</select><br />';
		echo '<span class="description">' . esc_html__( 'Choose how voters authenticate. Password mode allows voters to enter their name and optional password. Token mode sends secure one-time voting links via email for anonymous voting.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_ui_type">' . esc_html__( 'Voting UI Type', 'photo-competition-manager' ) . '</label><br />';
		echo '<select id="voting_ui_type" name="voting_ui_type">';
		echo '<option value="buttons"' . selected( $voting_ui_type, 'buttons', false ) . '>' . esc_html__( 'Horizontal Score Buttons', 'photo-competition-manager' ) . '</option>';
		echo '<option value="dropdown"' . selected( $voting_ui_type, 'dropdown', false ) . '>' . esc_html__( 'Dropdown', 'photo-competition-manager' ) . '</option>';
		echo '</select><br />';
		echo '<span class="description">' . esc_html__( 'Choose how voters select scores. Buttons offer a quick, one-click experience, while dropdowns conserve vertical space.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_password">' . esc_html__( 'Voting Password (for password mode)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="voting_password" name="voting_password" value="' . esc_attr( $voting['password'] ) . '" class="regular-text" />';
		echo '<span class="description">' . esc_html__( 'Voters must enter this password before submitting votes. Leave blank to disable by default. Only used when auth mode is "Password-based". Passwords are not case-sensitive.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		$click_to_zoom = isset( $voting['click_image_to_zoom'] ) ? (bool) $voting['click_image_to_zoom'] : false;
		echo '<label for="click_image_to_zoom">';
		echo '<input type="checkbox" id="click_image_to_zoom" name="click_image_to_zoom" value="1"' . checked( $click_to_zoom, true, false ) . ' />';
		echo ' ' . esc_html__( 'Click image to zoom on voting form', 'photo-competition-manager' );
		echo '</label><br />';
		echo '<span class="description">' . esc_html__( 'When enabled, images in the voting form can be clicked to open full-size in a new tab. When disabled, images are not clickable to prevent accidental navigation. Recommended: off for touch devices.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="score_matrix">' . esc_html__( 'Score Matrix (comma-separated)', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="score_matrix" name="score_matrix" value="' . esc_attr( implode( ', ', $voting['score_matrix'] ) ) . '" class="regular-text" />';
		echo '<span class="description">' . esc_html__( 'E.g., 9, 8, 7, 6, 5', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'Slideshow', 'photo-competition-manager' ) . '</h2>';

		echo '<p>';
		echo '<label>' . esc_html__( 'Progress Meter Style', 'photo-competition-manager' ) . '</label>';
		echo '</p>';

		$meter_types = array(
			'bar'    => __( 'Bar', 'photo-competition-manager' ),
			'line'   => __( 'Thin Line', 'photo-competition-manager' ),
			'dots'   => __( 'Dots', 'photo-competition-manager' ),
			'radial' => __( 'Radial', 'photo-competition-manager' ),
		);

		echo '<div class="progress-meter-selector" style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 20px;">';

		foreach ( $meter_types as $type => $label ) {
			$is_active = ( $type === $progress_meter_type ) ? ' active' : '';
			echo '<label class="progress-meter-card' . esc_attr( $is_active ) . '" style="cursor: pointer; border: 2px solid ' . ( $is_active ? '#0073aa' : '#ddd' ) . '; border-radius: 8px; padding: 12px; text-align: center; background: #1a1a1a; min-width: 140px; transition: border-color 0.2s;">';
			echo '<input type="radio" name="progress_meter_type" value="' . esc_attr( $type ) . '"' . checked( $progress_meter_type, $type, false ) . ' style="display: none;" />';
			echo '<div class="meter-preview" data-meter-type="' . esc_attr( $type ) . '" style="height: 50px; position: relative; margin-bottom: 8px; overflow: hidden; border-radius: 4px;"></div>';
			echo '<span style="color: #666; font-size: 13px; font-weight: 600;">' . esc_html( $label ) . '</span>';
			echo '</label>';
		}

		echo '</div>';
		echo '<span class="description">' . esc_html__( 'Choose the progress indicator style shown during the slideshow.', 'photo-competition-manager' ) . '</span>';

		echo '<table class="form-table" style="margin-top: 16px;"><tbody>';
		?>
		<tr>
			<th scope="row">
				<label for="preview_duration"><?php esc_html_e( 'Preview Duration', 'photo-competition-manager' ); ?></label>
			</th>
			<td>
				<input type="number" id="preview_duration" name="preview_duration" value="<?php echo esc_attr( $preview_duration ); ?>" min="0" max="120" step="1" class="small-text" />
				<span><?php esc_html_e( 'seconds (0 = manual advance)', 'photo-competition-manager' ); ?></span>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="voting_duration"><?php esc_html_e( 'Voting Slideshow Duration', 'photo-competition-manager' ); ?></label>
			</th>
			<td>
				<input type="number" id="voting_duration" name="voting_duration" value="<?php echo esc_attr( $voting_duration ); ?>" min="0" max="120" step="1" class="small-text" />
				<span><?php esc_html_e( 'seconds (0 = manual advance)', 'photo-competition-manager' ); ?></span>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="critique_duration"><?php esc_html_e( 'Critique Duration', 'photo-competition-manager' ); ?></label>
			</th>
			<td>
				<input type="number" id="critique_duration" name="critique_duration" value="<?php echo esc_attr( $critique_duration ); ?>" min="0" max="120" step="1" class="small-text" />
				<span><?php esc_html_e( 'seconds (0 = manual advance)', 'photo-competition-manager' ); ?></span>
			</td>
		</tr>
		<?php
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Email Configuration', 'photo-competition-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Configure the sender name and email address for all competition emails. If left blank, WordPress defaults will be used.', 'photo-competition-manager' ) . '</p>';

		$email_config = $settings['email'] ?? array(
			'from_name'  => '',
			'from_email' => '',
		);

		echo '<p>';
		echo '<label for="email_from_name">' . esc_html__( 'From Name', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" id="email_from_name" name="email_from_name" value="' . esc_attr( $email_config['from_name'] ) . '" class="regular-text" placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '" />';
		echo '<br /><span class="description">' . esc_html__( 'The name that appears as the sender in competition emails (e.g., "Photo Club Competitions").', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="email_from_email">' . esc_html__( 'From Email Address', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="email" id="email_from_email" name="email_from_email" value="' . esc_attr( $email_config['from_email'] ) . '" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '" />';
		echo '<br /><span class="description">' . esc_html__( 'The email address that appears as the sender (e.g., "competitions@yourclub.org"). Leave blank to use WordPress default.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<h2>' . esc_html__( 'URLs', 'photo-competition-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Default pages used in upload and voting notifications.', 'photo-competition-manager' ) . '</p>';

		echo '<p>';
		echo '<label for="upload_page_url">' . esc_html__( 'Upload Page URL', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="url" id="upload_page_url" name="upload_page_url" value="' . esc_attr( $urls['upload_page'] ?? '' ) . '" class="regular-text" placeholder="https://example.com/upload" />';
		echo '<br /><span class="description">' . esc_html__( 'Members receive this link when requesting upload tokens.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="voting_page_url">' . esc_html__( 'Voting Page URL', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="url" id="voting_page_url" name="voting_page_url" value="' . esc_attr( $urls['voting_page'] ?? '' ) . '" class="regular-text" placeholder="https://example.com/vote" />';
		echo '<br /><span class="description">' . esc_html__( 'Voters receive this link in voting invitation emails.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="results_page_url">' . esc_html__( 'Results Page URL', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="url" id="results_page_url" name="results_page_url" value="' . esc_attr( $urls['results_page'] ?? '' ) . '" class="regular-text" placeholder="https://example.com/results" />';
		echo '<br /><span class="description">' . esc_html__( 'Page displaying full competition results with all entries and scores.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		echo '<p>';
		echo '<label for="top3_page_url">' . esc_html__( 'Top 3 Page URL', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="url" id="top3_page_url" name="top3_page_url" value="' . esc_attr( $urls['top3_page'] ?? '' ) . '" class="regular-text" placeholder="https://example.com/top3" />';
		echo '<br /><span class="description">' . esc_html__( 'Page displaying top 3 winners in a featured format.', 'photo-competition-manager' ) . '</span>';
		echo '</p>';

		submit_button( __( 'Save Default Settings', 'photo-competition-manager' ) );

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render category field row.
	 *
	 * @param  int   $index    Category index.
	 * @param  array $category Category data.
	 * @return void
	 */
	private function render_category_field( int $index, array $category ): void {
		echo '<div class="category-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Label', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" name="categories[' . esc_attr( $index ) . '][label]" value="' . esc_attr( $category['label'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Slug', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" name="categories[' . esc_attr( $index ) . '][slug]" value="' . esc_attr( $category['slug'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Upload Quota', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="number" name="categories[' . esc_attr( $index ) . '][quota]" value="' . esc_attr( $category['quota'] ) . '" min="1" max="10" class="small-text" required />';
		echo '</p>';

		echo '<button type="button" class="button remove-category" style="color: #b32d2e;">' . esc_html__( 'Remove', 'photo-competition-manager' ) . '</button>';

		echo '</div>';
	}

	/**
	 * Render grade field row.
	 *
	 * @param  int   $index Grade index.
	 * @param  array $grade Grade data.
	 * @return void
	 */
	private function render_grade_field( int $index, array $grade ): void {
		echo '<div class="grade-row" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">';

		echo '<p style="margin: 5px 0;">';
		echo '<label>' . esc_html__( 'Label', 'photo-competition-manager' ) . '</label><br />';
		echo '<input type="text" name="grades[' . esc_attr( $index ) . '][label]" value="' . esc_attr( $grade['label'] ) . '" class="regular-text" required />';
		echo '</p>';

		echo '<button type="button" class="button remove-grade" style="color: #b32d2e;">' . esc_html__( 'Remove', 'photo-competition-manager' ) . '</button>';

		echo '</div>';
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
	 * Save global default settings.
	 *
	 * @param  array<string, mixed> $settings Settings to save.
	 * @return void
	 */
	private function save_global_settings( array $settings ): void {
		update_option( 'photo_comp_default_settings', Competition_Settings::encode( $settings ) );
	}

	/**
	 * Sync grades from global settings to all existing competitions.
	 *
	 * @param  array<int, array{label: string, slug: string}> $new_grades New grades array.
	 * @return void
	 */
	private function sync_grades_to_competitions( array $new_grades ): void {
		if ( ! $this->competitions_repository ) {
			return;
		}

		// Get all competitions (including archived).
		$competitions = $this->competitions_repository->all( 1000, true, false );

		foreach ( $competitions as $competition ) {
			// Parse existing settings.
			$settings = Competition_Settings::parse( $competition->settings );

			// Update grades.
			$settings['grades'] = $new_grades;

			// Save updated settings.
			$this->competitions_repository->update(
				$competition->id,
				array(
					'settings' => $settings,
				)
			);
		}
	}

	/**
	 * Sync member grades when global grades change.
	 *
	 * Maps old grade slugs to new grade slugs based on position/order.
	 * If a member's grade no longer exists, it's updated to the first available grade.
	 *
	 * @param  array<int, array{label: string, slug: string}> $old_grades Old grades array.
	 * @param  array<int, array{label: string, slug: string}> $new_grades New grades array.
	 * @return void
	 */
	private function sync_grades_to_members( array $old_grades, array $new_grades ): void {
		if ( ! $this->members_repository ) {
			return;
		}

		// Build a mapping from old grade slugs to new grade slugs based on position.
		$grade_mapping = array();
		foreach ( $old_grades as $index => $old_grade ) {
			$old_slug = $old_grade['slug'] ?? '';
			// Map to new grade at same position, or first grade if position doesn't exist.
			$new_slug = isset( $new_grades[ $index ]['slug'] ) ? $new_grades[ $index ]['slug'] : ( $new_grades[0]['slug'] ?? '' );
			if ( $old_slug && $new_slug ) {
				$grade_mapping[ $old_slug ] = $new_slug;
			}
		}

		// If no mapping exists (e.g., no old grades), default to first new grade.
		$default_grade = $new_grades[0]['slug'] ?? '';

		// Get all members (including inactive).
		$members = $this->members_repository->all( 10000, false );

		foreach ( $members as $member ) {
			$current_grade = $member->grade ?? '';

			// Determine new grade based on mapping or default.
			$new_grade = $grade_mapping[ $current_grade ] ?? $default_grade;

			// Only update if grade has changed.
			if ( $new_grade && $new_grade !== $current_grade ) {
				$this->members_repository->update(
					$member->id,
					array(
						'grade' => $new_grade,
					)
				);
			}
		}
	}
}
