<?php
/**
 * Tests for the slideshow shortcode and its image fetch.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Slideshow_Shortcode;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use WP_UnitTestCase;
use WPDieException;

class Slideshow_Shortcode_Test extends WP_UnitTestCase {

	/**
	 * @var Slideshow_Shortcode
	 */
	private $shortcode;

	/**
	 * @var int
	 */
	private $competition_id;

	/**
	 * Member the entry belongs to.
	 *
	 * @var int
	 */
	private $member_id = 0;

	/**
	 * Entry files written by a test.
	 *
	 * @var string[]
	 */
	private $written_files = array();

	public function setUp(): void {
		parent::setUp();

		$this->shortcode      = new Slideshow_Shortcode();
		$this->competition_id = (int) ( new Competitions_Repository() )->create(
			array(
				'title'     => 'Slideshow Comp',
				'slug'      => 'slideshow-comp',
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

		$user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$user->add_cap( 'manage_photo_competitions' );
		wp_set_current_user( $user->ID );
	}

	public function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();

		foreach ( $this->written_files as $file ) {
			wp_delete_file( $file );
		}
		$this->delete_folders( wp_upload_dir()['basedir'] . '/competitions/slideshow-comp' );
	}

	public function test_a_category_whose_images_are_all_missing_has_no_slideshow(): void {
		$this->add_entry();

		$output = $this->shortcode->render(
			array(
				'competition' => 'slideshow-comp',
				'category'    => 'colour',
			)
		);

		$this->assertStringContainsString( 'No images submitted in this category yet.', $output );
	}

	public function test_fetching_a_category_whose_images_are_all_missing_fails(): void {
		$this->add_entry();

		$response = $this->fetch_images( 'slideshow-comp' );

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'No images found for this category.', $response['data']['message'] );
	}

	public function test_fetched_images_are_found_under_the_competitions_own_slug(): void {
		$entry_id = $this->add_entry();
		$folder   = wp_upload_dir()['basedir'] . '/competitions/slideshow-comp/colour';
		wp_mkdir_p( $folder );
		$this->written_files[] = $folder . '/entry-' . $this->member_id . '.jpg';
		file_put_contents( end( $this->written_files ), 'full' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$response = $this->fetch_images( 'another-comp' );

		$this->assertTrue( $response['success'] );
		$this->assertSame( $entry_id, (int) $response['data']['images'][0]['id'] );
		$this->assertStringContainsString( '/competitions/slideshow-comp/colour/entry-' . $this->member_id . '.jpg?v=', $response['data']['images'][0]['url'] );
	}

	/**
	 * Add a colour entry with no files.
	 *
	 * @return int Entry ID.
	 */
	private function add_entry(): int {
		$this->member_id = (int) ( new Members_Repository() )->create(
			array(
				'name'   => 'Slide Entrant',
				'email'  => 'slide@example.com',
				'grade'  => 'beginner',
				'active' => 1,
			)
		);

		return Entry_Fixtures::insert_entry( $this->competition_id, 'colour', $this->member_id, array() );
	}

	/**
	 * Call the slideshow's image fetch as the admin screen does, and decode its JSON.
	 *
	 * @param string $posted_slug Competition slug the browser sends.
	 * @return array<string, mixed>
	 */
	private function fetch_images( string $posted_slug ): array {
		$_POST    = array(
			'nonce'            => wp_create_nonce( 'photo_comp_admin_slideshow' ),
			'competition_id'   => (string) $this->competition_id,
			'competition_slug' => $posted_slug,
			'category'         => 'colour',
		);
		$_REQUEST = $_POST;

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'get_wp_die_handler' ) );

		ob_start();
		try {
			$this->shortcode->handle_get_images();
		} catch ( WPDieException $e ) {
			unset( $e );
		}

		return json_decode( (string) ob_get_clean(), true );
	}
}
