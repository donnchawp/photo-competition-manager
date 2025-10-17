<?php
/**
 * Handle upload form shortcode.
 *
 * @package ClubCompetitions\Frontend
 */

namespace ClubCompetitions\Frontend;

use ClubCompetitions\Repository\CompetitionsRepository;
use ClubCompetitions\Repository\MembersRepository;
use ClubCompetitions\Repository\UploadTokenRepository;
use ClubCompetitions\Service\EmailService;
use ClubCompetitions\Service\UploadHandler;
use ClubCompetitions\Support\CompetitionSettings;

class UploadShortcode {

	/**
	 * Upload handler.
	 *
	 * @var UploadHandler
	 */
	private $upload_handler;

	/**
	 * Competitions repository.
	 *
	 * @var CompetitionsRepository
	 */
	private $competitions_repo;

	/**
	 * Members repository.
	 *
	 * @var MembersRepository
	 */
	private $members_repo;

	/**
	 * Upload token repository.
	 *
	 * @var UploadTokenRepository
	 */
	private $token_repo;

	/**
	 * Email service.
	 *
	 * @var EmailService
	 */
	private $email_service;

	/**
	 * Constructor.
	 *
	 * @param UploadHandler|null          $upload_handler    Upload handler.
	 * @param CompetitionsRepository|null $competitions_repo Competitions repository.
	 * @param MembersRepository|null      $members_repo      Members repository.
	 * @param UploadTokenRepository|null  $token_repo        Token repository.
	 * @param EmailService|null           $email_service     Email service.
	 */
	public function __construct(
		?UploadHandler $upload_handler = null,
		?CompetitionsRepository $competitions_repo = null,
		?MembersRepository $members_repo = null,
		?UploadTokenRepository $token_repo = null,
		?EmailService $email_service = null
	) {
		$this->upload_handler    = $upload_handler ?: new UploadHandler();
		$this->competitions_repo = $competitions_repo ?: new CompetitionsRepository();
		$this->members_repo      = $members_repo ?: new MembersRepository();
		$this->token_repo        = $token_repo ?: new UploadTokenRepository();
		$this->email_service     = $email_service ?: new EmailService();
	}

	/**
	 * Register shortcode.
	 */
	public function register(): void {
		add_shortcode( 'competition_upload', array( $this, 'render' ) );
	}

