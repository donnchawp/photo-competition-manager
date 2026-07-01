<?php
/**
 * Action-dispatch utilities for admin controllers.
 *
 * @package PhotoCompetitionManager\Admin\Traits
 */

namespace PhotoCompetitionManager\Admin\Traits;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Routes admin GET actions through a declarative map and verifies their nonce.
 *
 * Factors the repeated `if ( 'x' === $action )` chain and the sanitize-input /
 * verify-nonce boilerplate out of each controller's `handle_actions()`. Every
 * routed action is nonce-checked centrally before its handler runs, so handlers
 * cannot forget the check.
 *
 * @since 0.1.0
 */
trait Admin_Action_Dispatcher {

	/**
	 * Route the current request's `action` through a declarative map.
	 *
	 * Each map entry is keyed by the `action` string and holds:
	 * - `nonce`  callable(): string — builds the nonce action to verify.
	 * - `handle` callable(): void   — performs the action once the nonce passes.
	 *
	 * @param array<string, array{nonce: callable, handle: callable}> $actions Action map.
	 * @return void
	 */
	protected function dispatch_action( array $actions ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; the handler's nonce is verified below before any mutation.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( '' === $action || ! isset( $actions[ $action ] ) ) {
			return;
		}

		$spec = $actions[ $action ];

		check_admin_referer( ( $spec['nonce'] )() );
		( $spec['handle'] )();
	}

	/**
	 * Read a non-negative integer from the query string.
	 *
	 * Used to build nonce actions and locate records before the nonce is verified,
	 * mirroring the pre-nonce parameter reads the dispatched handlers rely on.
	 *
	 * @param string $key Query-string key.
	 * @return int
	 */
	protected function query_int( string $key ): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Value is incorporated into the nonce action verified before any mutation.
		return isset( $_GET[ $key ] ) ? absint( wp_unslash( $_GET[ $key ] ) ) : 0;
	}

	/**
	 * Read a sanitized text field from the query string.
	 *
	 * @param string $key Query-string key.
	 * @return string
	 */
	protected function query_text( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Value is incorporated into the nonce action verified before any mutation.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}
}
