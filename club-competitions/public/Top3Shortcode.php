<?php
/**
 * Handle top 3 results shortcode.
 *
 * @package ClubCompetitions\Frontend
 */

namespace ClubCompetitions\Frontend;

use ClubCompetitions\Repository\CompetitionsRepository;
use ClubCompetitions\Repository\ImagesRepository;
use ClubCompetitions\Repository\MembersRepository;
use ClubCompetitions\Repository\VotesRepository;
use ClubCompetitions\Support\CompetitionSettings;

class Top3Shortcode {

	/**
	 * Competitions repository.
	 *
	 * @var CompetitionsRepository
	 */
	private $competitions_repo;

	/**
	 * Images repository.
	 *
	 * @var ImagesRepository
	 */
	private $images_repo;

	/**
	 * Votes repository.
	 *
	 * @var VotesRepository
	 */
	private $votes_repo;

	/**
	 * Members repository.
	 *
	 * @var MembersRepository
	 */
	private $members_repo;

	/**
	 * Constructor.
	 *
	 * @param CompetitionsRepository|null $competitions_repo Competitions repository.
	 * @param ImagesRepository|null       $images_repo       Images repository.
	 * @param VotesRepository|null        $votes_repo        Votes repository.
	 * @param MembersRepository|null      $members_repo      Members repository.
	 */
	public function __construct(
		?CompetitionsRepository $competitions_repo = null,
		?ImagesRepository $images_repo = null,
		?VotesRepository $votes_repo = null,
		?MembersRepository $members_repo = null
	) {
		$this->competitions_repo = $competitions_repo ?: new CompetitionsRepository();
		$this->images_repo       = $images_repo ?: new ImagesRepository();
		$this->votes_repo        = $votes_repo ?: new VotesRepository();
		$this->members_repo      = $members_repo ?: new MembersRepository();
	}

	/**
	 * Register shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'competition_top3', array( $this, 'render' ) );
	}

	/**
	 * Render top 3 results shortcode.
	 *
	 * @param array<string, string> $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'competition' => '',
			),
			$atts,
			'competition_top3'
		);

		$competition = null;

		// If no competition specified, get the most recent one
		if ( empty( $atts['competition'] ) ) {
			$competitions = $this->competitions_repo->all( 1, false, false );
			if ( empty( $competitions ) ) {
				return '<p class="error">' . esc_html__( 'No competitions found.', 'club-competitions' ) . '</p>';
			}
			$competition = $competitions[0];
		} else {
			$competition = $this->competitions_repo->find_by_slug( $atts['competition'] );
			if ( ! $competition ) {
				return '<p class="error">' . esc_html__( 'Competition not found.', 'club-competitions' ) . '</p>';
			}
		}

		ob_start();
		$this->render_top3_results( $competition );
		$output = ob_get_clean();
		return $output ? $output : '';
	}

	/**
	 * Render top 3 results display.
	 *
	 * @param object $competition Competition object.
	 * @return void
	 */
	private function render_top3_results( object $competition ): void {
		$settings   = CompetitionSettings::parse( $competition->settings );
		$grades     = CompetitionSettings::get_grades( $settings );
		$categories = CompetitionSettings::get_categories( $settings );

		// Get all images for this competition with their scores
		$images = $this->images_repo->find_by_competition( (int) $competition->id );
		if ( empty( $images ) ) {
			echo '<p class="notice">' . esc_html__( 'No images submitted for this competition yet.', 'club-competitions' ) . '</p>';
			return;
		}

		// Get member details for building image URLs and grouping by grade
		$member_ids = array_unique( array_map( fn( $img ) => (int) $img->member_id, $images ) );
		$members    = $this->members_repo->find_many( $member_ids );

		// Calculate scores for each image
		$image_scores = $this->votes_repo->calculate_averages( (int) $competition->id );

		// Group images by category first, then by grade within each category
		$results_by_category = array();
		foreach ( $images as $image ) {
			$member = $members[ (int) $image->member_id ] ?? null;
			if ( ! $member ) {
				continue;
			}

			$category    = $image->category;
			$grade       = $member->grade ?: 'unknown';
			$score_data  = $image_scores[ (int) $image->id ] ?? null;
			$total_score = $score_data ? $score_data['average_score'] : 0;

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
				'vote_count'  => $score_data ? $score_data['vote_count'] : 0,
			);
		}

