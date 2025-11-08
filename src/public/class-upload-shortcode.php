<?php
/**
 * Handle upload form shortcode.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Service\Upload_Handler;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Upload shortcode handler.
 *
 * @since 0.1.0
 */
class Upload_Shortcode {

	/**
	 * Allowed message keys and their corresponding text.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_MESSAGES = array(
		'upload_success'   => 'Image uploaded successfully!',
		'delete_success'   => 'Image deleted successfully.',
		'category_missing' => 'Please select a category.',
		'image_missing'    => 'Please select an image to upload.',
		'invalid_deletion' => 'Invalid deletion request.',
		'upload_failed'    => 'Upload failed. Please try again.',
		'delete_failed'    => 'Failed to delete image. Please try again.',
	);

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
	 * Enqueue assets for upload shortcode.
	 */
	private function enqueue_assets(): void {
		// Determine plugin root directory.
		// Development: src/public/ -> need dirname(dirname(__DIR__)) to get to club-competitions/.
		// Production: public/ -> need dirname(__DIR__) to get to photo-competition-manager/.
		$parent_dir = dirname( __DIR__ );
		if ( basename( $parent_dir ) === 'src' ) {
			// Development mode - go up one more level.
			$plugin_dir = dirname( $parent_dir );
		} else {
			// Production mode - parent is already plugin root.
			$plugin_dir = $parent_dir;
		}

		$asset_file = $plugin_dir . '/assets/build/drag-drop-upload.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		// Get the plugin URL.
		$plugin_url = plugin_dir_url( $plugin_dir . '/photo-competition-manager.php' );

		wp_enqueue_script(
			'photo-comp-drag-drop-upload',
			$plugin_url . 'assets/build/drag-drop-upload.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			'photo-comp-drag-drop-upload',
			$plugin_url . 'assets/build/drag-drop-upload.css',
			array(),
			$asset['version']
		);

		// Enqueue submission category update assets.
		$category_asset_file = $plugin_dir . '/assets/build/submission-category.asset.php';
		if ( file_exists( $category_asset_file ) ) {
			$category_asset = require $category_asset_file;

			wp_enqueue_script(
				'photo-comp-submission-category',
				$plugin_url . 'assets/build/submission-category.js',
				$category_asset['dependencies'],
				$category_asset['version'],
				true
			);

			wp_enqueue_style(
				'photo-comp-submission-category',
				$plugin_url . 'assets/build/submission-category.css',
				array(),
				$category_asset['version']
			);
		}
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

		// Enqueue assets when shortcode is rendered.
		$this->enqueue_assets();

		$competition = $this->competitions_repo->find_current_active();
		if ( ! $competition ) {
			return '<p class="error">' . esc_html__( 'No active competition is currently accepting uploads.', 'photo-competition-manager' ) . '</p>';
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

		// Handle messages from redirects.
		$message = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Message display only, no actions.
		if ( isset( $_GET['msg_type'], $_GET['msg_key'], $_GET['msg_time'] ) ) {
			// Only show messages that are less than 30 seconds old to prevent stale messages.
			$msg_time = absint( $_GET['msg_time'] );
			if ( ( time() - $msg_time ) < 30 ) {
				$msg_type = sanitize_text_field( wp_unslash( $_GET['msg_type'] ) );
				$msg_key  = sanitize_text_field( wp_unslash( $_GET['msg_key'] ) );

				// Only display message if the key is in the allowed list.
				if ( isset( self::ALLOWED_MESSAGES[ $msg_key ] ) ) {
					$msg_text = self::ALLOWED_MESSAGES[ $msg_key ];
					$class    = 'success' === $msg_type ? 'success' : 'error';
					$message  = '<p class="' . esc_attr( $class ) . '">' . esc_html( $msg_text ) . '</p>';
				}
			}
		}

		// Handle token request form submission.
		if (
			isset( $_POST['photo_competition_request_token'] )
			&& check_admin_referer( 'photo_competition_request_token', 'photo_competition_nonce' )
		) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$member_email = isset( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';
			$message      = $this->handle_token_request( $competition, $member_email );
		}

		// Handle upload with token (redirects, doesn't return).
		if (
			$token_record && $member
			&& isset( $_POST['photo_competition_upload'] )
			&& check_admin_referer( 'photo_competition_upload_with_token', 'photo_competition_nonce' )
		) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES cannot be sanitized; validated in Upload_Handler::handle_upload().
			$image_file = isset( $_FILES['image'] ) ? $_FILES['image'] : null;
			$this->handle_token_upload( (int) $competition->id, (int) $member->id, $token_record, $category, $image_file );
		}

		// Handle deletion with token (redirects, doesn't return).
		if (
			$token_record && $member
			&& isset( $_POST['photo_competition_delete'] )
			&& check_admin_referer( 'photo_competition_delete_with_token', 'photo_competition_delete_nonce' )
		) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
			$image_id = isset( $_POST['image_id'] ) ? absint( $_POST['image_id'] ) : 0;
			$this->handle_token_deletion( (int) $competition->id, (int) $member->id, $token_record, $image_id );
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
			return '<p class="error">' . esc_html__( 'Please enter your email address.', 'photo-competition-manager' ) . '</p>';
		}

