<?php
/**
 * Deleting a member.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use WP_Error;

/**
 * Removes everything the club holds about a member, for the Members screen's
 * Delete and for WordPress's personal data eraser. The votes they cast stay,
 * without anything that identifies them, so no score changes.
 *
 * @since 0.4.0
 */
class Member_Deletion {

	/**
	 * Entries module.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes;

	/**
	 * Voting token repository.
	 *
	 * @var Voting_Token_Repository
	 */
	private $voting_tokens;

	/**
	 * Upload token repository.
	 *
	 * @var Upload_Token_Repository
	 */
	private $upload_tokens;

	/**
	 * Logs repository.
	 *
	 * @var Logs_Repository
	 */
	private $logs;

	/**
	 * Constructor.
	 *
	 * @param Entries|null            $entries Entries module.
	 * @param Members_Repository|null $members Members repository.
	 */
	public function __construct( ?Entries $entries = null, ?Members_Repository $members = null ) {
		$this->members       = $members ?? new Members_Repository();
		$this->entries       = $entries ?? new Entries( null, null, $this->members );
		$this->votes         = new Votes_Repository();
		$this->voting_tokens = new Voting_Token_Repository();
		$this->upload_tokens = new Upload_Token_Repository();
		$this->logs          = new Logs_Repository();
	}

	/**
	 * Delete a member: their entries, tokens, log rows and record. The votes they
	 * cast are kept.
	 *
	 * @param int $member_id Member ID.
	 * @return int|WP_Error Number of the member's votes kept, or the error that stopped it.
	 */
	public function delete( int $member_id ) {
		$member = $this->members->find( $member_id );
		if ( ! $member ) {
			return new WP_Error( 'missing_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		// The entries go first, so their files and originals go with them.
		$removed = $this->entries->remove_member_entries( Actor::admin(), $member_id );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		// Matched the way named voting matches a name, and renamed per member so
		// two deleted voters' votes for one entry stay distinct.
		$renamed = $this->votes->rename_voter( ( new Named_Voter( $member->name ) )->name(), 'Former member #' . $member_id );
		if ( is_wp_error( $renamed ) ) {
			return $renamed;
		}

		$kept = $renamed + $this->votes->count_by_member_tokens( $member_id );

		$email = Members_Repository::unmark_deactivated_email( $member->email );

		// The record goes last, so a deletion that stops part way can be run again.
		if (
			! $this->voting_tokens->delete_by_member( $member_id )
			|| ! $this->upload_tokens->delete_by_member( $member_id )
			|| false === $this->logs->delete_about_member( $member_id, array( $email, Members_Repository::mark_deactivated_email( $email ) ) )
		) {
			return new WP_Error( 'db_delete_failed', __( 'Could not delete everything that names the member. Try again.', 'photo-competition-manager' ) );
		}

		$deleted = $this->members->delete( $member_id );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return $kept;
	}
}
