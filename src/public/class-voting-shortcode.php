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
use PhotoCompetitionManager\Service\Ballots;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Service\Link_Voter;
use PhotoCompetitionManager\Service\Named_Voter;
use PhotoCompetitionManager\Support\Competition_Settings;
use function PhotoCompetitionManager\Support\format_site_date;
use function PhotoCompetitionManager\Support\utc_time;

/**
 * Shortcode renderer for competition voting (token- and password-based).
 *
 * Renders the voting page. Ballots are cast by handle_ballot() on
 * template_redirect, through the Ballots module.
 *
 * @since 0.1.0
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
	 * Entries module.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Ballots module.
	 *
	 * @var Ballots
	 */
	private $ballots;

	/**
	 * A ballot refused earlier in this request, for the page to show with the
	 * voter's form as they filled it in.
	 *
	 * @var array{error:\WP_Error,name:string,password:string,category:string,scores:array}|null
	 */
	private $refused = null;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Images_Repository|null       $images_repo       Images repository.
	 * @param Votes_Repository|null        $votes_repo        Votes repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 * @param Voting_Token_Repository|null $token_repo        Token repository.
	 * @param Email_Service|null           $email_service     Email service.
	 * @param Entries|null                 $entries           Entries module.
	 * @param Ballots|null                 $ballots           Ballots module.
	 * @param Competition_Workflow|null    $workflow          Competition workflow.
	 */
	public function __construct(
		?Competitions_Repository $competitions_repo = null,
		?Images_Repository $images_repo = null,
		?Votes_Repository $votes_repo = null,
		?Members_Repository $members_repo = null,
		?Voting_Token_Repository $token_repo = null,
		?Email_Service $email_service = null,
		?Entries $entries = null,
		?Ballots $ballots = null,
		?Competition_Workflow $workflow = null
	) {
		$this->competitions_repo = $competitions_repo ? $competitions_repo : new Competitions_Repository();
		$this->workflow          = $workflow ? $workflow : new Competition_Workflow( $this->competitions_repo );
		$this->images_repo       = $images_repo ? $images_repo : new Images_Repository();
		$this->votes_repo        = $votes_repo ? $votes_repo : new Votes_Repository();
		$this->members_repo      = $members_repo ? $members_repo : new Members_Repository();
		$this->token_repo        = $token_repo ? $token_repo : new Voting_Token_Repository();
		$this->email_service     = $email_service ? $email_service : new Email_Service();
		$this->entries           = $entries ? $entries : new Entries( $this->competitions_repo, $this->images_repo, $this->members_repo );
		$this->ballots           = $ballots ? $ballots : new Ballots( $this->workflow, $this->votes_repo, $this->token_repo, $this->members_repo, $this->images_repo );
	}

	/**
	 * Register shortcode.
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( 'competition_voting', array( $this, 'render' ) );
		add_action( 'template_redirect', array( $this, 'handle_ballot' ) );
	}

	/**
	 * Cast a posted ballot, before the page renders. A cast ballot, or one
	 * already cast, redirects to the page with ?ballot=cast or
	 * ?ballot=already_cast; a refused one is kept for render() to show.
	 *
	 * @return void
	 */
	public function handle_ballot(): void {
		if ( ! isset( $_POST['photo_competition_vote'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below, once the competition says which form this is.
			return;
		}

		$competition = $this->competitions_repo->find_current_active();
		if ( ! $competition ) {
			return;
		}

		$voting   = Competition_Settings::get_voting_config( Competition_Settings::parse( $competition->settings ) );
		$name     = '';
		$password = '';

		if ( 'token' === ( $voting['auth_mode'] ?? 'password' ) ) {
			check_admin_referer( 'photo_competition_vote_with_token', 'photo_competition_vote_nonce' );
			$token    = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
			$voter    = $this->ballots->link_voter( $competition, $token );
			$category = is_wp_error( $voter ) ? '' : $voter->category();
		} else {
			check_admin_referer( 'photo_competition_vote', 'photo_competition_vote_nonce' );
			$name     = isset( $_POST['voter_name'] ) ? sanitize_text_field( wp_unslash( $_POST['voter_name'] ) ) : '';
			$password = isset( $_POST['voting_password'] ) ? sanitize_text_field( wp_unslash( $_POST['voting_password'] ) ) : '';
			$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
			$voter    = $this->ballots->named_voter( $competition, $name, $password );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Ballots::cast() accepts only whole-number scores in the matrix.
		$scores = isset( $_POST['votes'] ) && is_array( $_POST['votes'] ) ? wp_unslash( $_POST['votes'] ) : array();
		$result = is_wp_error( $voter ) ? $voter : $this->ballots->cast( $competition, $category, $voter, $scores );

		if ( true === $result || 'already_cast' === $result->get_error_code() ) {
			if ( $voter instanceof Named_Voter ) {
				$voter->remember( $password );
			}

			wp_safe_redirect( add_query_arg( 'ballot', true === $result ? 'cast' : 'already_cast', get_permalink() ) );
			exit;
		}

		$this->refused = array(
			'error'    => $result,
			'name'     => $name,
			'password' => $password,
			'category' => $category,
			'scores'   => array_filter( $scores, 'is_scalar' ),
		);
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
				PHOTO_COMPETITION_MANAGER_URL . 'assets/build/style-index.css',
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

		// Find the most recent active competition.
		$competition = $this->competitions_repo->find_current_active();

		if ( ! $competition ) {
			return '<p class="error">' . esc_html__( 'No active competition found.', 'photo-competition-manager' ) . '</p>';
		}

		$settings      = Competition_Settings::parse( $competition->settings );
		$voting_config = Competition_Settings::get_voting_config( $settings );
		$auth_mode     = $voting_config['auth_mode'] ?? 'password';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks which thank-you message to show.
		$outcome = isset( $_GET['ballot'] ) ? sanitize_key( wp_unslash( $_GET['ballot'] ) ) : '';
		if ( 'cast' === $outcome || 'already_cast' === $outcome ) {
			return $this->render_outcome( $competition, $outcome, $auth_mode );
		}

		// Branch based on authentication mode.
		if ( 'token' === $auth_mode ) {
			return $this->render_token_based_voting( $competition, $settings );
		} else {
			return $this->render_password_based_voting( $competition, $settings );
		}
	}

	/**
	 * The page after a ballot was cast, or turned out to be cast already.
	 *
	 * @param object $competition Competition object.
	 * @param string $outcome     cast or already_cast.
	 * @param string $auth_mode   token or password.
	 * @return string
	 */
	private function render_outcome( object $competition, string $outcome, string $auth_mode ): string {
		if ( 'already_cast' === $outcome ) {
			$message = $this->already_voted_notice();
		} elseif ( 'token' === $auth_mode ) {
			$message = '<p class="success">' . esc_html__( 'Thank you for voting! Your votes have been recorded anonymously.', 'photo-competition-manager' ) . '</p>';
		} else {
			$message = '<p class="success">' . esc_html__( 'Thank you for voting! Your votes have been recorded.', 'photo-competition-manager' ) . '</p>';
		}

		return '<div class="photo-comp-voting">'
			. '<h2>' . esc_html( $competition->title ) . ' - ' . esc_html__( 'Voting', 'photo-competition-manager' ) . '</h2>'
			. wp_kses_post( $message )
			. '<p><button type="button" class="button photo-comp-redirect-btn" data-redirect-url="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Check If Voting Is Open', 'photo-competition-manager' ) . '</button></p>'
			. '</div>';
	}

	/**
	 * Why the ballot posted in this request was refused, as a notice.
	 *
	 * @return string
	 */
	private function refused_notice(): string {
		return $this->refused ? '<p class="error">' . esc_html( $this->refused['error']->get_error_message() ) . '</p>' : '';
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a read-only token from the URL for magic-link auth; sanitized and hashed by Ballots.
		$token_string = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$voter        = $this->ballots->link_voter( $competition, $token_string );
		$voter        = is_wp_error( $voter ) ? null : $voter;

		// A closed category says so on the page itself.
		$message = $this->refused && 'voting_closed' !== $this->refused['error']->get_error_code() ? $this->refused_notice() : '';

		// Handle token request form submission.
		if ( isset( $_POST['photo_competition_request_voting_token'] ) && check_admin_referer( 'photo_competition_request_voting_token', 'photo_competition_voting_nonce' ) ) {
			$message = $this->handle_token_request( $competition, $_POST );
		}

		ob_start();
		$this->render_voting_interface( $competition, $message, $voter, $settings, $this->refused['scores'] ?? array(), $token_string );
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
		$form  = $this->refused ?? Named_Voter::remembered() + array(
			'category' => '',
			'scores'   => array(),
		);
		$voter = $this->ballots->named_voter( $competition, $form['name'], $form['password'] );
		$voter = is_wp_error( $voter ) ? null : $voter;

		ob_start();
		$this->render_password_voting_interface( $competition, $this->refused_notice(), $settings, $form, $voter );
		$output = ob_get_clean();
		return $output ? $output : '';
	}

	/**
	 * Handle token request form submission.
	 *
	 * @param object $competition Competition object.
	 * @param array  $request     Request array (typically $_POST) already nonce-verified by the caller.
	 * @return string Message to display.
	 */
	private function handle_token_request( object $competition, array $request ): string {
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
		if ( ! $this->workflow->is_accepting_votes( $competition, $category ) ) {
			return '<p class="error">' . esc_html__( 'Voting is not open for this category.', 'photo-competition-manager' ) . '</p>';
		}

		// Find member by email silently.
		$member = $this->members_repo->find_by_email( $member_email );
		if ( ! $member || ! $member->active ) {
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
			$member->name,
			$competition->title,
			$voting_url,
			format_site_date( $competition->close_date ),
			(int) $competition->id
		);

		if ( ! $email_sent ) {
			return '<p class="error">' . esc_html__( 'Failed to send email. Please contact the administrator.', 'photo-competition-manager' ) . '</p>';
		}

		return $generic_success;
	}

	/**
	 * Render voting interface for token-based voting.
	 *
	 * @param object            $competition     Competition object.
	 * @param string            $message         Message to display.
	 * @param Link_Voter|null   $voter           The voter, if their link checks out.
	 * @param array             $settings        Competition settings.
	 * @param array<int,string> $submitted_votes The refused ballot's scores, to fill the form back in.
	 * @param string            $token_string    Raw voting token from the URL, kept on the "Check If Voting Is Open" link.
	 * @return void
	 */
	private function render_voting_interface( object $competition, string $message, ?Link_Voter $voter, array $settings, array $submitted_votes, string $token_string ): void {
		$voting_config = Competition_Settings::get_voting_config( $settings );
		$categories    = Competition_Settings::get_categories( $settings );

		// Filter to only show categories where voting is open.
		$open_categories   = $this->workflow->categories_accepting_votes( $competition );
		$voting_categories = array_filter(
			$categories,
			function ( $cat ) use ( $open_categories ) {
				return in_array( $cat['slug'], $open_categories, true );
			}
		);

		// Get score matrix, UI type, and image click setting.
		$score_matrix        = $voting_config['score_matrix'];
		$click_image_to_zoom = $voting_config['click_image_to_zoom'] ?? false;
		$voting_ui_type      = Competition_Settings::get_voting_ui_type( $settings );

		?>
		<div class="photo-comp-voting">
			<h2><?php echo esc_html( $competition->title ); ?> - <?php esc_html_e( 'Voting', 'photo-competition-manager' ); ?></h2>

			<?php if ( $message ) : ?>
				<?php echo wp_kses_post( $message ); ?>
			<?php endif; ?>

			<?php if ( empty( $voting_categories ) ) : ?>
				<p class="notice"><?php esc_html_e( 'Voting is not currently open for any category. Please check back later.', 'photo-competition-manager' ); ?></p>
				<p>
					<button type="button" class="button photo-comp-redirect-btn" data-redirect-url="<?php echo esc_url( $token_string ? add_query_arg( 'token', rawurlencode( $token_string ), get_permalink() ) : get_permalink() ); ?>">
						<?php esc_html_e( 'Check If Voting Is Open', 'photo-competition-manager' ); ?>
					</button>
				</p>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( ! $voter ) : ?>
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
				$category = $voter->category();

				// Verify voting is still open for this category.
				if ( ! $this->workflow->is_accepting_votes( $competition, $category ) ) {
					// Another category is open, so drop the token: the bare page lets the voter request a link for it.
					echo '<p class="notice">' . esc_html__( 'Voting is no longer open for this category.', 'photo-competition-manager' ) . '</p>';
					echo '<p><button type="button" class="button photo-comp-redirect-btn" data-redirect-url="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Check If Voting Is Open', 'photo-competition-manager' ) . '</button></p>';
					return;
				}

				if ( $this->ballots->has_cast( $competition, $category, $voter ) ) {
					echo wp_kses_post( $this->already_voted_notice() );
					echo '<p><button type="button" class="button photo-comp-redirect-btn" data-redirect-url="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Check If Voting Is Open', 'photo-competition-manager' ) . '</button></p>';
					return;
				}

				// Get category label.
				$category_label = array_column( $voting_categories, 'label', 'slug' )[ $category ] ?? $category;

				// Get images for this category in randomized order to prevent identification across categories.
				$images = $this->images_repo->find_by_competition( (int) $competition->id, $category );
				$images = $this->images_repo->shuffle_deterministic( $images, (int) $competition->id, $category );

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
								$voter->member()->name
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
							$urls      = $this->entries->urls( $competition, $image );
							$image_url = $urls['full'];
							$thumb_url = $urls['thumb'] ? $urls['thumb'] : $urls['full'];
							?>
							<div class="voting-image-item" data-image-id="<?php echo esc_attr( $image->id ); ?>">
								<div class="image-wrapper">
									<?php if ( '' !== $image_url ) : ?>
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
	 * @param object           $competition Competition object.
	 * @param string           $message     Message to display.
	 * @param array            $settings    Competition settings.
	 * @param array            $form        The form as the voter filled it in or this device remembers it: name, password, category and scores.
	 * @param Named_Voter|null $voter       The voter, if their name and password check out.
	 * @return void
	 */
	private function render_password_voting_interface( object $competition, string $message, array $settings, array $form, ?Named_Voter $voter ): void {
		$voting_config = Competition_Settings::get_voting_config( $settings );
		$categories    = Competition_Settings::get_categories( $settings );

		// Filter to only show categories where voting is open.
		$open_categories   = $this->workflow->categories_accepting_votes( $competition );
		$voting_categories = array_filter(
			$categories,
			function ( $cat ) use ( $open_categories ) {
				return in_array( $cat['slug'], $open_categories, true );
			}
		);

		// Get score matrix and image click setting.
		$score_matrix        = $voting_config['score_matrix'];
		$voting_password     = $voting_config['password'] ?? '';
		$password_enabled    = '' !== $voting_password;
		$click_image_to_zoom = $voting_config['click_image_to_zoom'] ?? false;
		$voting_ui_type      = Competition_Settings::get_voting_ui_type( $settings );

		?>
		<div class="photo-comp-voting">
			<h2><?php echo esc_html( $competition->title ); ?> - <?php esc_html_e( 'Voting', 'photo-competition-manager' ); ?></h2>

			<?php if ( $message ) : ?>
				<?php echo wp_kses_post( $message ); ?>
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
					$images        = $this->images_repo->shuffle_deterministic( $images, (int) $competition->id, $category_slug );

					if ( empty( $images ) ) {
						continue;
					}

					if ( $voter && $this->ballots->has_cast( $competition, $category_slug, $voter ) ) {
						echo '<div class="voting-category-section voting-category-complete">';
						echo '<h3>' . esc_html( $category_data['label'] ) . '</h3>';
						echo wp_kses_post( $this->already_voted_notice() );
						echo '<p><button type="button" class="button photo-comp-redirect-btn" data-redirect-url="' . esc_url( get_permalink() ) . '">' . esc_html__( 'Check If Voting Is Open', 'photo-competition-manager' ) . '</button></p>';
						echo '</div>';
						continue;
					}

					$category_votes = $form['category'] === $category_slug ? $form['scores'] : array();
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
									value="<?php echo esc_attr( $form['name'] ); ?>"
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
										value="<?php echo esc_attr( $form['password'] ); ?>"
										required
									/>
									<small><?php esc_html_e( 'Password is not case-sensitive', 'photo-competition-manager' ); ?></small>
								</p>
							<?php endif; ?>

							<div class="images-grid">
								<?php foreach ( $images as $image ) : ?>
									<?php
									$urls      = $this->entries->urls( $competition, $image );
									$image_url = $urls['full'];
									$thumb_url = $urls['thumb'] ? $urls['thumb'] : $urls['full'];
									?>
									<div class="voting-image-item" data-image-id="<?php echo esc_attr( $image->id ); ?>">
										<div class="image-wrapper">
											<?php if ( '' !== $image_url ) : ?>
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
	 * The notice shown to a voter whose ballot for the category is already saved.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	private function already_voted_notice(): string {
		return '<p class="notice notice-success">' . esc_html__( 'Thank you! Your votes for this category have already been recorded.', 'photo-competition-manager' ) . '</p>';
	}
}
