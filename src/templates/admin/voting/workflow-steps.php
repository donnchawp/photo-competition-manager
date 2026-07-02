<?php
/**
 * Workflow steps partial for the admin voting controls page.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;
?>
		<div id="focus-panel" class="postbox photo-comp-workflow-card"
			data-competition-id="<?php echo esc_attr( $data['comp_id'] ); ?>"
			data-competition-slug="<?php echo esc_attr( $data['competition']->slug ); ?>"
			data-category="<?php echo esc_attr( $data['category_slug'] ); ?>"
			data-category-label="<?php echo esc_attr( $data['category_label'] ); ?>">

			<div class="inside <?php echo ! $data['is_ready'] ? 'photo-comp-workflow-disabled' : ''; ?>">
				<?php if ( $data['total_categories'] < 2 ) : ?>
					<h2 class="photo-comp-single-category-heading">
						<?php echo esc_html( $data['category_label'] ); ?>
						<span class="photo-comp-tab-count">(<?php echo (int) $data['image_count']; ?> <?php esc_html_e( 'images', 'photo-competition-manager' ); ?>)</span>
					</h2>
				<?php endif; ?>

				<?php if ( $data['current_step'] > 1 ) : ?>
					<a href="#" class="photo-comp-reset-toggle">
						<?php esc_html_e( 'Reset', 'photo-competition-manager' ); ?>
					</a>
					<div class="photo-comp-reset-panel" style="display: none;">
						<p>
						<?php
						/* translators: %s: Category name */
						printf( esc_html__( 'Reset %s back to step 1?', 'photo-competition-manager' ), '<strong>' . esc_html( $data['category_label'] ) . '</strong>' );
						?>
						</p>
						<label>
							<input type="checkbox" class="photo-comp-reset-clear-votes" />
							<?php esc_html_e( 'Also clear all votes for this category', 'photo-competition-manager' ); ?>
						</label>
						<div class="photo-comp-reset-actions">
							<a href="<?php echo esc_url( $data['reset_url'] ); ?>" class="button button-small photo-comp-reset-confirm" data-base-url="<?php echo esc_url( $data['reset_url'] ); ?>" data-clear-url="<?php echo esc_url( $data['reset_url'] . '&clear_votes=1' ); ?>">
								<?php esc_html_e( 'Reset', 'photo-competition-manager' ); ?>
							</a>
							<a href="#" class="button button-small button-link photo-comp-reset-cancel"><?php esc_html_e( 'Cancel', 'photo-competition-manager' ); ?></a>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( ! $data['is_ready'] ) : ?>
					<div class="notice notice-warning inline photo-comp-prereq-notice">
						<p>
						<?php
						$uploads_closed  = $data['settings']['upload']['uploads_closed'] ?? false;
						$results_visible = $data['settings']['results']['results_visible'] ?? false;
						if ( ! $uploads_closed ) {
							esc_html_e( 'Close uploads before starting the voting workflow.', 'photo-competition-manager' );
						} elseif ( $results_visible ) {
							esc_html_e( 'Hide results before starting the voting workflow.', 'photo-competition-manager' );
						}
						?>
						</p>
					</div>
				<?php endif; ?>

				<div class="photo-comp-steps">
					<?php
					foreach ( $data['steps'] as $step_num => $step ) :
						$is_completed = $data['current_step'] > $step_num;
						$is_active    = $data['current_step'] === $step_num && $data['is_ready'];
						$is_upcoming  = $data['current_step'] < $step_num || ! $data['is_ready'];
						?>
						<div class="photo-comp-step <?php echo $is_completed ? 'step-completed' : ''; ?> <?php echo $is_active ? 'step-active' : ''; ?> <?php echo $is_upcoming ? 'step-upcoming' : ''; ?>">
							<div class="step-indicator">
								<?php if ( $is_completed ) : ?>
									<span class="step-circle step-circle-done">&#10003;</span>
								<?php elseif ( $is_active ) : ?>
									<span class="step-circle step-circle-active"><?php echo (int) $step_num; ?></span>
								<?php else : ?>
									<span class="step-circle step-circle-upcoming"><?php echo (int) $step_num; ?></span>
								<?php endif; ?>
							</div>
							<div class="step-content">
								<div class="step-label">
									<?php if ( $is_completed ) : ?>
										<s><?php echo esc_html( $step['label'] ); ?></s>
									<?php else : ?>
										<?php echo esc_html( $step['label'] ); ?>
									<?php endif; ?>

									<?php // Show "Voting Open" badge on completed step 2 while voting is open. ?>
									<?php if ( 2 === $step_num && $is_completed && $data['voting_open_here'] ) : ?>
										<span class="photo-comp-badge photo-comp-badge-success"><?php esc_html_e( 'Voting Open', 'photo-competition-manager' ); ?></span>
									<?php endif; ?>
								</div>

								<?php if ( $is_active ) : ?>
									<div class="step-description"><?php echo esc_html( $step['description'] ); ?></div>
									<div class="step-actions">
										<?php if ( 'slideshow' === $step['type'] ) : ?>
											<button type="button" class="button button-primary photo-competition-manager-start-slideshow"
												data-competition-id="<?php echo esc_attr( $data['comp_id'] ); ?>"
												data-competition-slug="<?php echo esc_attr( $data['competition']->slug ); ?>"
												data-category="<?php echo esc_attr( $data['category_slug'] ); ?>"
												data-category-label="<?php echo esc_attr( $data['category_label'] ); ?>">
												<?php
												if ( 1 === $step_num ) {
													esc_html_e( 'Start Preview', 'photo-competition-manager' );
												} elseif ( 5 === $step_num ) {
													esc_html_e( 'Start Critique', 'photo-competition-manager' );
												} else {
													esc_html_e( 'Start Slideshow', 'photo-competition-manager' );
												}
												?>
												&#9654;
											</button>
											<span class="step-separator">|</span>
											<label class="step-duration-label">
												<?php esc_html_e( 'Duration:', 'photo-competition-manager' ); ?>
												<input type="number" class="small-text photo-comp-step-duration" value="<?php echo esc_attr( $step['duration'] ); ?>" min="0" max="120" step="1" />s
											</label>
											<span class="step-separator">|</span>
											<button type="button" class="button photo-comp-continue-step"
												data-competition-id="<?php echo esc_attr( $data['comp_id'] ); ?>"
												data-category="<?php echo esc_attr( $data['category_slug'] ); ?>"
												data-next-step="<?php echo esc_attr( $step_num + 1 ); ?>">
												<?php esc_html_e( 'Continue', 'photo-competition-manager' ); ?> &rarr;
											</button>
										<?php elseif ( 'voting_open' === $step['type'] ) : ?>
											<?php if ( $data['another_cat_voting'] ) : ?>
												<button type="button" class="button" disabled title="<?php esc_attr_e( 'Close voting in the other category first', 'photo-competition-manager' ); ?>">
													<?php esc_html_e( 'Open Voting', 'photo-competition-manager' ); ?>
												</button>
												<span class="step-hint"><?php esc_html_e( 'Close voting in the other category first.', 'photo-competition-manager' ); ?></span>
											<?php else : ?>
												<a href="<?php echo esc_url( $data['open_voting_url'] ); ?>" class="button button-primary">
													<?php esc_html_e( 'Open Voting', 'photo-competition-manager' ); ?>
												</a>
											<?php endif; ?>
										<?php elseif ( 'voting_close' === $step['type'] ) : ?>
											<a href="<?php echo esc_url( $data['close_voting_url'] ); ?>" class="button button-primary">
												<?php esc_html_e( 'Close Voting', 'photo-competition-manager' ); ?>
											</a>
										<?php endif; ?>
									</div>
								<?php elseif ( $is_upcoming && ! $is_completed ) : ?>
									<span class="step-upcoming-desc"><?php echo esc_html( $step['description'] ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		