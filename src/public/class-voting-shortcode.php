<?php
/**
 * Handle voting shortcode with token-based authentication.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Support\Image_Processor;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Shortcode renderer for competition voting (token- and password-based).
 *
 * Responsible for rendering forms, validating input, and recording votes.
 *
 * @since 1.0.0
 */
class Voting_Shortcode {

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
	 * Voting token repository.
	 *
	 * @var Voting_Token_Repository
	 */
	private $token_repo;

	/**
	 * Email service.
	 *
	 * @var Email_Service
	 */
	private $email_service;

	/**
	 * Image processor.
	 *
	 * @var Image_Processor
	 */
	private $image_processor;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Images_Repository|null       $images_repo       Images repository.
	 * @param Votes_Repository|null        $votes_repo        Votes repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 * @param Voting_Token_Repository|null $token_repo        Token repository.
	 * @param Email_Service|null           $email_service     Email service.
	 * @param Image_Processor|null         $image_processor   Image processor.
	 */
	public function __construct(
		?Competitions_Repository $competitions_repo = null,
		?Images_Repository $images_repo = null,
		?Votes_Repository $votes_repo = null,
		?Members_Repository $members_repo = null,
		?Voting_Token_Repository $token_repo = null,
		?Email_Service $email_service = null,
		?Image_Processor $image_processor = null
	) {
		$this->competitions_repo = $competitions_repo ? $competitions_repo : new Competitions_Repository();
		$this->images_repo       = $images_repo ? $images_repo : new Images_Repository();
		$this->votes_repo        = $votes_repo ? $votes_repo : new Votes_Repository();
		$this->members_repo      = $members_repo ? $members_repo : new Members_Repository();
		$this->token_repo        = $token_repo ? $token_repo : new Voting_Token_Repository();
		$this->email_service     = $email_service ? $email_service : new Email_Service();
		$this->image_processor   = $image_processor ? $image_processor : new Image_Processor();
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
	 * Enqueue voting assets (CSS and JS).
	 *
	 * @return void
	 */
	private function enqueue_voting_assets(): void {
		// Enqueue main voting styles.
		$style_asset = PHOTO_COMPETITION_MANAGER_DIR . '/assets/build/index.asset.php';
		if ( file_exists( $style_asset ) ) {
			$asset_data = require $style_asset;
			wp_enqueue_style(
				'photo-competition-manager-voting',
				PHOTO_COMPETITION_MANAGER_URL . 'assets/build/index.css',
				array(),
				$asset_data['version'] ?? PHOTO_COMPETITION_MANAGER_VERSION
			);
		}

		// Enqueue voting validation script.
		$script_asset = PHOTO_COMPETITION_MANAGER_DIR . '/assets/build/voting-validation.asset.php';
		if ( file_exists( $script_asset ) ) {
			$asset_data = require $script_asset;
			wp_enqueue_script(
				'photo-competition-manager-voting-validation',
				PHOTO_COMPETITION_MANAGER_URL . 'assets/build/voting-validation.js',
				$asset_data['dependencies'] ?? array(),
				$asset_data['version'] ?? PHOTO_COMPETITION_MANAGER_VERSION,
				true
			);
		}

		// Enqueue redirect button handler.
		wp_register_script( 'photo-comp-voting-redirect', '', array(), PHOTO_COMPETITION_MANAGER_VERSION, true );
		wp_enqueue_script( 'photo-comp-voting-redirect' );

		$inline_script = "
		document.addEventListener('DOMContentLoaded', function() {
			var redirectButtons = document.querySelectorAll('.photo-comp-redirect-btn');
			redirectButtons.forEach(function(button) {
				button.addEventListener('click', function() {
					var url = this.getAttribute('data-redirect-url');
					if (url) {
						window.location.href = url;
					}
				});
			});
		});
		";

		wp_add_inline_script( 'photo-comp-voting-redirect', $inline_script );
	}

	/**
	 * Render voting shortcode.
	 *
	 * @return string
	 */
	public function render(): string {

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is a WP Super Cache constant.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

		// Enqueue voting validation assets.
		$this->enqueue_voting_assets();

		// Enforce HTTPS requirement for voting.
		if ( ! $this->is_https_connection() ) {
			return $this->render_https_required_notice();
		}

		// Find the most recent active competition.
		$competition = $this->competitions_repo->find_current_active();

		if ( ! $competition ) {
			return '<p class="error">' . esc_html__( 'No active competition found.', 'photo-competition-manager' ) . '</p>';
		}

		$settings      = Competition_Settings::parse( $competition->settings );
		$voting_config = Competition_Settings::get_voting_config( $settings );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a read-only token from the URL for magic-link auth; sanitized and hashed below.
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
		if ( isset( $_POST['photo_competition_request_voting_token'] ) && check_admin_referer( 'photo_competition_request_voting_token', 'photo_competition_voting_nonce' ) ) {
			$message = $this->handle_token_request( $competition, $settings, $_POST );
		}

		$submitted_votes = array();

		// Handle vote submission with token.
		if ( $token_record && $member && isset( $_POST['photo_competition_vote'] ) && check_admin_referer( 'photo_competition_vote_with_token', 'photo_competition_vote_nonce' ) ) {
			$submitted_votes = $this->collect_vote_selections_from_request( $_POST, $settings );
			$message         = $this->handle_vote_submission_token( $competition, $token_record, $settings, $submitted_votes );
		}

		ob_start();
		$this->render_voting_interface( $competition, $message, $token_record, $member, $settings, $category, $submitted_votes );
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
		$message      = '';
		$status_param = isset( $_GET['vote_status'] ) ? sanitize_key( wp_unslash( $_GET['vote_status'] ) ) : '';
		if ( 'success' === $status_param ) {
			$message  = '<p class="success">' . esc_html__( 'Thank you for voting! Your votes have been recorded.', 'photo-competition-manager' ) . '</p>';
			$message .= '<p><button type="button" class="button photo-comp-redirect-btn" data-redirect-url="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Check If Voting Is Open', 'photo-competition-manager' ) . '</button></p>';
		}

		$submitted_data = array(
			'voter_name'      => '',
			'category'        => '',
			'voting_password' => '',
			'votes'           => array(),
		);

		if ( isset( $_POST['photo_competition_vote'] ) && check_admin_referer( 'photo_competition_vote', 'photo_competition_vote_nonce' ) ) {
			$submitted_data = $this->collect_password_submission_data( $_POST, $settings );
			$result         = $this->handle_vote_submission_password( $competition, $settings, $submitted_data );

			if ( 'success' === $result['status'] ) {
				$redirect_args = array(
					'vote_status' => 'success',
				);

				if ( ! empty( $result['category'] ) ) {
					$redirect_args['vote_category'] = $result['category'];
				}

				wp_safe_redirect( add_query_arg( $redirect_args, get_permalink() ) );
				exit;
			}

			$message = $result['message'];
		}

		ob_start();
		$this->render_password_voting_interface( $competition, $message, $settings, $submitted_data );
		$output = ob_get_clean();
		return $output ? $output : '';
	}