		// Generic success message for security (prevents email enumeration).
		$generic_success = '<p class="success">' . esc_html__( 'If this email is registered, you will receive an upload link shortly. Please check your inbox.', 'photo-competition-manager' ) . '</p>';

		// Delegate creation + email to the token repository.
		$ok = $this->token_repo->send_upload_link_by_email(
			(int) $competition->id,
			$member_email,
			get_permalink()
		);

		if ( ! $ok ) {
			return '<p class="error">' . esc_html__( 'Failed to send email. Please contact the administrator.', 'photo-competition-manager' ) . '</p>';
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
	 * @return void Redirects after processing.
	 */
	private function handle_token_upload( int $competition_id, int $member_id, $token_record, string $category, $image_file ): void {
		if ( empty( $category ) ) {
			$this->redirect_with_message( 'error', 'category_missing' );
			return;
		}

		// Check file upload.
		if ( empty( $image_file ) || ! is_array( $image_file ) || ( isset( $image_file['error'] ) && UPLOAD_ERR_NO_FILE === $image_file['error'] ) ) {
			$this->redirect_with_message( 'error', 'image_missing' );
			return;
		}

		// Process upload.
		$result = $this->upload_handler->handle_upload( $competition_id, $member_id, $category, $image_file );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_message( 'error', 'upload_failed' );
			return;
		}

		$this->redirect_with_message( 'success', 'upload_success' );
	}

	/**
	 * Handle deletion with valid token.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param int    $member_id      Member ID.
	 * @param object $token_record   Token record.
	 * @param int    $image_id       Image ID to delete.
	 * @return void Redirects after processing.
	 */
	private function handle_token_deletion( int $competition_id, int $member_id, $token_record, int $image_id ): void {
		if ( ! $image_id ) {
			$this->redirect_with_message( 'error', 'invalid_deletion' );
			return;
		}

		$result = $this->upload_handler->delete_submission( $image_id, $member_id, $competition_id );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_message( 'error', 'delete_failed' );
			return;
		}

