<?php
/**
 * Handle slideshow shortcode for in-person voting.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Support\Image_Processor;

/**
 * Shortcode renderer for competition slideshow presentation.
 *
 * Displays images in full-screen mode for in-person voting at meetings.
 * Automatically opens and closes voting windows for the selected category.
 *
 * @since 1.0.0
 */
class Slideshow_Shortcode {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions_repo;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images_repo;

	/**
	 * Image processor.
	 *
	 * @var Image_Processor
	 */
	private $image_processor;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Images_Repository|null       $images_repo       Images repository.
	 * @param Image_Processor|null         $image_processor   Image processor.
	 */
	public function __construct(
		?Competitions_Repository $competitions_repo = null,
		?Images_Repository $images_repo = null,
		?Image_Processor $image_processor = null
	) {
		$this->competitions_repo = $competitions_repo ? $competitions_repo : new Competitions_Repository();
		$this->images_repo       = $images_repo ? $images_repo : new Images_Repository();
		$this->image_processor   = $image_processor ? $image_processor : new Image_Processor();
	}

	/**
	 * Register shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'competition_slideshow', array( $this, 'render' ) );
		add_action( 'wp_ajax_photo_comp_get_slideshow_images', array( $this, 'handle_get_images' ) );
		add_action( 'wp_ajax_photo_comp_slideshow_start', array( $this, 'handle_slideshow_start' ) );
		add_action( 'wp_ajax_photo_comp_slideshow_stop', array( $this, 'handle_slideshow_stop' ) );
	}

	/**
	 * Render slideshow shortcode.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'competition' => '',
				'category'    => '',
			),
			$atts,
			'competition_slideshow'
		);

		if ( empty( $atts['competition'] ) ) {
			return '<p class="error">' . esc_html__( 'Please specify a competition slug.', 'photo-competition-manager' ) . '</p>';
		}

		if ( empty( $atts['category'] ) ) {
			return '<p class="error">' . esc_html__( 'Please specify a category slug.', 'photo-competition-manager' ) . '</p>';
		}

		$competition = $this->competitions_repo->find_by_slug( $atts['competition'] );
		if ( ! $competition ) {
			return '<p class="error">' . esc_html__( 'Competition not found.', 'photo-competition-manager' ) . '</p>';
		}

		$category = sanitize_text_field( $atts['category'] );
		$settings = Competition_Settings::parse( $competition->settings );

		// Verify category exists in competition settings.
		$categories      = Competition_Settings::get_categories( $settings );
		$category_exists = false;
		$category_label  = $category;

		foreach ( $categories as $cat ) {
			if ( $cat['slug'] === $category ) {
				$category_exists = true;
				$category_label  = $cat['label'];
				break;
			}
		}

		if ( ! $category_exists ) {
			return '<p class="error">' . esc_html__( 'Invalid category specified.', 'photo-competition-manager' ) . '</p>';
		}

		// Get images for this category.
		$images = $this->images_repo->find_by_competition( (int) $competition->id, $category );

		if ( empty( $images ) ) {
			return '<p class="notice">' . esc_html__( 'No images submitted in this category yet.', 'photo-competition-manager' ) . '</p>';
		}

		// Enqueue slideshow styles and scripts.
		$this->enqueue_assets();

		// Prepare image data for JavaScript.
		$image_data = array();
		foreach ( $images as $image ) {
			$image_url = $this->image_processor->get_image_url( $competition->slug, $image->category, $image->filename );
			if ( ! is_wp_error( $image_url ) ) {
				$image_data[] = array(
					'id'            => $image->id,
					'url'           => $image_url,
					'random_number' => $image->random_number,
				);
			}
		}

		// Output slideshow interface.
		ob_start();
		$this->render_slideshow_interface( $competition, $category, $category_label, $image_data );
		$output = ob_get_clean();

		return $output ? $output : '';
	}

	/**
	 * Render slideshow interface.
	 *
	 * @param object            $competition    Competition object.
	 * @param string            $category       Category slug.
	 * @param string            $category_label Category label.
	 * @param array<int, array> $image_data     Image data for JavaScript.
	 * @return void
	 */
	private function render_slideshow_interface( object $competition, string $category, string $category_label, array $image_data ): void {
		$nonce = wp_create_nonce( 'photo_comp_slideshow' );
		?>
		<div class="photo-comp-slideshow-container"
			data-competition-id="<?php echo esc_attr( $competition->id ); ?>"
			data-category="<?php echo esc_attr( $category ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-images="<?php echo esc_attr( wp_json_encode( $image_data ) ); ?>">

			<!-- Control Panel -->
			<div class="slideshow-controls">
				<h2><?php echo esc_html( $competition->title ); ?> - <?php echo esc_html( $category_label ); ?></h2>

				<div class="control-buttons">
					<button type="button" class="button button-primary slideshow-start">
						<?php esc_html_e( 'Start Slideshow', 'photo-competition-manager' ); ?>
					</button>
					<button type="button" class="button slideshow-pause" disabled>
						<?php esc_html_e( 'Pause', 'photo-competition-manager' ); ?>
					</button>
					<button type="button" class="button slideshow-resume" disabled>
						<?php esc_html_e( 'Resume', 'photo-competition-manager' ); ?>
					</button>
					<button type="button" class="button slideshow-stop" disabled>
						<?php esc_html_e( 'Stop Slideshow', 'photo-competition-manager' ); ?>
					</button>
				</div>

				<div class="slideshow-settings">
					<label for="slideshow-interval">
						<?php esc_html_e( 'Display duration per image (seconds):', 'photo-competition-manager' ); ?>
						<input type="number" id="slideshow-interval" min="5" max="60" value="10" step="1" />
					</label>
					<p class="description">
						<?php esc_html_e( 'Note: This slideshow does not control voting. Open and close voting separately using the Voting Controls page.', 'photo-competition-manager' ); ?>
					</p>
				</div>

				<div class="slideshow-status">
					<p class="status-message"><?php esc_html_e( 'Ready to start', 'photo-competition-manager' ); ?></p>
					<p class="image-counter">
						<span class="current-image">0</span> / <span class="total-images"><?php echo esc_html( count( $image_data ) ); ?></span>
					</p>
				</div>
			</div>

			<!-- Full-screen Display Area -->
			<div class="slideshow-display" style="display: none;">
				<div class="slideshow-image-container">
					<img src="" alt="" class="slideshow-current-image" />
					<div class="slideshow-image-info">
						<span class="image-number"></span>
					</div>
				</div>
				<div class="slideshow-progress">
					<div class="progress-bar" style="width: 0%;"></div>
				</div>
				<button type="button" class="slideshow-exit" aria-label="<?php esc_attr_e( 'Exit fullscreen', 'photo-competition-manager' ); ?>">
					<span class="dashicons dashicons-no-alt"></span>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle AJAX request to get slideshow images.
	 *
	 * @return void
	 */
	public function handle_get_images(): void {
		check_ajax_referer( 'photo_comp_admin_slideshow', 'nonce' );

		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'photo-competition-manager' ) ) );
		}

		$competition_id   = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$competition_slug = isset( $_POST['competition_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['competition_slug'] ) ) : '';
		$category         = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';

		if ( ! $competition_id || ! $category ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'photo-competition-manager' ) ) );
		}

		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			wp_send_json_error( array( 'message' => __( 'Competition not found.', 'photo-competition-manager' ) ) );
		}

		// Get images for this category.
		$images = $this->images_repo->find_by_competition( $competition_id, $category );

		if ( empty( $images ) ) {
			wp_send_json_error( array( 'message' => __( 'No images found for this category.', 'photo-competition-manager' ) ) );
		}

		// Prepare image data.
		$image_data = array();
		foreach ( $images as $image ) {
			$image_url = $this->image_processor->get_image_url( $competition_slug, $image->category, $image->filename );
			if ( ! is_wp_error( $image_url ) ) {
				$image_data[] = array(
					'id'            => $image->id,
					'url'           => $image_url,
					'random_number' => $image->random_number,
				);
			}
		}

		wp_send_json_success( array( 'images' => $image_data ) );
	}

	/**
	 * Enqueue slideshow assets.
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		wp_enqueue_style(
			'photo-competition-manager-slideshow',
			plugins_url( 'assets/css/slideshow.css', dirname( __DIR__ ) . '/photo-competition-manager.php' ),
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'photo-competition-manager-slideshow',
			plugins_url( 'assets/js/slideshow.js', dirname( __DIR__ ) . '/photo-competition-manager.php' ),
			array( 'jquery' ),
			'1.0.0',
			true
		);
	}

	/**
	 * Handle AJAX request to start slideshow.
	 *
	 * Note: This does NOT open voting. Admin must manually open voting
	 * using the "Open Voting" button on the Voting Controls page.
	 *
	 * @return void
	 */
	public function handle_slideshow_start(): void {
		check_ajax_referer( 'photo_comp_slideshow', 'nonce' );

		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'photo-competition-manager' ) ) );
		}

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$category       = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';

		if ( ! $competition_id || ! $category ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'photo-competition-manager' ) ) );
		}

		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			wp_send_json_error( array( 'message' => __( 'Competition not found.', 'photo-competition-manager' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Slideshow started.', 'photo-competition-manager' ) ) );
	}

	/**
	 * Handle AJAX request to stop slideshow.
	 *
	 * Note: This does NOT control voting. Admin must use the Voting Controls page
	 * to open or close voting independently.
	 *
	 * @return void
	 */
	public function handle_slideshow_stop(): void {
		check_ajax_referer( 'photo_comp_slideshow', 'nonce' );

		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'photo-competition-manager' ) ) );
		}

		$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$category       = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';

		if ( ! $competition_id || ! $category ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'photo-competition-manager' ) ) );
		}

		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			wp_send_json_error( array( 'message' => __( 'Competition not found.', 'photo-competition-manager' ) ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Slideshow stopped.', 'photo-competition-manager' ),
			)
		);
	}
}
