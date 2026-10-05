<?php
/**
 * Tests for Upload_API token permission checks.
 *
 * @package PhotoCompetitionManager\Tests\API
 */

namespace PhotoCompetitionManager\Tests\API;

use PhotoCompetitionManager\API\Upload_API;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Deactivated members must not use the upload REST endpoints with an existing token.
 */
class Upload_API_Test extends WP_UnitTestCase {

	public function tearDown(): void {
		parent::tearDown();

		// Moves create the category folders, each with an index.php, even when an entry has no
		// files. Clean up after the rollback, so a failure here can't leave the test's rows behind.
		$folder = wp_upload_dir()['basedir'] . '/competitions/upload-comp';
		array_map( 'wp_delete_file', (array) glob( $folder . '/*/index.php' ) );
		$this->delete_folders( $folder );
	}

	private function request_for_member( bool $active ): WP_REST_Request {
		$competition_id = (int) ( new Competitions_Repository() )->create(
			array(
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
		$colour  = $this->entry( (int) $token->competition_id, (int) $token->member_id, 'colour' );
		$mono    = $this->entry( (int) $token->competition_id, (int) $token->member_id, 'black-white' );

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
		$theirs   = $this->entry( (int) $token->competition_id, $other_id, 'colour' );

		$response = rest_do_request( $this->change_categories_request( $request->get_param( 'token' ), array( $theirs => 'black-white' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'colour', $images->find( $theirs )->category );
	}

	/**
	 * An entry row with no files; a move skips files that aren't there.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param int    $member_id      Member ID.
	 * @param string $category       Category slug.
	 * @return int Entry ID.
	 */
	private function entry( int $competition_id, int $member_id, string $category ): int {
		return (int) ( new Images_Repository() )->create(
			array(
				'competition_id' => $competition_id,
				'member_id'      => $member_id,
				'category'       => $category,
				'filename'       => "entry-{$category}.jpg",
			)
		);
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
}
