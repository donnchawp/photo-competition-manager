<?php
/**
 * Tests for Upload_Handler.
 *
 * Tests cover validation logic (ownership, quota, category, competition state).
 * Filesystem operations via Image_Processor are not exercised here.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Upload_Handler;
use WP_UnitTestCase;

class Upload_Handler_Test extends WP_UnitTestCase {

	/**
	 * @var Upload_Handler
	 */
	private $handler;

	/**
	 * @var Competitions_Repository
	 */
	private $competitions_repo;

	/**
	 * @var Images_Repository
	 */
	private $images_repo;

	/**
	 * @var Members_Repository
	 */
	private $members_repo;

	/**
	 * @var int
	 */
	private $competition_id;

	/**
	 * @var int
	 */
	private $member_id;

	public function setUp(): void {
		parent::setUp();

		$this->competitions_repo = new Competitions_Repository();
		$this->images_repo       = new Images_Repository();
		$this->members_repo      = new Members_Repository();

		// Pass null for Image_Processor — tests here don't reach filesystem ops.
		$this->handler = new Upload_Handler(
			$this->competitions_repo,
			$this->images_repo,
			$this->members_repo,
			null
		);

		// Create an open competition with default settings (colour + black-white, quota 1 each).
		$this->competition_id = $this->competitions_repo->create(
			array(
				'title'     => 'Test Competition',
				'slug'      => 'test-comp',
				'open_date' => '2020-01-01 00:00:00',
			)
		);

		$this->member_id = $this->members_repo->create(
			array(
				'name'   => 'Alice',
				'email'  => 'alice@example.com',
				'grade'  => 'beginner',
				'active' => 1,
			)
		);
	}

	// ---------------------------------------------------------------
	// handle_upload() — validation paths
	// ---------------------------------------------------------------

	public function test_handle_upload_rejects_invalid_competition(): void {
		$result = $this->handler->handle_upload( 9999, $this->member_id, 'colour', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
	}

	public function test_handle_upload_rejects_closed_competition(): void {
		// Create a competition that closed in the past.
		$closed_id = $this->competitions_repo->create(
			array(
				'title'      => 'Closed Comp',
				'slug'       => 'closed-comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-02-01 00:00:00',
			)
		);

		$result = $this->handler->handle_upload( $closed_id, $this->member_id, 'colour', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'competition_closed', $result->get_error_code() );
	}

	public function test_handle_upload_rejects_invalid_member(): void {
		$result = $this->handler->handle_upload( $this->competition_id, 9999, 'colour', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_member', $result->get_error_code() );
	}

	public function test_handle_upload_rejects_inactive_member(): void {
		$inactive_id = $this->members_repo->create(
			array(
				'name'   => 'Inactive',
				'email'  => 'inactive@example.com',
				'grade'  => 'beginner',
				'active' => 0,
			)
		);

		$result = $this->handler->handle_upload( $this->competition_id, $inactive_id, 'colour', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'inactive_member', $result->get_error_code() );
	}

	public function test_handle_upload_rejects_invalid_category(): void {
		$result = $this->handler->handle_upload( $this->competition_id, $this->member_id, 'nonexistent', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_category', $result->get_error_code() );
	}

	public function test_handle_upload_rejects_when_quota_exceeded(): void {
		// Default quota is 1. Insert one image to fill it.
		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'existing.jpg',
			)
		);

		$result = $this->handler->handle_upload( $this->competition_id, $this->member_id, 'colour', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'quota_exceeded', $result->get_error_code() );
	}

	// ---------------------------------------------------------------
	// delete_submission() — validation paths
	// ---------------------------------------------------------------

	public function test_delete_submission_rejects_invalid_image(): void {
		$result = $this->handler->delete_submission( 9999, $this->member_id, $this->competition_id );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_image', $result->get_error_code() );
	}

	public function test_delete_submission_rejects_wrong_owner(): void {
		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'test.jpg',
			)
		);

		$other_member = $this->members_repo->create(
			array(
				'name'   => 'Bob',
				'email'  => 'bob@example.com',
				'grade'  => 'beginner',
				'active' => 1,
			)
		);

		$result = $this->handler->delete_submission( $image_id, $other_member, $this->competition_id );

		$this->assertWPError( $result );
		$this->assertSame( 'not_authorized', $result->get_error_code() );
	}

	public function test_delete_submission_rejects_wrong_competition(): void {
		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'test.jpg',
			)
		);

		$result = $this->handler->delete_submission( $image_id, $this->member_id, 9999 );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
	}

	public function test_delete_submission_rejects_when_competition_closed(): void {
		$closed_id = $this->competitions_repo->create(
			array(
				'title'      => 'Closed Comp',
				'slug'       => 'closed-comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-02-01 00:00:00',
			)
		);

		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $closed_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'test.jpg',
			)
		);

		$result = $this->handler->delete_submission( $image_id, $this->member_id, $closed_id );

		$this->assertWPError( $result );
		$this->assertSame( 'competition_closed', $result->get_error_code() );
	}

	// ---------------------------------------------------------------
	// get_quota_status()
	// ---------------------------------------------------------------

	public function test_get_quota_status_returns_full_quota_when_empty(): void {
		$status = $this->handler->get_quota_status( $this->competition_id, $this->member_id, 'colour' );

		$this->assertIsArray( $status );
		$this->assertSame( 0, $status['current'] );
		$this->assertSame( 1, $status['quota'] );
		$this->assertSame( 1, $status['remaining'] );
	}

	public function test_get_quota_status_reflects_uploads(): void {
		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'test.jpg',
			)
		);

		$status = $this->handler->get_quota_status( $this->competition_id, $this->member_id, 'colour' );

		$this->assertSame( 1, $status['current'] );
		$this->assertSame( 0, $status['remaining'] );
	}

	public function test_get_quota_status_rejects_invalid_competition(): void {
		$result = $this->handler->get_quota_status( 9999, $this->member_id, 'colour' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
	}

	public function test_get_quota_status_rejects_invalid_category(): void {
		$result = $this->handler->get_quota_status( $this->competition_id, $this->member_id, 'nonexistent' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_category', $result->get_error_code() );
	}

	// ---------------------------------------------------------------
	// update_submission_category() — validation paths
	// ---------------------------------------------------------------

	public function test_update_category_rejects_invalid_submission(): void {
		$result = $this->handler->update_submission_category( 9999, $this->member_id, $this->competition_id, 'black-white' );

		$this->assertWPError( $result );
		$this->assertSame( 'submission_not_found', $result->get_error_code() );
	}

	public function test_update_category_rejects_wrong_owner(): void {
		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'test.jpg',
			)
		);

		$other_member = $this->members_repo->create(
			array(
				'name'   => 'Bob',
				'email'  => 'bob@example.com',
				'grade'  => 'beginner',
				'active' => 1,
			)
		);

		$result = $this->handler->update_submission_category( $image_id, $other_member, $this->competition_id, 'black-white' );

		$this->assertWPError( $result );
		$this->assertSame( 'permission_denied', $result->get_error_code() );
	}

	public function test_update_category_rejects_wrong_competition(): void {
		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'test.jpg',
			)
		);

		$result = $this->handler->update_submission_category( $image_id, $this->member_id, 9999, 'black-white' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
	}

	public function test_update_category_returns_true_when_unchanged(): void {
		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'test.jpg',
			)
		);

		$result = $this->handler->update_submission_category( $image_id, $this->member_id, $this->competition_id, 'colour' );

		$this->assertTrue( $result );
	}

	public function test_update_category_rejects_invalid_target_category(): void {
		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'test.jpg',
			)
		);

		$result = $this->handler->update_submission_category( $image_id, $this->member_id, $this->competition_id, 'nonexistent' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_category', $result->get_error_code() );
	}

	// ---------------------------------------------------------------
	// get_member_submissions()
	// ---------------------------------------------------------------

	public function test_get_member_submissions_returns_empty_for_invalid_competition(): void {
		$result = $this->handler->get_member_submissions( 9999, $this->member_id );

		$this->assertSame( array(), $result );
	}
}
