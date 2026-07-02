<?php
/**
 * Quick actions partial for the admin voting controls page.
 *
 * Reads $data keys: voting_page_url, voting_password, top3_url, results_url.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;
?>
		<div class="quick-actions-bar" id="quick-actions">
			<button type="button" class="quick-actions-toggle" aria-expanded="false" aria-controls="quick-actions-content">
				<span class="dashicons dashicons-arrow-right-alt2"></span>
				<?php esc_html_e( 'Quick Actions', 'photo-competition-manager' ); ?>
			</button>
			<div class="quick-actions-content" id="quick-actions-content" style="display: none;">
				<div class="quick-actions-buttons">
					<?php if ( ! empty( $data['voting_page_url'] ) ) : ?>
						<button type="button" class="button quick-action-qr" data-target="qr-code-panel">
							<span class="dashicons dashicons-smartphone"></span>
							<?php esc_html_e( 'Show QR Code', 'photo-competition-manager' ); ?>
						</button>
					<?php endif; ?>
					<?php if ( ! empty( $data['results_url'] ) ) : ?>
						<a href="<?php echo esc_url( $data['results_url'] ); ?>" class="button" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-chart-bar"></span>
							<?php esc_html_e( 'Full Results', 'photo-competition-manager' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( ! empty( $data['top3_url'] ) ) : ?>
						<a href="<?php echo esc_url( $data['top3_url'] ); ?>" class="button" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-awards"></span>
							<?php esc_html_e( 'Top 3 Results', 'photo-competition-manager' ); ?>
						</a>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $data['voting_page_url'] ) ) : ?>
				<div class="qr-code-panel" id="qr-code-panel" style="display: none;">
					<?php if ( '' !== $data['voting_password'] ) : ?>
					<div class="qr-code-password">
						<span class="qr-code-password-label"><?php esc_html_e( 'Voting Password:', 'photo-competition-manager' ); ?></span>
						<span class="qr-code-password-value"><?php echo esc_html( $data['voting_password'] ); ?></span>
					</div>
					<?php endif; ?>
					<div class="qr-code-container" data-voting-url="<?php echo esc_attr( $data['voting_page_url'] ); ?>">
						<div class="qr-code-canvas"></div>
						<div class="qr-code-details">
							<h4><?php esc_html_e( 'Voting Page', 'photo-competition-manager' ); ?></h4>
							<p><a href="<?php echo esc_url( $data['voting_page_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $data['voting_page_url'] ); ?></a></p>
							<button type="button" class="button button-small copy-url-btn" data-url="<?php echo esc_attr( $data['voting_page_url'] ); ?>">
								<span class="dashicons dashicons-clipboard"></span>
								<?php esc_html_e( 'Copy Link', 'photo-competition-manager' ); ?>
							</button>
						</div>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
