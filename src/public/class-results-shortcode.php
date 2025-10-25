<?php
/**
 * Handle results shortcode.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Render the public competition results shortcode output.
 *
 * @since 0.1.0
 */
class Results_Shortcode {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions_repo;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images_repo;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes_repo;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members_repo;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Images_Repository|null       $images_repo       Images repository.
	 * @param Votes_Repository|null        $votes_repo        Votes repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 */
	public function __construct(
		?Competitions_Repository $competitions_repo = null,
		?Images_Repository $images_repo = null,
		?Votes_Repository $votes_repo = null,
		?Members_Repository $members_repo = null
	) {
		$this->competitions_repo = $competitions_repo ?? new Competitions_Repository();
		$this->images_repo       = $images_repo ?? new Images_Repository();
		$this->votes_repo        = $votes_repo ?? new Votes_Repository();
		$this->members_repo      = $members_repo ?? new Members_Repository();
	}

	/**
	 * Register shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'competition_results', array( $this, 'render' ) );
	}

	/**
	 * Render results shortcode.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'competition' => '',
				'hide_names'  => 'false',
			),
			$atts,
			'competition_results'
		);

		$competition = null;

		// If no competition specified, get the most recent one.
		if ( empty( $atts['competition'] ) ) {
			$competitions = $this->competitions_repo->all( 1, false, false );
			if ( empty( $competitions ) ) {
				return '<p class="error">' . esc_html__( 'No competitions found.', 'photo-competition-manager' ) . '</p>';
			}
			$competition = $competitions[0];
		} else {
			$competition = $this->competitions_repo->find_by_slug( $atts['competition'] );
			if ( ! $competition ) {
				return '<p class="error">' . esc_html__( 'Competition not found.', 'photo-competition-manager' ) . '</p>';
			}
		}

		$hide_names = filter_var( $atts['hide_names'], FILTER_VALIDATE_BOOLEAN );

		ob_start();
		$this->render_results( $competition, $hide_names );
		$output = ob_get_clean();
		return false !== $output ? $output : '';
	}

	/**
	 * Render results display.
	 *
	 * @param object $competition Competition object.
	 * @param bool   $hide_names  Whether to hide member names.
	 * @return void
	 */
	private function render_results( object $competition, bool $hide_names = false ): void {
		$settings   = Competition_Settings::parse( $competition->settings );
		$grades     = Competition_Settings::get_grades( $settings );
		$categories = Competition_Settings::get_categories( $settings );

		// Get all images for this competition with their scores.
		$images = $this->images_repo->find_by_competition( (int) $competition->id );
		if ( empty( $images ) ) {
			echo '<p class="notice">' . esc_html__( 'No images submitted for this competition yet.', 'photo-competition-manager' ) . '</p>';
			return;
		}

		// Get member details for building image URLs and grouping by grade.
		$member_ids = array_unique( array_map( fn( $img ) => (int) $img->member_id, $images ) );
		$members    = $this->members_repo->find_many( $member_ids );

		// Get all unique categories from images.
		$image_categories = array_unique( array_map( fn( $img ) => $img->category, $images ) );

		// Calculate scores for each image, grouped by category.
		$image_scores_by_category = array();
		foreach ( $image_categories as $cat ) {
			$image_scores_by_category[ $cat ] = $this->votes_repo->calculate_averages( (int) $competition->id, $cat );
		}

		// Group images by category first, then by grade within each category.
		$results_by_category = array();
		foreach ( $images as $image ) {
			$member = $members[ (int) $image->member_id ] ?? null;
			if ( ! $member ) {
				continue;
			}

			$category        = $image->category;
			$grade           = ! empty( $member->grade ) ? $member->grade : 'unknown';
			$category_scores = $image_scores_by_category[ $category ] ?? array();
			$score_data      = $category_scores[ (int) $image->id ] ?? null;
			$total_score     = null !== $score_data ? ( $score_data['average_score'] * $score_data['vote_count'] ) : 0;

			if ( ! isset( $results_by_category[ $category ] ) ) {
				$results_by_category[ $category ] = array();
			}

			if ( ! isset( $results_by_category[ $category ][ $grade ] ) ) {
				$results_by_category[ $category ][ $grade ] = array();
			}

			$results_by_category[ $category ][ $grade ][] = array(
				'image'       => $image,
				'member'      => $member,
				'total_score' => $total_score,
				'vote_count'  => null !== $score_data ? $score_data['vote_count'] : 0,
			);
		}

		// Sort each grade within each category by total score (highest first).
		foreach ( $results_by_category as $category => $grade_results ) {
			foreach ( $grade_results as $grade => $results ) {
				usort(
					$results_by_category[ $category ][ $grade ],
					function ( $a, $b ) {
						return $b['total_score'] <=> $a['total_score'];
					}
				);
			}
		}

		?>
		<div class="photo-comp-results">
			<h2><?php echo esc_html( $competition->title ); ?> - <?php esc_html_e( 'Results', 'photo-competition-manager' ); ?></h2>

			<?php foreach ( $categories as $category_config ) : ?>
				<?php
				$category_slug    = $category_config['slug'];
				$category_label   = $category_config['label'];
				$category_results = $results_by_category[ $category_slug ] ?? array();
				?>
				<?php if ( ! empty( $category_results ) ) : ?>
					<div class="results-category-section">
						<h3><?php echo esc_html( $category_label ); ?></h3>

						<?php foreach ( $grades as $grade_config ) : ?>
							<?php
							$grade_slug    = $grade_config['slug'];
							$grade_label   = $grade_config['label'];
							$grade_results = $category_results[ $grade_slug ] ?? array();
							?>
							<?php if ( ! empty( $grade_results ) ) : ?>
								<div class="results-grade-section">
									<h4><?php echo esc_html( $grade_label ); ?></h4>
									<div class="results-table">
										<table>
											<thead>
												<tr>
													<th><?php esc_html_e( 'Position', 'photo-competition-manager' ); ?></th>
													<th><?php esc_html_e( 'Image', 'photo-competition-manager' ); ?></th>
													<?php if ( ! $hide_names ) : ?>
														<th><?php esc_html_e( 'Member', 'photo-competition-manager' ); ?></th>
													<?php endif; ?>
													<th><?php esc_html_e( 'Score', 'photo-competition-manager' ); ?></th>
													<th><?php esc_html_e( 'Votes', 'photo-competition-manager' ); ?></th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ( $grade_results as $position => $result ) : ?>
													<?php
													$image       = $result['image'];
													$member      = $result['member'];
													$total_score = $result['total_score'];
													$vote_count  = $result['vote_count'];
													$image_urls  = $this->get_image_urls( $competition, $image );
													$thumb_url   = $image_urls['thumb'] ? $image_urls['thumb'] : $image_urls['full'];
													/* translators: %d: Anonymised image identifier. */
													$alt_text = sprintf( __( 'Image %d', 'photo-competition-manager' ), $image->random_number );
													?>
													<tr>
														<td class="position"><?php echo esc_html( $position + 1 ); ?></td>
														<td class="image-cell">
															<?php if ( $thumb_url ) : ?>
																<a href="<?php echo esc_url( $image_urls['full'] ); ?>" target="_blank" rel="noopener noreferrer" class="image-link">
																	<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $alt_text ); ?>" loading="lazy" class="result-thumbnail" />
																</a>
															<?php else : ?>
																<div class="image-unavailable">
																	<?php esc_html_e( 'Image unavailable', 'photo-competition-manager' ); ?>
																</div>
															<?php endif; ?>
															<div class="image-number">#<?php echo esc_html( $image->random_number ); ?></div>
														</td>
														<?php if ( ! $hide_names ) : ?>
															<td class="member-name"><?php echo esc_html( $member->name ); ?></td>
														<?php endif; ?>
														<td class="score"><?php echo esc_html( number_format( $total_score, 0 ) ); ?></td>
														<td class="vote-count"><?php echo esc_html( $vote_count ); ?></td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</div>
								</div>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>

