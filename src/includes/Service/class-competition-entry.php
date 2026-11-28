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
 * The single answer to "who may enter": every member of the competition's
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
	 * Competition labels, for the grades a competition's type uses.
	 *
	 * @var Competition_Labels
	 */
	private $labels;

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
		$this->labels        = new Competition_Labels();
	}

	/**
	 * Whether a member may enter a competition: they're active, in a grade its
	 * type uses, and, when it's open to everyone in its type, of that type,
	 * or, when it's for selected entrants only, one of them.
	 *
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @return bool
	 */
	public function may_enter( object $competition, object $member ): bool {
		if ( empty( $member->active ) || ! $this->uses_grade_of( $competition, $member ) ) {
			return false;
		}

		if ( self::is_selected( $competition ) ) {
			return $this->entrants->has( (int) $competition->id, (int) $member->id );
		}

		return $this->in_type( $competition, $member );
	}

	/**
	 * Whether a member's type is the competition's type.
	 *
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @return bool
	 */
	private function in_type( object $competition, object $member ): bool {
		$type_id = isset( $member->type_id ) ? (int) $member->type_id : 0;

		return (int) $competition->type_id === $type_id;
	}

	/**
	 * The members an admin can add as entrants: active members of the
	 * competition's type, in a grade it uses, who aren't entrants yet, by name.
	 *
	 * @since 0.4.0
	 * @param object $competition Competition row.
	 * @return object[] Member rows.
	 */
	public function addable_members( object $competition ): array {
		$entrants = array_flip( $this->entrants->member_ids( (int) $competition->id ) );

		return array_values(
			array_filter(
				$this->members->find_by_type( (int) $competition->type_id ),
				function ( $member ) use ( $competition, $entrants ) {
					return ! isset( $entrants[ (int) $member->id ] ) && $this->uses_grade_of( $competition, $member );
				}
			)
		);
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
				function ( $member ) use ( $competition ) {
					return ! empty( $member->active ) && ! empty( $member->email ) && $this->uses_grade_of( $competition, $member );
				}
			)
		);
	}

	/**
	 * Whether a competition's type uses a member's grade.
	 *
	 * @since 0.4.0
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @return bool
	 */
	public function uses_grade_of( object $competition, object $member ): bool {
		return in_array( (string) $member->grade, $this->labels->used_grades( $competition ), true );
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
	 * @return true|WP_Error 'entry_closed', 'not_selected_mode', 'missing_member', 'no_type', 'not_in_type', 'grade_not_used', or
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

		if ( null === $member->type_id ) {
			return new WP_Error(
				'no_type',
				sprintf(
					/* translators: %s: Member name */
					__( '%s has no type. Give them one on the Members screen first.', 'photo-competition-manager' ),
					$member->name
				)
			);
		}

		// A competition's entrants come from its type, which can have its own rules, such as clubs entering.
		if ( ! $this->in_type( $competition, $member ) ) {
			return new WP_Error(
				'not_in_type',
				sprintf(
					/* translators: %s: Member name */
					__( '%s is a member of another type. Change their type on the Members screen first.', 'photo-competition-manager' ),
					$member->name
				)
			);
		}

		if ( ! $this->uses_grade_of( $competition, $member ) ) {
			return new WP_Error(
				'grade_not_used',
				sprintf(
					/* translators: %s: Member name */
					__( '%s is in a grade this competition\'s type doesn\'t use, so they can\'t enter it.', 'photo-competition-manager' ),
					$member->name
				)
			);
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
	 * Set a competition's entry fee and payment instructions. A blank fee
	 * means none. Only a competition for selected entrants takes a fee, and
	 * it can change at any time, since payment is often checked afterwards.
	 *
	 * @since 0.6.0
	 *
	 * @param object $competition  Competition row.
	 * @param string $fee          Fee, such as "€20", or '' for none.
	 * @param string $instructions How to pay.
	 * @return true|WP_Error 'not_selected_mode', or the update's error.
	 */
	public function set_fee( object $competition, string $fee, string $instructions ) {
		if ( ! self::is_selected( $competition ) ) {
			return new WP_Error( 'not_selected_mode', __( 'Only a competition for selected entrants can have an entry fee.', 'photo-competition-manager' ) );
		}

		$updated = $this->competitions->update(
			(int) $competition->id,
			array(
				'entry_fee'            => $fee,
				'payment_instructions' => $instructions,
			)
		);

		return is_wp_error( $updated ) ? $updated : true;
	}

	/**
	 * Whether the competition charges an entry fee: it's for selected
	 * entrants and has a fee set.
	 *
	 * @since 0.6.0
	 *
	 * @param object $competition Competition row.
	 * @return bool
	 */
	public static function has_fee( object $competition ): bool {
		return self::is_selected( $competition ) && '' !== trim( (string) ( $competition->entry_fee ?? '' ) );
	}

	/**
	 * The paragraph telling an entrant the fee and how to pay it, for
	 * emails: '' when the competition has no fee.
	 *
	 * @since 0.6.0
	 *
	 * @param object $competition Competition row.
	 * @return string HTML.
	 */
	public static function fee_paragraph( object $competition ): string {
		if ( ! self::has_fee( $competition ) ) {
			return '';
		}

		$paragraph = '<p>' . esc_html(
			sprintf(
				/* translators: %s: Entry fee, such as "€20" */
				__( 'The entry fee is %s.', 'photo-competition-manager' ),
				$competition->entry_fee
			)
		) . '</p>';

		$instructions = trim( (string) ( $competition->payment_instructions ?? '' ) );

		return '' === $instructions ? $paragraph : $paragraph . wpautop( wp_kses_post( $instructions ) );
	}

	/**
	 * Mark an entrant as having paid the entry fee, or not. It never stops
	 * them entering; what happens to someone who hasn't paid is up to the
	 * organiser.
	 *
	 * @since 0.6.0
	 *
	 * @param object $competition Competition row.
	 * @param int    $member_id   Member ID.
	 * @param bool   $paid        Whether they've paid.
	 * @return true|WP_Error 'no_entry_fee' or 'not_an_entrant'.
	 */
	public function set_paid( object $competition, int $member_id, bool $paid ) {
		if ( ! self::has_fee( $competition ) ) {
			return new WP_Error( 'no_entry_fee', __( 'This competition has no entry fee.', 'photo-competition-manager' ) );
		}

		if ( ! $this->entrants->has( (int) $competition->id, $member_id ) ) {
			return new WP_Error( 'not_an_entrant', __( 'That member isn\'t an entrant in this competition.', 'photo-competition-manager' ) );
		}

		$this->entrants->set_paid( (int) $competition->id, $member_id, $paid );

		return true;
	}

	/**
	 * The entrants who haven't paid the entry fee: none when there is no fee.
	 *
	 * @since 0.6.0
	 *
	 * @param object $competition Competition row.
	 * @return int[] Member IDs, in ID order.
	 */
	public function unpaid_member_ids( object $competition ): array {
		return self::has_fee( $competition ) ? $this->entrants->unpaid_member_ids( (int) $competition->id ) : array();
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
