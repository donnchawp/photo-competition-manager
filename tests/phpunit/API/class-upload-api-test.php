<?php
/**
 * Tests for Upload_API token permission checks.
 *
 * @package PhotoCompetitionManager\Tests\API
 */

namespace PhotoCompetitionManager\Tests\API;

use PhotoCompetitionManager\API\Upload_API;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Entrants_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Photo_Uploads;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Deactivated members must not use the upload REST endpoints with an existing token.
 */
class Upload_API_Test extends WP_UnitTestCase {

	use Photo_Uploads;

	/**
	 * $_SERVER as it was before the test.
	 *
	 * @var array<string, mixed>
	 */
	private $server;

	public function setUp(): void {
		parent::setUp();
		$this->server = $_SERVER;

		$this->accept_temp_files_as_uploads();
	}

	public function tearDown(): void {
		$_SERVER = $this->server;
		$_POST   = array();
		$_FILES  = array();
		parent::tearDown();

		// Moves create the category folders, each with an index.php, even when an entry has no
		// files. Clean up after the rollback, so a failure here can't leave the test's rows behind.
		$this->remove_added_uploads();
		$folder = wp_upload_dir()['basedir'] . '/competitions/upload-comp';
		// A file planted here by an aborted run is in $ignore_files, so remove_added_uploads() leaves it.
		if ( is_file( $folder ) ) {
			wp_delete_file( $folder );
		}
		$this->delete_folders( $folder );
		$this->remove_tmp_files();
	}

	private function request_for_member( bool $active ): WP_REST_Request {
		$competition_id = (int) ( new Competitions_Repository() )->create(
			array(
				'type_id'    => Type_Fixtures::id(),
				'title'     => 'Upload Comp',
				'slug'      => 'upload-comp',
				'open_date' => '2020-01-01 00:00:00',
			)
		);
		$member_id      = (int) ( new Members_Repository() )->create(
			array(
				'name'   => 'Uploader',
				'email'  => 'uploader@example.com',
				'grade'  => 'beginner',
				'active' => $active ? 1 : 0,
			)
		);

		$request = new WP_REST_Request( 'GET', '/photo-comp/v1/upload/quota' );
		$request->set_param( 'token', ( new Upload_Token_Repository() )->find_or_create( $member_id, $competition_id )->token );
		return $request;
	}

	public function test_active_member_token_is_permitted(): void {
		$request = $this->request_for_member( true );

		$this->assertTrue( ( new Upload_API() )->validate_token_permission( $request ) );
		$this->assertNotNull( $request->get_param( '_token_record' ) );
	}

