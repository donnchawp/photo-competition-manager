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

	/**
	 * Log a voting state change event.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $action Action taken (e.g., 'opened', 'closed').
	 * @param string $category Category affected (optional, empty for global).
	 * @param array  $metadata Optional metadata.
	 * @return bool
	 */
	public function log_voting_state_change( int $competition_id, string $action, string $category = '', array $metadata = array() ): bool {
		if ( ! empty( $category ) ) {
			$description = sprintf(
				/* translators: 1: Action, 2: Category */
				__( 'Voting %1$s for category: %2$s', 'photo-competition-manager' ),
				$action,
				$category
			);
		} else {
			$description = sprintf(
				/* translators: %s: Action */
				__( 'Voting %s', 'photo-competition-manager' ),
				$action
			);
		}

		return $this->log(
			$competition_id,
			'voting_' . $action,
			'voting',
			$description,
			$metadata
		);
	}

	/**
	 * Log an upload state change event.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $action Action taken (e.g., 'opened', 'closed').
	 * @param array  $metadata Optional metadata.
	 * @return bool
	 */
	public function log_upload_state_change( int $competition_id, string $action, array $metadata = array() ): bool {
		$description = sprintf(
			/* translators: %s: Action */
			__( 'Uploads %s', 'photo-competition-manager' ),
			$action
		);

		return $this->log(
			$competition_id,
			'uploads_' . $action,
			'upload',
			$description,
			$metadata
		);
	}

	/**
	 * Log a results state change event.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $action Action taken (e.g., 'shown', 'hidden').
	 * @param array  $metadata Optional metadata.
	 * @return bool
	 */
	public function log_results_state_change( int $competition_id, string $action, array $metadata = array() ): bool {
		$description = sprintf(
			/* translators: %s: Action */
			__( 'Results %s', 'photo-competition-manager' ),
			$action
		);

		return $this->log(
			$competition_id,
			'results_' . $action,
			'voting',
			$description,
			$metadata
		);
	}

	/**
	 * Log a submission event.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $action Action taken (e.g., 'uploaded', 'deleted', 'moved').
	 * @param string $member_name Member name.
	 * @param string $category Category.
	 * @param array  $metadata Optional metadata.
	 * @return bool
	 */
	public function log_submission_event( int $competition_id, string $action, string $member_name, string $category, array $metadata = array() ): bool {
		$descriptions = array(
			'uploaded' => sprintf(
				/* translators: 1: Member name, 2: Category */
				__( 'Image uploaded by %1$s to %2$s', 'photo-competition-manager' ),
				$member_name,
				$category
			),
			'deleted'  => sprintf(
				/* translators: 1: Member name, 2: Category */
				__( 'Image deleted for %1$s from %2$s', 'photo-competition-manager' ),
				$member_name,
				$category
			),
			'moved'    => sprintf(
				/* translators: %s: Member name */
				__( 'Image category changed for %s', 'photo-competition-manager' ),
				$member_name
			),
		);

		$description = $descriptions[ $action ] ?? sprintf(
			/* translators: 1: Action, 2: Member name */
			__( 'Image %1$s for %2$s', 'photo-competition-manager' ),
			$action,
			$member_name
		);

		return $this->log(
			$competition_id,
			'submission_' . $action,
			'upload',
			$description,
			$metadata
		);
	}

	/**
	 * Log a competition lifecycle event.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $action Action taken (e.g., 'created', 'updated', 'archived', 'deleted').
	 * @param string $competition_title Competition title.
	 * @param array  $metadata Optional metadata.
	 * @return bool
	 */
	public function log_competition_event( int $competition_id, string $action, string $competition_title, array $metadata = array() ): bool {
		$descriptions = array(
			'created'     => sprintf(
				/* translators: %s: Competition title */
				__( 'Competition created: %s', 'photo-competition-manager' ),
				$competition_title
			),
			'updated'     => sprintf(
				/* translators: %s: Competition title */
				__( 'Competition updated: %s', 'photo-competition-manager' ),
				$competition_title
			),
			'archived'    => sprintf(
				/* translators: %s: Competition title */
				__( 'Competition archived: %s', 'photo-competition-manager' ),
				$competition_title
			),
			'restored'    => sprintf(
				/* translators: %s: Competition title */
				__( 'Competition restored: %s', 'photo-competition-manager' ),
				$competition_title
			),
			'deleted'     => sprintf(
				/* translators: %s: Competition title */
				__( 'Competition deleted: %s', 'photo-competition-manager' ),
				$competition_title
			),
			'votes_reset' => sprintf(
				/* translators: %s: Competition title */
				__( 'Votes reset for: %s', 'photo-competition-manager' ),
				$competition_title
			),
		);

		$description = $descriptions[ $action ] ?? sprintf(
			/* translators: 1: Action, 2: Competition title */
			__( 'Competition %1$s: %2$s', 'photo-competition-manager' ),
			$action,
			$competition_title
		);

		return $this->log(
			$competition_id,
			'competition_' . $action,
			'competition',
			$description,
			$metadata
		);
	}

	/**
	 * Log a settings update event.
	 *
	 * @param int|null $competition_id Competition ID (null for global settings).
	 * @param string   $settings_type Type of settings (e.g., 'global', 'competition').
	 * @param array    $metadata Optional metadata.
	 * @return bool
	 */
	public function log_settings_update( ?int $competition_id, string $settings_type, array $metadata = array() ): bool {
		if ( 'global' === $settings_type ) {
			$description = __( 'Global settings updated', 'photo-competition-manager' );
		} else {
			$description = __( 'Competition settings updated', 'photo-competition-manager' );
		}

		return $this->log(
			$competition_id,
			'settings_updated',
			'settings',
			$description,
			$metadata
		);
	}

	/**
	 * Log a vote received event.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $voter_name Voter name.
	 * @param string $category Category.
	 * @param array  $metadata Optional metadata.
	 * @return bool
	 */
	public function log_vote_received( int $competition_id, string $voter_name, string $category, array $metadata = array() ): bool {
		$description = sprintf(
			/* translators: 1: Voter name, 2: Category */
			__( 'Votes received from %1$s in %2$s', 'photo-competition-manager' ),
			$voter_name,
			$category
		);

		return $this->log(
			$competition_id,
			'vote_received',
			'voting',
			$description,
			$metadata
		);
	}
}
