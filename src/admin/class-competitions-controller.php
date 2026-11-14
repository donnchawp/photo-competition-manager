<?php
/**
 * Competitions controller for admin interface.
 *
 * @package PhotoCompetitionManager\Admin
 */

namespace PhotoCompetitionManager\Admin;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Admin\Traits\Date_Formatting;
use PhotoCompetitionManager\Admin\Traits\Email_Job_Notice;
use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use PhotoCompetitionManager\Repository\Competition_Judges_Repository;
use PhotoCompetitionManager\Repository\Competition_Types_Repository;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Entrants_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Judges_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Service\Competition_Labels;
use PhotoCompetitionManager\Service\Actor;
use PhotoCompetitionManager\Service\Ballots;
use PhotoCompetitionManager\Service\Competition_Entry;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Service\Email_Job_Manager;
use PhotoCompetitionManager\Service\Email_Service;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Service\Judge_Link;
use PhotoCompetitionManager\Service\Upload_Link_Service;
use PhotoCompetitionManager\Support\Competition_Settings;

use function PhotoCompetitionManager\Support\utc_time;

/**
 * Manage competitions dashboard and CRUD operations.
 *
 * @since 0.1.0
 */
class Competitions_Controller {

	use Date_Formatting;
	use Email_Job_Notice;
	use Form_Rendering;

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Email job queue.
	 *
	 * @var Email_Job_Manager
	 */
	private $email_jobs;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Entries module.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Judges repository.
	 *
	 * @var Judges_Repository
	 */
	private $judges;

	/**
	 * Judges assigned to competitions.
	 *
	 * @var Competition_Judges_Repository
	 */
	private $competition_judges;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes;

	/**
	 * Competition types.
	 *
	 * @var Competition_Types_Repository
	 */
	private $types;

	/**
	 * Who may enter.
	 *
	 * @var Competition_Entry
	 */
	private $entry;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Constructor.
	 *
	 * @since 0.4.0 Takes the Entries module.
	 *
	 * @param Competitions_Repository $competitions Competitions repository.
	 * @param Email_Job_Manager|null  $email_jobs   Email job queue.
	 * @param Entries|null            $entries      Entries module.
	 */
	public function __construct( Competitions_Repository $competitions, ?Email_Job_Manager $email_jobs = null, ?Entries $entries = null ) {
		$this->competitions = $competitions;
		$this->workflow     = new Competition_Workflow( $this->competitions );
		$this->email_jobs   = $email_jobs ?? ( new \PhotoCompetitionManager\Dependencies() )->email_job_manager;
		$this->entries      = $entries ?? new Entries( $this->competitions );

		$this->judges             = new Judges_Repository();
		$this->competition_judges = new Competition_Judges_Repository();
		$this->votes              = new Votes_Repository();
		$this->types              = new Competition_Types_Repository();
		$this->members            = new Members_Repository();
		$this->entry              = new Competition_Entry( $this->competitions, $this->members );
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
	 * Enqueue inline scripts for competitions page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_photo-competition-manager' !== $hook ) {
			return;
		}

		$this->enqueue_category_grade_script();

		wp_enqueue_script(
			'photo-comp-admin-confirm',
			PHOTO_COMPETITION_MANAGER_URL . 'assets/js/admin-confirm.js',
			array(),
			PHOTO_COMPETITION_MANAGER_VERSION,
			true
		);
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

		$action = '';

