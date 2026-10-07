<?php
/**
 * Results table partial for the detailed results email: the rank, scores and
 * votes for each of a member's entries.
 *
 * The output keeps the whitespace it had when it was built inline in
 * Email_Job_Manager, so members get the same bytes.
 *
 * @package PhotoCompetitionManager
 *
 * $data['images'] array<int, array{
 *     category_label: string,
 *     image_number: int|string,
 *     rank: int|null,
 *     total_in_grade: int,
 *     grade: string,
 *     thumbnail_url: string,
 *     total_score: int,
 *     vote_count: int,
 *     statistics: array{count: int, average: float, median: float, min: float, max: float}|null,
 *     votes: array<int, object>,
 * }> The member's entries; empty when they entered nothing. An entry with no rank is ungraded.
 * The total score and vote count are the ones in the results; the statistics come from the votes.
 * An entry whose votes no longer add up to its recorded vote count has no statistics, and its
 * votes aren't listed.
 *
 * The if and endif tags that leave them out end their lines, so the output keeps its whitespace.
 */

defined( 'ABSPATH' ) || exit;
?>
				<?php if ( empty( $data['images'] ) ) : ?>
					<p><em><?php esc_html_e( 'You did not submit any images for this competition.', 'photo-competition-manager' ); ?></em></p>
				<?php else : ?>
					<?php foreach ( $data['images'] as $image_data ) : ?>
						<div style="margin: 30px 0; padding: 20px; background-color: #f9f9f9; border-left: 4px solid #0073aa;">
							<h3 style="margin-top: 0; color: #0073aa;">
								<?php echo esc_html( $image_data['category_label'] ); ?> -
								<?php
								printf(
									/* translators: %s: Image number */
									esc_html__( 'Image #%s', 'photo-competition-manager' ),
									esc_html( $image_data['image_number'] )
								);
								?>
							</h3>

							<?php if ( ! empty( $image_data['thumbnail_url'] ) ) : ?>
								<div style="margin-bottom: 15px;">
									<img src="<?php echo esc_url( $image_data['thumbnail_url'] ); ?>" alt="<?php esc_attr_e( 'Your submitted image', 'photo-competition-manager' ); ?>" style="max-width: 200px; height: auto; border: 1px solid #ddd; border-radius: 4px;">
								</div>
							<?php endif; ?>

							<table style="width: 100%; border-collapse: collapse;">
								<?php if ( null !== $image_data['rank'] ) : ?>
									<tr>
										<td style="padding: 8px 0; font-weight: bold; width: 40%;"><?php esc_html_e( 'Rank:', 'photo-competition-manager' ); ?></td>
										<td style="padding: 8px 0;">
											<?php
											$rank_display = $image_data['rank'];
											if ( ! empty( $image_data['total_in_grade'] ) ) {
												$rank_display .= ' ' . sprintf(
													/* translators: %d: Total number of images in the grade */
													__( 'of %d', 'photo-competition-manager' ),
													$image_data['total_in_grade']
												);
											}
											if ( ! empty( $image_data['grade'] ) ) {
												$rank_display .= ' (' . esc_html( $image_data['grade'] ) . ')';
											}
											echo esc_html( $rank_display );
											?>
										</td>
									</tr>
								<?php endif; ?>
								<tr>
									<td style="padding: 8px 0; font-weight: bold;"><?php esc_html_e( 'Final Score:', 'photo-competition-manager' ); ?></td>
									<td style="padding: 8px 0;"><strong><?php echo esc_html( number_format( $image_data['total_score'], 0 ) ); ?></strong></td>
								</tr>
								<tr>
									<td style="padding: 8px 0; font-weight: bold;"><?php esc_html_e( 'Total Votes:', 'photo-competition-manager' ); ?></td>
									<td style="padding: 8px 0;"><?php echo esc_html( $image_data['vote_count'] ); ?></td>
								</tr><?php if ( null !== $image_data['statistics'] ) : ?>

								<tr>
									<td style="padding: 8px 0; font-weight: bold;"><?php esc_html_e( 'Average Score:', 'photo-competition-manager' ); ?></td>
									<td style="padding: 8px 0;"><?php echo esc_html( number_format( $image_data['statistics']['average'], 2 ) ); ?></td>
								</tr>
								<tr>
									<td style="padding: 8px 0; font-weight: bold;"><?php esc_html_e( 'Median Score:', 'photo-competition-manager' ); ?></td>
									<td style="padding: 8px 0;"><?php echo esc_html( number_format( $image_data['statistics']['median'], 2 ) ); ?></td>
								</tr>
								<tr>
									<td style="padding: 8px 0; font-weight: bold;"><?php esc_html_e( 'Score Range:', 'photo-competition-manager' ); ?></td>
									<td style="padding: 8px 0;">
										<?php
										printf(
											'%s - %s',
											esc_html( number_format( $image_data['statistics']['min'], 0 ) ),
											esc_html( number_format( $image_data['statistics']['max'], 0 ) )
										);
										?>
									</td>
								</tr><?php endif; ?>

							</table><?php if ( null !== $image_data['statistics'] ) : ?>


							<h4 style="margin-top: 20px; margin-bottom: 10px;"><?php esc_html_e( 'Individual Votes:', 'photo-competition-manager' ); ?></h4>
							<table style="width: 100%; border-collapse: collapse; background-color: white;">
								<thead>
									<tr style="background-color: #0073aa; color: white;">
										<th style="padding: 10px; text-align: left;"><?php esc_html_e( 'Vote #', 'photo-competition-manager' ); ?></th>
										<th style="padding: 10px; text-align: left;"><?php esc_html_e( 'Score', 'photo-competition-manager' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$vote_number = 1;
									foreach ( $image_data['votes'] as $vote ) :
										?>
										<tr style="border-bottom: 1px solid #ddd;">
											<td style="padding: 10px;"><?php echo esc_html( $vote_number ); ?></td>
											<td style="padding: 10px;"><strong><?php echo esc_html( number_format( (float) $vote->score, 0 ) ); ?></strong></td>
										</tr>
										<?php
										++$vote_number;
									endforeach;
									?>
								</tbody>
							</table><?php endif; ?>

						</div>
					<?php endforeach; ?>
				<?php endif; ?>
		<?php
