<?php
/**
 * Handle slideshow shortcode for in-person voting.
 *
 * @package ClubCompetitions\Frontend
 */

namespace ClubCompetitions\Frontend;

use ClubCompetitions\Repository\Competitions_Repository;
use ClubCompetitions\Repository\Images_Repository;
use ClubCompetitions\Support\Competition_Settings;
use ClubCompetitions\Support\Image_Processor;

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
		add_action( 'wp_ajax_club_compete_get_slideshow_images', array( $this, 'handle_get_images' ) );
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
			return '<p class="error">' . esc_html__( 'Please specify a competition slug.', 'club-competitions' ) . '</p>';
		}

		if ( empty( $atts['category'] ) ) {
			return '<p class="error">' . esc_html__( 'Please specify a category slug.', 'club-competitions' ) . '</p>';
		}

		$competition = $this->competitions_repo->find_by_slug( $atts['competition'] );
		if ( ! $competition ) {
			return '<p class="error">' . esc_html__( 'Competition not found.', 'club-competitions' ) . '</p>';
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
			return '<p class="error">' . esc_html__( 'Invalid category specified.', 'club-competitions' ) . '</p>';
		}

		// Get images for this category.
		$images = $this->images_repo->find_by_competition( (int) $competition->id, $category );

		if ( empty( $images ) ) {
			return '<p class="notice">' . esc_html__( 'No images submitted in this category yet.', 'club-competitions' ) . '</p>';
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
		$nonce = wp_create_nonce( 'club_compete_slideshow' );
		?>
		<div class="club-competitions-slideshow-container"
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
						<?php esc_html_e( 'Start Slideshow', 'club-competitions' ); ?>
					</button>
					<button type="button" class="button slideshow-pause" disabled>
						<?php esc_html_e( 'Pause', 'club-competitions' ); ?>
					</button>
					<button type="button" class="button slideshow-resume" disabled>
						<?php esc_html_e( 'Resume', 'club-competitions' ); ?>
					</button>
					<button type="button" class="button slideshow-stop" disabled>
						<?php esc_html_e( 'Stop Slideshow', 'club-competitions' ); ?>
					</button>
				</div>

				<div class="slideshow-settings">
					<label for="slideshow-interval">
						<?php esc_html_e( 'Display duration per image (seconds):', 'club-competitions' ); ?>
						<input type="number" id="slideshow-interval" min="5" max="60" value="10" step="1" />
					</label>

					<label for="voting-duration">
						<?php esc_html_e( 'Voting window after slideshow ends (minutes):', 'club-competitions' ); ?>
						<input type="number" id="voting-duration" min="0" max="120" value="5" step="1" />
						<small><?php esc_html_e( 'Set to 0 to close voting immediately when slideshow ends.', 'club-competitions' ); ?></small>
					</label>
				</div>

				<div class="slideshow-status">
					<p class="status-message"><?php esc_html_e( 'Ready to start', 'club-competitions' ); ?></p>
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
				<button type="button" class="slideshow-exit" aria-label="<?php esc_attr_e( 'Exit fullscreen', 'club-competitions' ); ?>">
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
		check_ajax_referer( 'club_compete_admin_slideshow', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'club-competitions' ) ) );
		}

		$competition_id   = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		$competition_slug = isset( $_POST['competition_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['competition_slug'] ) ) : '';
		$category         = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';

		if ( ! $competition_id || ! $category ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'club-competitions' ) ) );
		}

		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			wp_send_json_error( array( 'message' => __( 'Competition not found.', 'club-competitions' ) ) );
		}

		// Get images for this category.
		$images = $this->images_repo->find_by_competition( $competition_id, $category );

		if ( empty( $images ) ) {
			wp_send_json_error( array( 'message' => __( 'No images found for this category.', 'club-competitions' ) ) );
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
			'club-competitions-slideshow',
			plugins_url( 'assets/css/slideshow.css', dirname( __DIR__ ) . '/club-competitions.php' ),
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'club-competitions-slideshow',
			plugins_url( 'assets/js/slideshow.js', dirname( __DIR__ ) . '/club-competitions.php' ),
			array( 'jquery' ),
			'1.0.0',
			true
		);
	}
}