			<?php if ( empty( array_filter( $results_by_category ) ) ) : ?>
				<p class="notice"><?php esc_html_e( 'No results available yet.', 'photo-competition-manager' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get image URLs for display.
	 *
	 * @param object $competition Competition object.
	 * @param object $image       Image record.
	 * @return array{full: string, thumb: string}
	 */
	private function get_image_urls( object $competition, object $image ): array {
		if ( empty( $competition->slug ) || empty( $image->filename ) ) {
			return array(
				'full'  => '',
				'thumb' => '',
			);
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return array(
				'full'  => '',
				'thumb' => '',
			);
		}

		$base = trailingslashit( $uploads['baseurl'] ) . 'competitions/';
		$slug = sanitize_file_name( (string) $competition->slug );
		$cat  = sanitize_file_name( (string) $image->category );

		$folder_url  = trailingslashit( $base . rawurlencode( $slug ) . '/' . rawurlencode( $cat ) );
		$folder_path = trailingslashit( trailingslashit( $uploads['basedir'] ) . 'competitions/' . $slug . '/' . $cat );

		$filename   = $image->filename;
		$thumb_name = $this->get_thumbnail_filename( $filename );

		$full_path  = $folder_path . $filename;
		$thumb_path = $folder_path . $thumb_name;

		$full_url  = file_exists( $full_path ) ? $folder_url . rawurlencode( $filename ) : '';
		$thumb_url = file_exists( $thumb_path ) ? $folder_url . rawurlencode( $thumb_name ) : '';

		return array(
			'full'  => $full_url,
			'thumb' => $thumb_url,
		);
	}

	/**
	 * Determine thumbnail filename from base filename.
	 *
	 * @param string $filename Base filename.
	 * @return string
	 */
	private function get_thumbnail_filename( string $filename ): string {
		$info = pathinfo( $filename );
		$base = $info['filename'] ?? $filename;
		$ext  = isset( $info['extension'] ) && '' !== $info['extension'] ? '.' . $info['extension'] : '';

		return $base . '-thumb' . $ext;
	}
}