		if ( isset( $_POST['photo_competition_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['photo_competition_action'] ) );
		} elseif ( isset( $_POST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; mutations require explicit nonce checks below.
			$action = sanitize_key( wp_unslash( $_POST['action'] ) );
		} elseif ( isset( $_GET['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of action for routing; mutations require explicit nonce checks below.
			$action = sanitize_key( wp_unslash( $_GET['action'] ) );
		}

		if ( '' === $action ) {
			return;
		}

		if ( 'create_competition' === $action ) {
			check_admin_referer( 'photo_competition_create', 'photo_competition_nonce' );

			$title_raw      = $this->get_post_string( 'competition_title' );
			$slug_raw       = $this->get_post_string( 'competition_slug' );
			$open_date_raw  = $this->get_post_string( 'competition_open_date' );
			$close_date_raw = $this->get_post_string( 'competition_close_date' );

			$title = sanitize_text_field( $title_raw );
			$slug  = sanitize_title( $slug_raw );

			// With one type there's no choice to make.
			$type = $this->types->only() ?? $this->types->find( absint( $this->get_post_string( 'competition_type' ) ) );

			$data = array(
				'title'      => $title,
				'slug'       => $slug,
				'open_date'  => $this->parse_date_input( $open_date_raw ),
				'close_date' => $this->parse_date_input( $close_date_raw ),
				'type_id'    => $type ? (int) $type->id : 0,
				// The competition keeps its own copy, so later changes to the type don't alter it.
				'settings'   => $type ? Competition_Settings::settings_for_new_competition( $type ) : array(),
				'share_hash' => Competition_Settings::generate_share_hash(),
			);

			$result = $this->link_overlap_error( $this->competitions->create( $data ) );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_manager',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'photo_competition_manager',
					'created',
					__( 'Competition created successfully.', 'photo-competition-manager' ),
					'updated'
				);
			}

			$this->redirect_with_settings_errors( $this->dashboard_url() );
		}

		if ( 'update_competition' === $action ) {
			$competition_id = absint( $this->get_post_string( 'competition_id' ) );

			check_admin_referer( 'photo_competition_update_' . $competition_id, 'photo_competition_nonce' );

			$title_raw      = $this->get_post_string( 'competition_title' );
			$slug_raw       = $this->get_post_string( 'competition_slug' );
			$open_date_raw  = $this->get_post_string( 'competition_open_date' );
			$close_date_raw = $this->get_post_string( 'competition_close_date' );

			$title = sanitize_text_field( $title_raw );
			$slug  = sanitize_title( $slug_raw );

			$data = array(
				'title'      => $title,
				'slug'       => $slug,
				'open_date'  => $this->parse_date_input( $open_date_raw ),
				'close_date' => $this->close_date_from_form( $competition_id, $this->parse_date_input( $close_date_raw ) ),
			);

			// Only posted when the club has several types, and not once there are entries.
			if ( '' !== $this->get_post_string( 'competition_type' ) ) {
				$data['type_id'] = absint( $this->get_post_string( 'competition_type' ) );
			}

			$before = $this->competitions->find( $competition_id );
			$result = $this->link_overlap_error( $this->competitions->update( $competition_id, $data ) );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_manager',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);

				$this->redirect_with_settings_errors( $this->edit_url( $competition_id ) );
			}

			if ( $before && isset( $data['type_id'] ) && (int) $data['type_id'] !== (int) $before->type_id ) {
				$new_type = $this->types->find( (int) $data['type_id'] );
				if ( $new_type ) {
					add_settings_error(
						'photo_competition_manager',
						'type_settings_copied',
						sprintf(
							/* translators: %s: competition type name */
							__( 'Categories and settings now come from %s.', 'photo-competition-manager' ),
							$new_type->name
						),
						'updated'
					);
				}
			}

			add_settings_error(
				'photo_competition_manager',
				'updated',
				__( 'Competition updated successfully.', 'photo-competition-manager' ),
				'updated'
			);

			$this->redirect_with_settings_errors( $this->dashboard_url() );
		}

		if ( 'generate_results_link' === $action && isset( $_GET['competition'] ) ) {
			$competition_id = absint( wp_unslash( $_GET['competition'] ) );

			check_admin_referer( 'photo_competition_generate_results_link_' . $competition_id );

			$competition = $this->competitions->find( $competition_id );
			if ( ! $competition ) {
				add_settings_error(
					'photo_competition_manager',
					'competition_not_found',
					__( 'Competition not found.', 'photo-competition-manager' ),
					'error'
				);
				$this->redirect_with_settings_errors( $this->dashboard_url() );
			}

			$new_hash = Competition_Settings::generate_share_hash();
			$result   = $this->competitions->update_share_hash( $competition_id, $new_hash );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_manager',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				$results_page_url = Competition_Settings::page_url( 'results_page', $competition );
				if ( '' !== $results_page_url ) {
					$share_url = add_query_arg( 'share', $new_hash, $results_page_url );
					add_settings_error(
						'photo_competition_manager',
						'results_link_generated',
						sprintf(
							/* translators: %1$s: share URL href, %2$s: share URL display text */
							__( 'Results share link generated: <a href="%1$s" target="_blank">%2$s</a>', 'photo-competition-manager' ),
							esc_url( $share_url ),
							esc_html( $share_url )
						),
						'updated'
					);
				} else {
					add_settings_error(
						'photo_competition_manager',
						'results_link_generated',
						__( 'Results share hash generated. Configure a results page URL to get a shareable link.', 'photo-competition-manager' ),
						'updated'
					);
				}
			}

			$this->redirect_with_settings_errors( $this->dashboard_url() );
		}

		if ( 'close_competition' === $action && isset( $_GET['competition'] ) ) {
			$competition_id = absint( wp_unslash( $_GET['competition'] ) );

			check_admin_referer( 'photo_competition_close_' . $competition_id );

			$result = $this->workflow->close_competition( $competition_id );

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_manager',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'photo_competition_manager',
					'competition_closed',
					__( 'Competition closed.', 'photo-competition-manager' ),
					'updated'
				);
			}

