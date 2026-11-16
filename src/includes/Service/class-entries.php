<?php
/**
 * The Entries module: one owner for an entry, its files and its original.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Frontend\Image_Permalink;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Support\Image_Processor;
use WP_Error;

/**
 * Adds, removes and moves entries, applying the rules for the member or admin acting.
 *
 * Entry files live at uploads/competitions/<competition slug>/<category>/<filename>,
 * with the thumbnail beside each image. Originals are media library attachments.
 *
 * @since 0.4.0
 */
class Entries {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions_repo;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images_repo;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members_repo;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes_repo;

	/**
	 * Image processor.
	 *
	 * @var Image_Processor
	 */
	private $image_processor;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Results ranking, which records a closed competition's results before
	 * its entries go.
	 *
	 * @var Results_Ranking
	 */
	private $ranking;

	/**
	 * Constructor.
	 *
	 * @since 0.4.0
	 *
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Images_Repository|null       $images_repo       Images repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 * @param Image_Processor|null         $image_processor   Image processor.
	 * @param Competition_Workflow|null    $workflow          Competition workflow.
	 * @param Votes_Repository|null        $votes_repo        Votes repository.
	 * @param Results_Ranking|null         $ranking           Results ranking.
	 */
	public function __construct(
		?Competitions_Repository $competitions_repo = null,
		?Images_Repository $images_repo = null,
		?Members_Repository $members_repo = null,
		?Image_Processor $image_processor = null,
		?Competition_Workflow $workflow = null,
		?Votes_Repository $votes_repo = null,
		?Results_Ranking $ranking = null
	) {
		$this->competitions_repo = $competitions_repo ?? new Competitions_Repository();
		$this->images_repo       = $images_repo ?? new Images_Repository();
		$this->members_repo      = $members_repo ?? new Members_Repository();
		$this->image_processor   = $image_processor ?? new Image_Processor();
		$this->workflow          = $workflow ?? new Competition_Workflow( $this->competitions_repo );
		$this->votes_repo        = $votes_repo ?? new Votes_Repository();
		$this->ranking           = $ranking ?? new Results_Ranking( $this->images_repo, $this->votes_repo, $this->members_repo, $this->competitions_repo, $this->workflow );
	}

