<?php
/**
 * Competition types controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Repository\Competition_Types_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Manage competition types: each holds the defaults for its new competitions
 * and its own names for the club's grades.
 *
 * @since 0.4.0
 */
class Types_Controller {

	use Form_Rendering;

	private const GROUP = 'photo_competition_types';

	/**
	 * Competition types repository.
	 *
	 * @var Competition_Types_Repository
	 */
	private $types;

	/**
	 * The competition-default sections of the type form.
	 *
	 * @var Type_Settings_Form
	 */
	private $form;

	/**
	 * Constructor.
	 *
	 * @param Competition_Types_Repository $types Competition types repository.
	 */
	public function __construct( Competition_Types_Repository $types ) {
		$this->types = $types;
		$this->form  = new Type_Settings_Form();
	}

	/**
	 * Register hooks for this controller.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the category row and progress meter script on the types screen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'competitions_page_photo-competition-manager-types' !== $hook ) {
			return;
		}

		$this->enqueue_category_grade_script();
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

		if ( ! isset( $_POST['photo_competition_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Routing only; each action checks its nonce.
			return;
		}

		$action = sanitize_key( wp_unslash( $_POST['photo_competition_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Routing only; each action checks its nonce.

		if ( 'create_type' === $action ) {
			check_admin_referer( 'photo_competition_type_create', 'photo_competition_type_nonce' );

			$result = $this->types->create(
				$this->get_post_string( 'type_name' ),
				$this->form->from_post( Competition_Settings::type_settings( (object) array() ) ),
				$this->posted_grade_names()
			);

			$back = is_wp_error( $result ) ? $this->types_url( array( 'type_action' => 'add' ) ) : $this->types_url();

			$this->notice_and_redirect( self::GROUP, $result, 'type_created', __( 'Competition type added.', 'photo-competition-manager' ), $back );
		}

		if ( 'update_type' === $action ) {
			$type_id = absint( $this->get_post_string( 'type_id' ) );

			check_admin_referer( 'photo_competition_type_update_' . $type_id, 'photo_competition_type_nonce' );

			$type   = $this->types->find( $type_id );
			$result = $type
				? $this->types->update(
					$type_id,
					$this->get_post_string( 'type_name' ),
					$this->form->from_post( Competition_Settings::type_settings( $type ) ),
					$this->posted_grade_names()
				)
				: new \WP_Error( 'type_not_found', __( 'Competition type not found.', 'photo-competition-manager' ) );

			$back = is_wp_error( $result ) && $type
				? $this->types_url(
					array(
						'type_action' => 'edit',
						'type'        => $type_id,
					)
				)
				: $this->types_url();

			$this->notice_and_redirect( self::GROUP, $result, 'type_updated', __( 'Competition type updated.', 'photo-competition-manager' ), $back );
		}

		if ( 'delete_type' === $action ) {
			$type_id = absint( $this->get_post_string( 'type_id' ) );

			check_admin_referer( 'photo_competition_delete_type_' . $type_id, 'photo_competition_type_nonce' );

			$this->notice_and_redirect(
				self::GROUP,
				$this->types->delete( $type_id ),
				'type_deleted',
				__( 'Competition type deleted.', 'photo-competition-manager' ),
				$this->types_url()
			);
		}
	}

	/**
	 * Render the types screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_photo_competitions' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'photo-competition-manager' ) );
		}

		settings_errors( self::GROUP );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view.
		$type_action = isset( $_GET['type_action'] ) ? sanitize_key( wp_unslash( $_GET['type_action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view.
		$type_id = isset( $_GET['type'] ) ? absint( wp_unslash( $_GET['type'] ) ) : 0;

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Competition Types', 'photo-competition-manager' ) . '</h1>';

		if ( in_array( $type_action, array( 'add', 'edit' ), true ) ) {
			echo $this->render_form( $type_action, $type_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		} else {
			echo $this->render_screen( 'delete' === $type_action ? 'delete' : 'list', $type_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		}

		echo '</div>';
	}

	/**
	 * Render the list of types, or the delete confirmation.
	 *
	 * @param string $mode    'list' or 'delete'.
	 * @param int    $type_id Type to delete.
	 * @return string
	 */
	private function render_screen( string $mode, int $type_id ): string {
		$rows = array();

		if ( 'list' === $mode ) {
			$types      = $this->types->all();
			$can_delete = count( $types ) > 1;

			foreach ( $types as $type ) {
				$rows[] = (object) array(
					'name'       => $type->name,
					'categories' => implode( ', ', wp_list_pluck( Competition_Settings::type_settings( $type )['categories'], 'label' ) ),
					'edit_url'   => $this->types_url(
						array(
							'type_action' => 'edit',
							'type'        => (int) $type->id,
						)
					),
					'delete_url' => $can_delete
						? $this->types_url(
							array(
								'type_action' => 'delete',
								'type'        => (int) $type->id,
							)
						)
						: '',
				);
			}
		}

		return $this->render_template(
			'admin/types/types-screen.php',
			array(
				'mode'      => $mode,
				'type'      => 'delete' === $mode ? $this->types->find( $type_id ) : null,
				'rows'      => $rows,
				'add_url'   => $this->types_url( array( 'type_action' => 'add' ) ),
				'types_url' => $this->types_url(),
			)
		);
	}

	/**
	 * Render the add or edit form.
	 *
	 * @param string $mode    'add' or 'edit'.
	 * @param int    $type_id Type to edit.
	 * @return string
	 */
	private function render_form( string $mode, int $type_id ): string {
		$type = 'edit' === $mode ? $this->types->find( $type_id ) : null;

		$grade_rows = array();
		foreach ( Competition_Settings::club_grades() as $grade ) {
			$grade_rows[] = (object) array(
				'slug'  => $grade['slug'],
				'label' => $grade['label'],
				'name'  => (string) ( $type->grade_names[ $grade['slug'] ] ?? '' ),
			);
		}

		return $this->render_template(
			'admin/types/type-form.php',
			array(
				'mode'          => $mode,
				'type'          => $type,
				'sections_html' => 'edit' === $mode && ! $type ? '' : $this->form->render_sections( Competition_Settings::type_settings( $type ?? (object) array() ) ),
				'grade_rows'    => $grade_rows,
				'types_url'     => $this->types_url(),
			)
		);
	}

	/**
	 * The posted grade names, keyed by club grade slug. A blank name is left
	 * out, so the type shows the club's label for that grade.
	 *
	 * @return array<string, string>
	 */
	private function posted_grade_names(): array {
		$posted = $this->get_post_array( 'grade_names' );
		$names  = array();

		foreach ( Competition_Settings::club_grades() as $grade ) {
			$name = isset( $posted[ $grade['slug'] ] ) && is_string( $posted[ $grade['slug'] ] ) ? sanitize_text_field( $posted[ $grade['slug'] ] ) : '';
			if ( '' !== $name ) {
				$names[ $grade['slug'] ] = $name;
			}
		}

		return $names;
	}
}