			$this->redirect_with_settings_errors( $this->dashboard_url() );
		}

		if ( in_array( $action, array( 'archive', 'restore', 'send_emails', 'delete', 'reset_votes' ), true ) && isset( $_GET['competition'] ) ) {
			$competition_id = absint( wp_unslash( $_GET['competition'] ) );
			$nonces         = array(
				'send_emails' => 'photo_competition_send_emails_',
				'archive'     => 'photo_competition_archive_',
				'restore'     => 'photo_competition_restore_',
				'delete'      => 'photo_competition_delete_',
				'reset_votes' => 'photo_competition_reset_votes_',
			);
			$nonce_action   = $nonces[ $action ];

			check_admin_referer( $nonce_action . $competition_id );

			if ( 'delete' === $action ) {
				// The entries go first, so their files and originals go with them.
				$result = $this->entries->remove_competition_entries( Actor::admin(), $competition_id );
				if ( ! is_wp_error( $result ) ) {
					$result = $this->competitions->delete( $competition_id );
				}

				if ( is_wp_error( $result ) ) {
					add_settings_error(
						'photo_competition_manager',
						$result->get_error_code(),
						$result->get_error_message(),
						'error'
					);
				} else {
					$this->email_jobs->discard_competition_jobs( $competition_id, __( 'Competition deleted', 'photo-competition-manager' ) );

					add_settings_error(
						'photo_competition_manager',
						'deleted',
						__( 'Competition permanently deleted.', 'photo-competition-manager' ),
						'updated'
					);
				}

				$this->redirect_with_settings_errors( $this->dashboard_url() );
			}

			if ( 'reset_votes' === $action ) {
				$result = $this->workflow->reset_competition( $competition_id );

				if ( is_wp_error( $result ) ) {
					add_settings_error(
						'photo_competition_manager',
						$result->get_error_code(),
						$result->get_error_message(),
						'error'
					);
				} else {
					add_settings_error(
						'photo_competition_manager',
						'votes_reset',
						__( 'All votes, tokens, and voting progress have been reset for this competition.', 'photo-competition-manager' ),
						'updated'
					);
				}

				$this->redirect_with_settings_errors( $this->dashboard_url() );
			}

			if ( 'send_emails' === $action ) {
				$upload_link_service = new Upload_Link_Service();
				$job_id              = $upload_link_service->queue_reminders( $competition_id, $this->email_jobs );

				if ( is_wp_error( $job_id ) ) {
					add_settings_error(
						'photo_competition_manager',
						$job_id->get_error_code(),
						$job_id->get_error_message(),
						'error'
					);
					$this->redirect_with_settings_errors( $this->dashboard_url() );
				}

				wp_safe_redirect( add_query_arg( 'job_id', $job_id, $this->dashboard_url() ) );
				exit;
			}

			if ( 'archive' === $action ) {
				$result = $this->competitions->archive( $competition_id );
			} else {
				$result = $this->link_overlap_error( $this->competitions->restore( $competition_id ) );
			}

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_manager',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				$message = 'archive' === $action
				? __( 'Competition archived.', 'photo-competition-manager' )
				: __( 'Competition restored.', 'photo-competition-manager' );

				add_settings_error(
					'photo_competition_manager',
					'archive' === $action ? 'archived' : 'restored',
					$message,
					'updated'
				);
			}

			$redirect = 'restore' === $action
			? add_query_arg(
				array(
					'page' => 'photo-competition-manager',
					'view' => 'archived',
				),
				admin_url( 'admin.php' )
			)
			: $this->dashboard_url();

			$this->redirect_with_settings_errors( $redirect );
		}

		// Centralised toggle_uploads handler — all pages route here via ref_page for redirect.
		if ( 'toggle_uploads' === $action && isset( $_GET['competition'] ) ) {
			$competition_id = absint( wp_unslash( $_GET['competition'] ) );

			check_admin_referer( 'photo_competition_toggle_uploads_' . $competition_id );

			$competition = $this->competitions->find( $competition_id );

			if ( $competition ) {
				$uploads_closed = $this->workflow->uploads_closed( $competition );
				$result         = $uploads_closed
					? $this->workflow->reopen_uploads( $competition_id )
					: $this->workflow->close_uploads( $competition_id );

				if ( is_wp_error( $result ) ) {
					add_settings_error(
						'photo_competition_manager',
						$result->get_error_code(),
						$result->get_error_message(),
						'error'
					);
				} else {
					$message = $uploads_closed
						? __( 'Uploads reopened. Members can now upload images.', 'photo-competition-manager' )
						: __( 'Uploads closed. Members can no longer upload images.', 'photo-competition-manager' );
					add_settings_error(
						'photo_competition_manager',
						'uploads_toggled',
						$message,
						'updated'
					);
				}
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Safe read of ref_page for redirect routing.
			$ref_page     = isset( $_GET['ref_page'] ) ? sanitize_key( wp_unslash( $_GET['ref_page'] ) ) : '';
			$redirect_map = array(
				'voting'  => add_query_arg( array( 'page' => 'photo-competition-manager-voting' ), admin_url( 'admin.php' ) ),
				'members' => $this->members_url(),
			);
			$redirect_url = $redirect_map[ $ref_page ] ?? $this->dashboard_url();

			$this->redirect_with_settings_errors( $redirect_url );
		}

		if ( in_array( $action, array( 'update_competition_judges', 'send_judge_link', 'send_all_judge_links' ), true ) ) {
			$this->handle_judge_actions( $action );
		}

		if ( in_array( $action, array( 'set_entry_mode', 'add_entrants', 'add_new_entrant', 'remove_entrant', 'send_entrant_upload_link' ), true ) ) {
			$this->handle_entrant_actions( $action );
		}

		if ( 'update_competition_settings' === $action ) {
			$competition_id = absint( $this->get_post_string( 'competition_id' ) );

			check_admin_referer( 'photo_competition_update_settings_' . $competition_id, 'photo_competition_nonce' );

			$existing_competition = $this->competitions->find( $competition_id );
			$existing_settings    = $existing_competition ? Competition_Settings::parse( $existing_competition->settings ) : array();

			$settings   = ( new Type_Settings_Form() )->from_post( array_intersect_key( $existing_settings, array_flip( Competition_Settings::TYPE_KEYS ) ) );
			$validation = Competition_Settings::validate_type_settings( $settings );

			if ( is_wp_error( $validation ) ) {
				add_settings_error(
					'photo_competition_manager',
					$validation->get_error_code(),
					$validation->get_error_message(),
					'error'
				);

				$this->redirect_with_settings_errors( $this->settings_tab_url( $competition_id ) );
			}

			// Votes are tied to who cast them, so who scores can't move between members and judges once any exist.
			if ( $existing_competition
				&& Ballots::is_judged( $existing_competition ) !== ( 'judges' === $settings['voting']['auth_mode'] )
				&& $this->votes->has_votes( $competition_id )
			) {
				add_settings_error(
					'photo_competition_manager',
					'scored_by_locked',
					__( 'Votes have been cast, so this competition can\'t switch between members and judges scoring it. Reset its votes first.', 'photo-competition-manager' ),
					'error'
				);

				$this->redirect_with_settings_errors( $this->settings_tab_url( $competition_id ) );
			}

			$voting_password       = $settings['voting']['password'];
			$voting_password_clear = '1' === $this->get_post_string( 'voting_password_clear' );
			$voting_ui_type        = sanitize_text_field( $this->get_post_string( 'voting_ui_type', 'buttons' ) );
			if ( ! in_array( $voting_ui_type, array( 'buttons', 'dropdown' ), true ) ) {
				$voting_ui_type = 'buttons';
			}

			// Store the password if provided, clear if empty or checkbox checked.
			// For legacy hashed passwords (not shown in the form), preserve when field is blank.
			$existing_password = $existing_settings['voting']['password'] ?? '';
			$is_legacy_hash    = '' !== $existing_password && (bool) preg_match( '/^\$P\$|\$wp\$/', $existing_password );
			$hashed_password   = '';
			if ( $voting_password_clear ) {
				// Clear the password via checkbox (legacy hash flow).
				$hashed_password = '';
			} elseif ( ! empty( $voting_password ) ) {
				// Password provided - store lowercase for case-insensitive comparison.
				$hashed_password = strtolower( $voting_password );
			} elseif ( $is_legacy_hash ) {
				// Legacy hash: blank field means keep existing password.
				$hashed_password = $existing_password;
			}

			$settings['voting']['password'] = $hashed_password;
			$settings['voting']['ui_type']  = $voting_ui_type;
			$settings['urls']               = array(
				'upload_page' => sanitize_url( $this->get_post_string( 'upload_page_url', '' ) ),
				'voting_page' => sanitize_url( $this->get_post_string( 'voting_page_url', '' ) ),
			);

			$result = $this->competitions->update(
				$competition_id,
				array(
					'settings' => $settings,
				)
			);

			if ( is_wp_error( $result ) ) {
				add_settings_error(
					'photo_competition_manager',
					$result->get_error_code(),
					$result->get_error_message(),
					'error'
				);
			} else {
				add_settings_error(
					'photo_competition_manager',
					'settings_updated',
					__( 'Competition settings updated successfully.', 'photo-competition-manager' ),
					'updated'
				);
			}

			$this->redirect_with_settings_errors( $this->settings_tab_url( $competition_id ) );
		}
	}

	/**
	 * Handle the judges panel's actions.
	 *
	 * @since 0.4.0
	 *
	 * @param string $action Action from the request.
	 * @return void
	 */
	private function handle_judge_actions( string $action ): void {
		$competition_id = absint( $this->get_post_string( 'competition_id' ) );

		check_admin_referer( 'photo_competition_judges_' . $competition_id, 'photo_competition_nonce' );

		$competition = $this->competitions->find( $competition_id );
		$url         = $this->settings_tab_url( $competition_id );

		if ( ! $competition || ! Ballots::is_judged( $competition ) ) {
			$this->queue_notice( 'judges_not_scoring', __( 'Judges aren\'t scoring this competition.', 'photo-competition-manager' ), 'error' );
			$this->redirect_with_settings_errors( $url );
		}

		if ( 'update_competition_judges' === $action ) {
			$wanted = array_map( 'absint', $this->get_post_array( 'judge_ids' ) );

			foreach ( $this->judges->all() as $judge ) {
				if ( in_array( (int) $judge->id, $wanted, true ) ) {
					$this->competition_judges->assign( $competition_id, (int) $judge->id );
				} else {
					$this->competition_judges->unassign( $competition_id, (int) $judge->id );
				}
			}

			$this->queue_notice( 'judges_updated', __( 'Judges updated.', 'photo-competition-manager' ), 'updated' );
			$this->redirect_with_settings_errors( $url );
		}

		$assigned = $this->competition_judges->judges_for( $competition_id );

		if ( 'send_judge_link' === $action ) {
			$judge_id = absint( $this->get_post_string( 'judge_id' ) );
			$assigned = array_values(
				array_filter(
					$assigned,
					function ( $judge ) use ( $judge_id ) {
						return (int) $judge->id === $judge_id;
					}
				)
			);
		}

		$this->send_judge_links( $competition, $assigned );
		$this->redirect_with_settings_errors( $url );
	}

	/**
	 * Handle the entrants panel's actions.
	 *
	 * @since 0.4.0
	 *
	 * @param string $action Action from the request.
	 * @return void
	 */
	private function handle_entrant_actions( string $action ): void {
		$competition_id = absint( $this->get_post_string( 'competition_id' ) );

		check_admin_referer( 'photo_competition_entrants_' . $competition_id, 'photo_competition_nonce' );

		$competition = $this->competitions->find( $competition_id );
		$url         = $this->tab_url( $competition_id, 'entrants' );

		if ( ! $competition ) {
			$this->queue_notice( 'competition_not_found', __( 'Competition not found.', 'photo-competition-manager' ), 'error' );
			$this->redirect_with_settings_errors( $this->dashboard_url() );
		}

		$member_id = absint( $this->get_post_string( 'member_id' ) );

		switch ( $action ) {
			case 'set_entry_mode':
				$result = $this->entry->set_mode( $competition, sanitize_key( $this->get_post_string( 'entry_mode' ) ) );
				$this->entrant_notice( $result, 'entry_mode_updated', __( 'Entry setting saved.', 'photo-competition-manager' ) );
				break;

			case 'add_entrants':
				// Checked once, so a refusal shows one notice rather than one per member.
				$can_add = $this->entry->can_add_entrants( $competition );
				if ( is_wp_error( $can_add ) ) {
					$this->entrant_notice( $can_add, '', '' );
					break;
				}
				$added = 0;
				foreach ( array_unique( array_map( 'absint', $this->get_post_array( 'member_ids' ) ) ) as $id ) {
					$result = $this->entry->add_entrant( $competition, $id );
					$added += $this->was_entered( $result ) ? 1 : 0;
					$this->entrant_notice( $result, '', '' );
				}
				$this->entrants_added_notice( $added );
				break;

			case 'add_new_entrant':
				// Checked first, so a refused entry leaves no member behind.
				$can_add = $this->entry->can_add_entrants( $competition );
				if ( is_wp_error( $can_add ) ) {
					$this->entrant_notice( $can_add, '', '' );
					break;
				}
				$email    = sanitize_email( $this->get_post_string( 'member_email' ) );
				$existing = $this->members->find_by_email( $email );
				if ( $existing ) {
					$this->enter_existing_member( $competition, $existing );
					break;
				}
				$new_id = $this->members->create(
					array(
						'name'     => sanitize_text_field( $this->get_post_string( 'member_name' ) ),
						'email'    => $email,
						'grade'    => sanitize_key( $this->get_post_string( 'member_grade' ) ),
						'type_ids' => array( (int) $competition->type_id ),
					)
				);
				if ( is_wp_error( $new_id ) ) {
					$this->entrant_notice( $new_id, '', '' );
					break;
				}
				$result = $this->entry->add_entrant( $competition, $new_id );
				$this->entrant_notice( $result, '', '' );
				$this->entrants_added_notice( $this->was_entered( $result ) ? 1 : 0 );
				break;

			case 'remove_entrant':
				$result = $this->entry->remove_entrant( $competition, $member_id );
				$this->entrant_notice( $result, 'entrant_removed', __( 'Entrant removed.', 'photo-competition-manager' ) );
				break;

			case 'send_entrant_upload_link':
				$result = $this->entry->send_upload_link( $competition, $member_id );
				$this->entrant_notice( $result, 'upload_link_sent', __( 'Upload link sent.', 'photo-competition-manager' ) );
				break;
		}

		$this->redirect_with_settings_errors( $url );
	}

	/**
	 * Enter a member whose email was typed into "Add a new member", as if they'd
	 * been picked. An inactive member isn't entered, since they couldn't upload.
	 *
	 * @param object $competition Competition row.
	 * @param object $member      The member holding that email.
	 * @return void
	 */
	private function enter_existing_member( object $competition, object $member ): void {
		if ( empty( $member->active ) ) {
			$this->queue_notice(
				'inactive_member',
				/* translators: %s: Member name */
				sprintf( __( '%s is an inactive member. Reactivate them on the Members screen, then add them as an entrant.', 'photo-competition-manager' ), $member->name ),
				'error'
			);
			return;
		}

		$result = $this->entry->add_entrant( $competition, (int) $member->id );
		$this->entrant_notice(
			$result,
			'existing_member_entered',
			/* translators: %s: Member name */
			sprintf( __( '%s is already a member, so they were entered.', 'photo-competition-manager' ), $member->name )
		);
	}

	/**
	 * Queue an entrants action's outcome as a notice: its error, or the success message if given.
	 *
	 * @param true|\WP_Error $result  Outcome.
	 * @param string         $code    Success notice code, or '' for none.
	 * @param string         $message Success message.
	 * @return void
	 */
	private function entrant_notice( $result, string $code, string $message ): void {
		if ( is_wp_error( $result ) ) {
			$this->queue_notice( $result->get_error_code(), $result->get_error_message(), 'error' );
		} elseif ( '' !== $code ) {
			$this->queue_notice( $code, $message, 'updated' );
		}
	}

	/**
	 * Whether add_entrant() entered the member, even if their upload link didn't go.
	 *
	 * @param true|\WP_Error $result add_entrant()'s outcome.
	 * @return bool
	 */
	private function was_entered( $result ): bool {
		return true === $result || 'upload_link_not_sent' === $result->get_error_code();
	}

	/**
	 * Queue the notice for entrants added, if any were.
	 *
	 * @param int $added Entrants added.
	 * @return void
	 */
	private function entrants_added_notice( int $added ): void {
		if ( $added ) {
			$this->queue_notice(
				'entrants_added',
				/* translators: %d: Entrants added */
				sprintf( _n( '%d entrant added.', '%d entrants added.', $added, 'photo-competition-manager' ), $added ),
				'updated'
			);
		}
	}

	/**
	 * Email judges their links and queue the outcome as a notice.
	 *
	 * @since 0.4.0
	 *
	 * @param object   $competition Competition row.
	 * @param object[] $judges      Assigned judges, with their tokens.
	 * @return void
	 */
	private function send_judge_links( object $competition, array $judges ): void {
		if ( ! $judges ) {
			$this->queue_notice( 'no_judges_to_email', __( 'There are no judges to email.', 'photo-competition-manager' ), 'error' );
			return;
		}

		$email_service = new Email_Service();
		$sent          = 0;
		$errors        = array();

		foreach ( $judges as $judge ) {
			$link = Judge_Link::url( $competition, $judge->token );

			if ( '' === $link ) {
				$this->queue_notice( 'judge_link_no_voting_page', __( 'No voting page is set for this competition and no published page has the voting shortcode, so there is no link to send.', 'photo-competition-manager' ), 'error' );
				return;
			}

			$result = $email_service->send(
				'judge_link',
				$judge,
				$competition,
				array(
					'{judge_link}' => $link,
					'{judge_name}' => (string) $judge->name,
				)
			);

			if ( is_wp_error( $result ) ) {
				/* translators: 1: Judge name, 2: Reason */
				$errors[] = sprintf( __( 'Could not email %1$s: %2$s', 'photo-competition-manager' ), $judge->name, $result->get_error_message() );
				continue;
			}

			$this->competition_judges->mark_sent( (int) $competition->id, (int) $judge->id );
			++$sent;
		}

		foreach ( $errors as $error ) {
			$this->queue_notice( 'judge_link_failed', $error, 'error' );
		}

		if ( $sent ) {
			$this->queue_notice(
				'judge_links_sent',
				/* translators: %d: Judges emailed */
				sprintf( _n( 'Link sent to %d judge.', 'Links sent to %d judges.', $sent, 'photo-competition-manager' ), $sent ),
				'updated'
			);
		}
	}

	/**
	 * Queue a notice for the next screen.
	 *
	 * @param string $code    Notice code.
	 * @param string $message Message.
	 * @param string $type    'error' or 'updated'.
	 * @return void
	 */
	private function queue_notice( string $code, string $message, string $type ): void {
		add_settings_error( 'photo_competition_manager', $code, $message, $type );
	}

	/**
	 * A competition's settings tab URL.
	 *
	 * @param int $competition_id Competition ID.
	 * @return string
	 */
	private function settings_tab_url( int $competition_id ): string {
		return $this->tab_url( $competition_id, 'settings' );
	}

	/**
	 * A competition's edit screen URL, on a tab.
	 *
	 * @since 0.4.0
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $tab            Tab slug.
	 * @return string
	 */
	private function tab_url( int $competition_id, string $tab ): string {
		return add_query_arg( 'tab', $tab, $this->edit_url( $competition_id ) );
	}

	/**
	 * The close date to save from the edit form, which only has the day.
	 *
	 * The same day keeps the stored time, such as the one Close Competition
	 * set. A day that has come, on a competition its close date hasn't
	 * closed yet, closes it now. The start of the day would date the close
	 * before anything done earlier that day, such as hiding results to
	 * correct them, and a record made then would be trusted (see
	 * Results_Ranking::record()).
	 *
	 * @since 0.4.0
	 *
	 * @param int         $competition_id Competition ID.
	 * @param string|null $day            Close day from the form (Y-m-d), or null for none.
	 * @return string|null Close date to save.
	 */
	private function close_date_from_form( int $competition_id, ?string $day ): ?string {
		$competition = $this->competitions->find( $competition_id );

		if ( ! $competition || null === $day ) {
			return $day;
		}

		$current = $competition->close_date;

		if ( $day === $this->format_date_for_input( $current ) ) {
			return $current;
		}

		$now          = utc_time();
		$had_closed   = ! empty( $current ) && $current <= $now;
		$day_has_come = $day . ' 00:00:00' <= $now;

		return ! $had_closed && $day_has_come ? $now : $day;
	}

	/**
	 * Link the competition named in an overlap error, since it may be too
	 * old to appear in the dashboard list.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed $result Result of a repository create(), update() or restore().
	 * @return mixed The same result, with an overlap error's message linked.
	 */
	private function link_overlap_error( $result ) {
		if ( ! is_wp_error( $result ) || 'competition_overlap' !== $result->get_error_code() ) {
			return $result;
		}

		$data  = $result->get_error_data();
		$other = $data['competition'];

		return new \WP_Error(
			'competition_overlap',
			sprintf(
				/* translators: %s: linked title of the overlapping competition */
				__( 'These dates overlap %s, and only one competition can be open at a time. Change its dates or close it first.', 'photo-competition-manager' ),
				'<a href="' . esc_url( $this->edit_url( (int) $other->id ) ) . '">' . esc_html( ( new Competition_Labels() )->title_for_everyone( $other ) ) . '</a>'
			),
			$data
		);
	}

	/**
	 * Render admin dashboard overview.
	 *
	 * @return void
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view; actions enforce nonces during processing.
		$action_query = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars to render appropriate admin view; actions enforce nonces during processing.
		$competition_query = isset( $_GET['competition'] ) ? absint( wp_unslash( $_GET['competition'] ) ) : 0;

		if ( 'edit' === $action_query && $competition_query ) {
			echo $this->render_edit_screen( $competition_query ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
			return;
		}

		settings_errors( 'photo_competition_manager' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_email_job_notice( $this->email_jobs );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading query vars for filtering only; no data mutation.
		$view         = isset( $_GET['view'] ) ? sanitize_text_field( wp_unslash( $_GET['view'] ) ) : 'active';
		$competitions = $this->competitions->all( 10, 'archived' === $view, 'archived' === $view );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Photo Competition Manager Dashboard', 'photo-competition-manager' ) . '</h1>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.
		echo $this->render_multiple_open_notice( $this->competitions->all_open(), false );

		echo $this->render_competition_table( $competitions, $view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.

		echo $this->render_create_form(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.

		echo '</div>';
	}

	/**
	 * Render the edit competition screen.
	 *
	 * @param  int $competition_id Competition ID.
	 * @return string
	 */
	private function render_edit_screen( int $competition_id ): string {
		settings_errors( 'photo_competition_manager' );

		$competition = $this->competitions->find( $competition_id );

		if ( ! $competition ) {
			return $this->render_template(
				'admin/competitions/edit-screen.php',
				array(
					'found'         => false,
					'dashboard_url' => $this->dashboard_url(),
				)
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query var used to switch tabs only.
		$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';

		$tabs_html = $this->render_competition_tabs( $competition_id, $current_tab );

		if ( 'settings' === $current_tab ) {
			$form_html = $this->render_competition_settings_form( $competition ) . $this->render_judges_panel( $competition );
		} elseif ( 'entrants' === $current_tab ) {
			$form_html = $this->render_entrants_panel( $competition );
		} else {
			$form_html = $this->render_competition_general_form( $competition );
		}

		return $this->render_template(
			'admin/competitions/edit-screen.php',
			array(
				'found'         => true,
				'tabs_html'     => $tabs_html,
				'form_html'     => $form_html,
				'dashboard_url' => $this->dashboard_url(),
			)
		);
	}

	/**
	 * Render tabs for competition edit screen.
	 *
	 * @param  int    $competition_id Competition ID.
	 * @param  string $current_tab    Current active tab.
	 * @return string
	 */
	private function render_competition_tabs( int $competition_id, string $current_tab ): string {
		$tabs = array(
			'general'  => __( 'General', 'photo-competition-manager' ),
			'settings' => __( 'Settings', 'photo-competition-manager' ),
			'entrants' => __( 'Entrants', 'photo-competition-manager' ),
		);

		return $this->render_template(
			'admin/competitions/competition-tabs.php',
			array(
				'competition_id' => $competition_id,
				'current_tab'    => $current_tab,
				'tabs'           => $tabs,
			)
		);
	}

	/**
	 * Render general competition form.
	 *
	 * @param  object $competition Competition data.
	 * @return string
	 */
	private function render_competition_general_form( object $competition ): string {
		$types   = $this->types->all();
		$type    = array_column( $types, null, 'id' )[ (int) $competition->type_id ] ?? null;
		$several = count( $types ) > 1;

		return $this->render_template(
			'admin/competitions/general-form.php',
			array(
				'types'            => $several ? $types : array(),
				'type_id'          => (int) $competition->type_id,
				'type_name'        => $type ? $type->name : '',
				'type_locked'      => $several && $this->competitions->has_entries( (int) $competition->id ),
				'competition_id'   => (int) $competition->id,
				'title'            => $competition->title,
				'slug'             => $competition->slug,
				'label_format'     => $this->get_ui_date_label(),
				'open_date_value'  => $this->format_date_for_input( $competition->open_date ),
				'close_date_value' => $this->format_date_for_input( $competition->close_date ),
			)
		);
	}

	/**
	 * Render competition settings form.
	 *
	 * @param  object $competition Competition data.
	 * @return string
	 */
	private function render_competition_settings_form( object $competition ): string {
		$settings            = Competition_Settings::parse( $competition->settings );
		$categories          = Competition_Settings::get_categories( $settings );
		$upload              = Competition_Settings::get_upload_constraints( $settings );
		$voting              = Competition_Settings::get_voting_config( $settings );
		$slideshow           = $settings['slideshow'] ?? array();
		$progress_meter_type = $slideshow['progress_meter_type'] ?? 'bar';
		$voting_ui_type      = $voting['ui_type'] ?? '';
		if ( ! in_array( $voting_ui_type, array( 'buttons', 'dropdown' ), true ) ) {
			$voting_ui_type = Competition_Settings::get_voting_ui_type( $settings );
		}

		$category_rows_html = '';
		foreach ( $categories as $index => $category ) {
			$category_rows_html .= $this->render_category_field( $index, $category );
		}

		$auth_mode = $voting['auth_mode'] ?? 'password';

		$is_plaintext_password = ! empty( $voting['password'] ) && ! preg_match( '/^\$P\$|\$wp\$/', $voting['password'] );
		$is_legacy_hash        = ! empty( $voting['password'] ) && ! $is_plaintext_password;
		$password_value        = $is_plaintext_password ? $voting['password'] : '';

		$click_to_zoom = isset( $voting['click_image_to_zoom'] ) ? (bool) $voting['click_image_to_zoom'] : false;

		$urls = $settings['urls'] ?? array(
			'upload_page' => '',
			'voting_page' => '',
		);

		return $this->render_template(
			'admin/competitions/settings-form.php',
			array(
				'competition_id'      => (int) $competition->id,
				'category_rows_html'  => $category_rows_html,
				'upload'              => $upload,
				'auth_mode'           => $auth_mode,
				'scored_by_locked'    => $this->votes->has_votes( (int) $competition->id ),
				'password_value'      => $password_value,
				'is_legacy_hash'      => $is_legacy_hash,
				'click_to_zoom'       => $click_to_zoom,
				'voting_ui_type'      => $voting_ui_type,
				'score_matrix_text'   => implode( ', ', $voting['score_matrix'] ),
				'progress_meter_type' => $progress_meter_type,
				'urls'                => $urls,
				'share_hash'          => $competition->share_hash ?? '',
				'results_page_url'    => Competition_Settings::page_url( 'results_page', $competition ),
			)
		);
	}

	/**
	 * Render the judges panel, for a competition judges score.
	 *
	 * @since 0.4.0
	 *
	 * @param  object $competition Competition data.
	 * @return string Empty when members score the competition.
	 */
	private function render_judges_panel( object $competition ): string {
		if ( ! Ballots::is_judged( $competition ) ) {
			return '';
		}

		$assigned = array();
		foreach ( $this->competition_judges->judges_for( (int) $competition->id ) as $judge ) {
			$assigned[ (int) $judge->id ] = (object) array(
				'id'      => (int) $judge->id,
				'name'    => $judge->name,
				'email'   => $judge->email,
				'link'    => Judge_Link::url( $competition, $judge->token ),
				'sent_at' => $judge->sent_at ? $this->format_datetime( $judge->sent_at ) : '',
			);
		}

		$judges = array();
		foreach ( $this->judges->all() as $judge ) {
			$judges[] = (object) array(
				'id'       => (int) $judge->id,
				'name'     => $judge->name,
				'email'    => $judge->email,
				'assigned' => isset( $assigned[ (int) $judge->id ] ),
			);
		}

		return $this->render_template(
			'admin/competitions/judges-panel.php',
			array(
				'competition_id' => (int) $competition->id,
				'judges'         => $judges,
				'assigned'       => array_values( $assigned ),
				'judges_url'     => $this->judges_url(),
			)
		);
	}

	/**
	 * Render the entrants panel: who may enter, and the selected entrants.
	 *
	 * @since 0.4.0
	 *
	 * @param  object $competition Competition data.
	 * @return string
	 */
	private function render_entrants_panel( object $competition ): string {
		$competition_id = (int) $competition->id;
		$type           = $this->types->find( (int) $competition->type_id );
		$selected       = Competition_Entry::is_selected( $competition );
		$named_type     = $type && $this->types->has_several() ? $type : null;

		$entrants      = array();
		$member_groups = array();

		if ( $selected ) {
			$entrant_ids  = ( new Entrants_Repository() )->member_ids( $competition_id );
			$with_entries = array_flip( ( new Images_Repository() )->member_ids( $competition_id ) );

			foreach ( $this->members->find_many( $entrant_ids ) as $member ) {
				$entrants[] = (object) array(
					'id'          => (int) $member->id,
					'name'        => $member->name,
					'email'       => $member->email,
					'active'      => (bool) $member->active,
					'has_entries' => isset( $with_entries[ (int) $member->id ] ),
				);
			}
			usort(
				$entrants,
				function ( $a, $b ) {
					return strcasecmp( $a->name, $b->name );
				}
			);

			$member_groups = $this->entrant_picker_groups( (int) $competition->type_id, $entrant_ids, $named_type );
		}

		$refusal = $this->entry->change_refusal( $competition );

		return $this->render_template(
			'admin/competitions/entrants-panel.php',
			array(
				'competition_id' => $competition_id,
				'entry_mode'     => $selected ? 'selected' : 'all',
				'open_label'     => $named_type
					/* translators: %s: Competition type name */
					? sprintf( __( 'Open to everyone in %s', 'photo-competition-manager' ), $named_type->name )
					: __( 'Open to all members', 'photo-competition-manager' ),
				'can_change'     => null === $refusal,
				'locked_message' => $refusal ? $refusal->get_error_message() : '',
				'uploads_open'   => $this->workflow->is_accepting_uploads( $competition ),
				'entrants'       => $entrants,
				'member_groups'  => $member_groups,
				'grade_options'  => Competition_Settings::grade_labels(),
			)
		);
	}

	/**
	 * The "Add entrants" picker's options: active members who aren't entrants,
	 * those with the competition's type first.
	 *
	 * @since 0.4.0
	 *
	 * @param int         $type_id     The competition's type ID.
	 * @param int[]       $entrant_ids Current entrants.
	 * @param object|null $named_type  The competition's type when the club has several, to name its group; null for one list.
	 * @return array<int, array{label: string, members: object[]}> Groups; a group's label is '' when there's only one.
	 */
	private function entrant_picker_groups( int $type_id, array $entrant_ids, ?object $named_type ): array {
		$tagged   = array();
		$others   = array();
		$entrants = array_flip( $entrant_ids );
		$in_type  = array_flip( array_map( 'intval', wp_list_pluck( $this->members->find_by_type( $type_id ), 'id' ) ) );

		foreach ( $this->members->find_active_members() as $member ) {
			if ( isset( $entrants[ (int) $member->id ] ) ) {
				continue;
			}

			$option = (object) array(
				'id'    => (int) $member->id,
				'name'  => $member->name,
				'email' => $member->email,
			);

			if ( isset( $in_type[ (int) $member->id ] ) ) {
				$tagged[] = $option;
			} else {
				$others[] = $option;
			}
		}

		// With one type there's nothing to name, so the members come as one list, the type's first.
		if ( ! $named_type ) {
			return array_merge( $tagged, $others )
				? array(
					array(
						'label'   => '',
						'members' => array_merge( $tagged, $others ),
					),
				)
				: array();
		}

		return array_values(
			array_filter(
				array(
					array(
						'label'   => $named_type->name,
						'members' => $tagged,
					),
					array(
						'label'   => __( 'Other members', 'photo-competition-manager' ),
						'members' => $others,
					),
				),
				function ( $group ) {
					return ! empty( $group['members'] );
				}
			)
		);
	}

	/**
	 * Render category field row.
	 *
	 * @param  int   $index    Category index.
	 * @param  array $category Category data.
	 * @return string
	 */
	private function render_category_field( int $index, array $category ): string {
		return $this->render_template(
			'admin/shared/category-field.php',
			array(
				'index' => $index,
				'label' => $category['label'],
				'slug'  => $category['slug'],
				'quota' => $category['quota'],
			)
		);
	}


	/**
	 * Render competitions list table.
	 *
	 * @param  array<int, object> $competitions Competitions.
	 * @param  string             $view         Current view.
	 * @return string
	 */
	private function render_competition_table( array $competitions, string $view ): string {
		$total_active   = $this->competitions->count( false );
		$total_archived = $this->competitions->count( true );

		$view_defs = array(
			'active'   => __( 'Active', 'photo-competition-manager' ),
			'archived' => __( 'Archived', 'photo-competition-manager' ),
		);

		$view_counts = array(
			'active'   => $total_active,
			'archived' => max( 0, $total_archived ),
		);

		$views = array();
		foreach ( $view_defs as $view_slug => $view_label ) {
			$views[] = array(
				'label'      => $view_label,
				'count'      => $view_counts[ $view_slug ],
				'url'        => add_query_arg(
					array(
						'page' => 'photo-competition-manager',
						'view' => $view_slug,
					),
					admin_url( 'admin.php' )
				),
				'is_current' => $view_slug === $view,
			);
		}

		$rows   = array();
		$labels = new Competition_Labels();
		foreach ( $competitions as $competition ) {
			$is_archived = ! empty( $competition->deleted_at );
			$comp_id     = (int) $competition->id;

			$edit_url = $this->edit_url( $comp_id );

			$toggle_uploads_url = '';
			$uploads_closed     = false;
			$reopen_refusal     = '';
			if ( ! $is_archived ) {
				$uploads_closed = $this->workflow->uploads_closed( $competition );
				if ( $uploads_closed ) {
					$reopen_check   = $this->workflow->can_reopen_uploads( $competition );
					$reopen_refusal = is_wp_error( $reopen_check ) ? $reopen_check->get_error_message() : '';
				}
				$toggle_uploads_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'toggle_uploads',
							'competition' => $comp_id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_toggle_uploads_' . $comp_id
				);
			}

			$is_open        = $this->workflow->is_open( $competition );
			$send_email_url = '';
			$close_url      = '';
			if ( $is_open ) {
				$send_email_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'send_emails',
							'competition' => $comp_id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_send_emails_' . $comp_id
				);
				$close_url      = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'close_competition',
							'competition' => $comp_id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_close_' . $comp_id
				);
			}

			$generate_link_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager',
						'action'      => 'generate_results_link',
						'competition' => $comp_id,
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_generate_results_link_' . $comp_id
			);

			$restore_url = '';
			$archive_url = '';
			if ( $is_archived ) {
				$restore_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'restore',
							'competition' => $comp_id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_restore_' . $comp_id
				);
			} else {
				$archive_url = wp_nonce_url(
					add_query_arg(
						array(
							'page'        => 'photo-competition-manager',
							'action'      => 'archive',
							'competition' => $comp_id,
						),
						admin_url( 'admin.php' )
					),
					'photo_competition_archive_' . $comp_id
				);
			}

			$reset_votes_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager',
						'action'      => 'reset_votes',
						'competition' => $comp_id,
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_reset_votes_' . $comp_id
			);

			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'page'        => 'photo-competition-manager',
						'action'      => 'delete',
						'competition' => $comp_id,
					),
					admin_url( 'admin.php' )
				),
				'photo_competition_delete_' . $comp_id
			);

			$last_updated_raw = ! empty( $competition->updated_at ) ? $competition->updated_at : $competition->created_at;

			$rows[] = array(
				'title'              => $labels->title_for_everyone( $competition ),
				'opens'              => $this->format_datetime( $competition->open_date ),
				'closes'             => $this->format_datetime( $competition->close_date ),
				'last_updated'       => $this->format_datetime( $last_updated_raw ),
				'edit_url'           => $edit_url,
				'is_archived'        => $is_archived,
				'toggle_uploads_url' => $toggle_uploads_url,
				'uploads_closed'     => $uploads_closed,
				'reopen_refusal'     => $reopen_refusal,
				'is_open'            => $is_open,
				'send_email_url'     => $send_email_url,
				'close_url'          => $close_url,
				'generate_link_url'  => $generate_link_url,
				'restore_url'        => $restore_url,
				'archive_url'        => $archive_url,
				'reset_votes_url'    => $reset_votes_url,
				'delete_url'         => $delete_url,
			);
		}

		return $this->render_template(
			'admin/competitions/competitions-table.php',
			array(
				'views' => $views,
				'rows'  => $rows,
			)
		);
	}

	/**
	 * Render the create competition form.
	 *
	 * @return string
	 */
	private function render_create_form(): string {
		$types = $this->types->all();

		return $this->render_template(
			'admin/competitions/create-form.php',
			array(
				'label_format' => $this->get_ui_date_label(),
				'types'        => count( $types ) > 1 ? $types : array(),
			)
		);
	}
}