	/**
	 * Render upload form shortcode.
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
			'competition_upload'
		);

		if ( empty( $atts['competition'] ) ) {
			return '<p class="error">' . esc_html__( 'Please specify a competition slug.', 'club-competitions' ) . '</p>';
		}

		$competition = $this->competitions_repo->find_by_slug( $atts['competition'] );
		if ( ! $competition ) {
			return '<p class="error">' . esc_html__( 'Competition not found.', 'club-competitions' ) . '</p>';
		}

		$settings = CompetitionSettings::parse( $competition->settings );

		// Check for upload token in URL.
		$token_string = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$token_hash   = $token_string ? hash( 'sha256', $token_string ) : '';
		$token_record = null;
		$member       = null;

		if ( $token_hash ) {
			$debug_file = '/tmp/token-validation.log';
			file_put_contents( $debug_file, '[' . gmdate( 'Y-m-d H:i:s' ) . '] Validating token...' . PHP_EOL, FILE_APPEND );
			file_put_contents( $debug_file, 'Token hash: ' . $token_hash . PHP_EOL, FILE_APPEND );

			$token_record = $this->token_repo->find_valid_token( $token_hash );
			file_put_contents( $debug_file, 'Token found: ' . ( $token_record ? 'YES' : 'NO' ) . PHP_EOL, FILE_APPEND );

			if ( $token_record ) {
				file_put_contents( $debug_file, 'Token competition_id: ' . $token_record->competition_id . PHP_EOL, FILE_APPEND );
				file_put_contents( $debug_file, 'Current competition_id: ' . $competition->id . PHP_EOL, FILE_APPEND );
				file_put_contents( $debug_file, 'Match: ' . ( (int) $token_record->competition_id === (int) $competition->id ? 'YES' : 'NO' ) . PHP_EOL, FILE_APPEND );

				if ( (int) $token_record->competition_id === (int) $competition->id ) {
					$member = $this->members_repo->find( (int) $token_record->member_id );
					file_put_contents( $debug_file, 'Member found: ' . ( $member ? $member->name : 'NO' ) . PHP_EOL, FILE_APPEND );
				}
			}
		}

		// Handle token request form submission.
		$message = '';
		if ( isset( $_POST['club_competitions_request_token'] ) && check_admin_referer( 'club_competitions_request_token', 'club_competitions_nonce' ) ) {
			$message = $this->handle_token_request( $competition );
		}

		// Handle upload with token.
		if ( $token_record && $member && isset( $_POST['club_competitions_upload'] ) && check_admin_referer( 'club_competitions_upload_with_token', 'club_competitions_nonce' ) ) {
			$message = $this->handle_token_upload( $competition->id, $member->id, $token_record );
		}

		// Handle deletion with token.
		if ( $token_record && $member && isset( $_POST['club_competitions_delete'] ) && check_admin_referer( 'club_competitions_delete_with_token', 'club_competitions_delete_nonce' ) ) {
			$message = $this->handle_token_deletion( $competition->id, $member->id, $token_record );
		}

		ob_start();
		$this->render_form( $competition, $message, $token_record, $member, $settings, $token_string );
		$output = ob_get_clean();
		return $output ? $output : '';
	}

	/**
	 * Handle token request form submission.
	 *
	 * @param object $competition Competition object.
	 * @return string Message to display.
	 */
	private function handle_token_request( $competition ): string {
		$member_email = isset( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';

		if ( empty( $member_email ) ) {
			return '<p class="error">' . esc_html__( 'Please enter your email address.', 'club-competitions' ) . '</p>';
		}

		// Generic success message for security (prevents email enumeration).
		$generic_success = '<p class="success">' . esc_html__( 'If this email is registered, you will receive an upload link shortly. Please check your inbox.', 'club-competitions' ) . '</p>';

		// Find member by email silently.
		$member = $this->members_repo->find_by_email( $member_email );
		if ( ! $member ) {
			// Return success message but don't send email.
			return $generic_success;
		}

		// Check for recent token to prevent spam.
		if ( $this->token_repo->has_recent_token( $member->id, $competition->id ) ) {
			return $generic_success;
		}

		// Generate secure token.
		$token_string = bin2hex( random_bytes( 32 ) );
		$token_hash   = hash( 'sha256', $token_string );
		$expires_at   = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + HOUR_IN_SECONDS );

		// Create token record.
		$token_id = $this->token_repo->create( $member->id, $competition->id, $token_hash, $expires_at );

		if ( is_wp_error( $token_id ) ) {
			return '<p class="error">' . esc_html__( 'Failed to create upload link. Please try again.', 'club-competitions' ) . '</p>';
		}

		// Build magic link.
		$upload_url = add_query_arg(
			array(
				'token'       => $token_string,
				'competition' => $competition->slug,
			),
			get_permalink()
		);

		// Send email.
		$email_sent = $this->email_service->send_upload_link(
			$member_email,
			$member->name,
			$competition->title,
			$upload_url
		);

		if ( ! $email_sent ) {
			return '<p class="error">' . esc_html__( 'Failed to send email. Please contact the administrator.', 'club-competitions' ) . '</p>';
		}

		return $generic_success;
	}

	/**
	 * Handle upload with valid token.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param int    $member_id      Member ID.
	 * @param object $token_record   Token record.
	 * @return string Message to display.
	 */
	private function handle_token_upload( int $competition_id, int $member_id, $token_record ): string {
		$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';

		if ( empty( $category ) ) {
			return '<p class="error">' . esc_html__( 'Please select a category.', 'club-competitions' ) . '</p>';
		}

		// Check file upload.
		if ( empty( $_FILES['image'] ) || UPLOAD_ERR_NO_FILE === $_FILES['image']['error'] ) {
			return '<p class="error">' . esc_html__( 'Please select an image to upload.', 'club-competitions' ) . '</p>';
		}

		// Process upload.
		$result = $this->upload_handler->handle_upload( $competition_id, $member_id, $category, $_FILES['image'] );

		if ( is_wp_error( $result ) ) {
			return '<p class="error">' . esc_html( $result->get_error_message() ) . '</p>';
		}

		// Mark token as used after successful upload.
		$this->token_repo->mark_as_used( (int) $token_record->id );

		return '<p class="success">' . esc_html__( 'Image uploaded successfully! Your upload link has been used and is no longer valid.', 'club-competitions' ) . '</p>';
	}