	/**
	 * Handle token request form submission.
	 *
	 * @param object $competition Competition object.
	 * @param array  $settings    Competition settings.
	 * @param array  $request     Request array (typically $_POST) already nonce-verified by the caller.
	 * @return string Message to display.
	 */
	private function handle_token_request( object $competition, array $settings, array $request ): string {
		$member_email = isset( $request['member_email'] ) ? sanitize_email( wp_unslash( $request['member_email'] ) ) : '';
		$category     = isset( $request['category'] ) ? sanitize_text_field( wp_unslash( $request['category'] ) ) : '';

		if ( empty( $member_email ) ) {
			return '<p class="error">' . esc_html__( 'Please enter your email address.', 'photo-competition-manager' ) . '</p>';
		}

		if ( empty( $category ) ) {
			return '<p class="error">' . esc_html__( 'Please select a category.', 'photo-competition-manager' ) . '</p>';
		}

		// Generic success message for security (prevents email enumeration).
		$generic_success = '<p class="success">' . esc_html__( 'If this email is registered, you will receive a voting link shortly. Please check your inbox.', 'photo-competition-manager' ) . '</p>';

		// Verify category is open for voting.
		if ( ! Competition_Settings::is_voting_open_for_category( $settings, $category ) ) {
			return '<p class="error">' . esc_html__( 'Voting is not open for this category.', 'photo-competition-manager' ) . '</p>';
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
		$expires_at   = utc_time( HOUR_IN_SECONDS );

		// Create token record.
		$token_id = $this->token_repo->create( $member->id, $competition->id, $category, $token_hash, $expires_at );

		if ( is_wp_error( $token_id ) ) {
			return '<p class="error">' . esc_html__( 'Failed to create voting link. Please try again.', 'photo-competition-manager' ) . '</p>';
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
			return '<p class="error">' . esc_html__( 'Failed to send email. Please contact the administrator.', 'photo-competition-manager' ) . '</p>';
		}

		return $generic_success;
	}

	/**
	 * Handle vote submission with valid token (for token-based voting).
	 *
	 * @param object         $competition     Competition object.
	 * @param object         $token_record    Token record.
	 * @param array          $settings        Competition settings.
	 * @param array<int,int> $submitted_votes Sanitized vote selections keyed by image ID.
	 * @return string Message to display.
	 */
	private function handle_vote_submission_token( object $competition, object $token_record, array $settings, array $submitted_votes ): string {
		// Get all images for this category to validate all have been voted for.
		$images      = $this->images_repo->find_by_competition( (int) $competition->id, $token_record->category );
		$image_count = count( $images );

		if ( empty( $submitted_votes ) ) {
			return '<p class="error">' . esc_html__( 'Please select at least one image to vote for.', 'photo-competition-manager' ) . '</p>';
		}

		// Validate that all images have received a vote.
		if ( count( $submitted_votes ) < $image_count ) {
			return '<p class="error">' . esc_html(
				sprintf(
					/* translators: %1$d: number of images voted for, %2$d: total number of images */
					_n(
						'You must vote for all images. You have voted for %1$d of %2$d image.',
						'You must vote for all images. You have voted for %1$d of %2$d images.',
						$image_count,
						'photo-competition-manager'
					),
					count( $submitted_votes ),
					$image_count
				)
			) . '</p>';
		}

		// Get score matrix from settings.
		$voting_config = Competition_Settings::get_voting_config( $settings );
		$score_matrix  = $voting_config['score_matrix'] ?? array( 9, 8, 7, 6, 5 );

		// Verify voting is still open for this category.
		if ( ! Competition_Settings::is_voting_open_for_category( $settings, $token_record->category ) ) {
			return '<p class="error">' . esc_html__( 'Voting is no longer open for this category.', 'photo-competition-manager' ) . '</p>';
		}

		$existing_scores = $this->votes_repo->get_votes_by_token( (int) $token_record->id );
		if ( ! empty( $existing_scores ) ) {
			return '<p class="notice notice-success">' . esc_html__( 'Thank you! Your votes for this category have already been recorded.', 'photo-competition-manager' ) . '</p>';
		}

		// Process votes.
		$success_count = 0;
		foreach ( $submitted_votes as $image_id => $score ) {
			// Verify image belongs to this competition and category.
			$image = $this->images_repo->find( $image_id );
			if ( ! $image || (int) $image->competition_id !== (int) $competition->id || $image->category !== $token_record->category ) {
				continue;
			}

			$result = $this->votes_repo->create_anonymous(
				(int) $competition->id,
				$token_record->category,
				(int) $token_record->id,
				$image_id,
				(int) $score
			);

			if ( ! is_wp_error( $result ) ) {
				++$success_count;
			}
		}

		if ( $success_count > 0 ) {
			return '<p class="success">' . esc_html__( 'Thank you for voting! Your latest votes have been recorded anonymously.', 'photo-competition-manager' ) . '</p>';
		}

		return '<p class="error">' . esc_html__( 'Failed to record votes. Please try again.', 'photo-competition-manager' ) . '</p>';
	}

	/**
	 * Handle vote submission with password (for password-based voting).
	 *
	 * @param object $competition Competition object.
	 * @param array  $settings    Competition settings.
	 * @param array  $submission  Sanitized submission data.
	 * @return array{status:string,message:string,category:string} Submission outcome.
	 */
	private function handle_vote_submission_password( object $competition, array $settings, array $submission ): array {
		$voter_name    = $submission['voter_name'] ?? '';
		$category      = $submission['category'] ?? '';
		$votes         = $submission['votes'] ?? array();
		$provided_pass = $submission['voting_password'] ?? '';

		if ( '' === $voter_name ) {
			return array(
				'status'   => 'error',
				'message'  => '<p class="error">' . esc_html__( 'Please enter your name.', 'photo-competition-manager' ) . '</p>',
				'category' => $category,
			);
		}

		if ( '' === $category ) {
			return array(
				'status'   => 'error',
				'message'  => '<p class="error">' . esc_html__( 'Invalid category.', 'photo-competition-manager' ) . '</p>',
				'category' => $category,
			);
		}

		// Verify voting is open for this category.
		$voting_config = Competition_Settings::get_voting_config( $settings );

		$expected_password = isset( $voting_config['password'] ) ? (string) $voting_config['password'] : '';

		if ( '' !== $expected_password ) {
			if ( '' === $provided_pass ) {
				return array(
					'status'   => 'error',
					'message'  => '<p class="error">' . esc_html__( 'Please enter the voting password.', 'photo-competition-manager' ) . '</p>',
					'category' => $category,
				);
			}

			// Try direct case-insensitive comparison first (plaintext), fall back to wp_check_password for legacy hashes.
			$password_matches = strtolower( $provided_pass ) === strtolower( $expected_password )
				|| wp_check_password( strtolower( $provided_pass ), $expected_password );
			if ( ! $password_matches ) {
				return array(
					'status'   => 'error',
					'message'  => '<p class="error">' . esc_html__( 'The voting password is incorrect.', 'photo-competition-manager' ) . '</p>',
					'category' => $category,
				);
			}
		}

		if ( ! Competition_Settings::is_voting_open_for_category( $settings, $category ) ) {
			return array(
				'status'   => 'error',
				'message'  => '<p class="error">' . esc_html__( 'Voting is not open for this category.', 'photo-competition-manager' ) . '</p>',
				'category' => $category,
			);
		}

		// Get all images for this category to validate all have been voted for.
		$images      = $this->images_repo->find_by_competition( (int) $competition->id, $category );
		$image_count = count( $images );

		if ( empty( $votes ) ) {
			return array(
				'status'   => 'error',
				'message'  => '<p class="error">' . esc_html__( 'Please select at least one image to vote for.', 'photo-competition-manager' ) . '</p>',
				'category' => $category,
			);
		}

		// Validate that all images have received a vote.
		if ( count( $votes ) < $image_count ) {
			return array(
				'status'   => 'error',
				'message'  => '<p class="error">' . esc_html(
					sprintf(
						/* translators: %1$d: number of images voted for, %2$d: total number of images */
						_n(
							'You must vote for all images. You have voted for %1$d of %2$d image.',
							'You must vote for all images. You have voted for %1$d of %2$d images.',
							$image_count,
							'photo-competition-manager'
						),
						count( $votes ),
						$image_count
					)
				) . '</p>',
				'category' => $category,
			);
		}

		if ( $this->votes_repo->has_voted( (int) $competition->id, $category, $voter_name ) ) {
			$this->refresh_voter_cookie( $voter_name, $provided_pass );
			return array(
				'status'   => 'already_voted',
				'message'  => '<p class="notice notice-success">' . esc_html__( 'Thank you! Your votes for this category have already been recorded.', 'photo-competition-manager' ) . '</p>',
				'category' => $category,
			);
		}

		// Process votes.
		$success_count = 0;
		foreach ( $votes as $image_id => $score ) {
			// Verify image belongs to this competition and category.
			$image = $this->images_repo->find( $image_id );
			if ( ! $image || (int) $image->competition_id !== (int) $competition->id || $image->category !== $category ) {
				continue;
			}

			$result = $this->votes_repo->create( (int) $competition->id, $category, $voter_name, $image_id, (int) $score );

			if ( ! is_wp_error( $result ) ) {
				++$success_count;
			}
		}

		if ( $success_count > 0 ) {
			$this->refresh_voter_cookie( $voter_name, $provided_pass );
			return array(
				'status'   => 'success',
				'message'  => '',
				'category' => $category,
			);
		}

		return array(
			'status'   => 'error',
			'message'  => '<p class="error">' . esc_html__( 'Failed to record votes. Please try again.', 'photo-competition-manager' ) . '</p>',
			'category' => $category,
		);
	}

	/**
	 * Render voting interface for token-based voting.
	 *
	 * @param object           $competition     Competition object.
	 * @param string           $message         Message to display.
	 * @param object|null      $token_record    Token record if validated.
	 * @param object|null      $member          Member object if authenticated.
	 * @param array            $settings        Competition settings.
	 * @param string           $category        Category slug from token.
	 * @param array<int,float> $submitted_votes Previously submitted vote selections.
	 * @return void
	 */
	private function render_voting_interface( object $competition, string $message, ?object $token_record, ?object $member, array $settings, string $category, array $submitted_votes ): void {
		$voting_config = Competition_Settings::get_voting_config( $settings );
		$categories    = Competition_Settings::get_categories( $settings );

		// Filter to only show categories where voting is open.
		$open_categories   = Competition_Settings::get_open_voting_categories( $settings );
		$voting_categories = array_filter(
			$categories,
			function ( $cat ) use ( $open_categories ) {
				return in_array( $cat['slug'], $open_categories, true );
			}
		);

		// Get score matrix, UI type, and image click setting.
		$score_matrix        = $voting_config['score_matrix'] ?? array( 9, 8, 7, 6, 5 );
		$click_image_to_zoom = $voting_config['click_image_to_zoom'] ?? false;
		$voting_ui_type      = Competition_Settings::get_voting_ui_type( $settings );

		?>
		<div class="photo-comp-voting">
			<h2><?php echo esc_html( $competition->title ); ?> - <?php esc_html_e( 'Voting', 'photo-competition-manager' ); ?></h2>

			<?php if ( $message ) : ?>
				<?php echo wp_kses_post( $message ); ?>
			<?php endif; ?>

			<?php if ( ! $this->competitions_repo->is_open( $competition ) ) : ?>
				<p class="notice"><?php esc_html_e( 'Voting is not currently open for this competition.', 'photo-competition-manager' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( empty( $voting_categories ) ) : ?>
				<p class="notice"><?php esc_html_e( 'Voting is not currently open for any category. Please check back later.', 'photo-competition-manager' ); ?></p>
				<p>
					<button type="button" class="button photo-comp-redirect-btn" data-redirect-url="<?php echo esc_url( add_query_arg( 'token', $token_string, get_permalink() ) ); ?>">
						<?php esc_html_e( 'Check If Voting Is Open', 'photo-competition-manager' ); ?>
					</button>
				</p>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( ! $token_record || ! $member ) : ?>
				<!-- Token request form -->
				<div class="token-request-section">
					<p><?php esc_html_e( 'To vote, please enter your registered email address and select a category. We will send you a secure voting link.', 'photo-competition-manager' ); ?></p>

					<form method="post" class="voting-token-request-form">
						<?php wp_nonce_field( 'photo_competition_request_voting_token', 'photo_competition_voting_nonce' ); ?>

						<p>
							<label for="member_email">
								<?php esc_html_e( 'Your Email Address:', 'photo-competition-manager' ); ?>
								<span class="required">*</span>
							</label>
							<input
								type="email"
								id="member_email"
								name="member_email"
								required
							/>
							<small><?php esc_html_e( 'Enter the email address associated with your club membership.', 'photo-competition-manager' ); ?></small>
						</p>

						<p>
							<label for="category">
								<?php esc_html_e( 'Category:', 'photo-competition-manager' ); ?>
								<span class="required">*</span>
							</label>
							<select id="category" name="category" required>
								<option value=""><?php esc_html_e( '-- Select Category --', 'photo-competition-manager' ); ?></option>
								<?php foreach ( $voting_categories as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat['slug'] ); ?>">
										<?php echo esc_html( $cat['label'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<small><?php esc_html_e( 'You can only vote in one category at a time.', 'photo-competition-manager' ); ?></small>
						</p>

						<p>
							<button type="submit" name="photo_competition_request_voting_token" class="button">
								<?php esc_html_e( 'Send Voting Link', 'photo-competition-manager' ); ?>
							</button>
						</p>
					</form>
				</div>
			<?php else : ?>
				<!-- Member is authenticated with valid token, show voting form -->
				<?php
				// Verify voting is still open for this category.
				if ( ! Competition_Settings::is_voting_open_for_category( $settings, $category ) ) {
					echo '<p class="notice">' . esc_html__( 'Voting is no longer open for this category.', 'photo-competition-manager' ) . '</p>';
					echo '<p><button type="button" class="button photo-comp-redirect-btn" data-redirect-url="' . esc_url( add_query_arg( 'token', $token_string, get_permalink() ) ) . '">' . esc_html__( 'Check If Voting Is Open', 'photo-competition-manager' ) . '</button></p>';
					return;
				}

				$existing_votes = array();
				if ( $token_record ) {
					$existing_scores = $this->votes_repo->get_votes_by_token( (int) $token_record->id );
					$existing_votes  = $this->sanitize_vote_selections( $existing_scores, $score_matrix );
				}

				if ( ! empty( $existing_votes ) ) {
					echo '<p class="notice notice-success">' . esc_html__( 'Thank you! Your votes for this category have already been recorded.', 'photo-competition-manager' ) . '</p>';
					echo '<p><button type="button" class="button photo-comp-redirect-btn" data-redirect-url="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Check If Voting Is Open', 'photo-competition-manager' ) . '</button></p>';
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
					echo '<p class="notice">' . esc_html__( 'No images submitted in this category yet.', 'photo-competition-manager' ) . '</p>';
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
								__( 'Authenticated as: %s', 'photo-competition-manager' ),
								$member->name
							)
						);
						?>
					</p>
				</div>

				<!-- Voting instructions -->
				<div class="voting-instructions">
					<h3><?php esc_html_e( 'How to Vote', 'photo-competition-manager' ); ?></h3>
					<p>
						<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of scoring options */
									_n(
										'Assign points to each image using the controls below. You have %d score option available.',
										'Assign points to each image using the controls below. You have %d score options available.',
										count( $score_matrix ),
										'photo-competition-manager'
									),
									count( $score_matrix )
								)
							);
						?>
					</p>
					<p>
				<?php
					$score_labels = array_map( 'number_format_i18n', $score_matrix );
					echo esc_html(
						sprintf(
							/* translators: %s: comma-separated score values */
							__( 'Points awarded: %s', 'photo-competition-manager' ),
							implode( ', ', $score_labels )
						)
					);
				?>
					</p>
					<p><strong><?php esc_html_e( 'You must vote for all images to submit your votes.', 'photo-competition-manager' ); ?></strong></p>
					<p class="anonymity-notice" style="color: #666; font-style: italic;">
						<?php esc_html_e( 'Your votes are completely anonymous. Your name will not be associated with your votes.', 'photo-competition-manager' ); ?>
					</p>
				</div>

				<!-- Voting form -->
				<form method="post" class="voting-form" id="voting-form">
					<?php wp_nonce_field( 'photo_competition_vote_with_token', 'photo_competition_vote_nonce' ); ?>

					<div class="images-grid">
						<?php foreach ( $images as $image ) : ?>
							<?php
							$image_url = $this->image_processor->get_image_url( $competition->slug, $image->category, $image->filename );
							$thumb_url = $this->image_processor->get_thumbnail_url( $competition->slug, $image->category, $image->filename );
							?>
							<div class="voting-image-item" data-image-id="<?php echo esc_attr( $image->id ); ?>">
								<div class="image-wrapper">
									<?php if ( ! is_wp_error( $image_url ) && ! is_wp_error( $thumb_url ) ) : ?>
										<?php
										// translators: %d: image random number.
										$alt = sprintf( __( 'Image %d', 'photo-competition-manager' ), $image->random_number );
										?>
										<?php if ( $click_image_to_zoom ) : ?>
											<a href="<?php echo esc_url( $image_url ); ?>" target="_blank" rel="noopener noreferrer" class="image-link">
												<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" />
											</a>
										<?php else : ?>
											<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" />
										<?php endif; ?>
									<?php else : ?>
										<div class="image-unavailable"><?php esc_html_e( 'Image unavailable', 'photo-competition-manager' ); ?></div>
									<?php endif; ?>
									<div class="image-number">#<?php echo esc_html( $image->random_number ); ?></div>
								</div>
							<?php
							$selected_score = (string) ( $submitted_votes[ $image->id ] ?? '' );
							$field_name     = 'votes[' . $image->id . ']';
							$control_id     = 'vote_' . $image->id;
							$this->render_vote_selector_control(
								$field_name,
								$control_id,
								$score_matrix,
								$voting_ui_type,
								$selected_score
							);
							?>
							</div>
						<?php endforeach; ?>
					</div>

					<div class="voting-submit">
						<button type="submit" name="photo_competition_vote" class="button button-primary button-large">
							<?php esc_html_e( 'Submit Anonymous Votes', 'photo-competition-manager' ); ?>
						</button>
					</div>
				</form>

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
				// translators: %d: image random number.
				$alt = sprintf( __( 'Image %d', 'photo-competition-manager' ), $image->random_number );
				echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" />';
				echo '</a>';
			} else {
				echo '<div class="image-unavailable">' . esc_html__( 'Image unavailable', 'photo-competition-manager' ) . '</div>';
			}
			echo '<div class="image-number">#' . esc_html( $image->random_number ) . '</div>';
			echo '</div>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Render the vote selector control for a single image.
	 *
	 * @param string                $field_name       Name attribute for the input.
	 * @param string                $control_id_base  Base ID used for the control.
	 * @param array<int, int|float> $score_matrix     Allowed score values.
	 * @param string                $voting_ui_type   Resolved voting UI type.
	 * @param string                $selected_score   Currently selected score value.
	 * @return void
	 */
	private function render_vote_selector_control( string $field_name, string $control_id_base, array $score_matrix, string $voting_ui_type, string $selected_score ): void {
		$ui_type       = in_array( $voting_ui_type, array( 'buttons', 'dropdown' ), true ) ? $voting_ui_type : 'buttons';
		$wrapper_class = 'vote-selector-' . ( 'buttons' === $ui_type ? 'buttons' : 'dropdown' );
		$label_text    = __( 'Score:', 'photo-competition-manager' );

		echo '<div class="vote-selector ' . esc_attr( $wrapper_class ) . '">';

		if ( 'buttons' === $ui_type ) {
			$group_label_id = $control_id_base . '_legend';
			echo '<span class="vote-label" id="' . esc_attr( $group_label_id ) . '">' . esc_html( $label_text ) . '</span>';
			echo '<div class="vote-options" role="radiogroup" aria-labelledby="' . esc_attr( $group_label_id ) . '">';

			foreach ( $score_matrix as $index => $score_value ) {
				$score_label = number_format_i18n( $score_value );
				$input_id    = $control_id_base . '_' . $index;

				echo '<div class="vote-option">';
				echo '<input type="radio" name="' . esc_attr( $field_name ) . '" id="' . esc_attr( $input_id ) . '" value="' . esc_attr( (string) $score_value ) . '"' . checked( $selected_score, (string) $score_value, false ) . ' />';
				echo '<label for="' . esc_attr( $input_id ) . '"><span class="vote-value">' . esc_html( $score_label ) . '</span></label>';
				echo '</div>';
			}

			echo '</div>';
		} else {
			echo '<label for="' . esc_attr( $control_id_base ) . '" class="vote-label">' . esc_html( $label_text ) . '</label>';
			echo '<select name="' . esc_attr( $field_name ) . '" id="' . esc_attr( $control_id_base ) . '" class="vote-select">';
			echo '<option value=""' . selected( $selected_score, '', false ) . '>-</option>';

			foreach ( $score_matrix as $score_value ) {
				$score_label = number_format_i18n( $score_value );
				echo '<option value="' . esc_attr( (string) $score_value ) . '"' . selected( $selected_score, (string) $score_value, false ) . '>';
				echo esc_html(
					sprintf(
						/* translators: %s: score value */
						__( '%s pts', 'photo-competition-manager' ),
						$score_label
					)
				);
				echo '</option>';
			}

			echo '</select>';
		}

		echo '</div>';
	}

