<?php
/**
 * Tests for Upload_API token permission checks.
 *
 * @package PhotoCompetitionManager\Tests\API
 */

namespace PhotoCompetitionManager\Tests\API;

use PhotoCompetitionManager\API\Upload_API;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Deactivated members must not use the upload REST endpoints with an existing token.
 */
class Upload_API_Test extends WP_UnitTestCase {

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
		$this->assertSame( 'inactive_member', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}
}
