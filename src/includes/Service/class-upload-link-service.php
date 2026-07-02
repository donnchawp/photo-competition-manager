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
		$this->members_repo      = $members_repo ?? new Members_Repository();
		$this->email_service     = $email_service ?? new Email_Service();
	}

	/**
	 * Create a fresh upload token and email a magic link to a member.
	 *
	 * Treats recent token as success (rate-limited) to avoid spamming members.
	 *
	 * @since 0.3.0
	 * @param int    $competition_id  Competition ID.
	 * @param int    $member_id       Member ID.
	 * @param string $upload_page_url Base URL of the upload page.
	 * @param bool   $force_send      Force sending even if a recent token exists.
	 * @return bool|WP_Error True on success, WP_Error on hard failure.
	 */
	public function send_to_member( int $competition_id, int $member_id, string $upload_page_url, $force_send = false ) {
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new WP_Error( 'missing_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$member = $this->members_repo->find( $member_id );
		if ( ! $member ) {
			return new WP_Error( 'missing_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		if ( empty( $member->email ) ) {
			return new WP_Error( 'missing_email', __( 'Member does not have an email address.', 'photo-competition-manager' ) );
		}

		// Rate-limit: if an email was sent recently, skip unless forced.
		if ( $this->token_repo->has_recent_email_send( $member_id, $competition_id ) && ! $force_send ) {
			return true;
		}

		$token_obj = $this->token_repo->find_or_create( $member_id, $competition_id );
		if ( is_wp_error( $token_obj ) ) {
			return $token_obj;
		}

		$upload_url = $this->token_repo->generate_upload_url( $competition_id, $member_id, $upload_page_url );
		if ( is_wp_error( $upload_url ) ) {
			return $upload_url;
		}

		$settings        = Competition_Settings::parse( $competition->settings );
		$voting_page_url = $settings['urls']['voting_page'] ?? null;

		$sent = $this->email_service->send_upload_link(
			$member->email,
			$member->name ?? $member->email,
			$competition->title,
			$upload_url,
			$competition_id,
			$voting_page_url
		);

		if ( ! $sent ) {
			return new WP_Error( 'send_failed', __( 'Failed to send email.', 'photo-competition-manager' ) );
		}

		$this->token_repo->mark_sent( (int) $token_obj->id );

		return true;
	}

	/**
	 * Email an upload link by member email without leaking existence (no enumeration).
	 *
	 * @since 0.3.0
	 * @param int    $competition_id  Competition ID.
	 * @param string $member_email    Member email (unsanitized).
	 * @param string $upload_page_url Base URL for the upload page.
	 * @return bool True if sent or intentionally suppressed; false only on hard send failure.
	 */
	public function send_by_email( int $competition_id, string $member_email, string $upload_page_url ): bool {
		$member_email = sanitize_email( $member_email );
		if ( empty( $member_email ) ) {
			return false;
		}

		$member = $this->members_repo->find_by_email( $member_email );

		// If member doesn't exist, pretend success to avoid enumeration.
		if ( ! $member ) {
			return true;
		}

		$result = $this->send_to_member( $competition_id, (int) $member->id, $upload_page_url );

		// Treat most errors as success to preserve privacy; only fail on hard send errors.
		if ( is_wp_error( $result ) ) {
			return 'send_failed' === $result->get_error_code() ? false : true;
		}

		return (bool) $result;
	}

	/**
	 * Send submission reminder emails to all members for a competition.
	 *
	 * @since 0.3.0
	 * @param int $competition_id Competition ID.
	 * @return array{success: bool, sent_count: int, skipped_count: int, failed_count: int, total_count: int, errors: array, message: string}|WP_Error
	 */
	public function send_reminders( $competition_id ) {
		if ( $competition_id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new WP_Error( 'missing_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		if ( ! $this->competitions_repo->is_open( $competition ) ) {
			return new WP_Error( 'competition_not_open', __( 'Competition must be open to send reminder emails.', 'photo-competition-manager' ) );
		}

		$members = $this->members_repo->all( 10000, false );
		if ( empty( $members ) ) {
			return new WP_Error( 'no_members', __( 'No active members found.', 'photo-competition-manager' ) );
		}

		// Determine upload page URL; fall back to home URL.
		$upload_page_url = Competition_Settings::find_page_url_with_shortcode( 'competition_upload' );
		if ( empty( $upload_page_url ) ) {
			$upload_page_url = home_url( '/' );
		}
		$upload_page_url = apply_filters( 'photo_competition_manager_upload_page_url', $upload_page_url, $competition );

		$sent_count    = 0;
		$skipped_count = 0;
		$failed_count  = 0;
		$total_count   = count( $members );
		$errors        = array();

		foreach ( $members as $member ) {
			if ( empty( $member->email ) ) {
				continue;
			}

			$has_recent = $this->token_repo->has_recent_email_send( (int) $member->id, (int) $competition_id );

			$result = $this->send_to_member( (int) $competition_id, (int) $member->id, $upload_page_url );

			if ( is_wp_error( $result ) ) {
				++$failed_count;
				$errors[] = sprintf(
					'%s: %s',
					$member->name ?? $member->email,
					$result->get_error_message()
				);
			} elseif ( true === $result ) {
				if ( $has_recent ) {
					++$skipped_count;
				} else {
					++$sent_count;
				}
			}
		}

		return array(
			'success'       => true,
			'sent_count'    => $sent_count,
			'skipped_count' => $skipped_count,
			'failed_count'  => $failed_count,
			'total_count'   => $total_count,
			'errors'        => $errors,
			'message'       => sprintf(
				/* translators: 1: Number of emails sent, 2: Total number of members */
				__( 'Sent %1$d of %2$d submission reminder emails.', 'photo-competition-manager' ),
				$sent_count,
				$total_count
			),
		);
	}
}
