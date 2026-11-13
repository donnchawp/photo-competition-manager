<?php
/**
 * Tests for the image permalink.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Image_Permalink;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Service\Entries;
use PhotoCompetitionManager\Support\Image_Processor;
use WP_UnitTestCase;

class Image_Permalink_Test extends WP_UnitTestCase {

	/**
	 * @var int
	 */
	private $competition_id;

	/**
	 * @var int
	 */
	private $entry_id;

	/**
	 * Files written by a test.
	 *
	 * @var string[]
	 */
	private $written_files = array();

	public function setUp(): void {
		parent::setUp();

		$this->competition_id = (int) ( new Competitions_Repository() )->create(
			array(
				'title'     => 'Permalink Comp',
				'slug'      => 'permalink-comp',
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

		$this->entry_id = (int) ( new Images_Repository() )->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => 1,
				'category'       => 'colour',
				'filename'       => 'jane-murphy-colour.jpg',
			)
		);

		$folder = wp_upload_dir()['basedir'] . '/competitions/permalink-comp/colour';
		wp_mkdir_p( $folder );
		foreach ( array( 'jane-murphy-colour.jpg', Image_Processor::get_thumbnail_filename( 'jane-murphy-colour.jpg' ) ) as $filename ) {
			$this->written_files[] = $folder . '/' . $filename;
			file_put_contents( $folder . '/' . $filename, 'img' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	public function tearDown(): void {
		parent::tearDown();

		foreach ( $this->written_files as $file ) {
			wp_delete_file( $file );
		}
	}

	public function test_public_urls_use_entry_id_not_filename(): void {
		$urls = ( new Entries() )->public_urls( ( new Images_Repository() )->find( $this->entry_id ) );

		$this->assertStringContainsString( '/photocomp-image/' . $this->entry_id . '/?v=', $urls['full'] );
		$this->assertStringContainsString( '/photocomp-image/' . $this->entry_id . '/thumb/?v=', $urls['thumb'] );
		$this->assertStringNotContainsString( 'jane-murphy', $urls['full'] . $urls['thumb'] );
	}

	public function test_public_urls_are_empty_when_the_file_is_missing(): void {
		wp_delete_file( $this->written_files[1] );

		$urls = ( new Entries() )->public_urls( ( new Images_Repository() )->find( $this->entry_id ) );

		$this->assertNotSame( '', $urls['full'] );
		$this->assertSame( '', $urls['thumb'] );
	}

	public function test_resolve_finds_the_full_image_and_the_thumbnail(): void {
		$permalink = new Image_Permalink();

		$this->assertSame( $this->written_files[0], $permalink->resolve( $this->entry_id, '' ) );
		$this->assertSame( $this->written_files[1], $permalink->resolve( $this->entry_id, 'thumb' ) );
	}

	public function test_serve_404s_unknown_entry(): void {
		$this->assertNull( ( new Image_Permalink() )->resolve( $this->entry_id + 1000, '' ) );
	}

	public function test_serve_404s_deleted_competition(): void {
		$this->assertTrue( ( new Competitions_Repository() )->archive( $this->competition_id ) );

		$this->assertNull( ( new Image_Permalink() )->resolve( $this->entry_id, '' ) );
	}

	public function test_serve_404s_missing_file(): void {
		wp_delete_file( $this->written_files[0] );

		$this->assertNull( ( new Image_Permalink() )->resolve( $this->entry_id, '' ) );
	}

	public function test_the_rewrite_rule_maps_the_permalink_to_query_vars(): void {
		Image_Permalink::add_rewrite_rule();
		global $wp_rewrite;
		$extra = $wp_rewrite->extra_rules_top;
		$rule  = '^photocomp-image/([0-9]+)(?:/(thumb))?/?$';
		$this->assertArrayHasKey( $rule, $extra );
		$this->assertSame( 1, preg_match( '#' . $rule . '#', 'photocomp-image/12/thumb/', $m ) );
		$this->assertSame( array( '12', 'thumb' ), array_slice( $m, 1 ) );
	}
}
