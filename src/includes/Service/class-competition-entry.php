<?php
/**
 * Who may enter a competition.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Entrants_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use WP_Error;

/**
 * The single answer to "who may enter": everyone tagged with the competition's
 * type, or only its selected entrants. Also changes the entry setting and the
 * entrants, which stop changing once any category's voting has started.
 *
 * @since 0.4.0
 */
class Competition_Entry {

	/**
	 * Entrants repository.
	 *
	 * @var Entrants_Repository
	 */
	private $entrants;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Upload link service.
	 *
	 * @var Upload_Link_Service
	 */
	private $upload_links;

	/**
	 * Upload token repository.
	 *
	 * @var Upload_Token_Repository
	 */
	private $upload_tokens;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository|null $competitions Competitions repository.
	 * @param Members_Repository|null      $members      Members repository.
	 * @param Upload_Link_Service|null     $upload_links Upload link service.
	 */
	public function __construct( ?Competitions_Repository $competitions = null, ?Members_Repository $members = null, ?Upload_Link_Service $upload_links = null ) {
		$this->competitions  = $competitions ?? new Competitions_Repository();
		$this->members       = $members ?? new Members_Repository();
		$this->upload_links  = $upload_links ?? new Upload_Link_Service( null, $this->competitions, $this->members );
		$this->workflow      = new Competition_Workflow( $this->competitions );
		$this->entrants      = new Entrants_Repository();
		$this->images        = new Images_Repository();
		$this->upload_tokens = new Upload_Token_Repository();
	}

	/**
	 * Whether a member may enter a competition: they're active and, when it's
	 * open to everyone in its type, tagged with that type, or, when it's for
	 * selected entrants only, one of them.
	 *
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @return bool
	 */
	public function may_enter( object $competition, object $member ): bool {
		if ( empty( $member->active ) ) {
			return false;
		}

		if ( self::is_selected( $competition ) ) {
			return $this->entrants->has( (int) $competition->id, (int) $member->id );
		}

		return in_array( (int) $competition->type_id, $this->members->type_ids( (int) $member->id ), true );
	}

	/**
	 * The active members who may enter a competition and have an email address.
	 *
	 * @param object $competition Competition row.
	 * @return object[] Member rows, by name.
	 */
	public function eligible_members( object $competition ): array {
		if ( self::is_selected( $competition ) ) {
			$members = array_values( $this->members->find_many( $this->entrants->member_ids( (int) $competition->id ) ) );
			usort(
				$members,
				function ( $a, $b ) {
					return strcasecmp( $a->name, $b->name );
				}
			);
		} else {
			$members = $this->members->find_by_type( (int) $competition->type_id );
		}

		return array_values(
			array_filter(
				$members,
				function ( $member ) {
					return ! empty( $member->active ) && ! empty( $member->email );
				}
			)
		);
	}

	/**
	 * The IDs of the members eligible_members() returns.
	 *
	 * @param object $competition Competition row.
	 * @return int[] Member IDs, by name.
	 */
	public function eligible_member_ids( object $competition ): array {
		return array_map( 'intval', wp_list_pluck( $this->eligible_members( $competition ), 'id' ) );
	}

