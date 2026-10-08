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
	 * The count of kept votes includes password votes an earlier, stopped run
	 * already renamed, but not votes cast with voting links it already deleted.
	 *
	 * @param object $member Member record, as Members_Repository finds it.
	 * @return int|WP_Error Number of the member's votes kept, or the error that stopped it.
	 */
	public function delete( object $member ) {
		$member_id = (int) $member->id;

		// The record may have gone since it was loaded, e.g. Delete clicked in two tabs.
		if ( ! $this->members->find( $member_id ) ) {
			return new WP_Error( 'missing_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		// The entries go first, so their files and originals go with them.
		$removed = $this->entries->remove_member_entries( Actor::admin(), $member_id );
		if ( is_wp_error( $removed ) ) {
			return $removed;
		}

		// Matched the way named voting matches a name, and renamed per member so
		// two deleted voters' votes for one entry stay distinct.
		$former_name = 'Former member #' . $member_id;
		$renamed     = $this->votes->rename_voter( ( new Named_Voter( $member->name ) )->name(), $former_name );
		if ( is_wp_error( $renamed ) ) {
			return $renamed;
		}

		$kept = $this->votes->count_by_voter( $former_name ) + $this->votes->count_by_member_tokens( $member_id );

		// The record goes last, so a deletion that stops part way can be run again.
		if (
			! $this->voting_tokens->delete_by_member( $member_id )
			|| ! $this->upload_tokens->delete_by_member( $member_id )
			|| false === $this->logs->delete_about_member( $member_id, Members_Repository::email_forms( $member->email ) )
		) {
			return new WP_Error( 'db_delete_failed', __( 'Could not delete everything that names the member. Try again.', 'photo-competition-manager' ) );
		}

		$deleted = $this->members->delete( $member_id );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		return $kept;
	}

	/**
	 * Add the plugin's eraser to WordPress's personal data erasers.
	 *
	 * @param array<string, array> $erasers Registered erasers.
	 * @return array<string, array>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['photo-competition-manager'] = array(
			'eraser_friendly_name' => __( 'Photo Competition Manager', 'photo-competition-manager' ),
			'callback'             => array( new self(), 'erase' ),
		);

		return $erasers;
	}

	/**
	 * WordPress's personal data eraser: delete the members holding an email address.
	 *
	 * Each page deletes one member record, so a member with many entries can't
	 * run a request out of time. Every record holding the address goes, whether
	 * or not it's marked as deactivated.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1; each page deletes the first record left.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}|WP_Error
	 *         The error when a deletion stops, so WordPress shows it and leaves the request open.
	 */
	public function erase( string $email, int $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress passes the page; the records left say where to carry on.
		$response = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		$members = $this->members->find_all_by_email( $email );
		if ( ! $members ) {
			return $response;
		}

		$kept = $this->delete( $members[0] );
		if ( is_wp_error( $kept ) ) {
			// An array would mark the request completed and tell the person their data was erased.
			return $kept;
		}

		$response['items_removed'] = true;
		$response['done']          = count( $members ) <= 1;

		if ( $kept > 0 ) {
			$response['items_retained'] = true;
			$response['messages'][]     = __( 'The votes this member cast are kept without their name, so the results of past competitions don\'t change.', 'photo-competition-manager' );
		}

		return $response;
	}
}