	public function test_inactive_member_token_is_rejected(): void {
		$result = ( new Upload_API() )->validate_token_permission( $this->request_for_member( false ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_a_member_swaps_two_entries_in_one_request(): void {
		$images  = new Images_Repository();
		$request = $this->request_for_member( true );
		$token   = ( new Upload_Token_Repository() )->find_valid_token( $request->get_param( 'token' ) );
		$colour  = Entry_Fixtures::insert_entry( (int) $token->competition_id, 'colour', (int) $token->member_id, array() );
		$mono    = Entry_Fixtures::insert_entry( (int) $token->competition_id, 'black-white', (int) $token->member_id, array() );

		$response = rest_do_request(
			$this->change_categories_request(
				$request->get_param( 'token' ),
				array(
					$colour => 'black-white',
					$mono   => 'colour',
				)
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'black-white', $images->find( $colour )->category );
		$this->assertSame( 'colour', $images->find( $mono )->category );
	}

	public function test_a_member_cant_move_another_members_entry(): void {
		$images   = new Images_Repository();
		$request  = $this->request_for_member( true );
		$token    = ( new Upload_Token_Repository() )->find_valid_token( $request->get_param( 'token' ) );
		$other_id = (int) ( new Members_Repository() )->create(
			array(
				'name'   => 'Someone Else',
				'email'  => 'someone@example.com',
				'grade'  => 'beginner',
				'active' => 1,
			)
		);
		$theirs   = Entry_Fixtures::insert_entry( (int) $token->competition_id, 'colour', $other_id, array() );

		$response = rest_do_request( $this->change_categories_request( $request->get_param( 'token' ), array( $theirs => 'black-white' ) ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'colour', $images->find( $theirs )->category );
	}

	public function test_changes_must_name_a_category_for_each_entry(): void {
		$request = $this->request_for_member( true );
		$token   = ( new Upload_Token_Repository() )->find_valid_token( $request->get_param( 'token' ) );
		$entry   = Entry_Fixtures::insert_entry( (int) $token->competition_id, 'colour', (int) $token->member_id, array() );

		$not_a_category = rest_do_request( $this->change_categories_request( $request->get_param( 'token' ), array( $entry => array( 'colour' ) ) ) );
		$no_changes     = rest_do_request( $this->change_categories_request( $request->get_param( 'token' ), array() ) );

		$this->assertSame( 400, $not_a_category->get_status() );
		$this->assertSame( 400, $no_changes->get_status() );
	}

	public function test_an_admin_with_a_members_link_moves_their_entries_after_uploads_close(): void {
		$images  = new Images_Repository();
		$request = $this->request_for_member( true );
		$token   = ( new Upload_Token_Repository() )->find_valid_token( $request->get_param( 'token' ) );
		$entry   = Entry_Fixtures::insert_entry( (int) $token->competition_id, 'colour', (int) $token->member_id, array() );
		Workflow_Fixtures::close_uploads( (int) $token->competition_id );
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin_id )->add_cap( 'manage_photo_competitions' );
		wp_set_current_user( $admin_id );

		$response = rest_do_request( $this->change_categories_request( $request->get_param( 'token' ), array( $entry => 'black-white' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'black-white', $images->find( $entry )->category );
	}

	public function test_a_logged_in_user_who_isnt_an_admin_is_treated_as_the_member(): void {
		$images  = new Images_Repository();
		$request = $this->request_for_member( true );
		$token   = ( new Upload_Token_Repository() )->find_valid_token( $request->get_param( 'token' ) );
		$entry   = Entry_Fixtures::insert_entry( (int) $token->competition_id, 'colour', (int) $token->member_id, array() );
		Workflow_Fixtures::close_uploads( (int) $token->competition_id );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = rest_do_request( $this->change_categories_request( $request->get_param( 'token' ), array( $entry => 'black-white' ) ) );

		$this->assertSame( 'competition_closed', $response->as_error()->get_error_code() );
		$this->assertSame( 'colour', $images->find( $entry )->category );
	}

	public function test_a_move_over_quota_is_a_bad_request(): void {
		$images  = new Images_Repository();
		$request = $this->request_for_member( true );
		$token   = ( new Upload_Token_Repository() )->find_valid_token( $request->get_param( 'token' ) );
		$colour  = Entry_Fixtures::insert_entry( (int) $token->competition_id, 'colour', (int) $token->member_id, array() );
		Entry_Fixtures::insert_entry( (int) $token->competition_id, 'black-white', (int) $token->member_id, array() );

		$response = rest_do_request( $this->change_categories_request( $request->get_param( 'token' ), array( $colour => 'black-white' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'That would leave Black & White with 2 entries, but the limit is 1.', $response->get_data()['message'] );
		$this->assertSame( 'colour', $images->find( $colour )->category );
		$this->assertSame( array(), ( new Logs_Repository() )->find_by_competition( (int) $token->competition_id, 50, 0, array( 'event_type' => 'category_change_failed' ) ) );
	}

	public function test_a_move_in_an_archived_competition_is_not_found(): void {
		$images  = new Images_Repository();
		$request = $this->request_for_member( true );
		$token   = ( new Upload_Token_Repository() )->find_valid_token( $request->get_param( 'token' ) );
		$entry   = Entry_Fixtures::insert_entry( (int) $token->competition_id, 'colour', (int) $token->member_id, array() );
		( new Competitions_Repository() )->archive( (int) $token->competition_id );

		$response = rest_do_request( $this->change_categories_request( $request->get_param( 'token' ), array( $entry => 'black-white' ) ) );

		// The same status as the quota and batch upload endpoints give for the same link.
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'invalid_competition', $response->as_error()->get_error_code() );
		$this->assertSame( 'colour', $images->find( $entry )->category );
	}

	public function test_a_move_the_server_cant_make_is_a_server_error(): void {
		$images  = new Images_Repository();
		$request = $this->request_for_member( true );
		$token   = ( new Upload_Token_Repository() )->find_valid_token( $request->get_param( 'token' ) );
		$entry   = Entry_Fixtures::insert_entry( (int) $token->competition_id, 'colour', (int) $token->member_id, array() );
		// A file where the competition's folder should be, so its category folders can't be made.
		$competitions = wp_upload_dir()['basedir'] . '/competitions';
		wp_mkdir_p( $competitions );
		touch( $competitions . '/upload-comp' );

		$response = rest_do_request( $this->change_categories_request( $request->get_param( 'token' ), array( $entry => 'black-white' ) ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'mkdir_failed', $response->as_error()->get_error_code() );
		$this->assertSame( 'colour', $images->find( $entry )->category );
	}

	public function test_a_move_that_fails_in_the_database_logs_the_database_error_and_keeps_it_from_the_client(): void {
		global $wpdb;
		$request        = $this->request_for_member( true );
		$token          = ( new Upload_Token_Repository() )->find_valid_token( $request->get_param( 'token' ) );
		$competition_id = (int) $token->competition_id;
		$entry          = Entry_Fixtures::insert_entry( $competition_id, 'colour', (int) $token->member_id, array() );
		$break_update   = function ( $query ) use ( $wpdb ) {
			return 0 === strpos( $query, 'UPDATE `' . $wpdb->prefix . 'photocomp_images`' )
				? str_replace( ' SET ', ' SET no_such_column = 1, ', $query )
				: $query;
		};

		add_filter( 'query', $break_update );
		$suppress = $wpdb->suppress_errors( true );
		$response = rest_do_request( $this->change_categories_request( $request->get_param( 'token' ), array( $entry => 'black-white' ) ) );
		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_update );

		$this->assertSame( 500, $response->get_status() );
		$body = rest_get_server()->response_to_data( $response, false );
		$this->assertSame( 'db_update_failed', $body['code'] );
		$this->assertArrayNotHasKey( 'additional_data', $body );
		$this->assertStringNotContainsString( 'no_such_column', wp_json_encode( $body ) );

		$logs = ( new Logs_Repository() )->find_by_competition( $competition_id, 50, 0, array( 'event_type' => 'category_change_failed' ) );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'upload', $logs[0]->event_category );
		$metadata = json_decode( $logs[0]->metadata, true );
		$this->assertSame( 'db_update_failed', $metadata['code'] );
		$this->assertStringContainsString( 'no_such_column', wp_json_encode( $metadata['data'] ) );
		$this->assertSame( (int) $token->member_id, $metadata['member_id'] );
		$this->assertSame( array( (string) $entry => 'black-white' ), $metadata['changes'] );
	}

	public function test_a_move_without_an_uploads_folder_keeps_the_servers_path_from_the_client(): void {
		$request        = $this->request_for_member( true );
		$token          = ( new Upload_Token_Repository() )->find_valid_token( $request->get_param( 'token' ) );
		$competition_id = (int) $token->competition_id;
		$entry          = Entry_Fixtures::insert_entry( $competition_id, 'colour', (int) $token->member_id, array() );
		// A file where the month's uploads folder's parent should be, so WordPress can't make it.
		$competitions = wp_upload_dir()['basedir'] . '/competitions';
		wp_mkdir_p( $competitions );
		touch( $competitions . '/upload-comp' );
		$unmakeable_uploads = function ( $uploads ) use ( $competitions ) {
			$uploads['path'] = $competitions . '/upload-comp/uploads';
			return $uploads;
		};

		add_filter( 'upload_dir', $unmakeable_uploads );
		$response = rest_do_request( $this->change_categories_request( $request->get_param( 'token' ), array( $entry => 'black-white' ) ) );
		remove_filter( 'upload_dir', $unmakeable_uploads );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'upload_dir_error', $response->as_error()->get_error_code() );
		$this->assertStringNotContainsString( 'Unable to create directory', wp_json_encode( rest_get_server()->response_to_data( $response, false ) ) );
		$logs = ( new Logs_Repository() )->find_by_competition( $competition_id, 50, 0, array( 'event_type' => 'category_change_failed' ) );
		$this->assertCount( 1, $logs );
		$this->assertStringContainsString( 'Unable to create directory', $logs[0]->metadata );
	}

	/**
	 * An open interclub competition whose colour panel takes the given images and one substitute, and a club's upload token for it.
	 *
	 * @param int  $quota     Images in the colour panel.
	 * @param bool $interclub Whether the competition is interclub; an individual one keeps the same numbers.
	 * @return string Upload token.
	 */
	private function interclub_token( int $quota, bool $interclub = true ): string {
		$competition_id = (int) ( new Competitions_Repository() )->create(
			array(
				'type_id'   => Type_Fixtures::id(),
				'title'     => 'Upload Comp',
				'slug'      => 'upload-comp',
				'open_date' => '2020-01-01 00:00:00',
				'settings'  => array(
					'categories' => array(
						array(
							'slug'  => 'colour',
							'label' => 'Colour',
							'quota' => $quota,
						),
					),
					'interclub'  => array(
						'enabled'            => $interclub,
						'substitutes'        => 1,
						'photographer_limit' => 0,
					),
				),
			)
		);
		$club_id        = (int) ( new Members_Repository() )->create(
			array(
				'name'  => 'Lee Camera Club',
				'email' => 'lee@example.com',
				'grade' => 'beginner',
			)
		);

		return ( new Upload_Token_Repository() )->find_or_create( $club_id, $competition_id )->token;
	}

	public function test_a_club_uploads_a_named_substitute_beside_its_full_panel(): void {
		$token = $this->interclub_token( 1 );
		$this->assertSame( 200, rest_do_request( $this->upload_request( $token, 'Ann', false ) )->get_status() );

		$response = rest_do_request( $this->upload_request( $token, 'Bob', true ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['success_count'], wp_json_encode( $response->get_data() ) );
		$image = ( new Images_Repository() )->find( $response->get_data()['results']['file_0']['image_id'] );
		$this->assertSame( 'substitute', $image->entry_status );
		$this->assertSame( 'Bob', $image->photographer );
	}

	public function test_a_substitute_sent_to_an_individual_competition_is_told_it_takes_none(): void {
		$response = rest_do_request( $this->upload_request( $this->interclub_token( 2, false ), 'Ann', true ) );

		$this->assertSame( 'This competition doesn\'t take substitutes.', $response->get_data()['results']['file_0']['error'] ?? wp_json_encode( $response->get_data() ) );
	}

	public function test_a_batch_with_more_entries_than_the_panel_takes_saves_none_of_them(): void {
		$request = $this->batch_request( $this->interclub_token( 2 ) );
		$request->set_body_params(
			array(
				'assignments'   => array_fill_keys( array( 'file_0', 'file_1', 'file_2' ), 'colour' ),
				'photographers' => array_fill_keys( array( 'file_0', 'file_1', 'file_2' ), 'Ann' ),
			)
		);
		$request->set_file_params(
			array(
				'file_0' => $this->photo( array( 10, 0, 0 ) ),
				'file_1' => $this->photo( array( 20, 0, 0 ) ),
				'file_2' => $this->photo( array( 30, 0, 0 ) ),
			)
		);

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'batch_quota_exceeded', $response->as_error()->get_error_code() );
		$this->assertSame( array(), ( new Images_Repository() )->find_uploads( (int) ( new Competitions_Repository() )->find_by_slug( 'upload-comp' )->id ) );
	}

	/**
	 * A batch upload of one colour image.
	 *
	 * @param string $token        Upload token.
	 * @param string $photographer Photographer's name.
	 * @param bool   $substitute   Whether it's a substitute.
	 * @return WP_REST_Request
	 */
	private function upload_request( string $token, string $photographer, bool $substitute ): WP_REST_Request {
		static $shade = 0;
		++$shade;

		$request = $this->batch_request( $token );
		$request->set_body_params(
			array(
				'assignments'   => array( 'file_0' => 'colour' ),
				'photographers' => array( 'file_0' => $photographer ),
				'substitutes'   => array( 'file_0' => $substitute ? '1' : '' ),
			)
		);
		$request->set_file_params( array( 'file_0' => $this->photo( array( $shade * 40, 0, 0 ) ) ) );

		return $request;
	}

	public function test_a_batch_upload_bigger_than_post_max_size_says_the_image_is_too_big(): void {
		$this->post_over_post_max_size();

		$response = rest_do_request( $this->batch_request( $this->request_for_member( true )->get_param( 'token' ) ) );

		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'file_too_large', $response->as_error()->get_error_code() );
		$this->assertSame( 'That image is too big. Check the size limit under the upload form.', $response->get_data()['message'] );
	}

	public function test_a_batch_upload_with_a_body_is_checked_as_usual_whatever_its_length(): void {
		$this->post_over_post_max_size();
		$_POST['assignments'] = array( 'file_0' => 'colour' );
		$request              = $this->batch_request( $this->request_for_member( true )->get_param( 'token' ) );
		$request->set_body_params( $_POST );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'no_files', $response->as_error()->get_error_code() );
	}

	public function test_a_batch_upload_with_no_assignments_under_post_max_size_says_they_are_required(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['CONTENT_LENGTH'] = '0';

		$response = rest_do_request( $this->batch_request( $this->request_for_member( true )->get_param( 'token' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_assignments', $response->as_error()->get_error_code() );
	}

	/**
	 * Make this a POST longer than post_max_size, whose body PHP has dropped.
	 */
	private function post_over_post_max_size(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_SERVER['CONTENT_LENGTH'] = (string) ( 2 * GB_IN_BYTES );
		$_POST                     = array();
		$_FILES                    = array();
	}

	/**
	 * @param string $token Upload token.
	 * @return WP_REST_Request A batch upload with no body.
	 */
	private function batch_request( string $token ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/photo-comp/v1/upload/batch' );
		$request->set_query_params( array( 'token' => $token ) );

		return $request;
	}

	/**
	 * @param string             $token   Upload token.
	 * @param array<int, string> $changes New category, keyed by entry ID.
	 * @return WP_REST_Request
	 */
	private function change_categories_request( string $token, array $changes ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/photo-comp/v1/upload/categories' );
		$request->set_query_params( array( 'token' => $token ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'changes' => $changes ) ) );

		return $request;
	}

	/**
	 * A token for a member of an Individual competition, one of two types.
	 *
	 * @param string $entry_mode 'all' or 'selected'.
	 * @param string $type       The member's type name.
	 * @return array{0: WP_REST_Request, 1: int, 2: int} Quota request, competition ID and member ID.
	 */
	private function two_type_request( string $entry_mode, string $type ): array {
		Type_Fixtures::clear();
		$type_ids       = array(
			'Individual' => Type_Fixtures::create( 'Individual' ),
			'Team'       => Type_Fixtures::create( 'Team' ),
		);
		$competition_id = (int) ( new Competitions_Repository() )->create(
			array(
				'type_id'    => $type_ids['Individual'],
				'title'      => 'Upload Comp',
				'slug'       => 'upload-comp',
				'open_date'  => '2020-01-01 00:00:00',
				'entry_mode' => $entry_mode,
			)
		);
		$member_id      = (int) ( new Members_Repository() )->create(
			array(
				'name'     => 'Uploader',
				'email'    => 'uploader@example.com',
				'grade'    => 'beginner',
				'type_id'  => $type_ids[ $type ],
			)
		);

		$request = new WP_REST_Request( 'GET', '/photo-comp/v1/upload/quota' );
		$request->set_param( 'token', ( new Upload_Token_Repository() )->find_or_create( $member_id, $competition_id )->token );

		return array( $request, $competition_id, $member_id );
	}

	public function test_removed_entrant_link_is_refused_on_page_and_api(): void {
		list( $request, $competition_id, $member_id ) = $this->two_type_request( 'selected', 'Individual' );
		$entrants                                     = new Entrants_Repository();
		$entrants->add( $competition_id, $member_id );
		$this->assertTrue( ( new Upload_API() )->validate_token_permission( $request ) );

		$entrants->remove( $competition_id, $member_id );
		$result = ( new Upload_API() )->validate_token_permission( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_a_member_without_the_competitions_type_is_refused(): void {
		list( $request ) = $this->two_type_request( 'all', 'Team' );

		$result = ( new Upload_API() )->validate_token_permission( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_a_non_entrants_batch_upload_is_forbidden(): void {
		list( $request ) = $this->two_type_request( 'selected', 'Individual' );

		$response = rest_do_request( $this->batch_request( $request->get_param( 'token' ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'invalid_token', $response->get_data()['code'] );
	}

	public function test_a_member_with_the_competitions_type_is_permitted(): void {
		list( $request ) = $this->two_type_request( 'all', 'Individual' );

		$this->assertTrue( ( new Upload_API() )->validate_token_permission( $request ) );
	}
}
