<?php
/**
 * Public-facing hooks.
 *
 * @package ClubCompetitions\Frontend
 */

namespace ClubCompetitions\Frontend;

class Frontend {

	/**
	 * Upload shortcode handler.
	 *
	 * @var UploadShortcode|null
	 */
	private $upload_shortcode;

	/**
	 * Voting shortcode handler.
	 *
	 * @var VotingShortcode|null
	 */
	private $voting_shortcode;

	/**
	 * Results shortcode handler.
	 *
	 * @var ResultsShortcode|null
	 */
	private $results_shortcode;

	/**
	 * Top 3 shortcode handler.
	 *
	 * @var Top3Shortcode|null
	 */
	private $top3_shortcode;

	/**
	 * Attach public hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// Register upload shortcode.
		$this->upload_shortcode = new UploadShortcode();
		$this->upload_shortcode->register();

		// Register voting shortcode.
		$this->voting_shortcode = new VotingShortcode();
		$this->voting_shortcode->register();

		// Register results shortcode.
		$this->results_shortcode = new ResultsShortcode();
		$this->results_shortcode->register();

		// Register top 3 shortcode.
		$this->top3_shortcode = new Top3Shortcode();
		$this->top3_shortcode->register();

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
			plugins_url( 'assets/css/frontend.css', dirname( __DIR__ ) . '/club-competitions.php' ),
			array(),
			'1.0.0'
		);
	}
}
