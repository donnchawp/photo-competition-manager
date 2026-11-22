<?php
/**
 * The public forms that email a member a link: their nonce and email field.
 *
 * @package PhotoCompetitionManager\Support
 */

namespace PhotoCompetitionManager\Support;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * The upload, voting and member page link forms ask for an email address and
 * need no login. A nonce older than its lifetime (a tab left open overnight,
 * or a page cache serving an old copy) shows the form again with a notice and
 * the address filled back in, rather than WordPress's "The link you followed
 * has expired" page.
 *
 * @since 0.5.0
 */
class Link_Request_Form {

	/**
	 * Name of the form's submit button, which is also its nonce action.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Field the nonce is posted in.
	 *
	 * @var string
	 */
	private $nonce_field;

	/**
	 * Whether this request posted the form with a stale or missing nonce.
	 *
	 * @var bool
	 */
	private $stale = false;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 *
	 * @param string $name        Name of the form's submit button, and its nonce action.
	 * @param string $nonce_field Field the nonce is posted in.
	 */
	public function __construct( string $name, string $nonce_field ) {
		$this->name        = $name;
		$this->nonce_field = $nonce_field;
	}

	/**
	 * Print the nonce field.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function nonce_field(): void {
		wp_nonce_field( $this->name, $this->nonce_field );
	}

	/**
	 * Handle the form, if this request posted it. A stale or missing nonce
	 * gives a notice and leaves what was posted to fill back in; unlike
	 * check_admin_referer(), it doesn't end the request. A valid one sends.
	 *
	 * @since 0.5.0
	 *
	 * @param callable $send Takes the posted address; returns the message to show.
	 * @return string|null The message to show, or null when the form wasn't posted.
	 */
	public function handle( callable $send ): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- This is the nonce check.
		$this->stale = false;
		if ( ! isset( $_POST[ $this->name ] ) ) {
			return null;
		}

		$email = isset( $_POST['member_email'] ) && is_string( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';
		$nonce = isset( $_POST[ $this->nonce_field ] ) && is_string( $_POST[ $this->nonce_field ] ) ? sanitize_key( wp_unslash( $_POST[ $this->nonce_field ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $nonce || false === wp_verify_nonce( $nonce, $this->name ) ) {
			$this->stale = true;
			return '<p class="error">' . esc_html__( 'This form has expired. Please send it again.', 'photo-competition-manager' ) . '</p>';
		}

		return $send( $email );
	}

	/**
	 * A posted value to fill back into the form, if its nonce was stale.
	 *
	 * @since 0.5.0
	 *
	 * @param string $field Field name, such as 'member_email'.
	 * @return string Sanitized text, or '' when the nonce wasn't stale.
	 */
	public function refill( string $field ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only refills the form; nothing is sent.
		return $this->stale && isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
	}
}
