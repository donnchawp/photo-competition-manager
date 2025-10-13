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
	 * Attach public hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'competition_voting', array( $this, 'render_voting_placeholder' ) );

		// Register upload shortcode.
		$this->upload_shortcode = new UploadShortcode();
		$this->upload_shortcode->register();

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

	/**
	 * Render placeholder voting interface.
	 *
	 * @return string
	 */
	public function render_voting_placeholder(): string {
		ob_start();
		?>
		<div class="club-competitions-voting">
			<h2><?php esc_html_e( 'Competition Voting', 'club-competitions' ); ?></h2>
			<p><?php esc_html_e( 'Replace this template with the real voting experience.', 'club-competitions' ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