	/**
	 * Handle deletion with valid token.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param int    $member_id      Member ID.
	 * @param object $token_record   Token record.
	 * @return string Message to display.
	 */
	private function handle_token_deletion( int $competition_id, int $member_id, $token_record ): string {
		$image_id = isset( $_POST['image_id'] ) ? absint( $_POST['image_id'] ) : 0;

		if ( ! $image_id ) {
			return '<p class="error">' . esc_html__( 'Invalid deletion request.', 'club-competitions' ) . '</p>';
		}

		$result = $this->upload_handler->delete_submission( $image_id, $member_id, $competition_id );

		if ( is_wp_error( $result ) ) {
			return '<p class="error">' . esc_html( $result->get_error_message() ) . '</p>';
		}

		return '<p class="success">' . esc_html__( 'Image deleted successfully.', 'club-competitions' ) . '</p>';
	}

	/**
	 * Convert file extensions to accept attribute format.
	 *
	 * @param array<string> $formats Array of file extensions (e.g., ['jpg', 'jpeg']).
	 * @return string Comma-separated MIME types (e.g., 'image/jpg,image/jpeg').
	 */
	private function get_accept_formats( array $formats ): string {
		$mime_types = array();
		foreach ( $formats as $ext ) {
			$mime_types[] = 'image/' . $ext;
		}
		return implode( ',', $mime_types );
	}

