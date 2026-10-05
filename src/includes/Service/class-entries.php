<?php
/**
 * The Entries module: one owner for an entry, its files and its original.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
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
	 * Constructor.
	 *
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Images_Repository|null       $images_repo       Images repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 * @param Image_Processor|null         $image_processor   Image processor.
	 * @param Competition_Workflow|null    $workflow          Competition workflow.
	 */
	public function __construct(
		?Competitions_Repository $competitions_repo = null,
		?Images_Repository $images_repo = null,
		?Members_Repository $members_repo = null,
		?Image_Processor $image_processor = null,
		?Competition_Workflow $workflow = null
	) {
		$this->competitions_repo = $competitions_repo ? $competitions_repo : new Competitions_Repository();
		$this->images_repo       = $images_repo ? $images_repo : new Images_Repository();
		$this->members_repo      = $members_repo ? $members_repo : new Members_Repository();
		$this->image_processor   = $image_processor ? $image_processor : new Image_Processor();
		$this->workflow          = $workflow ? $workflow : new Competition_Workflow( $this->competitions_repo );
	}

	/**
	 * Add an entry: store the uploaded image and its original, and record it.
	 *
	 * A member may add only their own entries, and only while the competition accepts uploads.
	 * An admin may add for any member at any time. Both are held to the category's quota.
	 *
	 * @param Actor                $actor          Who is adding the entry.
	 * @param int                  $competition_id Competition ID.
	 * @param int                  $member_id      The member the entry belongs to.
	 * @param string               $category       Category slug.
	 * @param array<string, mixed> $file           Uploaded file from $_FILES.
	 * @return int|WP_Error Entry ID on success, WP_Error on failure.
	 */
	public function add( Actor $actor, int $competition_id, int $member_id, string $category, array $file ) {
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

		$current_count = $this->images_repo->count_by_member_category( $competition_id, $member_id, $category );
		$quota         = $category_config['quota'] ?? 1;

		if ( $current_count >= $quota ) {
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
		$filename = $counter > 0
			? sprintf( '%s-%s-%d.jpg', $username, sanitize_title( $category ), $counter )
			: sprintf( '%s-%s.jpg', $username, sanitize_title( $category ) );
		$title    = $counter > 0
			? sprintf( '%s - %s - %s #%d', $competition->slug, $category, $username, $counter )
			: sprintf( '%s - %s - %s', $competition->slug, $category, $username );

		$result = $this->image_processor->process(
			$file,
			$directory,
			$filename,
			$title,
			$constraints
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$filename      = $result['filename'];
		$attachment_id = $result['attachment_id'];

		// Uninstall finds the plugin's originals by this meta.
		update_post_meta( $attachment_id, '_photo_comp_slug', $competition->slug );
		update_post_meta( $attachment_id, '_photo_comp_category', $category );
		update_post_meta( $attachment_id, '_photo_comp_member', $username );

		$entry_id = $this->images_repo->create(
			array(
				'competition_id'         => $competition_id,
				'member_id'              => $member_id,
				'category'               => $category,
				'filename'               => $filename,
				'original_attachment_id' => $attachment_id,
			)
		);

		if ( is_wp_error( $entry_id ) ) {
			$this->delete_files( $competition, $category, $filename, $attachment_id );
			return $entry_id;
		}

		$email_service = new Email_Service();
		$email_service->send_submission_confirmed_notification(
			$member->email,
			$member->name,
			$competition->title,
			$category_config['label'],
			$counter,
			$quota,
			null,
			! empty( $member->grade ) ? $member->grade : ''
		);

		return $entry_id;
	}

	/**
	 * Remove an entry: its row and votes first, then its image, thumbnail and original.
	 *
	 * A member may remove only their own entries, and only while the competition accepts uploads.
	 * An admin may remove any entry at any time. If the row won't delete, the files and original
	 * are left alone. A file that won't delete is logged and doesn't fail the removal.
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

		$deleted = $this->images_repo->delete( $entry_id );
		if ( is_wp_error( $deleted ) ) {
			return $deleted;
		}

		$this->delete_files( $competition, $entry->category, $entry->filename, (int) $entry->original_attachment_id );

		return true;
	}

	/**
	 * Move an entry to another category, renaming its files if the new folder has the name already.
	 *
	 * A member may move only their own entries. This is a straight copy of the old
	 * Upload_Handler::update_submission_category(); #125 replaces it with change_categories().
	 *
	 * @param Actor  $actor          Who is moving the entry.
	 * @param int    $competition_id Competition the entry must belong to.
	 * @param int    $entry_id       Entry ID.
	 * @param string $new_category   New category slug.
	 * @return true|WP_Error
	 */
	public function change_category( Actor $actor, int $competition_id, int $entry_id, string $new_category ) {
		$entry = $this->images_repo->find( $entry_id );
		if ( ! $entry ) {
			return new WP_Error( 'submission_not_found', __( 'Submission not found.', 'photo-competition-manager' ) );
		}

		if ( ! $actor->is_admin() && ! $actor->is_member( (int) $entry->member_id ) ) {
			return new WP_Error( 'permission_denied', __( 'You do not have permission to modify this submission.', 'photo-competition-manager' ) );
		}

		if ( (int) $entry->competition_id !== $competition_id ) {
			return new WP_Error( 'invalid_competition', __( 'Submission does not belong to this competition.', 'photo-competition-manager' ) );
		}

		if ( $entry->category === $new_category ) {
			return true;
		}

		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$settings        = Competition_Settings::parse( $competition->settings );
		$category_config = Competition_Settings::find_category( $settings, $new_category );

		if ( ! $category_config ) {
			return new WP_Error( 'invalid_category', __( 'Invalid category.', 'photo-competition-manager' ) );
		}

		// Moving into a category at quota is allowed so the upload page can swap two entries
		// one move at a time. Only a category already over quota is refused.
		$current_count = $this->images_repo->count_by_member_category( $competition_id, (int) $entry->member_id, $new_category );
		$quota         = $category_config['quota'] ?? 1;

		if ( $current_count > $quota ) {
			return new WP_Error(
				'quota_exceeded',
				sprintf(
					/* translators: 1: category label, 2: current count, 3: quota limit */
					__( 'Category "%1$s" has too many images (%2$d/%3$d). Please remove images from this category first.', 'photo-competition-manager' ),
					$category_config['label'],
					$current_count,
					$quota
				)
			);
		}

		$new_filename = $this->move_files( $competition->slug, $entry->category, $new_category, $entry->filename );
		if ( is_wp_error( $new_filename ) ) {
			return $new_filename;
		}

		$result = $this->images_repo->update_category( $entry_id, $new_category, $new_filename );

		if ( is_wp_error( $result ) ) {
			// Put the files back, under the name the row still has.
			$this->move_files( $competition->slug, $new_category, $entry->category, $new_filename, $entry->filename );
			return $result;
		}

		return true;
	}

	/**
	 * A member's entries in a competition, each with its image and thumbnail URLs.
	 *
	 * @param int $competition_id Competition ID.
	 * @param int $member_id      Member ID.
	 * @return array<int, object> Entry records with `url` and `thumbnail_url` added.
	 */
	public function get_member_entries( int $competition_id, int $member_id ): array {
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return array();
		}

		$entries = $this->images_repo->find_by_competition( $competition_id, null, $member_id );

		foreach ( $entries as $entry ) {
			$entry->url           = $this->image_processor->get_image_url( $competition->slug, $entry->category, $entry->filename );
			$entry->thumbnail_url = $this->image_processor->get_thumbnail_url( $competition->slug, $entry->category, $entry->filename );
		}

		return $entries;
	}

	/**
	 * Get a member's quota status for every category in a competition.
	 *
	 * @param object $competition Competition record.
	 * @param int    $member_id   Member ID.
	 * @return array<string, array{label: string, slug: string, current: int, quota: int, remaining: int}> Keyed by category slug.
	 */
	public function get_quota_status( object $competition, int $member_id ): array {
		$settings = Competition_Settings::parse( $competition->settings );

		$quota_status = array();
		foreach ( Competition_Settings::get_categories( $settings ) as $cat ) {
			$current = $this->images_repo->count_by_member_category( (int) $competition->id, $member_id, $cat['slug'] );
			$quota   = $cat['quota'] ?? 1;

			$quota_status[ $cat['slug'] ] = array(
				'label'     => $cat['label'],
				'slug'      => $cat['slug'],
				'current'   => $current,
				'quota'     => $quota,
				'remaining' => max( 0, $quota - $current ),
			);
		}

		return $quota_status;
	}

	/**
	 * Get how many entries a member has in a category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param int    $member_id      Member ID.
	 * @param string $category       Category slug.
	 * @return int
	 */
	public function get_category_count( int $competition_id, int $member_id, string $category ): int {
		return $this->images_repo->count_by_member_category( $competition_id, $member_id, $category );
	}

	/**
	 * Path of a category's folder, which may not exist yet.
	 *
	 * @param string $competition_slug Competition slug.
	 * @param string $category_slug    Category slug.
	 * @return string|WP_Error
	 */
	private function category_directory( string $competition_slug, string $category_slug ) {
		$wp_upload_dir = wp_upload_dir();
		if ( $wp_upload_dir['error'] ) {
			return new WP_Error( 'upload_dir_error', $wp_upload_dir['error'] );
		}

		// Security: Explicitly check for path traversal sequences.
		if ( strpos( $competition_slug, '..' ) !== false || strpos( $category_slug, '..' ) !== false ) {
			return new WP_Error( 'invalid_path', __( 'Invalid directory name.', 'photo-competition-manager' ) );
		}

		return trailingslashit( $wp_upload_dir['basedir'] ) . 'competitions/' . sanitize_file_name( $competition_slug ) . '/' . sanitize_file_name( $category_slug );
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
	 * @param string $dest_filename    Name to use in the new folder if it's free. Defaults to $filename.
	 * @return string|WP_Error Filename in the new folder, suffixed if another entry has the name there.
	 */
	private function move_files( string $competition_slug, string $old_category, string $new_category, string $filename, string $dest_filename = '' ) {
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

		$dest_filename = wp_unique_filename( $dest_path, '' !== $dest_filename ? $dest_filename : $filename );
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
					$this->log_undeleted( $competition, $path );
				}
			}
		}

		// An original that's already gone from the media library has nothing left to delete.
		if ( $attachment_id > 0 && get_post( $attachment_id ) ) {
			$original = get_attached_file( $attachment_id );
			if ( ! wp_delete_attachment( $attachment_id, true ) ) {
				$this->log_undeleted( $competition, $original ? $original : sprintf( 'attachment %d', $attachment_id ) );
			}
		}
	}

	/**
	 * Log an entry file or original that wouldn't delete, so an admin can remove it by hand.
	 *
	 * @param object $competition Competition record.
	 * @param string $path        The file's path, or the original's attachment ID when it has no file.
	 * @return void
	 */
	private function log_undeleted( object $competition, string $path ): void {
		( new Event_Logger() )->log(
			(int) $competition->id,
			'entry_file_not_deleted',
			'upload',
			__( 'An entry file could not be deleted.', 'photo-competition-manager' ),
			array( 'path' => $path )
		);
	}
}
