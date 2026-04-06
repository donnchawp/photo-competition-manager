<?php
/**
 * Tests for Images_Repository.
 *
 * @package PhotoCompetitionManager\Tests\Repository
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use WP_UnitTestCase;

class Images_Repository_Test extends WP_UnitTestCase {

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images_repo;

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions_repo;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members_repo;

	/**
	 * Test competition ID.
	 *
	 * @var int
	 */
	private $competition_id;

	/**
	 * Test member ID.
	 *
	 * @var int
	 */
	private $member_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->images_repo       = new Images_Repository();
		$this->competitions_repo = new Competitions_Repository();
		$this->members_repo      = new Members_Repository();

		// Create test competition.
		$competition_id = $this->competitions_repo->create(
			array(
				'title'    => 'Test Competition',
				'slug'     => 'test-competition',
				'status'   => 'active',
				'settings' => wp_json_encode( array( 'categories' => array() ) ),
			)
		);

		$this->assertIsInt( $competition_id );
		$this->competition_id = $competition_id;

		// Create test member.
		$member_id = $this->members_repo->create(
			array(
				'name'  => 'Test User',
				'email' => 'test@example.com',
				'grade' => 'beginner',
			)
		);

		$this->assertIsInt( $member_id );
		$this->member_id = $member_id;
	}

	public function test_create_image_record(): void {
		$data = array(
			'competition_id' => $this->competition_id,
			'member_id'      => $this->member_id,
			'category'       => 'colour',
			'filename'       => 'testuser-colour-1.jpg',
		);

		$image_id = $this->images_repo->create( $data );

		$this->assertIsInt( $image_id );
		$this->assertGreaterThan( 0, $image_id );
	}

	public function test_create_rejects_missing_competition_id(): void {
		$data = array(
			'member_id' => $this->member_id,
			'category'  => 'colour',
			'filename'  => 'test.jpg',
		);

		$result = $this->images_repo->create( $data );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_data', $result->get_error_code() );
	}

	public function test_create_rejects_missing_member_id(): void {
		$data = array(
			'competition_id' => $this->competition_id,
			'category'       => 'colour',
			'filename'       => 'test.jpg',
		);

		$result = $this->images_repo->create( $data );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_data', $result->get_error_code() );
	}

	public function test_create_rejects_missing_category(): void {
		$data = array(
			'competition_id' => $this->competition_id,
			'member_id'      => $this->member_id,
			'filename'       => 'test.jpg',
		);

		$result = $this->images_repo->create( $data );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_data', $result->get_error_code() );
	}

	public function test_create_rejects_missing_filename(): void {
		$data = array(
			'competition_id' => $this->competition_id,
			'member_id'      => $this->member_id,
			'category'       => 'colour',
		);

		$result = $this->images_repo->create( $data );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_data', $result->get_error_code() );
	}

	public function test_find_retrieves_image_by_id(): void {
		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
			)
		);

		$image = $this->images_repo->find( $image_id );

		$this->assertIsObject( $image );
		$this->assertEquals( $image_id, $image->id );
		$this->assertEquals( $this->competition_id, $image->competition_id );
		$this->assertEquals( $this->member_id, $image->member_id );
		$this->assertEquals( 'colour', $image->category );
		$this->assertEquals( 'testuser-colour-1.jpg', $image->filename );
	}

	public function test_find_returns_null_for_invalid_id(): void {
		$image = $this->images_repo->find( 9999 );

		$this->assertNull( $image );
	}

	public function test_find_by_competition_retrieves_all_images(): void {
		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
			)
		);

		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'black-white',
				'filename'       => 'testuser-black-white-1.jpg',
			)
		);

		$images = $this->images_repo->find_by_competition( $this->competition_id );

		$this->assertIsArray( $images );
		$this->assertCount( 2, $images );
	}

	public function test_find_by_competition_filters_by_category(): void {
		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
			)
		);

		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'black-white',
				'filename'       => 'testuser-black-white-1.jpg',
			)
		);

		$images = $this->images_repo->find_by_competition( $this->competition_id, 'colour' );

		$this->assertIsArray( $images );
		$this->assertCount( 1, $images );
		$this->assertEquals( 'colour', $images[0]->category );
	}

	public function test_find_by_competition_filters_by_member(): void {
		$member2_id = $this->members_repo->create(
			array(
				'name'  => 'Test User 2',
				'email' => 'test2@example.com',
				'grade' => 'intermediate',
			)
		);

		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
			)
		);

		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $member2_id,
				'category'       => 'colour',
				'filename'       => 'testuser2-colour-1.jpg',
			)
		);

		$images = $this->images_repo->find_by_competition( $this->competition_id, null, $this->member_id );

		$this->assertIsArray( $images );
		$this->assertCount( 1, $images );
		$this->assertEquals( $this->member_id, $images[0]->member_id );
	}

	public function test_find_by_member_retrieves_member_images(): void {
		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
			)
		);

		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'black-white',
				'filename'       => 'testuser-black-white-1.jpg',
			)
		);

		$images = $this->images_repo->find_by_member( $this->member_id );

		$this->assertIsArray( $images );
		$this->assertCount( 2, $images );
	}

	public function test_count_by_member_category_returns_correct_count(): void {
		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
			)
		);

		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-2.jpg',
			)
		);

		$count = $this->images_repo->count_by_member_category( $this->competition_id, $this->member_id, 'colour' );

		$this->assertEquals( 2, $count );
	}

	public function test_count_by_member_category_excludes_other_categories(): void {
		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
			)
		);

		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'black-white',
				'filename'       => 'testuser-black-white-1.jpg',
			)
		);

		$count = $this->images_repo->count_by_member_category( $this->competition_id, $this->member_id, 'colour' );

		$this->assertEquals( 1, $count );
	}

	public function test_get_next_random_number_starts_at_one(): void {
		$random_number = $this->images_repo->get_next_random_number( $this->competition_id, 'colour' );

		$this->assertEquals( 1, $random_number );
	}

	public function test_get_next_random_number_increments(): void {
		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
				'random_number'  => 1,
			)
		);

		$random_number = $this->images_repo->get_next_random_number( $this->competition_id, 'colour' );

		$this->assertEquals( 2, $random_number );
	}

	public function test_get_next_random_number_is_category_specific(): void {
		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
				'random_number'  => 5,
			)
		);

		$random_number = $this->images_repo->get_next_random_number( $this->competition_id, 'black-white' );

		$this->assertEquals( 1, $random_number );
	}

	public function test_update_score_modifies_image_score(): void {
		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
			)
		);

		$result = $this->images_repo->update_score( $image_id, 8 );

		$this->assertTrue( $result );

		$image = $this->images_repo->find( $image_id );
		$this->assertEquals( 8, $image->score );
	}

	public function test_update_score_rejects_invalid_id(): void {
		$result = $this->images_repo->update_score( 9999, 7.0 );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_image', $result->get_error_code() );
	}

	public function test_delete_removes_image_record(): void {
		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
			)
		);

		$result = $this->images_repo->delete( $image_id );

		$this->assertTrue( $result );

		$image = $this->images_repo->find( $image_id );
		$this->assertNull( $image );
	}

	public function test_delete_rejects_invalid_id(): void {
		$result = $this->images_repo->delete( 9999 );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_image', $result->get_error_code() );
	}

	public function test_shuffle_deterministic_returns_empty_array_unchanged(): void {
		$result = $this->images_repo->shuffle_deterministic( array(), 1, 'colour' );

		$this->assertSame( array(), $result );
	}

	public function test_shuffle_deterministic_returns_single_image_unchanged(): void {
		$image     = (object) array( 'id' => 1 );
		$result    = $this->images_repo->shuffle_deterministic( array( $image ), 1, 'colour' );

		$this->assertCount( 1, $result );
		$this->assertSame( $image, $result[0] );
	}

	public function test_shuffle_deterministic_is_deterministic(): void {
		$images = array(
			(object) array( 'id' => 1 ),
			(object) array( 'id' => 2 ),
			(object) array( 'id' => 3 ),
			(object) array( 'id' => 4 ),
			(object) array( 'id' => 5 ),
		);

		$first  = $this->images_repo->shuffle_deterministic( $images, 10, 'colour' );
		$second = $this->images_repo->shuffle_deterministic( $images, 10, 'colour' );

		$first_ids  = array_map( fn( $img ) => $img->id, $first );
		$second_ids = array_map( fn( $img ) => $img->id, $second );

		$this->assertSame( $first_ids, $second_ids );
	}

	public function test_shuffle_deterministic_differs_by_category(): void {
		$images = array(
			(object) array( 'id' => 1 ),
			(object) array( 'id' => 2 ),
			(object) array( 'id' => 3 ),
			(object) array( 'id' => 4 ),
			(object) array( 'id' => 5 ),
		);

		$colour = $this->images_repo->shuffle_deterministic( $images, 10, 'colour' );
		$bw     = $this->images_repo->shuffle_deterministic( $images, 10, 'black-white' );

		$colour_ids = array_map( fn( $img ) => $img->id, $colour );
		$bw_ids     = array_map( fn( $img ) => $img->id, $bw );

		$this->assertNotSame( $colour_ids, $bw_ids, 'Different categories should produce different orders.' );
	}

	public function test_shuffle_deterministic_differs_by_competition(): void {
		$images = array(
			(object) array( 'id' => 1 ),
			(object) array( 'id' => 2 ),
			(object) array( 'id' => 3 ),
			(object) array( 'id' => 4 ),
			(object) array( 'id' => 5 ),
		);

		$comp1 = $this->images_repo->shuffle_deterministic( $images, 1, 'colour' );
		$comp2 = $this->images_repo->shuffle_deterministic( $images, 2, 'colour' );

		$comp1_ids = array_map( fn( $img ) => $img->id, $comp1 );
		$comp2_ids = array_map( fn( $img ) => $img->id, $comp2 );

		$this->assertNotSame( $comp1_ids, $comp2_ids, 'Different competitions should produce different orders.' );
	}

	public function test_shuffle_deterministic_preserves_all_images(): void {
		$images = array(
			(object) array( 'id' => 10, 'filename' => 'a.jpg' ),
			(object) array( 'id' => 20, 'filename' => 'b.jpg' ),
			(object) array( 'id' => 30, 'filename' => 'c.jpg' ),
		);

		$result = $this->images_repo->shuffle_deterministic( $images, 1, 'colour' );

		$result_ids = array_map( fn( $img ) => $img->id, $result );
		sort( $result_ids );

		$this->assertSame( array( 10, 20, 30 ), $result_ids );
	}

	// ---------------------------------------------------------------
	// update_category()
	// ---------------------------------------------------------------

	public function test_update_category_changes_image_category(): void {
		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
			)
		);

		$result = $this->images_repo->update_category( $image_id, 'black-white' );

		$this->assertTrue( $result );

		$image = $this->images_repo->find( $image_id );
		$this->assertSame( 'black-white', $image->category );
	}

	public function test_update_category_rejects_invalid_id(): void {
		$result = $this->images_repo->update_category( 9999, 'colour' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_image', $result->get_error_code() );
	}

	// ---------------------------------------------------------------
	// delete_by_competition()
	// ---------------------------------------------------------------

	public function test_delete_by_competition_removes_all_images(): void {
		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'a.jpg',
			)
		);

		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'black-white',
				'filename'       => 'b.jpg',
			)
		);

		$result = $this->images_repo->delete_by_competition( $this->competition_id );

		$this->assertTrue( $result );
		$this->assertEmpty( $this->images_repo->find_by_competition( $this->competition_id ) );
	}

	public function test_delete_by_competition_returns_false_for_invalid_id(): void {
		$this->assertFalse( $this->images_repo->delete_by_competition( 0 ) );
	}

	// ---------------------------------------------------------------
	// get_all_images_with_uploader_info()
	// ---------------------------------------------------------------

	public function test_get_all_images_with_uploader_info_joins_member_data(): void {
		$this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'test.jpg',
			)
		);

		$results = $this->images_repo->get_all_images_with_uploader_info();

		$this->assertCount( 1, $results );
		$this->assertSame( 'Test User', $results[0]->member_name );
		$this->assertSame( 'test@example.com', $results[0]->member_email );
	}

	// ---------------------------------------------------------------
	// regenerate_member_numbers()
	// ---------------------------------------------------------------

	public function test_regenerate_member_numbers_assigns_sequential(): void {
		$member2_id = $this->members_repo->create(
			array(
				'name'  => 'Test User 2',
				'email' => 'test2@example.com',
				'grade' => 'intermediate',
			)
		);

		$img1 = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'a.jpg',
			)
		);

		$img2 = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $member2_id,
				'category'       => 'colour',
				'filename'       => 'b.jpg',
			)
		);

		$result = $this->images_repo->regenerate_member_numbers( $this->competition_id );
		$this->assertTrue( $result );

		$image1 = $this->images_repo->find( $img1 );
		$image2 = $this->images_repo->find( $img2 );

		// Both should have numbers, and they should be different.
		$numbers = array( (int) $image1->random_number, (int) $image2->random_number );
		sort( $numbers );
		$this->assertSame( array( 1, 2 ), $numbers );
	}

	public function test_regenerate_member_numbers_rejects_invalid_competition(): void {
		$result = $this->images_repo->regenerate_member_numbers( 0 );
		$this->assertWPError( $result );
	}

	public function test_regenerate_member_numbers_rejects_empty_competition(): void {
		$result = $this->images_repo->regenerate_member_numbers( $this->competition_id );
		$this->assertWPError( $result );
		$this->assertSame( 'no_images', $result->get_error_code() );
	}

	public function test_create_assigns_random_number_automatically(): void {
		$image_id = $this->images_repo->create(
			array(
				'competition_id' => $this->competition_id,
				'member_id'      => $this->member_id,
				'category'       => 'colour',
				'filename'       => 'testuser-colour-1.jpg',
			)
		);

		$image = $this->images_repo->find( $image_id );

		$this->assertIsNumeric( $image->random_number );
		$this->assertEquals( 1, $image->random_number );
	}
}