	/**
	 * Render the upload form.
	 *
	 * @param object      $competition   Competition object.
	 * @param string      $message       Message to display.
	 * @param object|null $token_record  Token record if validated.
	 * @param object|null $member        Member object if authenticated.
	 * @param array       $settings      Competition settings.
	 * @param string      $token_string  Token string for URL preservation.
	 */
	private function render_form( $competition, string $message, $token_record, $member, array $settings, string $token_string ): void {
		$categories  = CompetitionSettings::get_categories( $settings );
		$constraints = CompetitionSettings::get_upload_constraints( $settings );

		// Get existing submissions and filter categories if member is authenticated.
		$submissions          = array();
		$available_categories = array();

		if ( $member && $token_record ) {
			$submissions = $this->upload_handler->get_member_submissions( $competition->id, $member->id );

			// Group submissions by category for quota checking.
			$submissions_by_category = array();
			foreach ( $submissions as $submission ) {
				if ( ! isset( $submissions_by_category[ $submission->category ] ) ) {
					$submissions_by_category[ $submission->category ] = array();
				}
				$submissions_by_category[ $submission->category ][] = $submission;
			}

			// Filter categories to only show those with remaining quota.
			foreach ( $categories as $cat ) {
				$count = isset( $submissions_by_category[ $cat['slug'] ] ) ? count( $submissions_by_category[ $cat['slug'] ] ) : 0;
				if ( $count < $cat['quota'] ) {
					$available_categories[] = array_merge( $cat, array( 'current' => $count ) );
				}
			}
		} else {
			// If no member logged in, show all categories.
			foreach ( $categories as $cat ) {
				$available_categories[] = array_merge( $cat, array( 'current' => 0 ) );
			}
		}
		?>
		<div class="club-competitions-upload">
			<h2><?php echo esc_html( $competition->title ); ?></h2>

			<?php if ( $message ) : ?>
				<?php echo wp_kses_post( $message ); ?>
			<?php endif; ?>

			<?php if ( 'active' !== $competition->status ) : ?>
				<p class="notice"><?php esc_html_e( 'This competition is not currently open for submissions.', 'club-competitions' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( ! $token_record || ! $member ) : ?>
				<!-- Token request form -->
				<div class="token-request-section">
					<p><?php esc_html_e( 'To upload images, please enter your registered email address. We will send you a secure upload link.', 'club-competitions' ); ?></p>

					<form method="post" class="competition-token-request-form">
						<?php wp_nonce_field( 'club_competitions_request_token', 'club_competitions_nonce' ); ?>

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
							<button type="submit" name="club_competitions_request_token" class="button">
								<?php esc_html_e( 'Send Upload Link', 'club-competitions' ); ?>
							</button>
						</p>
					</form>
				</div>
			<?php else : ?>
				<!-- Member is authenticated with valid token, show submissions and upload form -->
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

				<?php
				// Show existing submissions with individual delete forms.
				if ( ! empty( $submissions ) ) :
					?>
					<div class="member-submissions">
						<h3><?php esc_html_e( 'Your Submissions', 'club-competitions' ); ?></h3>
						<div class="submissions-grid">
							<?php foreach ( $submissions as $image ) : ?>
								<div class="submission-item">
									<img src="<?php echo esc_url( $image->thumbnail_url ); ?>" alt="" />
									<p class="category-label">
										<?php
										// Find category label.
										$category_label = $image->category;
										foreach ( $categories as $cat ) {
											if ( $cat['slug'] === $image->category ) {
												$category_label = $cat['label'];
												break;
											}
										}
										echo esc_html( $category_label );
										?>
									</p>
									<form method="post" class="delete-form">
										<?php wp_nonce_field( 'club_competitions_delete_with_token', 'club_competitions_delete_nonce' ); ?>
										<input type="hidden" name="image_id" value="<?php echo esc_attr( $image->id ); ?>" />
										<button
											type="submit"
											name="club_competitions_delete"
											class="button button-small button-link-delete"
											onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this image?', 'club-competitions' ) ); ?>');"
										>
											<?php esc_html_e( 'Delete', 'club-competitions' ); ?>
										</button>
									</form>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( empty( $available_categories ) ) : ?>
					<p class="notice">
						<?php esc_html_e( 'You have reached the maximum number of submissions for all categories. Thank you!', 'club-competitions' ); ?>
					</p>
				<?php else : ?>
					<!-- Upload form (separate from delete forms) -->
					<h3><?php esc_html_e( 'Upload New Image', 'club-competitions' ); ?></h3>

					<form method="post" enctype="multipart/form-data" class="competition-upload-form">
						<?php wp_nonce_field( 'club_competitions_upload_with_token', 'club_competitions_nonce' ); ?>

						<p>
							<label for="category">
								<?php esc_html_e( 'Category:', 'club-competitions' ); ?>
								<span class="required">*</span>
							</label>
							<select id="category" name="category" required>
								<option value=""><?php esc_html_e( '-- Select Category --', 'club-competitions' ); ?></option>
								<?php foreach ( $available_categories as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat['slug'] ); ?>">
										<?php
										$remaining = $cat['quota'] - $cat['current'];
										echo esc_html(
											sprintf(
												/* translators: 1: category label, 2: remaining slots, 3: total quota */
												__( '%1$s (%2$d of %3$d remaining)', 'club-competitions' ),
												$cat['label'],
												$remaining,
												$cat['quota']
											)
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>

						<p>
							<label for="image">
								<?php esc_html_e( 'Image:', 'club-competitions' ); ?>
								<span class="required">*</span>
							</label>
							<input
								type="file"
								id="image"
								name="image"
								accept="<?php echo esc_attr( $this->get_accept_formats( $constraints['allowed_formats'] ) ); ?>"
								required
							/>
							<small>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: max file size in MB, 2: max dimensions, 3: allowed formats */
										__( 'Max size: %1$d MB. Max dimensions: %2$d x %2$d px. Formats: %3$s', 'club-competitions' ),
										$constraints['max_file_size_mb'],
										$constraints['max_width'],
										strtoupper( implode( ', ', $constraints['allowed_formats'] ) )
									)
								);
								?>
							</small>
						</p>

						<p>
							<button type="submit" name="club_competitions_upload" class="button">
								<?php esc_html_e( 'Upload Image', 'club-competitions' ); ?>
							</button>
						</p>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