		$this->redirect_with_message( 'success', 'delete_success' );
	}

	/**
	 * Redirect with a message using query parameters (Post/Redirect/Get pattern).
	 *
	 * @param string $type        Message type (success or error).
	 * @param string $message_key Message key from ALLOWED_MESSAGES.
	 * @return void
	 */
	private function redirect_with_message( string $type, string $message_key ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token is read-only for magic-link auth.
		$token_param = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		$redirect_url = add_query_arg(
			array(
				'token'    => rawurlencode( $token_param ),
				'msg_type' => $type,
				'msg_key'  => $message_key,
				'msg_time' => time(),
			),
			get_permalink()
		);

		wp_safe_redirect( $redirect_url );
		exit;
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
		<div class="photo-comp-upload">
			<h2><?php echo esc_html( $competition->title ); ?></h2>

			<?php if ( $message ) : ?>
				<?php echo wp_kses_post( $message ); ?>
			<?php endif; ?>

			<?php if ( ! $this->competitions_repo->is_accepting_uploads( $competition ) ) : ?>
				<p class="notice"><?php esc_html_e( 'This competition is not currently open for submissions.', 'photo-competition-manager' ); ?></p>
				<?php return; ?>
			<?php endif; ?>

			<?php if ( ! $token_record || ! $member ) : ?>
				<!-- Token request form -->
				<div class="token-request-section">
					<p><?php esc_html_e( 'To upload images, please enter your registered email address. We will send you a secure upload link.', 'photo-competition-manager' ); ?></p>

					<form method="post" class="competition-token-request-form">
						<?php wp_nonce_field( 'photo_competition_request_token', 'photo_competition_nonce' ); ?>

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
							<button type="submit" name="photo_competition_request_token" class="button">
								<?php esc_html_e( 'Send Upload Link', 'photo-competition-manager' ); ?>
							</button>
						</p>
					</form>
				</div>
			<?php else : ?>
				<!-- Member is authenticated with valid token, show submissions and upload form -->
				<?php
				// Pass configuration to JavaScript for category update functionality.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token is read-only for magic-link auth.
				$token_param = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

				// Build category quota data for validation.
				$categories_data = array();
				foreach ( $categories as $cat ) {
					$categories_data[ $cat['slug'] ] = array(
						'label' => $cat['label'],
						'quota' => $cat['quota'],
					);
				}

				$category_update_data = array(
					'token'      => $token_param,
					'apiUrl'     => esc_url_raw( rest_url() ),
					'nonce'      => wp_create_nonce( 'wp_rest' ),
					'categories' => $categories_data,
				);

				// Localize for submission category updates.
				wp_localize_script( 'photo-comp-submission-category', 'photoCompCategoryUpdate', $category_update_data );
				?>

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

				<?php
				// Show existing submissions with category dropdowns and delete buttons.
				if ( ! empty( $submissions ) ) :
					?>
					<div class="member-submissions">
						<h3><?php esc_html_e( 'Your Submissions', 'photo-competition-manager' ); ?></h3>

						<div id="category-change-status" class="category-change-status" style="display: none;"></div>

						<div class="submissions-grid">
							<?php foreach ( $submissions as $image ) : ?>
								<div class="submission-item" data-submission-id="<?php echo esc_attr( $image->id ); ?>">
									<img src="<?php echo esc_url( $image->thumbnail_url ); ?>" alt="" />
									<?php
									// Build form action URL with token to preserve it across submissions.
									// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token is read-only for magic-link auth; sanitized below.
									$token_param        = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
									$delete_form_action = add_query_arg( 'token', rawurlencode( $token_param ), get_permalink() );
									?>
									<form method="post" class="delete-form" action="<?php echo esc_url( $delete_form_action ); ?>">
										<?php wp_nonce_field( 'photo_competition_delete_with_token', 'photo_competition_delete_nonce' ); ?>
										<input type="hidden" name="image_id" value="<?php echo esc_attr( $image->id ); ?>" />
										<button
											type="submit"
											name="photo_competition_delete"
											class="button button-small button-link-delete"
											onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to delete this image?', 'photo-competition-manager' ) ); ?>');"
										>
											<?php esc_html_e( 'Delete', 'photo-competition-manager' ); ?>
										</button>
									</form>
									<div class="submission-category">
										<label for="category-<?php echo esc_attr( $image->id ); ?>">
											<?php esc_html_e( 'Category:', 'photo-competition-manager' ); ?>
										</label>
										<select
											id="category-<?php echo esc_attr( $image->id ); ?>"
											class="submission-category-select"
											data-submission-id="<?php echo esc_attr( $image->id ); ?>"
											data-original-category="<?php echo esc_attr( $image->category ); ?>"
										>
											<?php foreach ( $categories as $cat ) : ?>
												<option
													value="<?php echo esc_attr( $cat['slug'] ); ?>"
													<?php selected( $image->category, $cat['slug'] ); ?>
												>
													<?php echo esc_html( $cat['label'] ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
							<?php endforeach; ?>
						</div>

						<button type="button" id="save-category-changes" class="button button-primary" style="display: none; margin-top: 20px;">
							<?php esc_html_e( 'Save Category Changes', 'photo-competition-manager' ); ?>
						</button>
					</div>
				<?php endif; ?>

				<?php if ( empty( $available_categories ) ) : ?>
					<p class="notice">
						<?php esc_html_e( 'You have reached the maximum number of submissions for all categories. Thank you!', 'photo-competition-manager' ); ?>
					</p>
				<?php else : ?>
					<!-- Drag and drop bulk upload UI -->
					<h3><?php esc_html_e( 'Upload Images', 'photo-competition-manager' ); ?></h3>

					<div class="photo-comp-drag-drop-zone">
						<div class="drop-zone-icon">📸</div>
						<div class="drop-zone-text">
							<?php esc_html_e( 'Drag and drop your images here', 'photo-competition-manager' ); ?>
						</div>
						<div class="drop-zone-hint">
							<?php esc_html_e( 'or click to select files', 'photo-competition-manager' ); ?>
						</div>
						<button type="button" class="drop-zone-button">
							<?php esc_html_e( 'Select Images', 'photo-competition-manager' ); ?>
						</button>
					</div>

					<input
						type="file"
						id="batch-file-input"
						multiple
						accept="<?php echo esc_attr( $this->get_accept_formats( $constraints['allowed_formats'] ) ); ?>"
					/>

					<div class="photo-comp-preview-grid"></div>

					<button type="button" class="photo-comp-upload-all-btn">
						<?php esc_html_e( 'Upload All', 'photo-competition-manager' ); ?>
					</button>

					<div class="photo-comp-upload-progress"></div>

					<?php
					// Pass configuration to JavaScript for drag-drop upload.
					$categories_js = array();
					foreach ( $categories as $cat ) {
						$categories_js[] = array(
							'slug'  => $cat['slug'],
							'label' => $cat['label'],
							'quota' => $cat['quota'],
						);
					}

					$quotas_js = array();
					foreach ( $available_categories as $cat ) {
						$quotas_js[ $cat['slug'] ] = array(
							'current'   => $cat['current'],
							'quota'     => $cat['quota'],
							'remaining' => $cat['quota'] - $cat['current'],
						);
					}

					// Localize upload data.
					wp_localize_script(
						'photo-comp-drag-drop-upload',
						'photoCompUpload',
						array(
							'token'          => $token_param,
							'apiUrl'         => esc_url_raw( rest_url() ),
							'nonce'          => wp_create_nonce( 'wp_rest' ),
							'categories'     => $categories_js,
							'quotas'         => $quotas_js,
							'maxFileSize'    => $constraints['max_file_size_mb'] * 1024 * 1024,
							'allowedFormats' => $constraints['allowed_formats'],
						)
					);
					?>

					<!-- Fallback: Traditional single upload -->
					<div class="photo-comp-fallback-upload">
						<h3><?php esc_html_e( 'Or Upload One Image at a Time', 'photo-competition-manager' ); ?></h3>
						<p><?php esc_html_e( 'If the drag-and-drop interface is not working, you can use this traditional upload form.', 'photo-competition-manager' ); ?></p>

						<?php
						// Build form action URL with token to preserve it across submissions.
						// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Token is read-only for magic-link auth; sanitized below.
						$token_param = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
						$form_action = add_query_arg( 'token', rawurlencode( $token_param ), get_permalink() );
						?>
						<form method="post" enctype="multipart/form-data" class="competition-upload-form" action="<?php echo esc_url( $form_action ); ?>">
							<?php wp_nonce_field( 'photo_competition_upload_with_token', 'photo_competition_nonce' ); ?>

							<?php if ( 1 === count( $available_categories ) ) : ?>
								<?php
								$single_category = $available_categories[0];
								$remaining       = $single_category['quota'] - $single_category['current'];
								?>
								<p>
									<label>
										<?php esc_html_e( 'Category:', 'photo-competition-manager' ); ?>
									</label>
									<strong><?php echo esc_html( $single_category['label'] ); ?></strong>
									<small>
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: remaining slots, 2: total quota */
												__( '(%1$d of %2$d remaining)', 'photo-competition-manager' ),
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
										<?php esc_html_e( 'Category:', 'photo-competition-manager' ); ?>
										<span class="required">*</span>
									</label>
									<select id="category" name="category" required>
										<option value=""><?php esc_html_e( '-- Select Category --', 'photo-competition-manager' ); ?></option>
										<?php foreach ( $available_categories as $cat ) : ?>
											<option value="<?php echo esc_attr( $cat['slug'] ); ?>">
												<?php
												$remaining = $cat['quota'] - $cat['current'];
												echo esc_html(
													sprintf(
														/* translators: 1: category label, 2: remaining slots, 3: total quota */
														__( '%1$s (%2$d of %3$d remaining)', 'photo-competition-manager' ),
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
									<?php esc_html_e( 'Image:', 'photo-competition-manager' ); ?>
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
											/* translators: 1: max file size in MB, 2: allowed formats */
											__( 'Max size: %1$d MB. Formats: %2$s. Images will be automatically resized if needed.', 'photo-competition-manager' ),
											$constraints['max_file_size_mb'],
											strtoupper( implode( ', ', $constraints['allowed_formats'] ) )
										)
									);
									?>
								</small>
							</p>

							<p>
								<button type="submit" name="photo_competition_upload" class="button">
									<?php esc_html_e( 'Upload Image', 'photo-competition-manager' ); ?>
								</button>
							</p>
						</form>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
