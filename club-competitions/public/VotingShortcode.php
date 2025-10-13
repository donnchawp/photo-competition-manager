<?php
/**
 * Handle voting shortcode.
 *
 * @package ClubCompetitions\Frontend
 */

namespace ClubCompetitions\Frontend;

use ClubCompetitions\Repository\CompetitionsRepository;
use ClubCompetitions\Repository\ImagesRepository;
use ClubCompetitions\Repository\MembersRepository;
use ClubCompetitions\Repository\VotesRepository;
use ClubCompetitions\Support\CompetitionSettings;

class VotingShortcode {

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
		add_shortcode( 'competition_voting', array( $this, 'render' ) );
	}

	/**
	 * Render voting shortcode.
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
			'competition_voting'
		);

		if ( empty( $atts['competition'] ) ) {
			return '<p class="error">' . esc_html__( 'Please specify a competition slug.', 'club-competitions' ) . '</p>';
		}

		$competition = $this->competitions_repo->find_by_slug( $atts['competition'] );
		if ( ! $competition ) {
			return '<p class="error">' . esc_html__( 'Competition not found.', 'club-competitions' ) . '</p>';
		}

		// Handle vote submission.
		$message = '';
		if ( isset( $_POST['club_competitions_vote'] ) && check_admin_referer( 'club_competitions_vote', 'club_competitions_vote_nonce' ) ) {
			$message = $this->handle_vote_submission( $competition );
		}

		ob_start();
		$this->render_voting_interface( $competition, $message );
		$output = ob_get_clean();
		return $output ? $output : '';
	}

	/**
	 * Handle vote submission.
	 *
	 * @param object $competition Competition object.
	 * @return string Message to display.
	 */
	private function handle_vote_submission( object $competition ): string {
		$voter_name = isset( $_POST['voter_name'] ) ? sanitize_text_field( wp_unslash( $_POST['voter_name'] ) ) : '';
		$category   = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$votes      = isset( $_POST['votes'] ) && is_array( $_POST['votes'] ) ? $_POST['votes'] : array();

		if ( empty( $voter_name ) ) {
			return '<p class="error">' . esc_html__( 'Please enter your name.', 'club-competitions' ) . '</p>';
		}

		if ( empty( $category ) ) {
			return '<p class="error">' . esc_html__( 'Invalid category.', 'club-competitions' ) . '</p>';
		}

		// Verify voting is open for this category.
		$settings = CompetitionSettings::parse( $competition->settings );
		if ( ! CompetitionSettings::is_voting_open_for_category( $settings, $category ) ) {
			return '<p class="error">' . esc_html__( 'Voting is not open for this category.', 'club-competitions' ) . '</p>';
		}

		if ( empty( $votes ) ) {
			return '<p class="error">' . esc_html__( 'Please select at least one image to vote for.', 'club-competitions' ) . '</p>';
		}

		// Check if voter has already voted in this category.
		if ( $this->votes_repo->has_voted( (int) $competition->id, $category, $voter_name ) ) {
			return '<p class="error">' . esc_html__( 'You have already voted in this category.', 'club-competitions' ) . '</p>';
		}

		// Get score matrix from settings.
		$voting_config = CompetitionSettings::get_voting_config( $settings );
		$score_matrix  = $voting_config['score_matrix'] ?? array( 9, 8, 7, 6, 5 );

		// Process votes.
		$success_count = 0;
		foreach ( $votes as $image_id => $position ) {
			$image_id = absint( $image_id );
			$position = absint( $position );

			if ( $image_id < 1 || $position < 1 || $position > count( $score_matrix ) ) {
				continue;
			}

			$score  = $score_matrix[ $position - 1 ];
			$result = $this->votes_repo->create( (int) $competition->id, $category, $voter_name, $image_id, (float) $score );

			if ( ! is_wp_error( $result ) ) {
				++$success_count;
			}
		}

		if ( $success_count > 0 ) {
			return '<p class="success">' . esc_html__( 'Thank you for voting!', 'club-competitions' ) . '</p>';
		}

		return '<p class="error">' . esc_html__( 'Failed to record votes. Please try again.', 'club-competitions' ) . '</p>';
	}

	/**
	 * Render voting interface.
	 *
	 * @param object $competition Competition object.
	 * @param string $message     Message to display.
	 * @return void
	 */
	private function render_voting_interface( object $competition, string $message ): void {
		$settings   = CompetitionSettings::parse( $competition->settings );
		$categories = CompetitionSettings::get_categories( $settings );

		// Filter to only show categories where voting is open.
		$open_categories = CompetitionSettings::get_open_voting_categories( $settings );
		$voting_categories = array_filter(
			$categories,
			function( $cat ) use ( $open_categories ) {
				return in_array( $cat['slug'], $open_categories, true );
			}
		);

		// Get score matrix.
		$voting_config = CompetitionSettings::get_voting_config( $settings );
		$score_matrix  = $voting_config['score_matrix'] ?? array( 9, 8, 7, 6, 5 );

		// Determine selected category (from POST or default to first open category).
		$selected_category = '';
		if ( isset( $_POST['select_category'] ) ) {
			$selected_category = isset( $_POST['category_select'] ) ? sanitize_text_field( wp_unslash( $_POST['category_select'] ) ) : '';
		}

		if ( empty( $selected_category ) && ! empty( $voting_categories ) ) {
			$first_open = reset( $voting_categories );
			$selected_category = $first_open['slug'];
		}

		// Get voter name from session/cookie or POST.
		$voter_name = '';
		if ( isset( $_POST['voter_name'] ) ) {
			$voter_name = sanitize_text_field( wp_unslash( $_POST['voter_name'] ) );
		} elseif ( isset( $_COOKIE['club_competitions_voter_name'] ) ) {
			$voter_name = sanitize_text_field( wp_unslash( $_COOKIE['club_competitions_voter_name'] ) );
		}

		?>
		<div class="club-competitions-voting">
			<h2><?php echo esc_html( $competition->title ); ?> - <?php esc_html_e( 'Voting', 'club-competitions' ); ?></h2>

			<?php if ( $message ) : ?>
				<?php echo wp_kses_post( $message ); ?>
			<?php endif; ?>

			<?php if ( 'active' !== $competition->status ) : ?>
				<p class="notice"><?php esc_html_e( 'Voting is not currently open for this competition.', 'club-competitions' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( empty( $voting_categories ) ) : ?>
				<p class="notice"><?php esc_html_e( 'Voting is not currently open for any category. Please check back later.', 'club-competitions' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<!-- Category selector -->
			<form method="post" class="category-selector">
				<label for="category_select"><?php esc_html_e( 'Select Category:', 'club-competitions' ); ?></label>
				<select name="category_select" id="category_select">
					<?php foreach ( $voting_categories as $cat ) : ?>
						<option value="<?php echo esc_attr( $cat['slug'] ); ?>" <?php selected( $selected_category, $cat['slug'] ); ?>>
							<?php echo esc_html( $cat['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="submit" name="select_category" class="button">
					<?php esc_html_e( 'View Category', 'club-competitions' ); ?>
				</button>
			</form>


			<?php
			// Verify selected category is actually open for voting.
			if ( ! CompetitionSettings::is_voting_open_for_category( $settings, $selected_category ) ) {
				echo '<p class="notice">' . esc_html__( 'Voting is not open for this category.', 'club-competitions' ) . '</p>';
				return;
			}
			// Get images for selected category.
			$images = $this->images_repo->find_by_competition( (int) $competition->id, $selected_category );

			if ( empty( $images ) ) {
				echo '<p class="notice">' . esc_html__( 'No images submitted in this category yet.', 'club-competitions' ) . '</p>';
				return;
			}

			// Check if voter has already voted.
			$has_voted = ! empty( $voter_name ) && $this->votes_repo->has_voted( (int) $competition->id, $selected_category, $voter_name );

			if ( $has_voted ) {
				echo '<p class="notice">' . esc_html__( 'You have already voted in this category. Thank you!', 'club-competitions' ) . '</p>';
				$this->render_image_gallery( $images, $competition );
				return;
			}

			// Get member details for building image URLs.
			$member_ids = array_unique( array_map( fn( $img ) => (int) $img->member_id, $images ) );
			$members    = $this->members_repo->find_many( $member_ids );
			?>

			<!-- Voting instructions -->
			<div class="voting-instructions">
				<h3><?php esc_html_e( 'How to Vote', 'club-competitions' ); ?></h3>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of images to select */
							_n(
								'Select your top image and assign it a position (1 for best).',
								'Select your top %d images and assign each a position (1 for best).',
								count( $score_matrix ),
								'club-competitions'
							),
							count( $score_matrix )
						)
					);
					?>
				</p>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: comma-separated score values */
							__( 'Points awarded: %s', 'club-competitions' ),
							implode( ', ', $score_matrix )
						)
					);
					?>
				</p>
			</div>

			<!-- Voting form -->
			<form method="post" class="voting-form" id="voting-form">
				<?php wp_nonce_field( 'club_competitions_vote', 'club_competitions_vote_nonce' ); ?>
				<input type="hidden" name="category" value="<?php echo esc_attr( $selected_category ); ?>" />

				<div class="voter-info">
					<label for="voter_name">
						<?php esc_html_e( 'Your Name:', 'club-competitions' ); ?>
						<span class="required">*</span>
					</label>
					<input
						type="text"
						id="voter_name"
						name="voter_name"
						value="<?php echo esc_attr( $voter_name ); ?>"
						required
					/>
					<small><?php esc_html_e( 'Your name will be recorded with your votes.', 'club-competitions' ); ?></small>
				</div>

				<div class="images-grid">
					<?php foreach ( $images as $image ) : ?>
						<?php
						$image_urls = $this->get_image_urls( $competition, $image, $members );
						$thumb_url  = $image_urls['thumb'] ?: $image_urls['full'];
						?>
						<div class="voting-image-item" data-image-id="<?php echo esc_attr( $image->id ); ?>">
							<div class="image-wrapper">
								<?php if ( $image_urls['full'] ) : ?>
									<a href="<?php echo esc_url( $image_urls['full'] ); ?>" target="_blank" rel="noopener noreferrer" class="image-link">
										<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( sprintf( __( 'Image %d', 'club-competitions' ), $image->random_number ) ); ?>" loading="lazy" />
									</a>
								<?php else : ?>
									<div class="image-unavailable">
										<?php esc_html_e( 'Image unavailable', 'club-competitions' ); ?>
									</div>
								<?php endif; ?>
								<div class="image-number">#<?php echo esc_html( $image->random_number ); ?></div>
							</div>
							<div class="vote-selector">
								<label for="vote_<?php echo esc_attr( $image->id ); ?>">
									<?php esc_html_e( 'Position:', 'club-competitions' ); ?>
								</label>
								<select name="votes[<?php echo esc_attr( $image->id ); ?>]" id="vote_<?php echo esc_attr( $image->id ); ?>" class="vote-select">
									<option value="">-</option>
									<?php for ( $i = 1; $i <= count( $score_matrix ); $i++ ) : ?>
										<option value="<?php echo esc_attr( $i ); ?>">
											<?php
											echo esc_html(
												sprintf(
													/* translators: 1: position number, 2: score points */
													__( '%1$d (%2$d pts)', 'club-competitions' ),
													$i,
													$score_matrix[ $i - 1 ]
												)
											);
											?>
										</option>
									<?php endfor; ?>
								</select>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="voting-submit">
					<button type="submit" name="club_competitions_vote" class="button button-primary button-large">
						<?php esc_html_e( 'Submit Votes', 'club-competitions' ); ?>
					</button>
				</div>
			</form>
		</div>

		<script>
		(function() {
			// Store voter name in cookie for convenience.
			const form = document.getElementById('voting-form');
			if (form) {
				form.addEventListener('submit', function() {
					const voterName = document.getElementById('voter_name').value;
					if (voterName) {
						document.cookie = 'club_competitions_voter_name=' + encodeURIComponent(voterName) + '; path=/; max-age=2592000'; // 30 days
					}
				});
			}

			// Prevent duplicate position selection.
			const selects = document.querySelectorAll('.vote-select');
			selects.forEach(function(select) {
				select.addEventListener('change', function() {
					const selectedValue = this.value;
					if (selectedValue) {
						// Clear other selects with the same value.
						selects.forEach(function(otherSelect) {
							if (otherSelect !== select && otherSelect.value === selectedValue) {
								otherSelect.value = '';
							}
						});
					}
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render image gallery (for voters who have already voted).
	 *
	 * @param array<object> $images      Images.
	 * @param object        $competition Competition object.
	 * @return void
	 */
	private function render_image_gallery( array $images, object $competition ): void {
		$member_ids = array_unique( array_map( fn( $img ) => (int) $img->member_id, $images ) );
		$members    = $this->members_repo->find_many( $member_ids );

		echo '<div class="images-grid gallery-view">';
		foreach ( $images as $image ) {
			$image_urls = $this->get_image_urls( $competition, $image, $members );
			$thumb_url  = $image_urls['thumb'] ?: $image_urls['full'];

			echo '<div class="voting-image-item">';
			echo '<div class="image-wrapper">';
			if ( $image_urls['full'] ) {
				echo '<a href="' . esc_url( $image_urls['full'] ) . '" target="_blank" rel="noopener noreferrer" class="image-link">';
				echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( sprintf( __( 'Image %d', 'club-competitions' ), $image->random_number ) ) . '" loading="lazy" />';
				echo '</a>';
			} else {
				echo '<div class="image-unavailable">' . esc_html__( 'Image unavailable', 'club-competitions' ) . '</div>';
			}
			echo '<div class="image-number">#' . esc_html( $image->random_number ) . '</div>';
			echo '</div>';
			echo '</div>';
		}
		echo '</div>';
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
			return array( 'full' => '', 'thumb' => '' );
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return array( 'full' => '', 'thumb' => '' );
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
