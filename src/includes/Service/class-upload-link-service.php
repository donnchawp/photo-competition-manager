<?php
/**
 * Send upload-link emails to members (magic links and bulk reminders).
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use WP_Error;

/**
 * Upload Link Service.
 *
 * @since 0.3.0
 */
class Upload_Link_Service {

	/**
	 * Upload token repository.
	 *
	 * @var Upload_Token_Repository
	 */
	private $token_repo;

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions_repo;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members_repo;

	/**
	 * Email service.
	 *
	 * @var Email_Service
	 */
	private $email_service;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Constructor.
	 *
	 * @param Upload_Token_Repository|null $token_repo        Token repository.
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 * @param Email_Service|null           $email_service     Email service.
	 */
	public function __construct(
		?Upload_Token_Repository $token_repo = null,
		?Competitions_Repository $competitions_repo = null,
		?Members_Repository $members_repo = null,
		?Email_Service $email_service = null
	) {
		$this->token_repo        = $token_repo ?? new Upload_Token_Repository();
		$this->competitions_repo = $competitions_repo ?? new Competitions_Repository();
		$this->workflow          = new Competition_Workflow( $this->competitions_repo );
		$this->members_repo      = $members_repo ?? new Members_Repository();
		$this->email_service     = $email_service ?? new Email_Service();
	}

	/**
	 * Create a fresh upload token and email a magic link to a member.
	 *
	 * Treats recent token as success (rate-limited) to avoid spamming members.
	 *
	 * The link goes to the competition's upload page, found by
	 * Competition_Settings::page_url(), and the email's {voting_page} to its
	 * voting page.
	 *
	 * @since 0.3.0
	 * @since 0.4.0 Finds the upload page itself instead of taking its URL.
	 * @param int  $competition_id Competition ID.
	 * @param int  $member_id      Member ID.
	 * @param bool $force_send     Force sending even if a recent token exists.
	 * @return bool|WP_Error True on success, WP_Error on hard failure, 'no_upload_page' when no upload page can be found.
	 */
	public function send_to_member( int $competition_id, int $member_id, $force_send = false ) {
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new WP_Error( 'missing_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$member = $this->members_repo->find( $member_id );
		if ( ! $member ) {
			return new WP_Error( 'missing_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		if ( ! $member->active ) {
			return new WP_Error( 'inactive_member', __( 'Member account is not active.', 'photo-competition-manager' ) );
		}

		if ( empty( $member->email ) ) {
			return new WP_Error( 'missing_email', __( 'Member does not have an email address.', 'photo-competition-manager' ) );
		}

		// Rate-limit: if an email was sent recently, skip unless forced.
		if ( $this->token_repo->has_recent_email_send( $member_id, $competition_id ) && ! $force_send ) {
			return true;
		}

		$upload_page_url = Competition_Settings::page_url( 'upload_page', $competition );
		if ( '' === $upload_page_url ) {
			return self::no_upload_page();
		}

		$token_obj = $this->token_repo->find_or_create( $member_id, $competition_id );
		if ( is_wp_error( $token_obj ) ) {
			return $token_obj;
		}

		$upload_url = $this->token_repo->generate_upload_url( $competition_id, $member_id, $upload_page_url );
		if ( is_wp_error( $upload_url ) ) {
			return $upload_url;
		}

		$sent = $this->email_service->send(
			'upload_reminder',
			$member,
			$competition,
			array(
				'{upload_link}' => $upload_url,
				'{voting_page}' => Competition_Settings::page_url( 'voting_page', $competition ),
			)
		);

		if ( is_wp_error( $sent ) ) {
			return $sent;
		}

		$this->token_repo->mark_sent( (int) $token_obj->id );

		return true;
	}

	/**
	 * Email an upload link by member email without leaking existence (no enumeration).
	 *
	 * @since 0.3.0
	 * @since 0.4.0 Finds the upload page itself instead of taking its URL.
	 * @param int    $competition_id Competition ID.
	 * @param string $member_email   Member email (unsanitized).
	 * @return bool True if sent or intentionally suppressed; false on hard send failure or when no upload page can be found.
	 */
	public function send_by_email( int $competition_id, string $member_email ): bool {
		$member_email = sanitize_email( $member_email );
		if ( empty( $member_email ) ) {
			return false;
		}

		// Checked before the member, so it says nothing about who is registered.
		$competition = $this->competitions_repo->find( $competition_id );
		if ( $competition && '' === Competition_Settings::page_url( 'upload_page', $competition ) ) {
			return false;
		}

		$member = $this->members_repo->find_by_email( $member_email );

		// If member doesn't exist, pretend success to avoid enumeration.
		if ( ! $member ) {
			return true;
		}

		$result = $this->send_to_member( $competition_id, (int) $member->id );

		// Treat most errors as success to preserve privacy; only fail on hard send errors.
		if ( is_wp_error( $result ) ) {
			return 'send_failed' === $result->get_error_code() ? false : true;
		}

		return (bool) $result;
	}

	/**
	 * Send one member a submission reminder, reporting rate-limited sends as skipped.
	 *
	 * @since 0.3.0
	 * @since 0.4.0 Finds the upload page itself instead of taking its URL.
	 * @param int $competition_id Competition ID.
	 * @param int $member_id      Member ID.
	 * @return string|WP_Error 'sent', 'skipped', or the send error.
	 */
	public function send_reminder( int $competition_id, int $member_id ) {
		$has_recent = $this->token_repo->has_recent_email_send( $member_id, $competition_id );

		$result = $this->send_to_member( $competition_id, $member_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $has_recent ? 'skipped' : 'sent';
	}

	/**
	 * Queue submission reminder emails to all active members for a competition.
	 *
	 * @since 0.3.0
	 * @param int               $competition_id Competition ID.
	 * @param Email_Job_Manager $email_jobs     Email job queue.
	 * @return string|WP_Error Job ID, or why nothing was queued.
	 */
	public function queue_reminders( int $competition_id, Email_Job_Manager $email_jobs ) {
		if ( $competition_id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new WP_Error( 'missing_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		if ( ! $this->workflow->is_open( $competition ) ) {
			return new WP_Error( 'competition_not_open', __( 'Competition must be open to send reminder emails.', 'photo-competition-manager' ) );
		}

		$member_ids = array();
		foreach ( $this->members_repo->find_active_members() as $member ) {
			if ( ! empty( $member->email ) ) {
				$member_ids[] = (int) $member->id;
			}
		}

		if ( empty( $member_ids ) ) {
			return new WP_Error( 'no_members', __( 'No active members found.', 'photo-competition-manager' ) );
		}

		if ( '' === Competition_Settings::page_url( 'upload_page', $competition ) ) {
			return self::no_upload_page();
		}

		return $email_jobs->queue( 'upload_reminder', $competition_id, $member_ids );
	}

	/**
	 * The refusal for a competition with no upload page to link to.
	 *
	 * @return WP_Error
	 */
	private static function no_upload_page(): WP_Error {
		return new WP_Error(
			'no_upload_page',
			__( 'No upload page is set, so there is no upload link to send. Set the upload page in Settings, or publish a page with the [competition_upload] shortcode.', 'photo-competition-manager' )
		);
	}
}
