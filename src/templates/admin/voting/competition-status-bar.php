<?php
/**
 * Competition status bar partial for the admin voting controls page.
 *
 * Reads $data keys: competition, uploads_closed, toggle_uploads_url,
 * results_visible, hide_results_url.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;
?>
		<div class="postbox photo-comp-status-bar">
			<div class="inside" style="margin: 0; padding: 10px 14px;">
				<div class="status-bar-layout">
					<strong class="status-bar-title"><?php echo esc_html( $data['competition']->title ); ?></strong>
					<div class="status-bar-controls">
						<div class="status-control">
							<span class="status-control-label"><?php esc_html_e( 'Uploads', 'photo-competition-manager' ); ?></span>
							<?php if ( $data['uploads_closed'] ) : ?>
								<span class="photo-comp-badge photo-comp-badge-success"><?php esc_html_e( 'Closed', 'photo-competition-manager' ); ?></span>
								<a href="<?php echo esc_url( $data['toggle_uploads_url'] ); ?>" class="button button-small"><?php esc_html_e( 'Reopen', 'photo-competition-manager' ); ?></a>
							<?php else : ?>
								<span class="photo-comp-badge photo-comp-badge-warning"><?php esc_html_e( 'Open', 'photo-competition-manager' ); ?></span>
								<a href="<?php echo esc_url( $data['toggle_uploads_url'] ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Close Uploads', 'photo-competition-manager' ); ?></a>
							<?php endif; ?>
						</div>
						<div class="status-control">
							<span class="status-control-label"><?php esc_html_e( 'Results', 'photo-competition-manager' ); ?></span>
							<?php if ( $data['results_visible'] ) : ?>
								<span class="photo-comp-badge photo-comp-badge-warning"><?php esc_html_e( 'Visible', 'photo-competition-manager' ); ?></span>
								<a href="<?php echo esc_url( $data['hide_results_url'] ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Hide', 'photo-competition-manager' ); ?></a>
							<?php else : ?>
								<span class="photo-comp-badge photo-comp-badge-success"><?php esc_html_e( 'Hidden', 'photo-competition-manager' ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
