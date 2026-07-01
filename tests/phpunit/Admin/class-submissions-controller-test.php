<?php
/**
 * Characterization tests for Submissions_Controller.
 *
 * Pins current behavior of the submissions action router (handle_actions)
 * ahead of a later refactor. Asserts what the code does today, including any
 * quirks. Does not modify source.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Submissions_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;

/**
 * Characterization tests for the submissions controller.
 *
 * @covers \PhotoCompetitionManager\Admin\Submissions_Controller
 */
class Submissions_Controller_Test extends Admin_Controller_Test_Case {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * Votes repository.
	 *
	 * @var Votes_Repository
	 */
	private $votes;

	/**
	 * Controller under test.
	 *
	 * @var Submissions_Controller
	 */
	private $controller;

	/**
	 * Seeded competition ID.
	 *
	 * @var int
	 */
	private $competition_id;

	/**
	 * Settings-error group used by this controller.
	 */
	private const GROUP = 'photo_competition_submissions';

	/**
	 * Set up the controller and a seeded competition.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->competitions   = new Competitions_Repository();
		$this->images         = new Images_Repository();
		$this->votes          = new Votes_Repository();
		$this->controller     = new Submissions_Controller(
			$this->competitions,
			new Members_Repository(),
			$this->images,
			$this->votes
		);
		$this->competition_id = $this->create_competition( 'Spring Show', 'spring-show' );
	}

	/**
	 * Create a competition and return its ID.
	 *
	 * @param string $title Title.
	 * @param string $slug  Slug.
	 * @return int Competition ID.
	 */
	private function create_competition( string $title, string $slug ): int {
		return $this->competitions->create(
			array(
				'title'      => $title,
				'slug'       => $slug,
				'open_date'  => '2026-01-01 00:00:00',
				'close_date' => '2026-12-31 00:00:00',
				'settings'   => array(),
			)
		);
	}

	/**
	 * Seed an image record and return its ID.
	 *
	 * @param int   $competition_id Competition ID.
	 * @param array $overrides      Field overrides.
	 * @return int Image ID.
	 */
	private function seed_image( int $competition_id, array $overrides = array() ): int {
		$data = array_merge(
			array(
				'competition_id' => $competition_id,
				'member_id'      => 42,
				'category'       => 'colour',
				'filename'       => 'photo.jpg',
				'random_number'  => 7,
			),
			$overrides
		);

		return (int) $this->images->create( $data );
	}

	/**
	 * First registered settings-error message for the submissions group.
	 *
	 * @return string
	 */
	private function first_error_message(): string {
		$errors = get_settings_errors( self::GROUP );
		return $errors ? $errors[0]['message'] : '';
	}

