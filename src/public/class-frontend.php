<?php
/**
 * Registers public-facing shortcodes and assets.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

/**
 * Coordinate public functionality (shortcodes and styles).
 *
 * @since 0.1.0
 */
class Frontend {

	/**
	 * Upload shortcode handler.
	 *
	 * @var Upload_Shortcode|null
	 */
	private $upload_shortcode;

	/**
	 * Voting shortcode handler.
	 *
	 * @var Voting_Shortcode|null
	 */
	private $voting_shortcode;

	/**
	 * Results shortcode handler.
	 *
	 * @var Results_Shortcode|null
	 */
	private $results_shortcode;

	/**
	 * Top 3 shortcode handler.
	 *
	 * @var Top3_Shortcode|null
	 */
	private $top3_shortcode;

	/**
	 * Slideshow shortcode handler.
	 *
	 * @var Slideshow_Shortcode|null
	 */
	private $slideshow_shortcode;

	/**
	 * Attach public hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Register upload shortcode.
		$this->upload_shortcode = new Upload_Shortcode();
		$this->upload_shortcode->register();

		// Register voting shortcode.
		$this->voting_shortcode = new Voting_Shortcode();
		$this->voting_shortcode->register();

		// Register results shortcode.
		$this->results_shortcode = new Results_Shortcode();
		$this->results_shortcode->register();

		// Register top 3 shortcode.
		$this->top3_shortcode = new Top3_Shortcode();
		$this->top3_shortcode->register();

		// Register slideshow shortcode.
		$this->slideshow_shortcode = new Slideshow_Shortcode();
		$this->slideshow_shortcode->register();

		// Enqueue frontend styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Enqueue frontend styles.
	 *
	 * @return void
	 */
	public function enqueue_styles(): void {
		wp_enqueue_style(
			'club-competitions-frontend',
			plugins_url( 'assets/css/frontend.css', dirname( __DIR__ ) . '/photo-competition-manager.php' ),
			array(),
			'1.0.0'
		);
	}
}
