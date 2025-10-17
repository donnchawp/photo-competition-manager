<?php
/**
 * Handle voting shortcode with token-based authentication.
 *
 * @package ClubCompetitions\Frontend
 */

namespace ClubCompetitions\Frontend;

use ClubCompetitions\Repository\CompetitionsRepository;
use ClubCompetitions\Repository\ImagesRepository;
use ClubCompetitions\Repository\MembersRepository;
use ClubCompetitions\Repository\VotesRepository;
use ClubCompetitions\Repository\VotingTokenRepository;
use ClubCompetitions\Service\EmailService;
use ClubCompetitions\Support\CompetitionSettings;
use ClubCompetitions\Support\ImageProcessor;

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
	 * Voting token repository.
	 *
	 * @var VotingTokenRepository
	 */
	private $token_repo;

	/**
	 * Email service.
	 *
	 * @var EmailService
	 */
	private $email_service;

	/**
	 * Image processor.
	 *
	 * @var ImageProcessor
	 */
	private $image_processor;

	/**
	 * Constructor.
	 *
	 * @param CompetitionsRepository|null   $competitions_repo Competitions repository.
	 * @param ImagesRepository|null         $images_repo       Images repository.
	 * @param VotesRepository|null          $votes_repo        Votes repository.
	 * @param MembersRepository|null        $members_repo      Members repository.
	 * @param VotingTokenRepository|null    $token_repo        Token repository.
	 * @param EmailService|null             $email_service     Email service.
	 * @param ImageProcessor|null           $image_processor   Image processor.
	 */
	public function __construct(
		?CompetitionsRepository $competitions_repo = null,
		?ImagesRepository $images_repo = null,
		?VotesRepository $votes_repo = null,
		?MembersRepository $members_repo = null,
		?VotingTokenRepository $token_repo = null,
		?EmailService $email_service = null,
		?ImageProcessor $image_processor = null
	) {
		$this->competitions_repo = $competitions_repo ?: new CompetitionsRepository();
		$this->images_repo       = $images_repo ?: new ImagesRepository();
		$this->votes_repo        = $votes_repo ?: new VotesRepository();
		$this->members_repo      = $members_repo ?: new MembersRepository();
		$this->token_repo        = $token_repo ?: new VotingTokenRepository();
		$this->email_service     = $email_service ?: new EmailService();
		$this->image_processor   = $image_processor ?: new ImageProcessor();
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

		$settings      = CompetitionSettings::parse( $competition->settings );
		$voting_config = CompetitionSettings::get_voting_config( $settings );
		$auth_mode     = $voting_config['auth_mode'] ?? 'password';

		// Branch based on authentication mode.
		if ( 'token' === $auth_mode ) {
			return $this->render_token_based_voting( $competition, $settings );
		} else {
			return $this->render_password_based_voting( $competition, $settings );
		}
	}

	/**
	 * Render token-based voting flow.
	 *
	 * @param object $competition Competition object.
	 * @param array  $settings    Competition settings.
	 * @return string
	 */
	private function render_token_based_voting( object $competition, array $settings ): string {
		// Check for voting token in URL.
		$token_string = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$token_hash   = $token_string ? hash( 'sha256', $token_string ) : '';
		$token_record = null;
		$member       = null;
		$category     = '';

		if ( $token_hash ) {
			$token_record = $this->token_repo->find_valid_token( $token_hash );
			if ( $token_record && (int) $token_record->competition_id === (int) $competition->id ) {
				$member   = $this->members_repo->find( (int) $token_record->member_id );
				$category = $token_record->category;
			}
		}

		// Handle token request form submission.
		$message = '';
		if ( isset( $_POST['club_competitions_request_voting_token'] ) && check_admin_referer( 'club_competitions_request_voting_token', 'club_competitions_voting_nonce' ) ) {
			$message = $this->handle_token_request( $competition, $settings );
		}

		// Handle vote submission with token.
		if ( $token_record && $member && isset( $_POST['club_competitions_vote'] ) && check_admin_referer( 'club_competitions_vote_with_token', 'club_competitions_vote_nonce' ) ) {
			$message = $this->handle_vote_submission_token( $competition, $token_record, $settings );
		}

		ob_start();
		$this->render_voting_interface( $competition, $message, $token_record, $member, $settings, $category );
		$output = ob_get_clean();
		return $output ? $output : '';
	}

	/**
	 * Render password-based voting flow.
	 *
	 * @param object $competition Competition object.
	 * @param array  $settings    Competition settings.
	 * @return string
	 */
	private function render_password_based_voting( object $competition, array $settings ): string {
		// Handle vote submission.
		$message = '';
		if ( isset( $_POST['club_competitions_vote'] ) && check_admin_referer( 'club_competitions_vote', 'club_competitions_vote_nonce' ) ) {
			$message = $this->handle_vote_submission_password( $competition, $settings );
		}

		ob_start();
		$this->render_password_voting_interface( $competition, $message, $settings );
		$output = ob_get_clean();
		return $output ? $output : '';
	}

	/**
	 * Handle token request form submission.
	 *
	 * @param object $competition Competition object.
	 * @param array  $settings    Competition settings.
	 * @return string Message to display.
	 */
	private function handle_token_request( object $competition, array $settings ): string {
		$member_email = isset( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';
		$category     = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';

		if ( empty( $member_email ) ) {
			return '<p class="error">' . esc_html__( 'Please enter your email address.', 'club-competitions' ) . '</p>';
		}

		if ( empty( $category ) ) {
			return '<p class="error">' . esc_html__( 'Please select a category.', 'club-competitions' ) . '</p>';
		}

		// Generic success message for security (prevents email enumeration).
		$generic_success = '<p class="success">' . esc_html__( 'If this email is registered, you will receive a voting link shortly. Please check your inbox.', 'club-competitions' ) . '</p>';

		// Verify category is open for voting.
		if ( ! CompetitionSettings::is_voting_open_for_category( $settings, $category ) ) {
			return '<p class="error">' . esc_html__( 'Voting is not open for this category.', 'club-competitions' ) . '</p>';
		}

		// Find member by email silently.
		$member = $this->members_repo->find_by_email( $member_email );
		if ( ! $member ) {
			// Return success message but don't send email.
			return $generic_success;
		}

		// Check for recent token to prevent spam.
		if ( $this->token_repo->has_recent_token( $member->id, $competition->id, $category ) ) {
			return $generic_success;
		}

		// Generate secure token.
		$token_string = bin2hex( random_bytes( 32 ) );
		$token_hash   = hash( 'sha256', $token_string );
		$expires_at   = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + HOUR_IN_SECONDS );

		// Create token record.
		$token_id = $this->token_repo->create( $member->id, $competition->id, $category, $token_hash, $expires_at );

		if ( is_wp_error( $token_id ) ) {
			return '<p class="error">' . esc_html__( 'Failed to create voting link. Please try again.', 'club-competitions' ) . '</p>';
		}

		// Build magic link.
		$voting_url = add_query_arg(
			array(
				'token'       => $token_string,
				'competition' => $competition->slug,
			),
			get_permalink()
		);

		// Send email.
		$email_sent = $this->email_service->send_voting_link(
			$member_email,
			$competition->title,
			$voting_url
		);

		if ( ! $email_sent ) {
			return '<p class="error">' . esc_html__( 'Failed to send email. Please contact the administrator.', 'club-competitions' ) . '</p>';
		}

		return $generic_success;
	}

	/**
	 * Handle vote submission with valid token (for token-based voting).
	 *
	 * @param object $competition  Competition object.
	 * @param object $token_record Token record.
	 * @param array  $settings     Competition settings.
	 * @return string Message to display.
	 */
	private function handle_vote_submission_token( object $competition, object $token_record, array $settings ): string {
		$votes = isset( $_POST['votes'] ) && is_array( $_POST['votes'] ) ? $_POST['votes'] : array();

		if ( empty( $votes ) ) {
			return '<p class="error">' . esc_html__( 'Please select at least one image to vote for.', 'club-competitions' ) . '</p>';
		}

		// Check if token has already been used to vote.
		if ( $this->votes_repo->has_voted_with_token( (int) $token_record->id ) ) {
			return '<p class="error">' . esc_html__( 'This voting link has already been used.', 'club-competitions' ) . '</p>';
		}

		// Get score matrix from settings.
		$voting_config = CompetitionSettings::get_voting_config( $settings );
		$score_matrix  = $voting_config['score_matrix'] ?? array( 9, 8, 7, 6, 5 );

		// Verify voting is still open for this category.
		if ( ! CompetitionSettings::is_voting_open_for_category( $settings, $token_record->category ) ) {
			return '<p class="error">' . esc_html__( 'Voting is no longer open for this category.', 'club-competitions' ) . '</p>';
		}

		// Process votes.
		$success_count = 0;
		foreach ( $votes as $image_id => $position ) {
			$image_id = absint( $image_id );
			$position = absint( $position );

			if ( $image_id < 1 || $position < 1 || $position > count( $score_matrix ) ) {
				continue;
			}

			// Verify image belongs to this competition and category.
			$image = $this->images_repo->find( $image_id );
			if ( ! $image || (int) $image->competition_id !== (int) $competition->id || $image->category !== $token_record->category ) {
				continue;
			}

			$score  = $score_matrix[ $position - 1 ];
			$result = $this->votes_repo->create_anonymous(
				(int) $competition->id,
				$token_record->category,
				(int) $token_record->id,
				$image_id,
				(float) $score
			);

			if ( ! is_wp_error( $result ) ) {
				++$success_count;
			}
		}

		if ( $success_count > 0 ) {
			// Mark token as used.
			$this->token_repo->mark_as_used( (int) $token_record->id );

			return '<p class="success">' . esc_html__( 'Thank you for voting! Your votes have been recorded anonymously.', 'club-competitions' ) . '</p>';
		}

		return '<p class="error">' . esc_html__( 'Failed to record votes. Please try again.', 'club-competitions' ) . '</p>';
	}

	/**
	 * Handle vote submission with password (for password-based voting).
	 *
	 * @param object $competition Competition object.
	 * @param array  $settings    Competition settings.
	 * @return string Message to display.
	 */
	private function handle_vote_submission_password( object $competition, array $settings ): string {
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
		$voting_config = CompetitionSettings::get_voting_config( $settings );

		$expected_password = isset( $voting_config['password'] ) ? (string) $voting_config['password'] : '';
		$provided_password = isset( $_POST['voting_password'] ) ? sanitize_text_field( wp_unslash( $_POST['voting_password'] ) ) : '';

		if ( '' !== $expected_password ) {
			if ( '' === $provided_password ) {
				return '<p class="error">' . esc_html__( 'Please enter the voting password.', 'club-competitions' ) . '</p>';
			}

			if ( ! \hash_equals( $expected_password, $provided_password ) ) {
				return '<p class="error">' . esc_html__( 'The voting password is incorrect.', 'club-competitions' ) . '</p>';
			}
		}

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
		$score_matrix = $voting_config['score_matrix'] ?? array( 9, 8, 7, 6, 5 );

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
	 * Render voting interface for token-based voting.
	 *
	 * @param object      $competition   Competition object.
	 * @param string      $message       Message to display.
	 * @param object|null $token_record  Token record if validated.
	 * @param object|null $member        Member object if authenticated.
	 * @param array       $settings      Competition settings.
	 * @param string      $category      Category slug from token.
	 * @return void
	 */
	private function render_voting_interface( object $competition, string $message, ?object $token_record, ?object $member, array $settings, string $category ): void {
		$voting_config = CompetitionSettings::get_voting_config( $settings );
		$categories    = CompetitionSettings::get_categories( $settings );

		// Filter to only show categories where voting is open.
		$open_categories   = CompetitionSettings::get_open_voting_categories( $settings );
		$voting_categories = array_filter(
			$categories,
			function ( $cat ) use ( $open_categories ) {
				return in_array( $cat['slug'], $open_categories, true );
			}
		);

		// Get score matrix.
		$score_matrix = $voting_config['score_matrix'] ?? array( 9, 8, 7, 6, 5 );

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

			<?php if ( ! $token_record || ! $member ) : ?>
				<!-- Token request form -->
				<div class="token-request-section">
					<p><?php esc_html_e( 'To vote, please enter your registered email address and select a category. We will send you a secure voting link.', 'club-competitions' ); ?></p>

					<form method="post" class="voting-token-request-form">
						<?php wp_nonce_field( 'club_competitions_request_voting_token', 'club_competitions_voting_nonce' ); ?>

						<p>
							<label for="member_email">
								<?php esc_html_e( 'Your Email Address:', 'club-competitions' ); ?>
								<span class="required">*</span>
							</label>
							<input
								type="email"
								id="member_email"
								name="member_email"
								required
							/>
							<small><?php esc_html_e( 'Enter the email address associated with your club membership.', 'club-competitions' ); ?></small>
						</p>

						<p>
							<label for="category">
								<?php esc_html_e( 'Category:', 'club-competitions' ); ?>
								<span class="required">*</span>
							</label>
							<select id="category" name="category" required>
								<option value=""><?php esc_html_e( '-- Select Category --', 'club-competitions' ); ?></option>
								<?php foreach ( $voting_categories as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat['slug'] ); ?>">
										<?php echo esc_html( $cat['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<small><?php esc_html_e( 'You can only vote in one category at a time.', 'club-competitions' ); ?></small>
						</p>

						<p>
							<button type="submit" name="club_competitions_request_voting_token" class="button">
								<?php esc_html_e( 'Send Voting Link', 'club-competitions' ); ?>
							</button>
						</p>
					</form>
				</div>
			<?php else : ?>
				<!-- Member is authenticated with valid token, show voting form -->
				<?php
				// Check if token has already been used.
				$has_voted = $this->votes_repo->has_voted_with_token( (int) $token_record->id );

				if ( $has_voted ) {
					echo '<p class="notice">' . esc_html__( 'You have already voted in this category. Thank you!', 'club-competitions' ) . '</p>';
					$this->render_image_gallery( $competition, $category );
					return;
				}

				// Verify voting is still open for this category.
				if ( ! CompetitionSettings::is_voting_open_for_category( $settings, $category ) ) {
					echo '<p class="notice">' . esc_html__( 'Voting is no longer open for this category.', 'club-competitions' ) . '</p>';
					return;
				}

				// Get category label.
				$category_label = $category;
				foreach ( $voting_categories as $cat ) {
					if ( $cat['slug'] === $category ) {
						$category_label = $cat['label'];
						break;
					}
				}

				// Get images for this category.
				$images = $this->images_repo->find_by_competition( (int) $competition->id, $category );

				if ( empty( $images ) ) {
					echo '<p class="notice">' . esc_html__( 'No images submitted in this category yet.', 'club-competitions' ) . '</p>';
					return;
				}
				?>

				<div class="current-category">
					<h3><?php echo esc_html( $category_label ); ?></h3>
					<p class="member-info">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: member name */
								__( 'Authenticated as: %s', 'club-competitions' ),
								$member->name
							)
						);
						?>
					</p>
				</div>

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
					<p class="anonymity-notice" style="color: #666; font-style: italic;">
						<?php esc_html_e( 'Your votes are completely anonymous. Your name will not be associated with your votes.', 'club-competitions' ); ?>
					</p>
				</div>

				<!-- Voting form -->
				<form method="post" class="voting-form" id="voting-form">
					<?php wp_nonce_field( 'club_competitions_vote_with_token', 'club_competitions_vote_nonce' ); ?>

					<div class="images-grid">
						<?php foreach ( $images as $image ) : ?>
							<?php
							$image_url = $this->image_processor->get_image_url( $competition->slug, $image->category, $image->filename );
							$thumb_url = $this->image_processor->get_thumbnail_url( $competition->slug, $image->category, $image->filename );
							?>
							<div class="voting-image-item" data-image-id="<?php echo esc_attr( $image->id ); ?>">
								<div class="image-wrapper">
									<?php if ( ! is_wp_error( $image_url ) && ! is_wp_error( $thumb_url ) ) : ?>
										<a href="<?php echo esc_url( $image_url ); ?>" target="_blank" rel="noopener noreferrer" class="image-link">
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
							<?php esc_html_e( 'Submit Anonymous Votes', 'club-competitions' ); ?>
						</button>
					</div>
				</form>

				<script>
				(function() {
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
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render image gallery (for voters who have already voted).
	 *
	 * @param object $competition Competition object.
	 * @param string $category    Category slug.
	 * @return void
	 */
	private function render_image_gallery( object $competition, string $category ): void {
		$images = $this->images_repo->find_by_competition( (int) $competition->id, $category );

		if ( empty( $images ) ) {
			return;
		}

		echo '<div class="images-grid gallery-view">';
		foreach ( $images as $image ) {
			$image_url = $this->image_processor->get_image_url( $competition->slug, $image->category, $image->filename );
			$thumb_url = $this->image_processor->get_thumbnail_url( $competition->slug, $image->category, $image->filename );

			echo '<div class="voting-image-item">';
			echo '<div class="image-wrapper">';
			if ( ! is_wp_error( $image_url ) && ! is_wp_error( $thumb_url ) ) {
				echo '<a href="' . esc_url( $image_url ) . '" target="_blank" rel="noopener noreferrer" class="image-link">';
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
	 * Render voting interface for password-based voting.
	 *
	 * @param object $competition Competition object.
	 * @param string $message     Message to display.
	 * @param array  $settings    Competition settings.
	 * @return void
	 */
	private function render_password_voting_interface( object $competition, string $message, array $settings ): void {
		$voting_config = CompetitionSettings::get_voting_config( $settings );
		$categories    = CompetitionSettings::get_categories( $settings );

		// Filter to only show categories where voting is open.
		$open_categories   = CompetitionSettings::get_open_voting_categories( $settings );
		$voting_categories = array_filter(
			$categories,
			function ( $cat ) use ( $open_categories ) {
				return in_array( $cat['slug'], $open_categories, true );
			}
		);

		// Get score matrix.
		$score_matrix     = $voting_config['score_matrix'] ?? array( 9, 8, 7, 6, 5 );
		$voting_password  = $voting_config['password'] ?? '';
		$password_enabled = '' !== $voting_password;

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

			<!-- Voting form -->
			<div class="password-voting-section">
				<?php foreach ( $voting_categories as $category_data ) : ?>
					<?php
					$category_slug = $category_data['slug'];
					$images        = $this->images_repo->find_by_competition( (int) $competition->id, $category_slug );

					if ( empty( $images ) ) {
						continue;
					}
					?>

					<div class="voting-category-section">
						<h3><?php echo esc_html( $category_data['label'] ); ?></h3>

						<!-- Voting instructions -->
						<div class="voting-instructions">
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
						<form method="post" class="voting-form">
							<?php wp_nonce_field( 'club_competitions_vote', 'club_competitions_vote_nonce' ); ?>
							<input type="hidden" name="category" value="<?php echo esc_attr( $category_slug ); ?>" />

							<p>
								<label for="voter_name_<?php echo esc_attr( $category_slug ); ?>">
									<?php esc_html_e( 'Your Name:', 'club-competitions' ); ?>
									<span class="required">*</span>
								</label>
								<input
									type="text"
									id="voter_name_<?php echo esc_attr( $category_slug ); ?>"
									name="voter_name"
									required
								/>
							</p>

							<?php if ( $password_enabled ) : ?>
								<p>
									<label for="voting_password_<?php echo esc_attr( $category_slug ); ?>">
										<?php esc_html_e( 'Voting Password:', 'club-competitions' ); ?>
										<span class="required">*</span>
									</label>
									<input
										type="password"
										id="voting_password_<?php echo esc_attr( $category_slug ); ?>"
										name="voting_password"
										required
									/>
								</p>
							<?php endif; ?>

							<div class="images-grid">
								<?php foreach ( $images as $image ) : ?>
									<?php
									$image_url = $this->image_processor->get_image_url( $competition->slug, $image->category, $image->filename );
									$thumb_url = $this->image_processor->get_thumbnail_url( $competition->slug, $image->category, $image->filename );
									?>
									<div class="voting-image-item" data-image-id="<?php echo esc_attr( $image->id ); ?>">
										<div class="image-wrapper">
											<?php if ( ! is_wp_error( $image_url ) && ! is_wp_error( $thumb_url ) ) : ?>
												<a href="<?php echo esc_url( $image_url ); ?>" target="_blank" rel="noopener noreferrer" class="image-link">
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
											<label for="vote_<?php echo esc_attr( $category_slug ); ?>_<?php echo esc_attr( $image->id ); ?>">
												<?php esc_html_e( 'Position:', 'club-competitions' ); ?>
											</label>
											<select name="votes[<?php echo esc_attr( $image->id ); ?>]" id="vote_<?php echo esc_attr( $category_slug ); ?>_<?php echo esc_attr( $image->id ); ?>" class="vote-select">
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

						<hr />
					</div>
				<?php endforeach; ?>

				<script>
				(function() {
					// Prevent duplicate position selection within each form.
					document.querySelectorAll('.voting-form').forEach(function(form) {
						const selects = form.querySelectorAll('.vote-select');
						selects.forEach(function(select) {
							select.addEventListener('change', function() {
								const selectedValue = this.value;
								if (selectedValue) {
									// Clear other selects with the same value within this form.
									selects.forEach(function(otherSelect) {
										if (otherSelect !== select && otherSelect.value === selectedValue) {
											otherSelect.value = '';
										}
									});
								}
							});
						});
					});
				})();
				</script>
			</div>
		</div>
		<?php
	}
}
