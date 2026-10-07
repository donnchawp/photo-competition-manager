<?php
/**
 * Tests for Upload_Shortcode token-based access.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Upload_Shortcode;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Tests\Admin\Redirect_Exception;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Member_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

require_once __DIR__ . '/../Admin/class-redirect-exception.php';

/**
 * Deactivated members must not reach the upload page with an existing link.
 */
class Upload_Shortcode_Test extends WP_UnitTestCase {

	/**
	 * @var Upload_Shortcode
	 */
	private $shortcode;

	/**
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * @var int
	 */
	private $competition_id;

	/**
	 * $_SERVER as it was before the test.
	 *
	 * @var array<string, mixed>
	 */
	private $server;

	public function setUp(): void {
		parent::setUp();
		$this->server = $_SERVER;

		$this->shortcode = new Upload_Shortcode();
		$this->members   = new Members_Repository();

		$this->competition_id = (int) ( new Competitions_Repository() )->create(
			array(
				'title'     => 'Upload Comp',
				'slug'      => 'upload-comp',
				'open_date' => '2020-01-01 00:00:00',
				'settings'  => array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
							'quota' => 1,
						),
					),
				),
			)
		);

		add_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );

		// The page only registers the script when the JS has been built, which CI doesn't do.
		wp_register_script( 'photo-comp-drag-drop-upload', 'drag-drop-upload.js', array(), '1', true );
	}

	public function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'throw_on_redirect' ) );
		$_SERVER  = $this->server;
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();
		unset( $GLOBALS['post'] );
		$GLOBALS['wp_scripts'] = null;
		parent::tearDown();
	}

	private function issue_token( bool $active ): string {
		$member_id = (int) $this->members->create(
			array(
				'name'   => 'Uploader',
				'email'  => 'uploader@example.com',
				'grade'  => 'beginner',
				'active' => $active ? 1 : 0,
			)
		);

		return ( new Upload_Token_Repository() )->find_or_create( $member_id, $this->competition_id )->token;
	}

	public function test_active_member_token_opens_upload_page(): void {
		$_GET['token'] = $this->issue_token( true );

		$output = $this->shortcode->render( array() );

		$this->assertStringContainsString( 'Authenticated as: Uploader', $output );
		$this->assertStringNotContainsString( 'token-request-section', $output );
	}

	/**
	 * The upload page shows the lower of the competition's limit (5 MB) and
	 * the server's.
	 *
	 * @dataProvider server_limits
	 *
	 * @param int $server_limit The server's limit, in bytes.
	 * @param int $shown_mb     The limit the page should show, in MB.
	 */
	public function test_the_upload_page_shows_the_limit_really_enforced( int $server_limit, int $shown_mb ): void {
		add_filter(
			'photo_competition_manager_server_upload_limit',
			static function () use ( $server_limit ) {
				return $server_limit;
			}
		);
		$_GET['token'] = $this->issue_token( true );

		$output = $this->shortcode->render( array() );

		$this->assertStringContainsString( "Max size: {$shown_mb} MB.", $output );
		$this->assertSame( $shown_mb * MB_IN_BYTES, (int) $this->upload_script_data()['maxFileSize'] );
	}

	public function test_the_upload_page_loads_the_upload_script_translations(): void {
		$_GET['token'] = $this->issue_token( true );

		$this->shortcode->render( array() );

		$this->assertSame( 'photo-competition-manager', wp_scripts()->registered['photo-comp-drag-drop-upload']->textdomain );
	}

	public function test_a_deactivated_members_page_loads_no_upload_script_translations(): void {
		$_GET['token'] = $this->issue_token( false );

		$this->shortcode->render( array() );

		$this->assertNull( wp_scripts()->registered['photo-comp-drag-drop-upload']->textdomain );
	}

	public function test_a_member_with_every_category_full_loads_no_upload_script_translations(): void {
		$_GET['token'] = $this->issue_token( true );
		$member        = $this->members->find_by_email( 'uploader@example.com' );
		( new Images_Repository() )->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => (int) $member->id,
				'category'       => 'colour',
				'filename'       => 'entry.jpg',
			)
		);

		$output = $this->shortcode->render( array() );

		$this->assertStringContainsString( 'You have reached the maximum number of submissions for all categories.', $output );
		$this->assertNull( wp_scripts()->registered['photo-comp-drag-drop-upload']->textdomain );
	}

	/**
	 * Server limits, and the limit the page should show for each.
	 *
	 * @return array<string, array{0: int, 1: int}>
	 */
	public function server_limits(): array {
		return array(
			'server below the competition' => array( 2 * MB_IN_BYTES, 2 ),
			'competition below the server' => array( 8 * MB_IN_BYTES, 5 ),
			// PHP reads a size limit of 0 or less as no limit.
			'no server limit'              => array( 0, 5 ),
		);
	}

	/**
	 * The settings the upload page hands the drag-and-drop script.
	 *
	 * @return array<string, mixed>
	 */
	private function upload_script_data(): array {
		$data = wp_scripts()->get_data( 'photo-comp-drag-drop-upload', 'data' );
		preg_match( '/var photoCompUpload = (\{.*\});/s', (string) $data, $matches );

		return json_decode( $matches[1] ?? 'null', true ) ?? array();
	}

	public function test_the_upload_page_has_a_live_region_for_the_drag_and_drop_results(): void {
		$_GET['token'] = $this->issue_token( true );

		$output = $this->shortcode->render( array() );

		// Screen readers announce only what's written into a live region already on the page.
		$this->assertStringContainsString( '<div class="photo-comp-upload-status" role="status" aria-atomic="false"></div>', $output );
	}

	public function test_submission_delete_form_uses_two_tap_confirmation(): void {
		$_GET['token'] = $this->issue_token( true );
		$member        = $this->members->find_by_email( 'uploader@example.com' );
		( new Images_Repository() )->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => (int) $member->id,
				'category'       => 'colour',
				'filename'       => 'entry.jpg',
			)
		);

		$output = $this->shortcode->render( array() );

		// The delete flag is a hidden field so disabling the button on submit cannot drop it.
		$this->assertStringContainsString( '<input type="hidden" name="photo_competition_delete" value="1" />', $output );
		$this->assertStringContainsString( 'photo-comp-delete-button', $output );
		$this->assertStringContainsString( 'data-confirm-label="Tap again to delete"', $output );
		$this->assertTrue( wp_script_is( 'photo-comp-delete-confirm', 'enqueued' ) );
	}

	public function test_an_entry_whose_image_is_missing_shows_no_broken_image(): void {
		$_GET['token'] = $this->issue_token( true );
		$member        = $this->members->find_by_email( 'uploader@example.com' );
		( new Images_Repository() )->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => (int) $member->id,
				'category'       => 'colour',
				'filename'       => 'entry.jpg',
			)
		);

		$output = $this->shortcode->render( array() );

		$this->assertStringNotContainsString( 'src=""', $output );
		$this->assertStringContainsString( 'Image unavailable', $output );
	}

	public function test_delete_script_not_loaded_without_submissions(): void {
		$_GET['token'] = $this->issue_token( true );

		$this->shortcode->render( array() );

		$this->assertFalse( wp_script_is( 'photo-comp-delete-confirm', 'enqueued' ) );
	}

	public function test_a_member_sees_only_the_closed_notice_once_uploads_close(): void {
		$_GET['token'] = $this->issue_token( true );
		Workflow_Fixtures::close_uploads( $this->competition_id );

		$output = $this->shortcode->render( array() );

		$this->assertStringContainsString( 'not currently open for submissions', $output );
		$this->assertStringNotContainsString( 'Authenticated as', $output );
	}

	public function test_an_admin_following_a_members_link_can_still_manage_their_entries_once_uploads_close(): void {
		$_GET['token'] = $this->issue_token( true );
		Workflow_Fixtures::close_uploads( $this->competition_id );
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin_id )->add_cap( 'manage_photo_competitions' );
		wp_set_current_user( $admin_id );

		$output = $this->shortcode->render( array() );

		$this->assertStringContainsString( 'Authenticated as: Uploader', $output );
		$this->assertStringContainsString( 'Uploads are closed', $output );
	}

	public function test_an_upload_over_quota_shows_the_quota_message(): void {
		$token = $this->issue_token( true );
		Entry_Fixtures::insert_entry( $this->competition_id, 'colour', (int) $this->members->find_by_email( 'uploader@example.com' )->id, array() );

		$output = $this->follow( $this->post_upload( $token, UPLOAD_ERR_OK ) );

		$this->assertStringContainsString( 'You&#039;ve already uploaded the maximum number of images for this category.', $output );
	}

	public function test_a_file_over_the_servers_upload_limit_says_it_is_too_big(): void {
		$output = $this->follow( $this->post_upload( $this->issue_token( true ), UPLOAD_ERR_INI_SIZE ) );

		$this->assertStringContainsString( 'That image is too big. Check the size limit under the upload form.', $output );
	}

	public function test_a_file_over_post_max_size_says_it_is_too_big(): void {
		$_GET['token']             = $this->issue_token( true );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['CONTENT_LENGTH'] = (string) ( 2 * GB_IN_BYTES );

		$output = $this->follow( $this->capture_redirect() );

		$this->assertStringContainsString( 'That image is too big. Check the size limit under the upload form.', $output );
	}

	public function test_an_empty_post_under_post_max_size_just_shows_the_page(): void {
		$_GET['token']             = $this->issue_token( true );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['CONTENT_LENGTH'] = '0';

		$output = $this->shortcode->render( array() );

		$this->assertStringContainsString( 'Authenticated as: Uploader', $output );
		$this->assertStringNotContainsString( 'class="error"', $output );
	}

	public function test_a_file_of_the_wrong_type_says_so(): void {
		$text_file = wp_tempnam( 'entry.gif' );
		file_put_contents( $text_file, 'not an image' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.

		$location = $this->post_upload(
			$this->issue_token( true ),
			UPLOAD_ERR_OK,
			array(
				'name'     => 'entry.gif',
				'type'     => 'image/gif',
				'tmp_name' => $text_file,
				'size'     => 12,
			)
		);
		wp_delete_file( $text_file );

		$this->assertStringContainsString( 'That file type isn&#039;t allowed. Check the formats listed under the upload form.', $this->follow( $location ) );
	}

	public function test_an_upload_error_with_no_message_of_its_own_still_says_upload_failed(): void {
		$output = $this->follow( $this->post_upload( $this->issue_token( true ), UPLOAD_ERR_PARTIAL ) );

		$this->assertStringContainsString( 'Upload failed. Please try again.', $output );
	}

	public function test_a_delete_once_uploads_close_says_deleting_has_stopped(): void {
		$token = $this->issue_token( true );
		$entry = Entry_Fixtures::insert_entry( $this->competition_id, 'colour', (int) $this->members->find_by_email( 'uploader@example.com' )->id, array() );
		Workflow_Fixtures::close_uploads( $this->competition_id );

		$output = $this->follow( $this->post_delete( $token, $entry ) );

		$this->assertStringContainsString( 'Images can&#039;t be deleted now that uploads have closed.', $output );
	}

	public function test_an_upload_once_uploads_close_says_uploads_are_not_open(): void {
		$token = $this->issue_token( true );
		Workflow_Fixtures::close_uploads( $this->competition_id );

		$output = $this->follow( $this->post_upload( $token, UPLOAD_ERR_OK ) );

		$this->assertStringContainsString( 'This competition isn&#039;t accepting uploads right now.', $output );
	}

	/**
	 * Uploads can't reopen once a category's voting has started, but a site
	 * that reopened them before that rule may still have uploads open.
	 */
	public function test_an_upload_to_a_category_whose_voting_has_started_says_so(): void {
		$token = $this->issue_token( true );
		( new Competitions_Repository() )->save_workflow( $this->competition_id, array( 'stages' => array( 'colour' => Competition_Workflow::STAGE_CRITIQUE ) ) );

		$output = $this->follow( $this->post_upload( $token, UPLOAD_ERR_OK ) );

		$this->assertStringContainsString( 'Voting has started in this category, so it can&#039;t take new entries.', $output );
	}

	public function test_deleting_an_entry_thats_already_gone_says_so(): void {
		$output = $this->follow( $this->post_delete( $this->issue_token( true ), 999999 ) );

		$this->assertStringContainsString( 'That image wasn&#039;t found. It may already have been deleted.', $output );
	}

	public function test_a_delete_error_with_no_message_of_its_own_still_says_failed_to_delete(): void {
		$token   = $this->issue_token( true );
		$someone = Member_Fixtures::insert_with_grade( 'Someone Else', 'someone@example.com', 'beginner' );
		$entry   = Entry_Fixtures::insert_entry( $this->competition_id, 'colour', $someone, array() );

		$output = $this->follow( $this->post_delete( $token, $entry ) );

		$this->assertStringContainsString( 'Failed to delete image. Please try again.', $output );
	}

	public function test_an_unknown_message_key_shows_no_message(): void {
		$_GET = array(
			'token'    => $this->issue_token( true ),
			'msg_type' => 'error',
			'msg_key'  => 'upload_made_up',
			'msg_time' => time(),
		);

		$output = $this->shortcode->render( array() );

		$this->assertStringNotContainsString( 'class="error"', $output );
	}

	/**
	 * @dataProvider message_texts
	 */
	public function test_each_message_key_shows_its_text( string $key, string $escaped_text ): void {
		$_GET = array(
			'token'    => $this->issue_token( true ),
			'msg_type' => 'success',
			'msg_key'  => $key,
			'msg_time' => time(),
		);

		$this->assertStringContainsString( '<p class="success">' . $escaped_text . '</p>', $this->shortcode->render( array() ) );
	}

	/**
	 * Every message key and the text it shows, as escaped on the page.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function message_texts(): array {
		return array(
			'upload_success'     => array( 'upload_success', 'Image uploaded successfully!' ),
			'delete_success'     => array( 'delete_success', 'Image deleted successfully.' ),
			'category_missing'   => array( 'category_missing', 'Please select a category.' ),
			'image_missing'      => array( 'image_missing', 'Please select an image to upload.' ),
			'invalid_deletion'   => array( 'invalid_deletion', 'Invalid deletion request.' ),
			'upload_failed'      => array( 'upload_failed', 'Upload failed. Please try again.' ),
			'delete_failed'      => array( 'delete_failed', 'Failed to delete image. Please try again.' ),
			'quota_exceeded'     => array( 'quota_exceeded', 'You&#039;ve already uploaded the maximum number of images for this category.' ),
			'uploads_closed'     => array( 'uploads_closed', 'This competition isn&#039;t accepting uploads right now.' ),
			'category_has_votes' => array( 'category_has_votes', 'This category already has votes, so it can&#039;t take new entries.' ),
			'voting_started'     => array( 'voting_started', 'Voting has started in this category, so it can&#039;t take new entries.' ),
			'invalid_category'   => array( 'invalid_category', 'That category isn&#039;t part of this competition.' ),
			'file_too_large'     => array( 'file_too_large', 'That image is too big. Check the size limit under the upload form.' ),
			'wrong_file_type'    => array( 'wrong_file_type', 'That file type isn&#039;t allowed. Check the formats listed under the upload form.' ),
			'invalid_image'      => array( 'invalid_image', 'That file isn&#039;t a valid image.' ),
			'deleting_closed'    => array( 'deleting_closed', 'Images can&#039;t be deleted now that uploads have closed.' ),
			'entry_not_found'    => array( 'entry_not_found', 'That image wasn&#039;t found. It may already have been deleted.' ),
		);
	}

	public function test_a_message_shows_its_translation(): void {
		$translate = static function ( $translation, $text, $domain ) {
			if ( 'photo-competition-manager' === $domain && 'Upload failed. Please try again.' === $text ) {
				return 'Níor éirigh leis an uaslódáil.';
			}
			return $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );

		try {
			$output = $this->follow( $this->post_upload( $this->issue_token( true ), UPLOAD_ERR_PARTIAL ) );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}

		$this->assertStringContainsString( '<p class="error">Níor éirigh leis an uaslódáil.</p>', $output );
	}

	/**
	 * Post the upload page's own form, and return where it redirects.
	 *
	 * @param string               $token      Upload token.
	 * @param int                  $file_error The uploaded file's PHP error code.
	 * @param array<string, mixed> $file       Fields of the uploaded file to override.
	 * @return string Redirect location.
	 */
	private function post_upload( string $token, int $file_error, array $file = array() ): string {
		$nonce = wp_create_nonce( 'photo_competition_upload_with_token' );

		$_GET['token']                       = $token;
		$_POST['photo_competition_upload']   = '1';
		$_POST['photo_competition_nonce']    = $nonce;
		$_REQUEST['photo_competition_nonce'] = $nonce;
		$_POST['category']                   = 'colour';
		$_FILES['image']                     = array_merge(
			array(
				'name'     => 'entry.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => '/nonexistent/entry.jpg',
				'error'    => $file_error,
				'size'     => 1024,
			),
			$file
		);

		return $this->capture_redirect();
	}

	/**
	 * Post the delete button for an entry, and return where it redirects.
	 *
	 * @param string $token    Upload token.
	 * @param int    $image_id Entry ID.
	 * @return string Redirect location.
	 */
	private function post_delete( string $token, int $image_id ): string {
		$nonce = wp_create_nonce( 'photo_competition_delete_with_token' );

		$_GET['token']                              = $token;
		$_POST['photo_competition_delete']          = '1';
		$_POST['photo_competition_delete_nonce']    = $nonce;
		$_REQUEST['photo_competition_delete_nonce'] = $nonce;
		$_POST['image_id']                          = (string) $image_id;

		return $this->capture_redirect();
	}

	/**
	 * Redirect interceptor: stop before the exit that follows a form post.
	 *
	 * @param string $location Redirect target.
	 * @throws Redirect_Exception Always, carrying the location.
	 */
	public function throw_on_redirect( $location ) {
		throw new Redirect_Exception( (string) $location ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Test harness; location captured, not output.
	}

	/**
	 * Render the page, and return the redirect it makes instead of exiting.
	 *
	 * @return string Redirect location.
	 */
	private function capture_redirect(): string {
		$GLOBALS['post'] = get_post( self::factory()->post->create( array( 'post_type' => 'page' ) ) );

		try {
			$this->shortcode->render( array() );
		} catch ( Redirect_Exception $e ) {
			return $e->getMessage();
		}

		$this->fail( 'Expected a redirect but none occurred.' );
	}

	/**
	 * Load the page a redirect points at, as the browser would after a form post.
	 *
	 * @param string $location Redirect location.
	 * @return string Rendered page.
	 */
	private function follow( string $location ): string {
		unset( $_SERVER['CONTENT_LENGTH'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$_REQUEST                  = array();
		$_FILES                    = array();
		wp_parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $_GET );

		return $this->shortcode->render( array() );
	}

	public function test_inactive_member_token_falls_back_to_request_form(): void {
		$_GET['token'] = $this->issue_token( false );

		$output = $this->shortcode->render( array() );

		$this->assertStringContainsString( 'token-request-section', $output );
		$this->assertStringNotContainsString( 'Authenticated as', $output );
	}
}