	/**
	 * Render voting interface for password-based voting.
	 *
	 * @param object $competition    Competition object.
	 * @param string $message        Message to display.
	 * @param array  $settings       Competition settings.
	 * @param array  $submitted_data Sanitized previously submitted data.
	 * @return void
	 */
	private function render_password_voting_interface( object $competition, string $message, array $settings, array $submitted_data ): void {
		$voting_config = Competition_Settings::get_voting_config( $settings );
		$categories    = Competition_Settings::get_categories( $settings );

		// Filter to only show categories where voting is open.
		$open_categories   = Competition_Settings::get_open_voting_categories( $settings );
		$voting_categories = array_filter(
			$categories,
			function ( $cat ) use ( $open_categories ) {
				return in_array( $cat['slug'], $open_categories, true );
			}
		);

		// Get score matrix and image click setting.
		$score_matrix        = $voting_config['score_matrix'] ?? array( 9, 8, 7, 6, 5 );
		$voting_password     = $voting_config['password'] ?? '';
		$password_enabled    = '' !== $voting_password;
		$click_image_to_zoom = $voting_config['click_image_to_zoom'] ?? false;
		$voting_ui_type      = Competition_Settings::get_voting_ui_type( $settings );
		$cookie_payload      = $this->get_voter_cookie();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$success_status = isset( $_GET['vote_status'] ) ? sanitize_key( wp_unslash( $_GET['vote_status'] ) ) : '';
		$requested_slug = isset( $_GET['vote_category'] ) ? sanitize_text_field( wp_unslash( $_GET['vote_category'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$valid_slugs            = array_map(
			function ( $category ) {
				return $category['slug'] ?? '';
			},
			$voting_categories
		);
		$limit_prefill_category = ( 'success' === $success_status && in_array( $requested_slug, $valid_slugs, true ) ) ? $requested_slug : '';

		$voter_name_value = $submitted_data['voter_name'] ?? '';
		if ( '' === $voter_name_value ) {
			$voter_name_value = $cookie_payload['name'];
		}

		$password_value = $submitted_data['voting_password'] ?? '';
		if ( '' === $password_value ) {
			$password_value = $cookie_payload['password'];
		}
		$current_category = $submitted_data['category'] ?? '';
		$submitted_votes  = $submitted_data['votes'] ?? array();

		?>
		<div class="photo-comp-voting">
			<h2><?php echo esc_html( $competition->title ); ?> - <?php esc_html_e( 'Voting', 'photo-competition-manager' ); ?></h2>

			<?php if ( $message ) : ?>
				<?php echo wp_kses_post( $message ); ?>
			<?php endif; ?>

			<?php if ( ! $this->competitions_repo->is_open( $competition ) ) : ?>
				<p class="notice"><?php esc_html_e( 'Voting is not currently open for this competition.', 'photo-competition-manager' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( empty( $voting_categories ) ) : ?>
				<p class="notice"><?php esc_html_e( 'Voting is not currently open for any category. Please check back later.', 'photo-competition-manager' ); ?></p>
				<p>
					<button type="button" class="button photo-comp-redirect-btn" data-redirect-url="<?php echo esc_url( get_permalink() ); ?>">
						<?php esc_html_e( 'Check If Voting Is Open', 'photo-competition-manager' ); ?>
					</button>
				</p>
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

					$has_voted = false;
					if ( '' !== $voter_name_value ) {
						$has_voted = $this->votes_repo->has_voted( (int) $competition->id, $category_slug, $voter_name_value );
					}

					if ( $has_voted ) {
						echo '<div class="voting-category-section voting-category-complete">';
						echo '<h3>' . esc_html( $category_data['label'] ) . '</h3>';
						echo '<p class="notice notice-success">' . esc_html__( 'Thank you! Your votes for this category have already been recorded.', 'photo-competition-manager' ) . '</p>';
						echo '<p><button type="button" class="button photo-comp-redirect-btn" data-redirect-url="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Check If Voting Is Open', 'photo-competition-manager' ) . '</button></p>';
						echo '</div>';
						continue;
					}

					$category_votes = array();
					if ( $current_category === $category_slug && ! empty( $submitted_votes ) ) {
						$category_votes = $submitted_votes;
					} elseif ( '' !== $voter_name_value && ( '' === $limit_prefill_category || $limit_prefill_category === $category_slug ) ) {
						$existing_scores = $this->votes_repo->get_votes_by_voter( (int) $competition->id, $category_slug, $voter_name_value );
						$category_votes  = $this->sanitize_vote_selections( $existing_scores, $score_matrix );
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
								/* translators: %d: number of scoring options */
								_n(
									'Assign points to each image using the controls below. You have %d score option available.',
									'Assign points to each image using the controls below. You have %d score options available.',
									count( $score_matrix ),
									'photo-competition-manager'
								),
								count( $score_matrix )
							)
						);
					?>
							</p>
							<p>
					<?php
					$score_labels = array_map( 'number_format_i18n', $score_matrix );
					echo esc_html(
						sprintf(
							/* translators: %s: comma-separated score values */
							__( 'Points awarded: %s', 'photo-competition-manager' ),
							implode( ', ', $score_labels )
						)
					);
					?>
				</p>
				<p>
					<?php esc_html_e( 'You can assign the same score to multiple images.', 'photo-competition-manager' ); ?>
				</p>
				<p><strong><?php esc_html_e( 'You must vote for all images to submit your votes.', 'photo-competition-manager' ); ?></strong></p>
			</div>

						<!-- Voting form -->
						<form method="post" class="voting-form">
							<?php wp_nonce_field( 'photo_competition_vote', 'photo_competition_vote_nonce' ); ?>
							<input type="hidden" name="category" value="<?php echo esc_attr( $category_slug ); ?>" />

							<p>
								<label for="voter_name_<?php echo esc_attr( $category_slug ); ?>">
									<?php esc_html_e( 'Your Name:', 'photo-competition-manager' ); ?>
									<span class="required">*</span>
								</label>
								<input
									type="text"
									id="voter_name_<?php echo esc_attr( $category_slug ); ?>"
									name="voter_name"
									value="<?php echo esc_attr( $voter_name_value ); ?>"
									required
								/>
							</p>

							<?php if ( $password_enabled ) : ?>
								<p>
									<label for="voting_password_<?php echo esc_attr( $category_slug ); ?>">
										<?php esc_html_e( 'Voting Password:', 'photo-competition-manager' ); ?>
										<span class="required">*</span>
									</label>
									<input
										type="text"
										id="voting_password_<?php echo esc_attr( $category_slug ); ?>"
										name="voting_password"
										value="<?php echo esc_attr( $password_value ); ?>"
										required
									/>
									<small><?php esc_html_e( 'Password is not case-sensitive', 'photo-competition-manager' ); ?></small>
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
												<?php
												// translators: %d: image random number.
												$alt = sprintf( __( 'Image %d', 'photo-competition-manager' ), $image->random_number );
												?>
												<?php if ( $click_image_to_zoom ) : ?>
													<a href="<?php echo esc_url( $image_url ); ?>" target="_blank" rel="noopener noreferrer" class="image-link">
														<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" />
													</a>
												<?php else : ?>
													<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" />
												<?php endif; ?>
											<?php else : ?>
												<div class="image-unavailable"><?php esc_html_e( 'Image unavailable', 'photo-competition-manager' ); ?></div>
											<?php endif; ?>
											<div class="image-number">#<?php echo esc_html( $image->random_number ); ?></div>
										</div>
									<?php
									$selected_score = (string) ( $category_votes[ $image->id ] ?? '' );
									$field_name     = 'votes[' . $image->id . ']';
									$control_id     = 'vote_' . $category_slug . '_' . $image->id;
									$this->render_vote_selector_control(
										$field_name,
										$control_id,
										$score_matrix,
										$voting_ui_type,
										$selected_score
									);
									?>
							</div>
						<?php endforeach; ?>
					</div>

							<div class="voting-submit">
								<button type="submit" name="photo_competition_vote" class="button button-primary button-large">
									<?php esc_html_e( 'Submit Votes', 'photo-competition-manager' ); ?>
								</button>
							</div>
						</form>

						<hr />
					</div>
				<?php endforeach; ?>

			</div>
		</div>
		<?php
	}

	/**
	 * Collect sanitized submission values for password-based voting.
	 *
	 * @param array $request  Request array (typically $_POST) already nonce-verified by the caller.
	 * @param array $settings Competition settings.
	 * @return array{
	 *     voter_name:string,
	 *     category:string,
	 *     voting_password:string,
	 *     votes:array<int,float>
	 * }
	 */
	private function collect_password_submission_data( array $request, array $settings ): array {
		$voter_name      = isset( $request['voter_name'] ) ? sanitize_text_field( wp_unslash( $request['voter_name'] ) ) : '';
		$category        = isset( $request['category'] ) ? sanitize_text_field( wp_unslash( $request['category'] ) ) : '';
		$voting_password = isset( $request['voting_password'] ) ? sanitize_text_field( wp_unslash( $request['voting_password'] ) ) : '';
		$votes           = $this->collect_vote_selections_from_request( $request, $settings );

		return array(
			'voter_name'      => $voter_name,
			'category'        => $category,
			'voting_password' => $voting_password,
			'votes'           => $votes,
		);
	}

	/**
	 * Collect sanitized vote selections from the given request array.
	 *
	 * @param array $request  Request array (typically $_POST) already nonce-verified by the caller.
	 * @param array $settings Competition settings.
	 * @return array<int, float> Sanitized vote selections keyed by image ID.
	 */
	private function collect_vote_selections_from_request( array $request, array $settings ): array {
		if ( ! isset( $request['votes'] ) || ! is_array( $request['votes'] ) ) {
			return array();
		}

		$raw_votes = wp_unslash( $request['votes'] );
		if ( ! is_array( $raw_votes ) ) {
			return array();
		}

		$voting_config   = Competition_Settings::get_voting_config( $settings );
		$score_matrix    = $voting_config['score_matrix'] ?? array( 9, 8, 7, 6, 5 );
		$sanitized_votes = $this->sanitize_vote_selections( $raw_votes, $score_matrix );

		return $sanitized_votes;
	}

	/**
	 * Sanitize vote selections by enforcing valid image IDs and allowed score values.
	 *
	 * @param array<int|string, mixed> $votes          Raw vote selections.
	 * @param array<int, int>          $allowed_scores Allowed score values.
	 * @return array<int, int> Sanitized vote selections keyed by image ID.
	 */
	private function sanitize_vote_selections( array $votes, array $allowed_scores ): array {
		$sanitized = array();

		if ( empty( $allowed_scores ) ) {
			return $sanitized;
		}

		$allowed_lookup = array_flip( array_map( 'intval', $allowed_scores ) );

		foreach ( $votes as $image_id => $raw_score ) {
			$image_id = absint( $image_id );
			$score    = (int) $raw_score;

			if ( $image_id < 1 ) {
				continue;
			}

			if ( ! isset( $allowed_lookup[ $score ] ) ) {
				continue;
			}

			$sanitized[ $image_id ] = $score;
		}

		return $sanitized;
	}

	/**
	 * Retrieve the persisted voter cookie values.
	 *
	 * @return array{name:string,password:string}
	 */
	private function get_voter_cookie(): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Value is sanitized after json_decode.
		if ( empty( $_COOKIE['photo_competition_voter'] ) ) {
			return array(
				'name'     => '',
				'password' => '',
			);
		}

		$raw_cookie = wp_unslash( $_COOKIE['photo_competition_voter'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Value is sanitized after json_decode.
		if ( ! is_string( $raw_cookie ) ) {
			return array(
				'name'     => '',
				'password' => '',
			);
		}

		$decoded = json_decode( $raw_cookie, true );

		if ( ! is_array( $decoded ) ) {
			return array(
				'name'     => '',
				'password' => '',
			);
		}

		$name     = isset( $decoded['name'] ) ? sanitize_text_field( $decoded['name'] ) : '';
		$password = isset( $decoded['password'] ) ? sanitize_text_field( $decoded['password'] ) : '';

		return array(
			'name'     => $name,
			'password' => $password,
		);
	}

	/**
	 * Persist voter name and password in a long-lived cookie.
	 *
	 * @param string $name     Voter name.
	 * @param string $password Voting password (if applicable).
	 * @return void
	 */
	private function refresh_voter_cookie( string $name, string $password ): void {
		// Only set cookies over HTTPS.
		if ( ! $this->is_https_connection() ) {
			return;
		}

		$payload = array(
			'name'     => $name,
			'password' => $password, // Store password for accessibility on mobile devices.
		);

		setcookie(
			'photo_competition_voter',
			wp_json_encode( $payload ),
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => true, // Always require HTTPS for cookies.
				'samesite' => 'Lax',
				'httponly' => true, // Prevent JavaScript access for security.
			)
		);

		// Make the cookie immediately available during this request.
		$_COOKIE['photo_competition_voter'] = wp_json_encode( $payload );
	}

	/**
	 * Check if the current connection is using HTTPS.
	 *
	 * @return bool True if HTTPS is being used, false otherwise.
	 */
	private function is_https_connection(): bool {
		return is_ssl();
	}

	/**
	 * Render HTTPS required notice.
	 *
	 * @return string HTML notice.
	 */
	private function render_https_required_notice(): string {
		$https_url = '';
		if ( isset( $_SERVER['HTTP_HOST'] ) && isset( $_SERVER['REQUEST_URI'] ) ) {
			$https_url = 'https://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		}

		ob_start();
		?>
		<div class="photo-comp-voting">
			<div class="notice notice-error">
				<h3><?php esc_html_e( 'Secure Connection Required', 'photo-competition-manager' ); ?></h3>
				<p>
					<?php esc_html_e( 'For security reasons, voting requires a secure HTTPS connection. This protects your voting password and personal information.', 'photo-competition-manager' ); ?>
				</p>
				<?php if ( $https_url ) : ?>
					<p>
						<a href="<?php echo esc_url( $https_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Switch to Secure Connection', 'photo-competition-manager' ); ?>
						</a>
					</p>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'If you continue to see this message after switching to HTTPS, please contact your site administrator.', 'photo-competition-manager' ); ?>
				</p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
