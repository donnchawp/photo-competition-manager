<?php
/**
 * Tests for Upload_Shortcode token-based access.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Upload_Shortcode;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use WP_UnitTestCase;

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

	public function setUp(): void {
		parent::setUp();

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
	}

	public function tearDown(): void {
		unset( $_GET['token'] );
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

	public function test_inactive_member_token_falls_back_to_request_form(): void {
		$_GET['token'] = $this->issue_token( false );

		$output = $this->shortcode->render( array() );

		$this->assertStringContainsString( 'token-request-section', $output );
		$this->assertStringNotContainsString( 'Authenticated as', $output );
	}
}