	/**
	 * Enter a member in a competition for selected entrants. While its uploads
	 * are open, they're sent their upload link.
	 *
	 * @param object $competition Competition row.
	 * @param int    $member_id   Member ID.
	 * @return true|WP_Error 'entry_closed', 'not_selected_mode', 'missing_member', or
	 *                       'upload_link_not_sent' when they were entered but the link didn't go.
	 */
	public function add_entrant( object $competition, int $member_id ) {
		$can_add = $this->can_add_entrants( $competition );
		if ( is_wp_error( $can_add ) ) {
			return $can_add;
		}

		$member = $this->members->find( $member_id );
		if ( ! $member ) {
			return new WP_Error( 'missing_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		$this->entrants->add( (int) $competition->id, $member_id );

		if ( ! $this->workflow->is_accepting_uploads( $competition ) ) {
			return true;
		}

		$sent = $this->send_upload_link( $competition, $member_id );
		if ( is_wp_error( $sent ) ) {
			return new WP_Error(
				'upload_link_not_sent',
				sprintf(
					/* translators: 1: Member name, 2: Reason */
					__( '%1$s was entered, but their upload link wasn\'t sent: %2$s', 'photo-competition-manager' ),
					$member->name,
					$sent->get_error_message()
				)
			);
		}

		return true;
	}

	/**
	 * Email a member their upload link now, even if one went out recently.
	 *
	 * @param object $competition Competition row.
	 * @param int    $member_id   Member ID.
	 * @return true|WP_Error 'uploads_not_open', 'not_an_entrant', or the send's error.
	 */
	public function send_upload_link( object $competition, int $member_id ) {
		if ( ! $this->workflow->is_accepting_uploads( $competition ) ) {
			return new WP_Error( 'uploads_not_open', __( 'Uploads aren\'t open, so there is no upload link to send.', 'photo-competition-manager' ) );
		}

		$sent = $this->upload_links->send_to_member( (int) $competition->id, $member_id, true );

		return is_wp_error( $sent ) ? $sent : true;
	}

	/**
	 * Whether entrants can be added: the competition is for selected entrants
	 * and its voting hasn't started.
	 *
	 * @param object $competition Competition row.
	 * @return true|WP_Error 'entry_closed' or 'not_selected_mode'.
	 */
	public function can_add_entrants( object $competition ) {
		$refused = $this->change_refusal( $competition );
		if ( $refused ) {
			return $refused;
		}

		if ( ! self::is_selected( $competition ) ) {
			return new WP_Error( 'not_selected_mode', __( 'This competition is open to everyone in its type, so it has no entrants to add.', 'photo-competition-manager' ) );
		}

		return true;
	}

	/**
	 * Take a member out of a competition's entrants, revoking their upload link.
	 *
	 * @param object $competition Competition row.
	 * @param int    $member_id   Member ID.
	 * @return true|WP_Error 'entry_closed' or 'entrant_has_entries'.
	 */
	public function remove_entrant( object $competition, int $member_id ) {
		$refused = $this->change_refusal( $competition );
		if ( $refused ) {
			return $refused;
		}

		if ( $this->images->count_by_member_category( (int) $competition->id, $member_id ) > 0 ) {
			return new WP_Error( 'entrant_has_entries', __( 'This entrant has entries. Remove their entries before removing them.', 'photo-competition-manager' ) );
		}

		$this->entrants->remove( (int) $competition->id, $member_id );
		$this->upload_tokens->delete_for( $member_id, (int) $competition->id );

		return true;
	}

	/**
	 * Change who may enter: 'all' for everyone in the competition's type, or
	 * 'selected' for its entrants only. Switching to 'selected' enters
	 * everyone who already has entries.
	 *
	 * @param object $competition Competition row.
	 * @param string $mode        'all' or 'selected'.
	 * @return true|WP_Error 'invalid_entry_mode', 'entry_closed', or the update's error.
	 */
	public function set_mode( object $competition, string $mode ) {
		if ( ! in_array( $mode, array( 'all', 'selected' ), true ) ) {
			return new WP_Error( 'invalid_entry_mode', __( 'Choose who may enter.', 'photo-competition-manager' ) );
		}

		$refused = $this->change_refusal( $competition );
		if ( $refused ) {
			return $refused;
		}

		if ( 'selected' === $mode ) {
			foreach ( $this->images->member_ids( (int) $competition->id ) as $member_id ) {
				$this->entrants->add( (int) $competition->id, $member_id );
			}
		}

		$updated = $this->competitions->update( (int) $competition->id, array( 'entry_mode' => $mode ) );

		return is_wp_error( $updated ) ? $updated : true;
	}

	/**
	 * Whether the competition is for selected entrants only.
	 *
	 * @param object $competition Competition row.
	 * @return bool
	 */
	public static function is_selected( object $competition ): bool {
		return 'selected' === ( $competition->entry_mode ?? 'all' );
	}

	/**
	 * Why who may enter can no longer change, or null while it still can:
	 * not once the competition has closed or any category's voting has started.
	 *
	 * @since 0.4.0
	 * @param object $competition Competition row.
	 * @return WP_Error|null 'entry_closed', or null.
	 */
	public function change_refusal( object $competition ): ?WP_Error {
		if ( $this->workflow->has_closed( $competition ) ) {
			return new WP_Error( 'entry_closed', __( 'This competition has closed, so who enters it can\'t change.', 'photo-competition-manager' ) );
		}

		if ( $this->workflow->has_any_voting_started( $competition ) ) {
			return new WP_Error( 'entry_closed', __( 'Voting has started, so who enters this competition can\'t change.', 'photo-competition-manager' ) );
		}

		return null;
	}
}
