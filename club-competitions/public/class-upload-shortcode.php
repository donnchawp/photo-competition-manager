<?php
/**
 * Handle upload form shortcode.
 *
 * @package ClubCompetitions\Frontend
 */

namespace ClubCompetitions\Frontend;

use ClubCompetitions\Repository\Competitions_Repository;
use ClubCompetitions\Repository\Members_Repository;
use ClubCompetitions\Repository\Upload_Token_Repository;
use ClubCompetitions\Service\Email_Service;
use ClubCompetitions\Service\Upload_Handler;
use ClubCompetitions\Support\Competition_Settings;

/**
 * Upload shortcode handler.
 *
 * @since 0.1.0
 */
class Upload_Shortcode {

	/**
	 * Upload handler.
	 *
	 * @var Upload_Handler
	 */
	private $upload_handler;

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions_repo;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members_repo;

	/**
	 * Upload token repository.
	 *
	 * @var Upload_Token_Repository
	 */
	private $token_repo;

	/**
	 * Email service.
	 *
	 * @var Email_Service
	 */
	private $email_service;

	/**
	 * Constructor.
	 *
	 * @param Upload_Handler|null          $upload_handler    Upload handler.
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 * @param Upload_Token_Repository|null $token_repo        Token repository.
	 * @param Email_Service|null           $email_service     Email service.
	 */
	public function __construct(
		?Upload_Handler $upload_handler = null,
		?Competitions_Repository $competitions_repo = null,
		?Members_Repository $members_repo = null,
		?Upload_Token_Repository $token_repo = null,
		?Email_Service $email_service = null
	) {
		$this->upload_handler    = $upload_handler ?? new Upload_Handler();
		$this->competitions_repo = $competitions_repo ?? new Competitions_Repository();
		$this->members_repo      = $members_repo ?? new Members_Repository();
		$this->token_repo        = $token_repo ?? new Upload_Token_Repository();
		$this->email_service     = $email_service ?? new Email_Service();
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
	public function render( $atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Shortcode signature requires $atts.

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		$competition = $this->competitions_repo->find_current_active();
		if ( ! $competition ) {
			return '<p class="error">' . esc_html__( 'No active competition is currently accepting uploads.', 'club-competitions' ) . '</p>';
		}

		$settings = Competition_Settings::parse( $competition->settings );

		// Check for upload token in URL.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Magic-link token read only; actions require POST + nonce.
		$token_string = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$token_hash   = $token_string ? hash( 'sha256', $token_string ) : '';
		$token_record = null;
		$member       = null;

		if ( $token_hash ) {
			$token_record = $this->token_repo->find_valid_token( $token_hash );
			if ( $token_record && (int) $token_record->competition_id === (int) $competition->id ) {
				$member = $this->members_repo->find( (int) $token_record->member_id );
			}
		}

		// Handle token request form submission.
		$message = '';
		if (
			isset( $_POST['club_competitions_request_token'] )
			&& check_admin_referer( 'club_competitions_request_token', 'club_competitions_nonce' )
		) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$member_email = isset( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';
			$message      = $this->handle_token_request( $competition, $member_email );
		}

		// Handle upload with token.
		if (
			$token_record && $member
			&& isset( $_POST['club_competitions_upload'] )
			&& check_admin_referer( 'club_competitions_upload_with_token', 'club_competitions_nonce' )
		) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES cannot be sanitized; validated in Upload_Handler::handle_upload().
			$image_file = isset( $_FILES['image'] ) ? $_FILES['image'] : null;
			$message    = $this->handle_token_upload( (int) $competition->id, (int) $member->id, $token_record, $category, $image_file );
		}

		// Handle deletion with token.
		if (
			$token_record && $member
			&& isset( $_POST['club_competitions_delete'] )
			&& check_admin_referer( 'club_competitions_delete_with_token', 'club_competitions_delete_nonce' )
		) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$image_id = isset( $_POST['image_id'] ) ? absint( $_POST['image_id'] ) : 0;
			$message  = $this->handle_token_deletion( (int) $competition->id, (int) $member->id, $token_record, $image_id );
		}

