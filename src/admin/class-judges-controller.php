<?php
/**
 * Judges controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Repository\Judges_Repository;
use PhotoCompetitionManager\Service\Judge_Deletion;

/**
 * Manage judges page: the people outside the club who score judged competitions.
 *
 * @since 0.4.0
 */
class Judges_Controller {

	use Form_Rendering;

	private const GROUP = 'photo_competition_judges';

	/**
	 * Judges repository.
	 *
	 * @var Judges_Repository
	 */
	private $judges;

	/**
	 * Constructor.
	 *
	 * @param Judges_Repository $judges Judges repository.
	 */
	public function __construct( Judges_Repository $judges ) {
		$this->judges = $judges;
	}

	/**
	 * Register hooks for this controller.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Handle admin post actions.
	 *
	 * @return void
	 */
	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			return;
		}

		if ( ! isset( $_POST['photo_competition_action'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['photo_competition_action'] ) );

		if ( 'create_judge' === $action ) {
			check_admin_referer( 'photo_competition_judge_create', 'photo_competition_judge_nonce' );

			$result = $this->judges->create(
				$this->get_post_string( 'judge_name' ),
				$this->get_post_string( 'judge_email' )
			);

			$this->notice_and_redirect( $result, 'judge_created', __( 'Judge added.', 'photo-competition-manager' ), $this->judges_url() );
		}

		if ( 'update_judge' === $action ) {
			$judge_id = absint( $this->get_post_string( 'judge_id' ) );

			check_admin_referer( 'photo_competition_judge_update_' . $judge_id, 'photo_competition_judge_nonce' );

			$result = $this->judges->update(
				$judge_id,
				$this->get_post_string( 'judge_name' ),
				$this->get_post_string( 'judge_email' )
			);

			$back = is_wp_error( $result )
				? $this->judges_url(
					array(
						'judge_action' => 'edit',
						'judge'        => $judge_id,
					)
				)
				: $this->judges_url();

			$this->notice_and_redirect( $result, 'judge_updated', __( 'Judge updated.', 'photo-competition-manager' ), $back );
		}

		if ( 'delete_judge' === $action ) {
			$judge_id = absint( $this->get_post_string( 'judge_id' ) );

			check_admin_referer( 'photo_competition_delete_judge_' . $judge_id, 'photo_competition_judge_nonce' );

			$result = ( new Judge_Deletion( $this->judges ) )->delete( $judge_id );

			$this->notice_and_redirect(
				$result,
				'judge_deleted',
				__( 'Judge deleted. Their scores stay in every competition they judged.', 'photo-competition-manager' ),
				$this->judges_url()
			);
		}
	}

	/**
	 * Render the judges screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( self::GROUP );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view.
		$judge_action = isset( $_GET['judge_action'] ) ? sanitize_key( wp_unslash( $_GET['judge_action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view.
		$judge_id = isset( $_GET['judge'] ) ? absint( wp_unslash( $_GET['judge'] ) ) : 0;

		$mode = in_array( $judge_action, array( 'edit', 'delete' ), true ) ? $judge_action : 'list';
		$rows = array();

		if ( 'list' === $mode ) {
			foreach ( $this->judges->all() as $judge ) {
				$rows[] = (object) array(
					'name'       => $judge->name,
					'email'      => $judge->email,
					'edit_url'   => $this->judges_url(
						array(
							'judge_action' => 'edit',
							'judge'        => (int) $judge->id,
						)
					),
					'delete_url' => $this->judges_url(
						array(
							'judge_action' => 'delete',
							'judge'        => (int) $judge->id,
						)
					),
				);
			}
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Judges', 'photo-competition-manager' ) . '</h1>';

		$screen = $this->render_template(
			'admin/judges/judges-screen.php',
			array(
				'mode'       => $mode,
				'judge'      => 'list' === $mode ? null : $this->judges->find( $judge_id ),
				'rows'       => $rows,
				'judges_url' => $this->judges_url(),
			)
		);

		echo $screen; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.

		echo '</div>';
	}

	/**
	 * Judges page URL.
	 *
	 * @param array<string, string|int> $args Extra query arguments.
	 * @return string
	 */
	private function judges_url( array $args = array() ): string {
		return add_query_arg(
			array_merge( array( 'page' => 'photo-competition-manager-judges' ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Queue the outcome as a notice and redirect.
	 *
	 * @param mixed  $result  Repository result; a WP_Error is an error.
	 * @param string $code    Success notice code.
	 * @param string $message Success message.
	 * @param string $url     Where to go next.
	 * @return never
	 */
	private function notice_and_redirect( $result, string $code, string $message, string $url ): void {
		if ( is_wp_error( $result ) ) {
			add_settings_error( self::GROUP, $result->get_error_code(), $result->get_error_message(), 'error' );
		} else {
			add_settings_error( self::GROUP, $code, $message, 'updated' );
		}

		$this->redirect_with_settings_errors( $url );
	}
}
