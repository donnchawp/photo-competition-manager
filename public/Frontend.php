<?php
/**
 * Public-facing hooks.
 *
 * @package ClubCompetitions\Frontend
 */

namespace ClubCompetitions\Frontend;

class Frontend {

	/**
	 * Attach public hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'competition_voting', array( $this, 'render_voting_placeholder' ) );
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