		ob_start();
		$this->render_form( $competition, $message, $token_record, $member, $settings );
		$output = ob_get_clean();
		return $output ? $output : '';
	}

	/**
	 * Handle token request form submission.
	 *
	 * @param object $competition  Competition object.
	 * @param string $member_email Member email (sanitized).
	 * @return string Message to display.
	 */
	private function handle_token_request( $competition, string $member_email ): string {
		if ( empty( $member_email ) ) {
			return '<p class="error">' . esc_html__( 'Please enter your email address.', 'club-competitions' ) . '</p>';
		}

		// Generic success message for security (prevents email enumeration).
		$generic_success = '<p class="success">' . esc_html__( 'If this email is registered, you will receive an upload link shortly. Please check your inbox.', 'club-competitions' ) . '</p>';

		// Delegate creation + email to the token repository.
		$ok = $this->token_repo->send_upload_link_by_email(
			(int) $competition->id,
			$member_email,
			get_permalink()
		);

		if ( ! $ok ) {
			return '<p class="error">' . esc_html__( 'Failed to send email. Please contact the administrator.', 'club-competitions' ) . '</p>';
		}

		return $generic_success;
	}

	/**
	 * Handle upload with valid token.
	 *
	 * @param int        $competition_id Competition ID.
	 * @param int        $member_id      Member ID.
	 * @param object     $token_record   Token record.
	 * @param string     $category       Selected category (sanitized).
	 * @param array|null $image_file     Uploaded file array from $_FILES or null.
	 * @return string Message to display.
	 */
	private function handle_token_upload( int $competition_id, int $member_id, $token_record, string $category, $image_file ): string {
		if ( empty( $category ) ) {
			return '<p class="error">' . esc_html__( 'Please select a category.', 'club-competitions' ) . '</p>';
		}

		// Check file upload.
		if ( empty( $image_file ) || ! is_array( $image_file ) || ( isset( $image_file['error'] ) && UPLOAD_ERR_NO_FILE === $image_file['error'] ) ) {
			return '<p class="error">' . esc_html__( 'Please select an image to upload.', 'club-competitions' ) . '</p>';
		}

		// Process upload.
		$result = $this->upload_handler->handle_upload( $competition_id, $member_id, $category, $image_file );

		if ( is_wp_error( $result ) ) {
			return '<p class="error">' . esc_html( $result->get_error_message() ) . '</p>';
		}

		return '<p class="success">' . esc_html__( 'Image uploaded successfully!', 'club-competitions' ) . '</p>';
	}

	/**
	 * Handle deletion with valid token.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param int    $member_id      Member ID.
	 * @param object $token_record   Token record.
	 * @param int    $image_id       Image ID to delete.
	 * @return string Message to display.
	 */
	private function handle_token_deletion( int $competition_id, int $member_id, $token_record, int $image_id ): string {
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
	 */
	private function render_form( $competition, string $message, $token_record, $member, array $settings ): void {
		$categories  = Competition_Settings::get_categories( $settings );
		$constraints = Competition_Settings::get_upload_constraints( $settings );

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

			<?php
			// Check if uploads have been manually closed.
			$settings       = \ClubCompetitions\Support\Competition_Settings::parse( $competition->settings );
			$uploads_closed = $settings['upload']['uploads_closed'] ?? false;
			if ( $uploads_closed ) :
				?>
				<p class="notice"><?php esc_html_e( 'Image uploads have been closed for this competition.', 'club-competitions' ); ?></p>
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
									<?php
									// Build form action URL with token to preserve it across submissions.
									// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token is read-only for magic-link auth; sanitized below.
									$token_param        = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
									$delete_form_action = add_query_arg( 'token', rawurlencode( $token_param ), get_permalink() );
									?>
									<form method="post" class="delete-form" action="<?php echo esc_url( $delete_form_action ); ?>">
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

					<?php
					// Build form action URL with token to preserve it across submissions.
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token is read-only for magic-link auth; sanitized below.
					$token_param = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
					$form_action = add_query_arg( 'token', rawurlencode( $token_param ), get_permalink() );
					?>
					<form method="post" enctype="multipart/form-data" class="competition-upload-form" action="<?php echo esc_url( $form_action ); ?>">
						<?php wp_nonce_field( 'club_competitions_upload_with_token', 'club_competitions_nonce' ); ?>

						<?php if ( 1 === count( $available_categories ) ) : ?>
							<?php
							$single_category = $available_categories[0];
							$remaining       = $single_category['quota'] - $single_category['current'];
							?>
							<p>
								<label>
									<?php esc_html_e( 'Category:', 'club-competitions' ); ?>
								</label>
								<strong><?php echo esc_html( $single_category['label'] ); ?></strong>
								<small>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: remaining slots, 2: total quota */
											__( '(%1$d of %2$d remaining)', 'club-competitions' ),
											$remaining,
											$single_category['quota']
										)
									);
									?>
								</small>
								<input type="hidden" name="category" value="<?php echo esc_attr( $single_category['slug'] ); ?>" />
							</p>
						<?php else : ?>
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
						<?php endif; ?>

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
