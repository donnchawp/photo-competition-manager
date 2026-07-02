<?php
/**
 * Form rendering utilities for admin screens.
 *
 * @package PhotoCompetitionManager\Admin\Traits
 */

namespace PhotoCompetitionManager\Admin\Traits;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

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
	 * @param bool $with_settings_updated Whether to add the settings-updated parameter to the URL.
	 *                                    Default is false.
	 * @return string
	 */
	private function members_url( bool $with_settings_updated = false ): string { // phpcs:ignore Generic.Metrics.CyclomaticComplexity.TooHigh -- This is a helper method.
		$args = array(
			'page' => 'photo-competition-manager-members',
		);

		if ( $with_settings_updated ) {
			$args['settings-updated'] = '1';
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Redirect with settings errors preserved across the redirect.
	 *
	 * This helper saves any settings errors to a transient before redirecting,
	 * ensuring they display on the destination page after the redirect completes.
	 *
	 * @param string $url Destination URL.
	 * @return void
	 */
	private function redirect_with_settings_errors( string $url ): void {
		set_transient( 'settings_errors', get_settings_errors(), 30 );

		// Ensure the URL includes the settings-updated parameter.
		$url = add_query_arg( 'settings-updated', '1', $url );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Resolve a template partial path under src/templates/.
	 *
	 * @since 0.3.0
	 * @param string $relative Relative path, e.g. 'admin/voting/category-tabs.php'.
	 * @return string Absolute filesystem path.
	 */
	protected function template_path( string $relative ): string {
		return PHOTO_COMPETITION_MANAGER_DIR . '/templates/' . ltrim( $relative, '/' );
	}

	/**
	 * Render a template partial to a string.
	 *
	 * The partial receives a single variable, $data (array), in scope and is
	 * responsible for its own output escaping.
	 *
	 * @since 0.3.0
	 * @param string $relative Relative partial path under src/templates/.
	 * @param array  $data     View data available to the partial as $data.
	 * @return string Rendered HTML.
	 */
	protected function render_template( string $relative, array $data = array() ): string {
		ob_start();
		include $this->template_path( $relative );
		return (string) ob_get_clean();
	}
}
