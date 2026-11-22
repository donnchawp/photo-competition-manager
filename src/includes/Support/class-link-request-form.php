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
 * or a page cache serving an old copy) shows the form again with a notice,
 * rather than WordPress's "The link you followed has expired" page.
 *
 * @since 0.5.0
 */
class Link_Request_Form {

	/**
	 * Whether the posted nonce is valid. Unlike check_admin_referer(), a
	 * stale one doesn't end the request.
	 *
	 * @since 0.5.0
	 *
	 * @param string $action      Nonce action.
	 * @param string $nonce_field Field the nonce was posted in.
	 * @return bool
	 */
	public static function verified( string $action, string $nonce_field ): bool {
		$nonce = isset( $_POST[ $nonce_field ] ) && is_string( $_POST[ $nonce_field ] ) ? sanitize_key( wp_unslash( $_POST[ $nonce_field ] ) ) : '';

		return '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
	}

	/**
	 * The notice for a form whose nonce was stale.
	 *
	 * @since 0.5.0
	 *
	 * @return string HTML.
	 */
	public static function stale_notice(): string {
		return '<p class="error">' . esc_html__( 'This form had been open too long. Please send it again.', 'photo-competition-manager' ) . '</p>';
	}

	/**
	 * The address posted with the form.
	 *
	 * @since 0.5.0
	 *
	 * @return string Sanitized email address, or ''.
	 */
	public static function typed_email(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers check the nonce, or only refill the form's own field.
		return isset( $_POST['member_email'] ) && is_string( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';
	}
}
