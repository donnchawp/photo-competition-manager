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
