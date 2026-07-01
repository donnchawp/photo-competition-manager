<?php
/**
 * Shared harness for characterizing admin controllers.
 *
 * Admin controllers guard on capability, verify nonces, and finish by calling
 * a redirect helper that does wp_safe_redirect() then exit. To exercise them in
 * PHPUnit we run as an admin, seed a valid nonce, and intercept the redirect
 * (via the wp_redirect filter) so the following exit is never reached.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-redirect-exception.php';

use WP_UnitTestCase;

/**
 * Base test case for admin controller characterization tests.
 */
abstract class Admin_Controller_Test_Case extends WP_UnitTestCase {

	/**
	 * Administrator user ID with the plugin capability.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up an authenticated admin, clean request state, and redirect capture.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user           = get_user_by( 'id', $this->admin_id );
		$user->add_cap( 'manage_photo_competitions' );
		wp_set_current_user( $this->admin_id );

		$this->reset_request();

		// Reset settings errors so assertions don't see leakage from prior tests.
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test isolation; no public API to clear settings errors.

		add_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );
	}

	/**
	 * Remove the redirect filter and clean request state.
	 */
	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );
		$this->reset_request();
		parent::tear_down();
	}

	/**
	 * Redirect interceptor: capture the location instead of sending headers.
	 *
	 * @param string $location Redirect target.
	 * @throws Redirect_Exception Always, carrying the location.
	 */
	public function throw_on_redirect( $location ) {
		throw new Redirect_Exception( (string) $location ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test harness; location captured, not output.
	}

	/**
	 * Clear the request superglobals.
	 */
	protected function reset_request(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Set request variables across GET, POST, and REQUEST.
	 *
	 * @param array<string, mixed> $vars Variables to set.
	 */
	protected function set_request( array $vars ): void {
		foreach ( $vars as $key => $value ) {
			$_GET[ $key ]     = $value;
			$_POST[ $key ]    = $value;
			$_REQUEST[ $key ] = $value;
		}
	}

	/**
	 * Seed a valid nonce for an action into the request.
	 *
	 * @param string $action Nonce action.
	 * @param string $field  Nonce field name.
	 */
	protected function set_nonce( string $action, string $field = '_wpnonce' ): void {
		$this->set_request( array( $field => wp_create_nonce( $action ) ) );
	}

	/**
	 * Run a callback and return the redirect location it triggers.
	 *
	 * Fails the test if no redirect occurs.
	 *
	 * @param callable $callback Code expected to redirect.
	 * @return string Redirect location.
	 */
	protected function capture_redirect( callable $callback ): string {
		try {
			$callback();
		} catch ( Redirect_Exception $e ) {
			return $e->getMessage();
		}

		$this->fail( 'Expected a redirect but none occurred.' );
	}

	/**
	 * Run a callback that emits a JSON response and terminates via wp_die().
	 *
	 * @param callable $callback Code expected to call wp_send_json_*.
	 * @return array<string, mixed> Decoded JSON response.
	 */
	protected function capture_json( callable $callback ): array {
		// wp_send_json() calls die() directly unless in AJAX context; force AJAX
		// context and a throwing die handler so the response is catchable.
		$ajax_die     = static function ( $message = '' ) {
			throw new \WPDieException( is_scalar( $message ) ? (string) $message : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test harness; message re-thrown, not output.
		};
		$ajax_handler = static function () use ( $ajax_die ) {
			return $ajax_die;
		};

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $ajax_handler );

		ob_start();
		try {
			$callback();
		} catch ( \WPDieException $e ) {
			// wp_send_json_* echoes the body then wp_die()s.
			unset( $e );
		}
		$output = ob_get_clean();

		remove_filter( 'wp_die_ajax_handler', $ajax_handler );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		return json_decode( $output, true );
	}

	/**
	 * Registered settings-error codes for a settings group.
	 *
	 * @param string $group Settings group slug.
	 * @return array<int, string> Error codes.
	 */
	protected function settings_error_codes( string $group ): array {
		return wp_list_pluck( get_settings_errors( $group ), 'code' );
	}
}
