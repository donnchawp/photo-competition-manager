<?php
/**
 * Handle upload form shortcode.
 *
 * @package ClubCompetitions\Frontend
 */

namespace ClubCompetitions\Frontend;

use ClubCompetitions\Repository\CompetitionsRepository;
use ClubCompetitions\Repository\MembersRepository;
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
	 * Constructor.
	 *
	 * @param UploadHandler|null          $upload_handler    Upload handler.
	 * @param CompetitionsRepository|null $competitions_repo Competitions repository.
	 * @param MembersRepository|null      $members_repo      Members repository.
	 */
	public function __construct(
		?UploadHandler $upload_handler = null,
		?CompetitionsRepository $competitions_repo = null,
		?MembersRepository $members_repo = null
	) {
		$this->upload_handler    = $upload_handler ?: new UploadHandler();
		$this->competitions_repo = $competitions_repo ?: new CompetitionsRepository();
		$this->members_repo      = $members_repo ?: new MembersRepository();
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

		// Handle form submission.
		$message = '';
		if ( isset( $_POST['club_competitions_upload'] ) && check_admin_referer( 'club_competitions_upload', 'club_competitions_nonce' ) ) {
			$message = $this->handle_submission( $competition->id );
		}

		// Handle deletion.
		if ( isset( $_POST['club_competitions_delete'] ) && check_admin_referer( 'club_competitions_delete', 'club_competitions_delete_nonce' ) ) {
			$message = $this->handle_deletion( $competition->id );
		}

		ob_start();
		$this->render_form( $competition, $message );
		$output = ob_get_clean();
		return $output ? $output : '';
	}

	/**
	 * Handle form submission.
	 *
	 * @param int $competition_id Competition ID.
	 * @return string Message to display.
	 */
	private function handle_submission( int $competition_id ): string {
		$member_email = isset( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';
		$category     = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';

		if ( empty( $member_email ) || empty( $category ) ) {
			return '<p class="error">' . esc_html__( 'Please fill in all required fields.', 'club-competitions' ) . '</p>';
		}

		// Find member by email.
		$member = $this->members_repo->find_by_email( $member_email );
		if ( ! $member ) {
			return '<p class="error">' . esc_html__( 'Member email not found. Please contact the administrator.', 'club-competitions' ) . '</p>';
		}

		// Check file upload.
		if ( empty( $_FILES['image'] ) || UPLOAD_ERR_NO_FILE === $_FILES['image']['error'] ) {
			return '<p class="error">' . esc_html__( 'Please select an image to upload.', 'club-competitions' ) . '</p>';
		}

		// Process upload.
		$result = $this->upload_handler->handle_upload( $competition_id, $member->id, $category, $_FILES['image'] );

		if ( is_wp_error( $result ) ) {
			return '<p class="error">' . esc_html( $result->get_error_message() ) . '</p>';
		}

		return '<p class="success">' . esc_html__( 'Image uploaded successfully!', 'club-competitions' ) . '</p>';
	}

	/**
	 * Handle image deletion.
	 *
	 * @param int $competition_id Competition ID.
	 * @return string Message to display.
	 */
	private function handle_deletion( int $competition_id ): string {
		$image_id     = isset( $_POST['image_id'] ) ? absint( $_POST['image_id'] ) : 0;
		$member_email = isset( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';

		if ( ! $image_id || ! $member_email ) {
			return '<p class="error">' . esc_html__( 'Invalid deletion request.', 'club-competitions' ) . '</p>';
		}

		$member = $this->members_repo->find_by_email( $member_email );
		if ( ! $member ) {
			return '<p class="error">' . esc_html__( 'Member not found.', 'club-competitions' ) . '</p>';
		}

		$result = $this->upload_handler->delete_submission( $image_id, $member->id, $competition_id );

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
	 * @param object $competition Competition object.
	 * @param string $message     Message to display.
	 */
	private function render_form( $competition, string $message ): void {
		$settings    = CompetitionSettings::parse( $competition->settings );
		$categories  = CompetitionSettings::get_categories( $settings );
		$constraints = CompetitionSettings::get_upload_constraints( $settings );

		$member_email = isset( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';
		$member       = $member_email ? $this->members_repo->find_by_email( $member_email ) : null;

		// Get existing submissions and filter categories.
		$submissions          = array();
		$available_categories = array();

		if ( $member ) {
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

			<?php if ( ! $member || ! $member_email ) : ?>
				<!-- Email identification form -->
				<form method="post" enctype="multipart/form-data" class="competition-upload-form">
					<?php wp_nonce_field( 'club_competitions_upload', 'club_competitions_nonce' ); ?>

					<p>
						<label for="member_email">
							<?php esc_html_e( 'Your Email Address:', 'club-competitions' ); ?>
							<span class="required">*</span>
						</label>
						<input
							type="email"
							id="member_email"
							name="member_email"
							value="<?php echo esc_attr( $member_email ); ?>"
							required
						/>
						<small><?php esc_html_e( 'Enter the email address associated with your membership.', 'club-competitions' ); ?></small>
					</p>

					<p>
						<button type="submit" class="button">
							<?php esc_html_e( 'Continue', 'club-competitions' ); ?>
						</button>
					</p>
				</form>
			<?php else : ?>
				<!-- Member is identified, show submissions and upload form -->
				<p class="member-info">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: member name */
							__( 'Logged in as: %s', 'club-competitions' ),
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
										<?php wp_nonce_field( 'club_competitions_delete', 'club_competitions_delete_nonce' ); ?>
										<input type="hidden" name="image_id" value="<?php echo esc_attr( $image->id ); ?>" />
										<input type="hidden" name="member_email" value="<?php echo esc_attr( $member_email ); ?>" />
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
						<?php wp_nonce_field( 'club_competitions_upload', 'club_competitions_nonce' ); ?>

						<!-- Preserve member email for upload -->
						<input type="hidden" name="member_email" value="<?php echo esc_attr( $member_email ); ?>" />

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
