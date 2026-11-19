<?php
/**
 * A cookie the voting page keeps on a voter's device.
 *
 * @package PhotoCompetitionManager\Support
 */

namespace PhotoCompetitionManager\Support;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Sets and reads the voting page's cookies, all with the same policy:
 * HTTP only, SameSite Lax, secure on HTTPS.
 *
 * @since 0.5.0
 */
final class Device_Cookie {

	/**
	 * Set a cookie, and copy it into $_COOKIE for the rest of the request.
	 *
	 * @param string $name     Cookie name.
	 * @param string $value    Value.
	 * @param int    $lifetime Seconds it lasts.
	 * @return void
	 */
	public static function set( string $name, string $value, int $lifetime ): void {
		// A real request gets here on template_redirect, before any output. PHPUnit has already printed, and setcookie() would warn.
		if ( ! headers_sent() ) {
			setcookie(
				$name,
				$value,
				array(
					'expires'  => time() + $lifetime,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'samesite' => 'Lax',
					'httponly' => true,
				)
			);
		}

		// A redirect follows, so this copy is how tests see the cookie. Slashed, as WordPress slashes $_COOKIE.
		$_COOKIE[ $name ] = wp_slash( $value );
	}

	/**
	 * A cookie's raw value, unslashed, or '' when there's none. The caller sanitizes it.
	 *
	 * @param string $name Cookie name.
	 * @return string
	 */
	public static function get( string $name ): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw by design; callers sanitize what they read from it.
		return isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] ) ? wp_unslash( $_COOKIE[ $name ] ) : '';
	}
}