		// Sort each grade within each category by total score (highest first) and take top 3
		foreach ( $results_by_category as $category => $grade_results ) {
			foreach ( $grade_results as $grade => $results ) {
				usort(
					$results_by_category[ $category ][ $grade ],
					function ( $a, $b ) {
						return $b['total_score'] <=> $a['total_score'];
					}
				);
				$results_by_category[ $category ][ $grade ] = array_slice( $results_by_category[ $category ][ $grade ], 0, 3 );
			}
		}

		?>
		<div class="club-competitions-top3">
			<h2><?php echo esc_html( $competition->title ); ?> - <?php esc_html_e( 'Top 3 Results', 'club-competitions' ); ?></h2>

			<?php foreach ( $categories as $category_config ) : ?>
				<?php
				$category_slug    = $category_config['slug'];
				$category_label   = $category_config['label'];
				$category_results = $results_by_category[ $category_slug ] ?? array();
				?>
				<?php if ( ! empty( $category_results ) ) : ?>
					<div class="top3-category-section">
						<h3><?php echo esc_html( $category_label ); ?></h3>

						<?php foreach ( $grades as $grade_config ) : ?>
							<?php
							$grade_slug    = $grade_config['slug'];
							$grade_label   = $grade_config['label'];
							$grade_results = $category_results[ $grade_slug ] ?? array();
							?>
							<?php if ( ! empty( $grade_results ) ) : ?>
								<div class="top3-grade-section">
									<h4><?php echo esc_html( $grade_label ); ?></h4>
									<div class="top3-podium">
										<?php
										$positions       = array( 'first', 'second', 'third' );
										$position_labels = array(
											'first'  => __( '1st Place', 'club-competitions' ),
											'second' => __( '2nd Place', 'club-competitions' ),
											'third'  => __( '3rd Place', 'club-competitions' ),
										);
										?>
										<?php foreach ( $grade_results as $index => $result ) : ?>
											<?php
											$image          = $result['image'];
											$member         = $result['member'];
											$total_score    = $result['total_score'];
											$vote_count     = $result['vote_count'];
											$image_urls     = $this->get_image_urls( $competition, $image, $members );
											$thumb_url      = $image_urls['thumb'] ?: $image_urls['full'];
											$position       = $positions[ $index ] ?? 'third';
											$position_label = $position_labels[ $position ] ?? __( '3rd Place', 'club-competitions' );
											?>
											<div class="podium-item <?php echo esc_attr( $position ); ?>">
												<div class="position-badge">
													<?php echo esc_html( $position_label ); ?>
												</div>
												<div class="image-container">
													<?php if ( $thumb_url ) : ?>
														<a href="<?php echo esc_url( $image_urls['full'] ); ?>" target="_blank" rel="noopener noreferrer" class="image-link">
															<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( sprintf( __( 'Image %d', 'club-competitions' ), $image->random_number ) ); ?>" loading="lazy" class="podium-thumbnail" />
														</a>
													<?php else : ?>
														<div class="image-unavailable">
															<?php esc_html_e( 'Image unavailable', 'club-competitions' ); ?>
														</div>
													<?php endif; ?>
													<div class="image-number">#<?php echo esc_html( $image->random_number ); ?></div>
												</div>
												<div class="member-info">
													<div class="member-name"><?php echo esc_html( $member->name ); ?></div>
													<div class="score-info">
														<span class="score"><?php echo esc_html( number_format( $total_score, 2 ) ); ?></span>
														<span class="vote-count">(<?php echo esc_html( $vote_count ); ?> <?php esc_html_e( 'votes', 'club-competitions' ); ?>)</span>
													</div>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>

			<?php if ( empty( array_filter( $results_by_category ) ) ) : ?>
				<p class="notice"><?php esc_html_e( 'No results available yet.', 'club-competitions' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Get image URLs for display.
	 *
	 * @param object        $competition Competition object.
	 * @param object        $image       Image record.
	 * @param array<object> $members     Members lookup array.
	 * @return array{full: string, thumb: string}
	 */
	private function get_image_urls( object $competition, object $image, array $members ): array {
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
		$ext  = isset( $info['extension'] ) && $info['extension'] !== '' ? '.' . $info['extension'] : '';

		return $base . '-thumb' . $ext;
	}
}