	/**
	 * Add an entry: store the uploaded image and its original, and record it.
	 *
	 * A member may add only their own entries, and only while the competition accepts uploads.
	 * An admin may add for any member at any time. Both are held to the category's quota, and
	 * neither can add to a category whose voting has started (its stage is past Previewed), or that
	 * already has votes. In an interclub competition the entry needs its photographer's name and
	 * is held to the panel's rules: see panel_error().
	 *
	 * @since 0.4.0 Takes the entry's details.
	 *
	 * @param Actor                $actor          Who is adding the entry.
	 * @param int                  $competition_id Competition ID.
	 * @param int                  $member_id      The member the entry belongs to.
	 * @param string               $category       Category slug.
	 * @param array<string, mixed> $file           Uploaded file from $_FILES.
	 * @param array<string, mixed> $details        Interclub details: `photographer` (string) and
	 *                                             `substitute` (bool). An individual competition
	 *                                             ignores the photographer and takes no substitutes.
	 * @return int|WP_Error Entry ID on success, WP_Error on failure.
	 */
	public function add( Actor $actor, int $competition_id, int $member_id, string $category, array $file, array $details = array() ) {
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		if ( ! $actor->is_admin() ) {
			if ( ! $actor->is_member( $member_id ) ) {
				return new WP_Error( 'not_authorized', __( 'You can only add your own entries.', 'photo-competition-manager' ) );
			}

			if ( ! $this->workflow->is_accepting_uploads( $competition ) ) {
				return new WP_Error( 'competition_closed', __( 'Competition is not open for submissions.', 'photo-competition-manager' ) );
			}
		}

		$member = $this->members_repo->find( $member_id );
		if ( ! $member ) {
			return new WP_Error( 'invalid_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		if ( ! $member->active ) {
			return new WP_Error( 'inactive_member', __( 'Member account is not active.', 'photo-competition-manager' ) );
		}

		$settings        = Competition_Settings::parse( $competition->settings );
		$category_config = Competition_Settings::find_category( $settings, $category );

		if ( ! $category_config ) {
			return new WP_Error( 'invalid_category', __( 'Invalid category.', 'photo-competition-manager' ) );
		}

		// Uploads can't reopen once a category's voting has started, so members only get here on a
		// site whose uploads were reopened before that rule, such as one upgraded from 0.3.0.
		if ( $this->workflow->has_voting_started( $competition, $category ) ) {
			return new WP_Error( 'voting_started', __( 'Voting has started in this category, so it can\'t take new entries. Reset the category first.', 'photo-competition-manager' ) );
		}

		// Members can't get here in practice: uploads can't reopen once there are votes. The rule
		// doesn't rely on that.
		$voted = $this->voted_category_error( $competition_id, $category );
		if ( $voted ) {
			return $voted;
		}

		$interclub    = $settings['interclub'];
		$entry_status = empty( $details['substitute'] ) ? 'entered' : 'substitute';
		$photographer = $interclub['enabled'] ? sanitize_text_field( (string) ( $details['photographer'] ?? '' ) ) : '';

		if ( 'substitute' === $entry_status && Competition_Settings::substitutes( $settings ) < 1 ) {
			return new WP_Error( 'no_substitutes', __( 'This competition doesn\'t take substitutes.', 'photo-competition-manager' ) );
		}

		if ( $interclub['enabled'] && '' === $photographer ) {
			return new WP_Error( 'photographer_missing', __( 'Please give the photographer\'s name.', 'photo-competition-manager' ) );
		}

		$panel         = $this->images_repo->find_in_panels( $competition_id, $category, $member_id );
		$current_count = count( $panel );
		$quota         = $category_config['quota'] ?? 1;

		if ( $interclub['enabled'] ) {
			$panel[] = (object) array(
				'entry_status' => $entry_status,
				'photographer' => $photographer,
			);

			$refused = $this->panel_error( $settings, $category_config, $panel );
			if ( $refused ) {
				return $refused;
			}
		} elseif ( $current_count >= $quota ) {
			return new WP_Error(
				'quota_exceeded',
				sprintf(
					/* translators: 1: category label, 2: quota */
					__( 'You have already uploaded the maximum of %2$d image(s) for %1$s.', 'photo-competition-manager' ),
					$category_config['label'],
					$quota
				)
			);
		}

		// Nothing is written until the file passes.
		$constraints = Competition_Settings::get_upload_constraints( $settings );
		$validation  = $this->image_processor->validate( $file, $constraints );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$directory = $this->make_category_directory( $competition->slug, $category );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		// The counter is only in the name when a member may enter more than one image in the category.
		$counter  = $quota > 1 ? $current_count + 1 : 0;
		$username = sanitize_title( $member->name );
		$filename = $username . '-' . sanitize_title( $category ) . ( $counter > 0 ? '-' . $counter : '' ) . '.jpg';
		$title    = $competition->slug . ' - ' . $category . ' - ' . $username . ( $counter > 0 ? ' #' . $counter : '' );

		$result = $this->image_processor->process(
			$file,
			$directory,
			$filename,
			$title,
			// Uninstall finds the plugin's originals by this meta.
			array(
				'_photo_comp_slug'     => $competition->slug,
				'_photo_comp_category' => $category,
				'_photo_comp_member'   => $username,
			),
			$constraints
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$filename      = $result['filename'];
		$attachment_id = $result['attachment_id'];

		$entry_id = $this->images_repo->create(
			array(
				'competition_id'         => $competition_id,
				'member_id'              => $member_id,
				'category'               => $category,
				'filename'               => $filename,
				'original_attachment_id' => $attachment_id,
				'photographer'           => $photographer,
				'entry_status'           => $entry_status,
			)
		);

		if ( is_wp_error( $entry_id ) ) {
			$this->delete_files( $competition, $category, $filename, $attachment_id );
			return $entry_id;
		}

		( new Email_Service() )->send(
			'submission_confirmed',
			$member,
			$competition,
			array(
				'{member_grade}'  => ( new Competition_Labels() )->grade_label( $competition, (string) ( $member->grade ?? '' ) ),
				'{category_name}' => (string) $category_config['label'],
				'{current_count}' => (string) ( $current_count + 1 ),
				'{quota}'         => (string) $quota,
			)
		);

		return $entry_id;
	}

	/**
	 * Remove an entry: its row and votes first, then its image, thumbnail and original.
	 *
	 * A member may remove only their own entries, and only while the competition accepts uploads.
	 * An admin may remove any entry at any time. If the row won't delete, its votes, files and
	 * original are left alone. A file that won't delete is logged and doesn't fail the removal.
	 * A competition whose results are due a record gets one first, so the removal moves nobody.
	 *
	 * @param Actor $actor          Who is removing the entry.
	 * @param int   $competition_id Competition the entry must belong to.
	 * @param int   $entry_id       Entry ID.
	 * @return true|WP_Error
	 */
	public function remove( Actor $actor, int $competition_id, int $entry_id ) {
		$entry = $this->images_repo->find( $entry_id );
		if ( ! $entry ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		if ( (int) $entry->competition_id !== $competition_id ) {
			return new WP_Error( 'invalid_competition', __( 'Image does not belong to this competition.', 'photo-competition-manager' ) );
		}

		$competition = $this->competitions_repo->find( $competition_id, true );
		if ( ! $competition ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		if ( ! $actor->is_admin() ) {
			if ( ! $actor->is_member( (int) $entry->member_id ) ) {
				return new WP_Error( 'not_authorized', __( 'You are not authorized to delete this image.', 'photo-competition-manager' ) );
			}

			if ( ! $this->workflow->is_accepting_uploads( $competition ) ) {
				return new WP_Error( 'competition_closed', __( 'Cannot delete images after competition has closed.', 'photo-competition-manager' ) );
			}
		}

		$recorded = $this->ranking->record_if_missing( $competition );
		if ( is_wp_error( $recorded ) ) {
			return $recorded;
		}

		return $this->remove_entry( $competition, $entry );
	}

	/**
	 * Change an entry's status: disqualify it, promote a substitute, or bring a disqualified entry back.
	 *
	 * Only an admin may do this, and only until the competition's results are recorded. An entry
	 * can be disqualified at any time before then, even after its category has votes: its votes
	 * stay but no longer count. Any other change puts the entry on its category's ballot or among
	 * its substitutes, so it follows the rules for adding one: the category's voting mustn't have
	 * started and it mustn't have votes, and the panel's rules must hold (see panel_error()), or
	 * in an individual competition the category's quota.
	 *
	 * @since 0.4.0
	 *
	 * @param Actor  $actor          Who is changing it.
	 * @param int    $competition_id Competition the entry must belong to.
	 * @param int    $entry_id       Entry ID.
	 * @param string $entry_status   The new status: entered, substitute or disqualified.
	 * @return true|WP_Error
	 */
	public function set_entry_status( Actor $actor, int $competition_id, int $entry_id, string $entry_status ) {
		if ( ! $actor->is_admin() ) {
			return new WP_Error( 'not_authorized', __( 'Only an admin can change an entry\'s status.', 'photo-competition-manager' ) );
		}

		$entry = $this->images_repo->find( $entry_id );
		if ( ! $entry ) {
			return new WP_Error( 'invalid_image', __( 'Image not found.', 'photo-competition-manager' ) );
		}

		if ( (int) $entry->competition_id !== $competition_id ) {
			return new WP_Error( 'invalid_competition', __( 'Image does not belong to this competition.', 'photo-competition-manager' ) );
		}

		$competition = $this->competitions_repo->find( $competition_id, true );
		if ( ! $competition ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		if ( $entry_status === $entry->entry_status ) {
			return true;
		}

		$refused = $this->status_phase_error( $competition, $entry, $entry_status ) ?? $this->status_panel_error( $competition, $entry, $entry_status );
		if ( $refused ) {
			return $refused;
		}

		$updated = $this->images_repo->update_entry_status( $entry_id, $entry_status );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		( new Event_Logger() )->log(
			$competition_id,
			'entry_status_changed',
			'upload',
			sprintf(
				/* translators: 1: entry ID, 2: old status, 3: new status */
				__( 'Entry #%1$d changed from %2$s to %3$s.', 'photo-competition-manager' ),
				$entry_id,
				$entry->entry_status,
				$entry_status
			),
			array(
				'entry_id' => $entry_id,
				'from'     => $entry->entry_status,
				'to'       => $entry_status,
			)
		);

		return true;
	}

	/**
	 * The statuses an admin may move an entry to as the competition stands, in the order of
	 * Images_Repository::ENTRY_STATUSES.
	 *
	 * Only the competition's phase and type decide them. The panel's rules are checked when the
	 * status changes, so a status listed here can still be refused.
	 *
	 * @since 0.4.0
	 *
	 * @param object $competition Competition row.
	 * @param object $entry       Entry row.
	 * @return string[]
	 */
	public function entry_status_options( object $competition, object $entry ): array {
		$options = array();
		foreach ( Images_Repository::ENTRY_STATUSES as $entry_status ) {
			if ( $entry_status !== $entry->entry_status && null === $this->status_phase_error( $competition, $entry, $entry_status ) ) {
				$options[] = $entry_status;
			}
		}

		return $options;
	}

	/**
	 * The refusal for a status change that the competition's phase or type rules out, if it does.
	 *
	 * @param object $competition  Competition row.
	 * @param object $entry        Entry row.
	 * @param string $entry_status The new status.
	 * @return WP_Error|null
	 */
	private function status_phase_error( object $competition, object $entry, string $entry_status ): ?WP_Error {
		if ( ! in_array( $entry_status, Images_Repository::ENTRY_STATUSES, true ) ) {
			return new WP_Error( 'invalid_entry_status', __( 'An image must be entered, a substitute or disqualified.', 'photo-competition-manager' ) );
		}

		if ( $this->workflow->reads_recorded_results( $competition ) ) {
			return new WP_Error( 'results_recorded', __( 'This competition\'s results are recorded, so its entries can\'t change status. Hide its results first.', 'photo-competition-manager' ) );
		}

		if ( 'disqualified' === $entry_status ) {
			return null;
		}

		if ( 'substitute' === $entry_status ) {
			if ( Competition_Settings::substitutes( Competition_Settings::parse( $competition->settings ) ) < 1 ) {
				return new WP_Error( 'no_substitutes', __( 'This competition doesn\'t take substitutes.', 'photo-competition-manager' ) );
			}
		}

		if ( $this->workflow->has_voting_started( $competition, (string) $entry->category ) ) {
			return new WP_Error( 'voting_started', __( 'Voting has started in this category, so its entries are fixed. Reset the category first.', 'photo-competition-manager' ) );
		}

		return $this->voted_category_error( (int) $competition->id, (string) $entry->category );
	}

	/**
	 * The refusal for a status change that would break the entry's panel or quota, if it would.
	 *
	 * Disqualifying never does.
	 *
	 * @param object $competition  Competition row.
	 * @param object $entry        Entry row.
	 * @param string $entry_status The new status.
	 * @return WP_Error|null
	 */
	private function status_panel_error( object $competition, object $entry, string $entry_status ): ?WP_Error {
		if ( 'disqualified' === $entry_status ) {
			return null;
		}

		$settings        = Competition_Settings::parse( $competition->settings );
		$category_config = Competition_Settings::find_category( $settings, (string) $entry->category );
		if ( ! $category_config ) {
			return new WP_Error( 'invalid_category', __( 'Invalid category.', 'photo-competition-manager' ) );
		}

		$panel = array_values(
			array_filter(
				$this->images_repo->find_in_panels( (int) $competition->id, (string) $entry->category, (int) $entry->member_id ),
				static function ( object $image ) use ( $entry ): bool {
					return (int) $image->id !== (int) $entry->id;
				}
			)
		);

		if ( $settings['interclub']['enabled'] ) {
			$panel[] = (object) array(
				'entry_status' => $entry_status,
				'photographer' => $entry->photographer,
			);

			return $this->panel_error( $settings, $category_config, $panel );
		}

		$quota = (int) ( $category_config['quota'] ?? 1 );
		if ( count( $panel ) >= $quota ) {
			return new WP_Error(
				'quota_exceeded',
				sprintf(
					/* translators: 1: category label, 2: quota */
					__( 'This member already has the maximum of %2$d image(s) in %1$s.', 'photo-competition-manager' ),
					$category_config['label'],
					$quota
				)
			);
		}

		return null;
	}

	/**
	 * Remove every entry in a competition, then the competition's folder.
	 *
	 * Only an admin may do this, at any phase. It stops at the first row that won't delete,
	 * leaving that entry and the ones after it in place, and the folder goes only once every
	 * entry is gone.
	 *
	 * @since 0.4.0
	 *
	 * @param Actor $actor          Who is removing the entries.
	 * @param int   $competition_id Competition ID.
	 * @return true|WP_Error
	 */
	public function remove_competition_entries( Actor $actor, int $competition_id ) {
		if ( ! $actor->is_admin() ) {
			return new WP_Error( 'not_authorized', __( 'Only an admin can remove a competition\'s entries.', 'photo-competition-manager' ) );
		}

		$competition = $this->competitions_repo->find( $competition_id, true );
		if ( ! $competition ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		foreach ( $this->images_repo->find_uploads( $competition_id ) as $entry ) {
			$removed = $this->remove_entry( $competition, $entry );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}
		}

		$this->remove_competition_directory( $competition->slug );

		return true;
	}

	/**
	 * Remove every entry a member has, in every competition.
	 *
	 * Only an admin may do this, at any phase. It stops at the first row that won't delete,
	 * leaving that entry and the ones after it in place. Every competition whose results are
	 * due a record gets one first, so the removal moves nobody.
	 *
	 * @since 0.4.0
	 *
	 * @param Actor $actor     Who is removing the entries.
	 * @param int   $member_id Member ID.
	 * @return true|WP_Error
	 */
	public function remove_member_entries( Actor $actor, int $member_id ) {
		if ( ! $actor->is_admin() ) {
			return new WP_Error( 'not_authorized', __( 'Only an admin can remove a member\'s entries.', 'photo-competition-manager' ) );
		}

		$entries      = $this->images_repo->find_by_member( $member_id );
		$competitions = array();
		foreach ( $entries as $entry ) {
			$competition_id = (int) $entry->competition_id;
			if ( ! array_key_exists( $competition_id, $competitions ) ) {
				$competitions[ $competition_id ] = $this->competitions_repo->find( $competition_id, true );
			}
		}

		foreach ( array_filter( $competitions ) as $competition ) {
			$recorded = $this->ranking->record_if_missing( $competition );
			if ( is_wp_error( $recorded ) ) {
				return $recorded;
			}
		}

		foreach ( $entries as $entry ) {
			$removed = $this->remove_entry( $competitions[ (int) $entry->competition_id ], $entry );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}
		}

		return true;
	}

	/**
	 * Remove an entry's row and votes, then its files and original, without checking any rules.
	 *
	 * @param object|null $competition Competition record, or null when it's gone.
	 * @param object      $entry       Entry record.
	 * @return true|WP_Error
	 */
	private function remove_entry( ?object $competition, object $entry ) {
		$deleted = $this->images_repo->delete( (int) $entry->id );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		if ( $competition ) {
			$this->delete_files( $competition, $entry->category, $entry->filename, (int) $entry->original_attachment_id );
		} else {
			// Without its competition there's no folder to look in, but the original is in the media library.
			$this->delete_original( (int) $entry->competition_id, (int) $entry->original_attachment_id );
		}

		return true;
	}

	/**
	 * A competition's originals that are on disk, at the size they were uploaded.
	 *
	 * @since 0.4.0
	 *
	 * @param int $competition_id Competition ID.
	 * @return array<int, string> Full-size file path, keyed by attachment ID.
	 */
	public function originals( int $competition_id ): array {
		$originals = array();

		foreach ( $this->images_repo->get_original_attachment_ids( $competition_id ) as $attachment_id ) {
			// WordPress keeps a 2560px -scaled copy of a larger upload as the attached file.
			$path = wp_get_original_image_path( $attachment_id );
			if ( $path && file_exists( $path ) ) {
				$originals[ $attachment_id ] = $path;
			}
		}

		return $originals;
	}

	/**
	 * Whether the actor may discard a competition's originals now.
	 *
	 * Only an admin may, and not while any of the competition's categories is accepting votes.
	 *
	 * @since 0.4.0
	 *
	 * @param Actor $actor          Who would discard the originals.
	 * @param int   $competition_id Competition ID.
	 * @return true|WP_Error
	 */
	public function can_discard_originals( Actor $actor, int $competition_id ) {
		if ( ! $actor->is_admin() ) {
			return new WP_Error( 'not_authorized', __( 'Only an admin can discard originals.', 'photo-competition-manager' ) );
		}

		$competition = $this->competitions_repo->find( $competition_id, true );
		if ( ! $competition ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		if ( $this->workflow->categories_accepting_votes( $competition ) ) {
			return new WP_Error( 'voting_open', __( 'Original images can\'t be deleted while a category is accepting votes.', 'photo-competition-manager' ) );
		}

		return true;
	}

	/**
	 * Discard a competition's originals, leaving its entries in place.
	 *
	 * The rules are can_discard_originals()'s. An original that WordPress won't delete keeps its
	 * ID on the entry, so a retry picks it up.
	 *
	 * @since 0.4.0
	 *
	 * @param Actor      $actor          Who is discarding the originals.
	 * @param int        $competition_id Competition ID.
	 * @param int[]|null $attachment_ids Only these originals, such as the ones just exported, or null for all.
	 * @return array{discarded: int, failed: int}|WP_Error
	 */
	public function discard_originals( Actor $actor, int $competition_id, ?array $attachment_ids = null ) {
		$allowed = $this->can_discard_originals( $actor, $competition_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$counts = array(
			'discarded' => 0,
			'failed'    => 0,
		);

		foreach ( $this->images_repo->find_uploads( $competition_id ) as $entry ) {
			if ( empty( $entry->original_attachment_id ) ) {
				continue;
			}

			$attachment_id = (int) $entry->original_attachment_id;
			if ( null !== $attachment_ids && ! in_array( $attachment_id, $attachment_ids, true ) ) {
				continue;
			}

			if ( ! $this->delete_original( $competition_id, $attachment_id ) ) {
				++$counts['failed'];
				continue;
			}

			// The original is gone, so a retry finds nothing to delete and clears the ID then.
			if ( is_wp_error( $this->images_repo->clear_original_attachment_id( (int) $entry->id ) ) ) {
				++$counts['failed'];
				continue;
			}

			++$counts['discarded'];
		}

		return $counts;
	}

	/**
	 * Move a member's entries to other categories, all at once or not at all.
	 *
	 * A member may move only their own entries, and only while the competition accepts uploads.
	 * An admin may move them whatever the phase, while both categories are at Not started or
	 * Previewed. No entry with votes can move, whoever is moving it: votes store their category,
	 * so its category has to be reset with its votes cleared first. Nor can any entry move into a
	 * category that already has votes, since it was on none of that category's ballots.
	 * Quota is checked against where the entries end up, so two entries can swap categories.
	 * If a move fails, the moves already made are undone.
	 *
	 * @since 0.4.0
	 *
	 * @see \PhotoCompetitionManager\API\Upload_API::CHANGE_CATEGORIES_REFUSALS Gives each refusal its HTTP status. A new refusal needs adding there.
	 *
	 * @param Actor              $actor          Who is moving the entries.
	 * @param int                $competition_id Competition ID.
	 * @param int                $member_id      The member whose entries they are.
	 * @param array<int, string> $changes        New category slug, keyed by entry ID.
	 * @return true|WP_Error
	 */
	public function change_categories( Actor $actor, int $competition_id, int $member_id, array $changes ) {
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		if ( ! $actor->is_admin() ) {
			if ( ! $actor->is_member( $member_id ) ) {
				return new WP_Error( 'permission_denied', __( 'You can only move your own entries.', 'photo-competition-manager' ) );
			}

			if ( ! $this->workflow->is_accepting_uploads( $competition ) ) {
				return new WP_Error( 'competition_closed', __( 'Entries can only change category while the competition is accepting uploads.', 'photo-competition-manager' ) );
			}
		}

		$settings = Competition_Settings::parse( $competition->settings );

		// The member's entries in this competition: the only ones they can move, and what quota counts.
		$entries = array_column( $this->images_repo->find_uploads( $competition_id, null, $member_id ), null, 'id' );
		$moves   = array();

		foreach ( $changes as $entry_id => $new_category ) {
			// Someone else's entry gets the same answer as a missing one, so this can't tell them apart.
			if ( ! isset( $entries[ $entry_id ] ) ) {
				return new WP_Error( 'submission_not_found', __( 'Submission not found.', 'photo-competition-manager' ) );
			}

			if ( ! Competition_Settings::find_category( $settings, $new_category ) ) {
				return new WP_Error( 'invalid_category', __( 'Invalid category.', 'photo-competition-manager' ) );
			}

			$entry = $entries[ $entry_id ];
			if ( $entry->category === $new_category ) {
				continue;
			}

			// Votes store their category, so a voted entry would leave its votes behind. Resetting
			// a category can keep its votes, so the stage alone doesn't rule this out.
			if ( $this->votes_repo->find_by_image( (int) $entry->id ) ) {
				return new WP_Error( 'entry_has_votes', __( 'This entry has votes, so it can\'t move. Reset its category and clear its votes first.', 'photo-competition-manager' ) );
			}

			$moves[] = array( $entry, $new_category );
		}

		if ( $actor->is_admin() ) {
			$touched = array_merge(
				array_column( $moves, 1 ),
				array_map(
					function ( $move ) {
						return $move[0]->category;
					},
					$moves
				)
			);

			foreach ( array_unique( $touched ) as $category ) {
				if ( $this->workflow->has_voting_started( $competition, $category ) ) {
					return new WP_Error( 'voting_started', __( 'Voting has started in one of these categories, so its entries can\'t move. Reset the category and clear its votes first.', 'photo-competition-manager' ) );
				}
			}
		}

		// Only the categories gaining entries can go over quota, or take an entry that missed their voting.
		foreach ( array_unique( array_column( $moves, 1 ) ) as $new_category ) {
			$voted = $this->voted_category_error( $competition_id, $new_category );
			if ( $voted ) {
				return $voted;
			}

			$category_config = Competition_Settings::find_category( $settings, $new_category );
			$quota           = $category_config['quota'] ?? 1;
			$panel           = $this->panel_after_moves( $entries, $moves, $new_category );

			if ( $settings['interclub']['enabled'] ) {
				$refused = $this->panel_error( $settings, $category_config, $panel );
				if ( $refused ) {
					return $refused;
				}
			} elseif ( count( $panel ) > $quota ) {
				return new WP_Error(
					'quota_exceeded',
					sprintf(
						/* translators: 1: category label, 2: number of entries, 3: quota limit */
						__( 'That would leave %1$s with %2$d entries, but the limit is %3$d.', 'photo-competition-manager' ),
						$category_config['label'],
						count( $panel ),
						$quota
					)
				);
			}
		}

		$moves_made = array();
		foreach ( $moves as list( $entry, $new_category ) ) {
			$new_filename = $this->move_entry( $competition, $entry, $new_category );

			if ( is_wp_error( $new_filename ) ) {
				// Put back the moves already made, newest first.
				foreach ( array_reverse( $moves_made ) as list( $moved, $moved_category, $moved_filename ) ) {
					$this->move_back( $competition, $moved, $moved_category, $moved_filename );
					$this->images_repo->update_category( (int) $moved->id, $moved->category, $moved->filename );
				}
				return $new_filename;
			}

			$moves_made[] = array( $entry, $new_category, $new_filename );
		}

		return true;
	}

	/**
	 * A category's panel once the given moves are made.
	 *
	 * @param array<int, object>                      $entries  The member's uploads, keyed by ID.
	 * @param array<int, array{0: object, 1: string}> $moves Each entry and the category it moves to.
	 * @param string                                  $category Category slug.
	 * @return array<int, object> Image records, without disqualified ones.
	 */
	private function panel_after_moves( array $entries, array $moves, string $category ): array {
		$moving_to = array();
		foreach ( $moves as list( $entry, $new_category ) ) {
			$moving_to[ (int) $entry->id ] = $new_category;
		}

		$panel = array();
		foreach ( $entries as $id => $entry ) {
			if ( 'disqualified' !== $entry->entry_status && ( $moving_to[ (int) $id ] ?? $entry->category ) === $category ) {
				$panel[] = $entry;
			}
		}

		return $panel;
	}

	/**
	 * The refusal for an interclub panel that breaks its rules, if it breaks one.
	 *
	 * A panel holds at most the category's quota of entered images, and at
	 * most the type's number of substitutes. No photographer may have more
	 * entered images in it than the type's limit, when it has one; their
	 * substitutes don't count. Photographers' names are compared ignoring
	 * case and surrounding spaces.
	 *
	 * @param array<string, mixed> $settings        Competition settings.
	 * @param array<string, mixed> $category_config The panel's category.
	 * @param array<int, object>   $panel           The panel's images, with `entry_status` and `photographer`, as it would be.
	 * @return WP_Error|null
	 */
	private function panel_error( array $settings, array $category_config, array $panel ): ?WP_Error {
		$quota       = (int) ( $category_config['quota'] ?? 1 );
		$substitutes = Competition_Settings::substitutes( $settings );
		$limit       = (int) $settings['interclub']['photographer_limit'];

		$entered       = 0;
		$substituting  = 0;
		$photographers = array();
		foreach ( $panel as $image ) {
			if ( 'substitute' === $image->entry_status ) {
				++$substituting;
				continue;
			}

			++$entered;
			$name                   = mb_strtolower( trim( (string) $image->photographer ) );
			$photographers[ $name ] = array(
				'name'  => trim( (string) $image->photographer ),
				'count' => ( $photographers[ $name ]['count'] ?? 0 ) + 1,
			);
		}

		if ( $entered > $quota ) {
			$message = sprintf(
				/* translators: 1: category label, 2: images the panel takes, 3: images it would have */
				__( 'The %1$s panel takes %2$d image(s), and that would give it %3$d.', 'photo-competition-manager' ),
				$category_config['label'],
				$quota,
				$entered
			);
			if ( $substitutes > 0 ) {
				$message .= ' ' . __( 'A substitute doesn\'t count towards that.', 'photo-competition-manager' );
			}

			return new WP_Error( 'quota_exceeded', $message );
		}

		if ( $substituting > $substitutes ) {
			return new WP_Error(
				'substitutes_full',
				sprintf(
					/* translators: 1: category label, 2: substitutes the panel takes */
					__( 'The %1$s panel already has its %2$d substitute(s).', 'photo-competition-manager' ),
					$category_config['label'],
					$substitutes
				)
			);
		}

		if ( $limit > 0 ) {
			foreach ( $photographers as $photographer ) {
				if ( $photographer['count'] > $limit ) {
					return new WP_Error(
						'photographer_limit',
						sprintf(
							/* translators: 1: photographer's name, 2: category label, 3: most images per photographer */
							__( '%1$s can have at most %3$d image(s) in the %2$s panel. Substitutes don\'t count.', 'photo-competition-manager' ),
							$photographer['name'],
							$category_config['label'],
							$limit
						)
					);
				}
			}
		}

		return null;
	}

	/**
	 * The refusal for a category that already has votes, if it has any.
	 *
	 * An entry added or moved there afterwards was on none of its ballots, so the results would
	 * compare it with nothing. Resetting a category can keep its votes, so the stage alone doesn't
	 * rule this out.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @return WP_Error|null
	 */
	private function voted_category_error( int $competition_id, string $category ): ?WP_Error {
		if ( ! $this->votes_repo->has_votes_in_category( $competition_id, $category ) ) {
			return null;
		}

		return new WP_Error( 'category_has_votes', __( 'This category already has votes, so it can\'t take new entries. Reset it and clear its votes first.', 'photo-competition-manager' ) );
	}

	/**
	 * Move one entry's files and row to another category, putting the files back if the row won't update.
	 *
	 * @param object $competition  Competition record.
	 * @param object $entry        Entry record.
	 * @param string $new_category New category slug.
	 * @return string|WP_Error The entry's filename in the new category.
	 */
	private function move_entry( object $competition, object $entry, string $new_category ) {
		$new_filename = $this->move_files( $competition->slug, $entry->category, $new_category, $entry->filename, $entry->filename );
		if ( is_wp_error( $new_filename ) ) {
			return $new_filename;
		}

		$result = $this->images_repo->update_category( (int) $entry->id, $new_category, $new_filename );
		if ( is_wp_error( $result ) ) {
			$this->move_back( $competition, $entry, $new_category, $new_filename );
			return $result;
		}

		return $new_filename;
	}

	/**
	 * Put an entry's files back in its own category, under the name its row has.
	 *
	 * @param object $competition    Competition record.
	 * @param object $entry          Entry record, as it was before the move.
	 * @param string $moved_category Category slug the files were moved to.
	 * @param string $moved_filename Filename they were given there.
	 * @return void
	 */
	private function move_back( object $competition, object $entry, string $moved_category, string $moved_filename ): void {
		$this->move_files( $competition->slug, $moved_category, $entry->category, $moved_filename, $entry->filename );
	}

	/**
	 * A member's entries in a competition, each with its image and thumbnail URLs.
	 *
	 * @param int $competition_id Competition ID.
	 * @param int $member_id      Member ID.
	 * @return array<int, object> Entry records with `urls` added, as urls() returns them.
	 */
	public function get_member_entries( int $competition_id, int $member_id ): array {
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return array();
		}

		$entries = $this->images_repo->find_uploads( $competition_id, null, $member_id );

		foreach ( $entries as $entry ) {
			$entry->urls = $this->urls( $competition, $entry );
		}

		return $entries;
	}

	/**
	 * URLs of an entry's image and thumbnail.
	 *
	 * Each carries its file's modified time, so an entry replaced by one with the same
	 * filename isn't shown from the browser's cache.
	 *
	 * @since 0.4.0
	 *
	 * @param object $competition Competition record.
	 * @param object $entry       Entry record.
	 * @return array{full: string, thumb: string} Each '' when its file is missing.
	 */
	public function urls( object $competition, object $entry ): array {
		$urls      = array(
			'full'  => '',
			'thumb' => '',
		);
		$directory = $this->category_directory( (string) $competition->slug, (string) $entry->category );
		if ( is_wp_error( $directory ) || '' === (string) $entry->filename ) {
			return $urls;
		}

		// The URL follows the same path the file is checked at, so the two can't disagree.
		$uploads    = wp_upload_dir();
		$relative   = substr( $directory, strlen( trailingslashit( $uploads['basedir'] ) ) );
		$folder_url = trailingslashit( $uploads['baseurl'] ) . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) );
		$files      = array(
			'full'  => $entry->filename,
			'thumb' => Image_Processor::get_thumbnail_filename( $entry->filename ),
		);
		foreach ( $files as $key => $filename ) {
			$path = $directory . '/' . $filename;
			if ( file_exists( $path ) ) {
				$urls[ $key ] = add_query_arg( 'v', filemtime( $path ), $folder_url . '/' . rawurlencode( $filename ) );
			}
		}

		return $urls;
	}

	/**
	 * Name-free URLs of an entry's image and thumbnail, for pages that mustn't show who took it.
	 *
	 * Entry files are named after the member, so these go through the image permalink,
	 * which serves the file by entry ID.
	 *
	 * @since 0.4.0
	 *
	 * @param object $competition Competition record.
	 * @param object $entry       Entry record.
	 * @return array{full: string, thumb: string} Each '' when its file is missing.
	 */
	public function public_urls( object $competition, object $entry ): array {
		$full  = $this->path_for( $competition, $entry, false );
		$thumb = $this->path_for( $competition, $entry, true );

		return array(
			'full'  => null === $full ? '' : $this->image_permalink( (int) $entry->id, false, (int) filemtime( $full ) ),
			'thumb' => null === $thumb ? '' : $this->image_permalink( (int) $entry->id, true, (int) filemtime( $thumb ) ),
		);
	}

	/**
	 * The permalink of an entry's image: the pretty URL, or the query form on plain permalinks, where the rewrite rule can't match.
	 *
	 * @param int  $entry_id Entry ID.
	 * @param bool $thumb    Whether it's the thumbnail.
	 * @param int  $version  Cache buster.
	 * @return string
	 */
	private function image_permalink( int $entry_id, bool $thumb, int $version ): string {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$args = array( Image_Permalink::QUERY_VAR => $entry_id );
			if ( $thumb ) {
				$args[ Image_Permalink::SIZE_QUERY_VAR ] = 'thumb';
			}
			return add_query_arg( 'v', $version, add_query_arg( $args, home_url( '/' ) ) );
		}

		return add_query_arg( 'v', $version, home_url( "/photocomp-image/{$entry_id}/" . ( $thumb ? 'thumb/' : '' ) ) );
	}

	/**
	 * Path of an entry's image or thumbnail file, looked up by entry ID.
	 *
	 * @since 0.4.0
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $size     'thumb' for the thumbnail, anything else for the full image.
	 * @return string|null Null when the entry, its competition (soft-deleted counts as missing) or the file is missing.
	 */
	public function file_path( int $entry_id, string $size ): ?string {
		$entry       = $this->images_repo->find( $entry_id );
		$competition = $entry ? $this->competitions_repo->find( (int) $entry->competition_id ) : null;

		return $competition ? $this->path_for( $competition, $entry, 'thumb' === $size ) : null;
	}

	/**
	 * Path of an entry's image or thumbnail file.
	 *
	 * Built from the entry's competition and category and its stored filename, never from a request.
	 *
	 * @since 0.4.0
	 *
	 * @param object $competition Competition record.
	 * @param object $entry       Entry record.
	 * @param bool   $thumb       Whether to give the thumbnail's path.
	 * @return string|null Null when the file is missing.
	 */
	private function path_for( object $competition, object $entry, bool $thumb ): ?string {
		if ( '' === (string) $entry->filename ) {
			return null;
		}

		$directory = $this->category_directory( (string) $competition->slug, (string) $entry->category );
		if ( is_wp_error( $directory ) ) {
			return null;
		}

		$path = $directory . '/' . ( $thumb ? Image_Processor::get_thumbnail_filename( $entry->filename ) : $entry->filename );

		return is_file( $path ) ? $path : null;
	}

	/**
	 * Get a member's quota status for every category in a competition.
	 *
	 * A disqualified image takes no place. In an interclub competition a
	 * panel also takes the type's number of substitutes, so `remaining`
	 * counts both, and `entered_remaining` and `substitutes_remaining` say
	 * which. In an individual competition there are no substitutes.
	 *
	 * @since 0.4.0 Leaves out disqualified images, and gives entered and substitute places apart.
	 *
	 * @param object $competition Competition record.
	 * @param int    $member_id   Member ID.
	 * @return array<string, array{label: string, slug: string, current: int, quota: int, remaining: int, entered_remaining: int, substitutes_remaining: int}> Keyed by category slug.
	 */
	public function get_quota_status( object $competition, int $member_id ): array {
		$settings    = Competition_Settings::parse( $competition->settings );
		$substitutes = Competition_Settings::substitutes( $settings );

		// One query for every category.
		$panels = array();
		foreach ( $this->images_repo->find_in_panels( (int) $competition->id, null, $member_id ) as $image ) {
			$panels[ $image->category ][] = $image;
		}

		$quota_status = array();
		foreach ( Competition_Settings::get_categories( $settings ) as $cat ) {
			$panel        = $panels[ $cat['slug'] ] ?? array();
			$substituting = count(
				array_filter(
					$panel,
					static function ( object $image ): bool {
						return 'substitute' === $image->entry_status;
					}
				)
			);
			$quota        = (int) ( $cat['quota'] ?? 1 );
			$entered_left = max( 0, $quota - ( count( $panel ) - $substituting ) );
			$subs_left    = max( 0, $substitutes - $substituting );

			$quota_status[ $cat['slug'] ] = array(
				'label'                 => $cat['label'],
				'slug'                  => $cat['slug'],
				'current'               => count( $panel ),
				'quota'                 => $quota,
				'remaining'             => $entered_left + $subs_left,
				'entered_remaining'     => $entered_left,
				'substitutes_remaining' => $subs_left,
			);
		}

		return $quota_status;
	}

	/**
	 * Path of a competition's folder, which may not exist.
	 *
	 * @param string $competition_slug Competition slug.
	 * @return string|WP_Error
	 */
	private function competition_directory( string $competition_slug ) {
		$wp_upload_dir = wp_upload_dir();
		if ( $wp_upload_dir['error'] ) {
			// WordPress's message names a folder on the server, so it goes in the data, not the message.
			return new WP_Error( 'upload_dir_error', __( 'The uploads folder isn\'t available.', 'photo-competition-manager' ), $wp_upload_dir['error'] );
		}

		// Security: Explicitly check for path traversal sequences.
		if ( strpos( $competition_slug, '..' ) !== false ) {
			return new WP_Error( 'invalid_path', __( 'Invalid directory name.', 'photo-competition-manager' ) );
		}

		return trailingslashit( $wp_upload_dir['basedir'] ) . 'competitions/' . sanitize_file_name( $competition_slug );
	}

	/**
	 * Path of a category's folder, which may not exist yet.
	 *
	 * @param string $competition_slug Competition slug.
	 * @param string $category_slug    Category slug.
	 * @return string|WP_Error
	 */
	private function category_directory( string $competition_slug, string $category_slug ) {
		$directory = $this->competition_directory( $competition_slug );
		if ( is_wp_error( $directory ) ) {
			return $directory;
		}

		// Security: Explicitly check for path traversal sequences.
		if ( strpos( $category_slug, '..' ) !== false ) {
			return new WP_Error( 'invalid_path', __( 'Invalid directory name.', 'photo-competition-manager' ) );
		}

		return $directory . '/' . sanitize_file_name( $category_slug );
	}

	/**
	 * Remove a competition's folder and its category folders, if nothing but their index.php is left.
	 *
	 * A file that wouldn't delete has already been logged, so its folder stays.
	 *
	 * @param string $competition_slug Competition slug.
	 * @return void
	 */
	private function remove_competition_directory( string $competition_slug ): void {
		// Without a slug, the competition's folder would be the one that holds every competition.
		if ( '' === sanitize_file_name( $competition_slug ) ) {
			return;
		}

		$directory = $this->competition_directory( $competition_slug );
		if ( is_wp_error( $directory ) || ! is_dir( $directory ) ) {
			return;
		}

		$category_directories = glob( $directory . '/*', GLOB_ONLYDIR );
		foreach ( false === $category_directories ? array() : $category_directories as $category_directory ) {
			$this->remove_empty_directory( $category_directory );
		}

		$this->remove_empty_directory( $directory );
	}

	/**
	 * Remove a folder whose only file is the index.php that stops directory browsing.
	 *
	 * @param string $directory Folder path.
	 * @return void
	 */
	private function remove_empty_directory( string $directory ): void {
		// A folder that can't be read is left alone, like a file that won't delete.
		$items = @scandir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false is handled below.
		if ( false === $items || array_diff( $items, array( '.', '..', 'index.php' ) ) ) {
			return;
		}

		$index = $directory . '/index.php';
		if ( file_exists( $index ) ) {
			wp_delete_file( $index );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		rmdir( $directory );
	}

	/**
	 * Path of a category's folder, created if it doesn't exist.
	 *
	 * @param string $competition_slug Competition slug.
	 * @param string $category_slug    Category slug.
	 * @return string|WP_Error
	 */
	private function make_category_directory( string $competition_slug, string $category_slug ) {
		$directory = $this->category_directory( $competition_slug, $category_slug );
		if ( is_wp_error( $directory ) || file_exists( $directory ) ) {
			return $directory;
		}

		if ( ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Could not create upload directory.', 'photo-competition-manager' ) );
		}

		// Add index.php to prevent directory browsing.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( trailingslashit( $directory ) . 'index.php', '<?php // Silence is golden.' );

		return $directory;
	}

	/**
	 * Move an entry's image and thumbnail from one category folder to another.
	 *
	 * @param string $competition_slug Competition slug.
	 * @param string $old_category     Category slug the files are in.
	 * @param string $new_category     Category slug to move them to.
	 * @param string $filename         Image filename.
	 * @param string $dest_filename    Name to use in the new folder if it's free.
	 * @return string|WP_Error Filename in the new folder, suffixed if another entry has the name there.
	 */
	private function move_files( string $competition_slug, string $old_category, string $new_category, string $filename, string $dest_filename ) {
		$source_dir = $this->category_directory( $competition_slug, $old_category );
		if ( is_wp_error( $source_dir ) ) {
			return $source_dir;
		}

		$dest_dir = $this->make_category_directory( $competition_slug, $new_category );
		if ( is_wp_error( $dest_dir ) ) {
			return $dest_dir;
		}

		$source_path = trailingslashit( $source_dir );
		$dest_path   = trailingslashit( $dest_dir );

		$dest_filename = wp_unique_filename( $dest_path, $dest_filename );
		$source_file   = $source_path . $filename;
		$dest_file     = $dest_path . $dest_filename;

		if ( file_exists( $source_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! rename( $source_file, $dest_file ) ) {
				return new WP_Error( 'move_failed', __( 'Failed to move image file.', 'photo-competition-manager' ) );
			}
		}

		$source_thumb = $source_path . Image_Processor::get_thumbnail_filename( $filename );
		$dest_thumb   = $dest_path . Image_Processor::get_thumbnail_filename( $dest_filename );

		if ( file_exists( $source_thumb ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! rename( $source_thumb, $dest_thumb ) ) {
				// Put the image back if the thumbnail won't move.
				if ( file_exists( $dest_file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
					rename( $dest_file, $source_file );
				}
				return new WP_Error( 'move_thumb_failed', __( 'Failed to move thumbnail file.', 'photo-competition-manager' ) );
			}
		}

		return $dest_filename;
	}

	/**
	 * Delete an entry's image, thumbnail and original, logging any that won't go.
	 *
	 * @param object $competition   Competition record.
	 * @param string $category      Category slug.
	 * @param string $filename      Image filename.
	 * @param int    $attachment_id Original's attachment ID, or 0 when there isn't one.
	 * @return void
	 */
	private function delete_files( object $competition, string $category, string $filename, int $attachment_id ): void {
		$directory = $this->category_directory( $competition->slug, $category );
		if ( ! is_wp_error( $directory ) ) {
			foreach ( array( $filename, Image_Processor::get_thumbnail_filename( $filename ) ) as $name ) {
				$path = trailingslashit( $directory ) . $name;
				if ( ! file_exists( $path ) ) {
					continue;
				}

				wp_delete_file( $path );

				if ( file_exists( $path ) ) {
					$this->log_undeleted( (int) $competition->id, $path );
				}
			}
		}

		$this->delete_original( (int) $competition->id, $attachment_id );
	}

	/**
	 * Delete an entry's original from the media library, logging it if WordPress refuses, or any file left behind.
	 *
	 * @param int $competition_id Competition ID, for the log.
	 * @param int $attachment_id  Original's attachment ID, or 0 when there isn't one.
	 * @return bool False when WordPress refused to delete the attachment.
	 */
	private function delete_original( int $competition_id, int $attachment_id ): bool {
		if ( $attachment_id <= 0 ) {
			return true;
		}

		// A large original's attached file is a -scaled copy, so check the full-size file too.
		// Both are false when the original is already gone from the media library.
		$originals = array_unique( array_filter( array( get_attached_file( $attachment_id ), wp_get_original_image_path( $attachment_id ) ) ) );

		// Null means the attachment was already gone, which is as good as deleted.
		if ( false === wp_delete_attachment( $attachment_id, true ) ) {
			// Its files can't be deleted by hand: the attachment would still be in the media library.
			( new Event_Logger() )->log(
				$competition_id,
				'original_not_deleted',
				'upload',
				__( 'WordPress would not delete an original from the media library.', 'photo-competition-manager' ),
				array(
					'attachment_id' => $attachment_id,
					'paths'         => array_values( $originals ),
				)
			);
			return false;
		}

		foreach ( $originals as $original ) {
			if ( file_exists( $original ) ) {
				$this->log_undeleted( $competition_id, $original );
			}
		}

		return true;
	}

	/**
	 * Log an entry file or original that wouldn't delete, so an admin can remove it by hand.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $path           The file's path.
	 * @return void
	 */
	private function log_undeleted( int $competition_id, string $path ): void {
		( new Event_Logger() )->log(
			$competition_id,
			'entry_file_not_deleted',
			'upload',
			__( 'An entry file could not be deleted.', 'photo-competition-manager' ),
			array( 'path' => $path )
		);
	}
}
