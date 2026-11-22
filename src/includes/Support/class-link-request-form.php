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
	 * Name of the form's submit button.
	 *
	 * @var string
	 */
	private $button;

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	private $action;

	/**
	 * Field the nonce is posted in.
	 *
	 * @var string
	 */
	private $nonce_field;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 *
	 * @param string $button      Name of the form's submit button.
	 * @param string $action      Nonce action.
	 * @param string $nonce_field Field the nonce is posted in.
	 */
	public function __construct( string $button, string $action, string $nonce_field ) {
		$this->button      = $button;
		$this->action      = $action;
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
		wp_nonce_field( $this->action, $this->nonce_field );
	}

	/**
	 * Whether this request posted the form.
	 *
	 * @since 0.5.0
	 *
	 * @return bool
	 */
	public function submitted(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only which form was posted; stale_notice() checks the nonce.
		return isset( $_POST[ $this->button ] );
	}

	/**
	 * The notice for a posted form whose nonce is stale or missing. Unlike
	 * check_admin_referer(), this doesn't end the request.
	 *
	 * @since 0.5.0
	 *
	 * @return string HTML, or '' when the form wasn't posted or its nonce is valid.
	 */
	public function stale_notice(): string {
		if ( ! $this->stale() ) {
			return '';
		}

		return '<p class="error">' . esc_html__( 'This form had been open too long. Please send it again.', 'photo-competition-manager' ) . '</p>';
	}

	/**
	 * The address posted with the form.
	 *
	 * @since 0.5.0
	 *
	 * @return string Sanitized email address, or ''.
	 */
	public function email(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers send only after stale_notice() is empty.
		return isset( $_POST['member_email'] ) && is_string( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';
	}

	/**
	 * The address to fill back into the form: the one posted, if its nonce was stale.
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function refill(): string {
		return $this->stale() ? $this->email() : '';
	}

	/**
	 * Whether the form was posted with a nonce that isn't valid.
	 *
	 * @return bool
	 */
	private function stale(): bool {
		if ( ! $this->submitted() ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- This is the nonce check.
		$nonce = isset( $_POST[ $this->nonce_field ] ) && is_string( $_POST[ $this->nonce_field ] ) ? sanitize_key( wp_unslash( $_POST[ $this->nonce_field ] ) ) : '';

		return '' === $nonce || false === wp_verify_nonce( $nonce, $this->action );
	}
}