	/*
	 * -----------------------------------------------------------------
	 * Capability guard.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Without the capability, handle_actions() is a no-op (no redirect, no errors).
	 */
	public function test_handle_actions_noop_without_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->seed_image( $this->competition_id );
		$this->set_request(
			array(
				'action'         => 'regenerate_numbers',
				'competition_id' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_regenerate_numbers_' . $this->competition_id );

		// Returns without redirecting or registering any settings error.
		$this->controller->handle_actions();

		$this->assertSame( array(), $this->settings_error_codes( self::GROUP ) );
	}

	/*
	 * -----------------------------------------------------------------
	 * regenerate_numbers.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Regenerating numbers for a competition with images succeeds.
	 */
	public function test_regenerate_numbers_success(): void {
		$this->seed_image( $this->competition_id );

		$this->set_request(
			array(
				'action'         => 'regenerate_numbers',
				'competition_id' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_regenerate_numbers_' . $this->competition_id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager-submissions', $location );
		$this->assertStringContainsString( 'competition_id=' . $this->competition_id, $location );
		$this->assertContains( 'numbers_regenerated', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A competition with no images surfaces the repository WP_Error (no_images).
	 */
	public function test_regenerate_numbers_no_images_error(): void {
		$this->set_request(
			array(
				'action'         => 'regenerate_numbers',
				'competition_id' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_regenerate_numbers_' . $this->competition_id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'no_images', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A missing competition_id yields invalid_competition and redirects to the base page.
	 */
	public function test_regenerate_numbers_invalid_competition(): void {
		$this->set_request( array( 'action' => 'regenerate_numbers' ) );
		$this->set_nonce( 'photo_competition_regenerate_numbers_0' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager-submissions', $location );
		$this->assertContains( 'invalid_competition', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A missing/invalid nonce aborts via wp_die().
	 */
	public function test_regenerate_numbers_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'action'         => 'regenerate_numbers',
				'competition_id' => $this->competition_id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * delete_original_images.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Deleting originals removes the attachments and clears the stored IDs.
	 */
	public function test_delete_original_images_success(): void {
		$attachment_id = self::factory()->post->create( array( 'post_type' => 'attachment' ) );
		$this->seed_image( $this->competition_id, array( 'original_attachment_id' => $attachment_id ) );

		$this->set_request(
			array(
				'action'         => 'delete_original_images',
				'competition_id' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_delete_originals_' . $this->competition_id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'originals_deleted', $this->settings_error_codes( self::GROUP ) );
		$this->assertStringContainsString( '1', $this->first_error_message() );
		$this->assertNull( get_post( $attachment_id ) );
		$this->assertSame( array(), $this->images->get_original_attachment_ids( $this->competition_id ) );
	}

	/**
	 * With no original attachments, a no_originals notice is shown.
	 */
	public function test_delete_original_images_none_found(): void {
		// Image without an original_attachment_id.
		$this->seed_image( $this->competition_id );

		$this->set_request(
			array(
				'action'         => 'delete_original_images',
				'competition_id' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_delete_originals_' . $this->competition_id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'no_originals', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A missing competition_id yields invalid_competition.
	 */
	public function test_delete_original_images_invalid_competition(): void {
		$this->set_request( array( 'action' => 'delete_original_images' ) );
		$this->set_nonce( 'photo_competition_delete_originals_0' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager-submissions', $location );
		$this->assertContains( 'invalid_competition', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A missing/invalid nonce aborts via wp_die().
	 */
	public function test_delete_original_images_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'action'         => 'delete_original_images',
				'competition_id' => $this->competition_id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * bulk_delete_submissions.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Deleting a selected submission removes the record and its votes.
	 */
	public function test_bulk_delete_submissions_success(): void {
		$image_id = $this->seed_image( $this->competition_id );
		$this->votes->create_anonymous( $this->competition_id, 'colour', 111, $image_id, 5 );

		$this->set_request(
			array(
				'action'         => 'bulk_delete_submissions',
				'competition_id' => $this->competition_id,
				'image_ids'      => array( $image_id ),
			)
		);
		$this->set_nonce( 'photo_competition_bulk_delete_' . $this->competition_id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'bulk_delete_success', $this->settings_error_codes( self::GROUP ) );
		$this->assertNull( $this->images->find( $image_id ) );
		$this->assertSame( 0, count( $this->votes->find_by_image( $image_id ) ) );
	}

	/**
	 * An empty selection yields no_images_selected and deletes nothing.
	 */
	public function test_bulk_delete_submissions_no_images_selected(): void {
		$this->set_request(
			array(
				'action'         => 'bulk_delete_submissions',
				'competition_id' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_bulk_delete_' . $this->competition_id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'no_images_selected', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * An image belonging to a different competition is counted as a failure.
	 */
	public function test_bulk_delete_submissions_partial_failure_for_wrong_competition(): void {
		$other       = $this->create_competition( 'Other', 'other' );
		$foreign_img = $this->seed_image( $other );

		$this->set_request(
			array(
				'action'         => 'bulk_delete_submissions',
				'competition_id' => $this->competition_id,
				'image_ids'      => array( $foreign_img ),
			)
		);
		$this->set_nonce( 'photo_competition_bulk_delete_' . $this->competition_id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'bulk_delete_partial_failure', $this->settings_error_codes( self::GROUP ) );
		$this->assertNotContains( 'bulk_delete_success', $this->settings_error_codes( self::GROUP ) );
		// The foreign image is untouched.
		$this->assertNotNull( $this->images->find( $foreign_img ) );
	}

	/**
	 * A missing competition_id yields invalid_competition.
	 */
	public function test_bulk_delete_submissions_invalid_competition(): void {
		$this->set_request( array( 'action' => 'bulk_delete_submissions' ) );
		$this->set_nonce( 'photo_competition_bulk_delete_0' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager-submissions', $location );
		$this->assertContains( 'invalid_competition', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A missing/invalid nonce aborts via wp_die().
	 */
	public function test_bulk_delete_submissions_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'action'         => 'bulk_delete_submissions',
				'competition_id' => $this->competition_id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}

	/*
	 * -----------------------------------------------------------------
	 * admin_upload.
	 *
	 * NOTE: the success path and the "Competition not found" branch are gated
	 * behind is_uploaded_file(), which only returns true for a genuine HTTP
	 * multipart upload and cannot be satisfied in the PHPUnit harness. Only the
	 * guard/validation branches before that check are exercised here.
	 * -----------------------------------------------------------------
	 */

	/**
	 * Missing member/category yields missing_data.
	 */
	public function test_admin_upload_missing_data(): void {
		$this->set_request(
			array(
				'action'         => 'admin_upload',
				'competition_id' => $this->competition_id,
			)
		);
		$this->set_nonce( 'photo_competition_admin_upload_' . $this->competition_id );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'competition_id=' . $this->competition_id, $location );
		$this->assertContains( 'missing_data', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * Member and category present but no uploaded file yields missing_file.
	 */
	public function test_admin_upload_missing_file(): void {
		$this->set_request(
			array(
				'action'         => 'admin_upload',
				'competition_id' => $this->competition_id,
				'member_id'      => 42,
				'category'       => 'colour',
			)
		);
		$this->set_nonce( 'photo_competition_admin_upload_' . $this->competition_id );

		$this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertContains( 'missing_file', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A missing competition_id yields invalid_competition.
	 */
	public function test_admin_upload_invalid_competition(): void {
		$this->set_request( array( 'action' => 'admin_upload' ) );
		$this->set_nonce( 'photo_competition_admin_upload_0' );

		$location = $this->capture_redirect(
			function () {
				$this->controller->handle_actions();
			}
		);

		$this->assertStringContainsString( 'page=photo-competition-manager-submissions', $location );
		$this->assertContains( 'invalid_competition', $this->settings_error_codes( self::GROUP ) );
	}

	/**
	 * A missing/invalid nonce aborts via wp_die().
	 */
	public function test_admin_upload_bad_nonce_dies(): void {
		$this->set_request(
			array(
				'action'         => 'admin_upload',
				'competition_id' => $this->competition_id,
			)
		);

		$this->expectException( \WPDieException::class );
		$this->controller->handle_actions();
	}
}
