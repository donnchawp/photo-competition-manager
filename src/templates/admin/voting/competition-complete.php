<?php
/**
 * Competition complete partial for the admin voting controls page.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;
?>
		<div class="postbox photo-comp-workflow-card">
			<div class="inside">
				<div class="complete-header" style="display: flex; align-items: center; gap: 8px; margin-bottom: 16px;">
					<span class="dashicons dashicons-yes-alt" style="color: #00a32a; font-size: 24px; width: 24px; height: 24px;"></span>
					<h2 style="margin: 0;"><?php esc_html_e( 'All Categories Complete', 'photo-competition-manager' ); ?></h2>
				</div>

				<div class="complete-slideshow-section" style="margin-bottom: 16px;">
					<div style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 12px;">
						<label class="step-duration-label">
							<?php esc_html_e( 'Slideshow duration:', 'photo-competition-manager' ); ?>
							<input type="number" class="small-text photo-comp-step-duration" id="replay-slideshow-duration" value="<?php echo esc_attr( $data['slideshow_replay_duration'] ); ?>" min="0" max="120" step="1" />s
						</label>
						<label class="step-duration-label">
							<?php esc_html_e( 'Critique duration:', 'photo-competition-manager' ); ?>
							<input type="number" class="small-text photo-comp-step-duration" id="replay-critique-duration" value="<?php echo esc_attr( $data['critique_replay_duration'] ); ?>" min="0" max="120" step="1" />s
						</label>
					</div>
				</div>

				<ul class="complete-categories" style="margin: 0 0 16px; padding: 0; list-style: none;">
					<?php foreach ( $data['all_categories'] as $cat_data ) : ?>
						<li class="complete-category-item" style="display: flex; align-items: center; gap: 8px; padding: 6px 0;">
							<span class="dashicons dashicons-yes" style="color: #00a32a;"></span>
							<span class="category-name" style="font-weight: 600;"><?php echo esc_html( $cat_data['category']['label'] ?? '' ); ?></span>
							<span class="category-count" style="color: #646970;">(<?php echo (int) $cat_data['image_count']; ?> <?php esc_html_e( 'images', 'photo-competition-manager' ); ?>)</span>
							<span class="category-slideshow-actions" style="margin-left: auto;">
								<button type="button" class="button button-small photo-competition-manager-start-slideshow"
									data-competition-id="<?php echo esc_attr( $data['competition']->id ); ?>"
									data-competition-slug="<?php echo esc_attr( $data['competition']->slug ); ?>"
									data-category="<?php echo esc_attr( $cat_data['category']['slug'] ?? '' ); ?>"
									data-category-label="<?php echo esc_attr( $cat_data['category']['label'] ?? '' ); ?>"
									data-duration-input="#replay-slideshow-duration">
									<span class="dashicons dashicons-slides"></span> <?php esc_html_e( 'Slideshow', 'photo-competition-manager' ); ?>
								</button>
								<button type="button" class="button button-small photo-competition-manager-start-slideshow"
									data-competition-id="<?php echo esc_attr( $data['competition']->id ); ?>"
									data-competition-slug="<?php echo esc_attr( $data['competition']->slug ); ?>"
									data-category="<?php echo esc_attr( $cat_data['category']['slug'] ?? '' ); ?>"
									data-category-label="<?php echo esc_attr( $cat_data['category']['label'] ?? '' ); ?>"
									data-duration-input="#replay-critique-duration"
									title="<?php esc_attr_e( 'Manual slideshow for discussion', 'photo-competition-manager' ); ?>">
									<span class="dashicons dashicons-format-chat"></span> <?php esc_html_e( 'Critique', 'photo-competition-manager' ); ?>
								</button>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>

				<div class="complete-actions">
					<?php if ( ! $data['results_visible'] ) : ?>
						<a href="<?php echo esc_url( $data['show_results_url'] ); ?>" class="button button-primary button-large">
							<span class="dashicons dashicons-visibility" style="margin-top: 4px;"></span>
							<?php esc_html_e( 'Show Results', 'photo-competition-manager' ); ?>
						</a>
					<?php endif; ?>

					<?php if ( ! empty( $data['results_url'] ) || ! empty( $data['top3_url'] ) ) : ?>
					<div class="results-links" style="margin-top: 12px;">
						<?php if ( ! empty( $data['results_url'] ) ) : ?>
							<a href="<?php echo esc_url( $data['results_url'] ); ?>" class="button" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-chart-bar" style="margin-top: 4px;"></span>
								<?php esc_html_e( 'View Full Results', 'photo-competition-manager' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( ! empty( $data['top3_url'] ) ) : ?>
							<a href="<?php echo esc_url( $data['top3_url'] ); ?>" class="button" target="_blank" rel="noopener noreferrer">
								<span class="dashicons dashicons-awards" style="margin-top: 4px;"></span>
								<?php esc_html_e( 'View Top 3 Results', 'photo-competition-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
