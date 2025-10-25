<?php
/**
 * Form rendering utilities for admin screens.
 *
 * @package PhotoCompetitionManager\Admin\Traits
 */

namespace PhotoCompetitionManager\Admin\Traits;

/**
 * Provides form input helper methods for admin controllers.
 *
 * @since 0.1.0
 */
trait Form_Rendering {

	/**
	 * Retrieve a POST value as an unslashed string.
	 *
	 * @param  string $key      POST key.
	 * @param  string $fallback Fallback value if key not present.
	 * @return string
	 */
	private function get_post_string( string $key, string $fallback = '' ): string {
		if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonces validated in calling context.
			return (string) wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonces validated in calling context.
		}

		return $fallback;
	}

	/**
	 * Retrieve a POST value as an unslashed array.
	 *
	 * @param  string $key POST key.
	 * @return array
	 */
	private function get_post_array( string $key ): array {
		// phpcs:ignore -- Nonces verified before calling this helper.
		if ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonces validated in calling context.
			$value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Nonces validated in calling context.
			return is_array( $value ) ? $value : array();
		}

		return array();
	}

	/**
	 * Dashboard URL.
	 *
	 * @return string
	 */
	private function dashboard_url(): string {
		return add_query_arg(
			array(
				'page' => 'photo-competition-manager',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Members page URL.
	 *
	 * @return string
	 */
	private function members_url(): string {
		return add_query_arg(
			array(
				'page' => 'club-competitions-members',
			),
			admin_url( 'admin.php' )
		);
	}
}
