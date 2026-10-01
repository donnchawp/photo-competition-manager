<?php
/**
 * Service for logging competition events.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Logs_Repository;

/**
 * Class Event_Logger
 *
 * @since 0.1.0
 */
class Event_Logger {

	/**
	 * Logs repository.
	 *
	 * @var Logs_Repository
	 */
	private $logs_repository;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->logs_repository = new Logs_Repository();
	}

	/**
	 * Log an event.
	 *
	 * @param int|null $competition_id Competition ID (null for global events).
	 * @param string   $event_type Event type (e.g., 'email_sent', 'voting_opened').
	 * @param string   $event_category Event category (e.g., 'email', 'voting', 'upload').
	 * @param string   $description Human-readable description.
	 * @param array    $metadata Optional metadata to store.
	 * @return bool Whether the log was created successfully.
	 */
	public function log(
		?int $competition_id,
		string $event_type,
		string $event_category,
		string $description,
		array $metadata = array()
	): bool {
		$current_user = wp_get_current_user();

		// Determine actor.
		$actor_type = 'system';
		$actor_id   = null;
		$actor_name = __( 'System', 'photo-competition-manager' );

		if ( $current_user->exists() ) {
			$actor_type = 'admin';
			$actor_id   = $current_user->ID;
			$actor_name = $current_user->display_name;
		}

		$data = array(
			'competition_id' => $competition_id,
			'event_type'     => $event_type,
			'event_category' => $event_category,
			'actor_type'     => $actor_type,
			'actor_id'       => $actor_id,
			'actor_name'     => $actor_name,
			'description'    => $description,
			'metadata'       => $metadata,
		);

		$result = $this->logs_repository->create( $data );

		return false !== $result && $result > 0;
	}

	/**
	 * Log an email sent event.
	 *
	 * @param int|null $competition_id Competition ID.
	 * @param string   $email_type Email type (e.g., 'upload_reminder', 'voting_opened').
	 * @param string   $recipient Recipient email or name.
	 * @param array    $metadata Optional metadata.
	 * @return bool
	 */
	public function log_email_sent( ?int $competition_id, string $email_type, string $recipient, array $metadata = array() ): bool {
		$descriptions = array(
			'upload_reminder'            => sprintf(
				/* translators: %s: Recipient name/email */
				__( 'Sent upload reminder email to %s', 'photo-competition-manager' ),
				$recipient
			),
			'voting_opened'              => sprintf(
				/* translators: %s: Recipient name/email */
				__( 'Sent voting link email to %s', 'photo-competition-manager' ),
				$recipient
			),
			'results_email'              => sprintf(
				/* translators: %s: Recipient name/email */
				__( 'Sent results email to %s', 'photo-competition-manager' ),
				$recipient
			),
			'submission_confirmed'       => sprintf(
				/* translators: %s: Recipient name/email */
				__( 'Sent submission confirmation to %s', 'photo-competition-manager' ),
				$recipient
			),
			'voting_opened_notification' => sprintf(
				/* translators: %s: Recipient name/email */
				__( 'Sent voting opened notification to %s', 'photo-competition-manager' ),
				$recipient
			),
			'competition_closed'         => sprintf(
				/* translators: %s: Recipient name/email */
				__( 'Sent competition closed notification to %s', 'photo-competition-manager' ),
				$recipient
			),
			'results_published'          => sprintf(
				/* translators: %s: Recipient name/email */
				__( 'Sent results published notification to %s', 'photo-competition-manager' ),
				$recipient
			),
		);

		$description = $descriptions[ $email_type ] ?? sprintf(
			/* translators: 1: Email type, 2: Recipient */
			__( 'Sent %1$s email to %2$s', 'photo-competition-manager' ),
			$email_type,
			$recipient
		);

		return $this->log(
			$competition_id,
			$email_type,
			'email',
			$description,
			$metadata
		);
	}
}
