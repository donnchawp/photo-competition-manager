<?php
/**
 * Tests for Upload_Token_Repository.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use WP_UnitTestCase;
use function PhotoCompetitionManager\Support\utc_time;

class Upload_Token_Repository_Test extends WP_UnitTestCase {

	/**
	 * Repository instance.
	 *
	 * @var Upload_Token_Repository
	 */
	private $repo;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->repo = new Upload_Token_Repository();
	}

	/**
	 * Test table suffix.
	 */
	public function test_table_suffix() {
		$reflection = new \ReflectionClass( $this->repo );
		$method     = $reflection->getMethod( 'table_suffix' );
		$method->setAccessible( true );

		$this->assertEquals( 'photocomp_upload_tokens', $method->invoke( $this->repo ) );
	}

	/**
	 * Test table name includes prefix.
	 */
	public function test_table_name() {
		global $wpdb;
		$this->assertEquals( $wpdb->prefix . 'photocomp_upload_tokens', $this->repo->table() );
	}

	/**
	 * Test creating a token with find_or_create.
	 */
	public function test_create_token() {
		$member_id      = 1;
		$competition_id = 2;

		$token = $this->repo->find_or_create( $member_id, $competition_id );

		$this->assertIsObject( $token );
		$this->assertObjectHasProperty( 'id', $token );
		$this->assertGreaterThan( 0, $token->id );

		// Verify token properties.
		$this->assertEquals( $member_id, $token->member_id );
		$this->assertEquals( $competition_id, $token->competition_id );
		$this->assertNotEmpty( $token->token );
		$this->assertNull( $token->used_at );
	}

	/**
	 * Test find_or_create with invalid data returns error.
	 */
	public function test_create_with_invalid_data() {
		$result = $this->repo->find_or_create( 0, 1 );
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Test finding a valid token.
	 */
	public function test_find_valid_token() {
		$token_obj = $this->repo->find_or_create( 1, 2 );
		$this->assertIsObject( $token_obj );

		$found = $this->repo->find_valid_token( $token_obj->token );

		$this->assertNotNull( $found );
		$this->assertEquals( $token_obj->id, $found->id );
		$this->assertEquals( 1, $found->member_id );
		$this->assertEquals( 2, $found->competition_id );
		$this->assertNull( $found->used_at );
	}

	/**
	 * Test finding expired token returns null.
	 */
	public function test_find_expired_token_returns_null() {
		global $wpdb;

		// Create an expired token directly in the database.
		$token_string = bin2hex( random_bytes( 32 ) );
		$expires_at   = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ); // Already expired.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$this->repo->table(),
			array(
				'member_id'      => 1,
				'competition_id' => 2,
				'token'          => $token_string,
				'expires_at'     => $expires_at,
				'created_at'     => utc_time(),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		$found = $this->repo->find_valid_token( $token_string );
		$this->assertNull( $found );
	}

	/**
	 * Test cleanup expired tokens.
	 */
	public function test_cleanup_expired() {
		global $wpdb;

		// Create expired token directly in database.
		$expired_token = bin2hex( random_bytes( 32 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$this->repo->table(),
			array(
				'member_id'      => 10,
				'competition_id' => 20,
				'token'          => $expired_token,
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				'created_at'     => utc_time(),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		// Create valid token for different member/competition.
		$valid_token_obj = $this->repo->find_or_create( 11, 21 );
		$this->assertIsObject( $valid_token_obj );

		$deleted = $this->repo->cleanup_expired();
		$this->assertGreaterThan( 0, $deleted );

		// Verify expired token is gone.
		$found_expired = $this->repo->find_valid_token( $expired_token );
		$this->assertNull( $found_expired );

		// Verify valid token still exists.
		$found_valid = $this->repo->find_valid_token( $valid_token_obj->token );
		$this->assertNotNull( $found_valid );
	}

	/**
	 * Test has_recent_email_send for rate limiting.
	 */
	public function test_has_recent_token() {
		$member_id      = 1;
		$competition_id = 2;

		// Initially should be false.
		$this->assertFalse( $this->repo->has_recent_email_send( $member_id, $competition_id ) );

		// Create a token and set sent_at.
		$token_obj = $this->repo->find_or_create( $member_id, $competition_id );
		$this->assertIsObject( $token_obj );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->repo->table(),
			array( 'sent_at' => utc_time() ),
			array( 'id' => $token_obj->id ),
			array( '%s' ),
			array( '%d' )
		);

		// Should now be true.
		$this->assertTrue( $this->repo->has_recent_email_send( $member_id, $competition_id ) );
	}

	/**
	 * Test has_recent_email_send returns false for old sent_at.
	 */
	public function test_has_recent_token_old_token() {
		$member_id      = 1;
		$competition_id = 2;

		// Create a token with old sent_at (older than 5 minutes).
		global $wpdb;
		$old_time     = gmdate( 'Y-m-d H:i:s', time() - ( 10 * MINUTE_IN_SECONDS ) );
		$token_string = bin2hex( random_bytes( 32 ) );
		$expires_at   = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$this->repo->table(),
			array(
				'member_id'      => $member_id,
				'competition_id' => $competition_id,
				'token'          => $token_string,
				'expires_at'     => $expires_at,
				'created_at'     => utc_time(),
				'sent_at'        => $old_time,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		// Should be false since sent_at is old.
		$this->assertFalse( $this->repo->has_recent_email_send( $member_id, $competition_id ) );
	}

	/**
	 * Test has_recent_email_send returns false for different competition.
	 */
	public function test_has_recent_token_different_competition() {
		$member_id = 1;

		// Create token for competition 2 with recent sent_at.
		$token_obj = $this->repo->find_or_create( $member_id, 2 );
		$this->assertIsObject( $token_obj );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->repo->table(),
			array( 'sent_at' => utc_time() ),
			array( 'id' => $token_obj->id ),
			array( '%s' ),
			array( '%d' )
		);

		// Check for competition 3.
		$this->assertFalse( $this->repo->has_recent_email_send( $member_id, 3 ) );
	}

	/**
	 * Test first_accessed_at is set on first find_valid_token call.
	 */
	public function test_first_accessed_at_set_on_first_access() {
		$token_obj = $this->repo->find_or_create( 1, 2 );
		$this->assertIsObject( $token_obj );

		// First access should set first_accessed_at.
		$found = $this->repo->find_valid_token( $token_obj->token );
		$this->assertNotNull( $found );
		$this->assertNotNull( $found->first_accessed_at );

		// Store the timestamp.
		$first_timestamp = $found->first_accessed_at;

		// Wait a moment to ensure time difference.
		sleep( 1 );

		// Second access should NOT update first_accessed_at.
		$found_again = $this->repo->find_valid_token( $token_obj->token );
		$this->assertNotNull( $found_again );
		$this->assertEquals( $first_timestamp, $found_again->first_accessed_at );
	}

	/**
	 * Test first_accessed_at is NOT updated on subsequent accesses.
	 */
	public function test_first_accessed_at_not_updated_on_subsequent_access() {
		$token_obj = $this->repo->find_or_create( 1, 2 );
		$this->assertIsObject( $token_obj );

		// Access token multiple times.
		$first_find = $this->repo->find_valid_token( $token_obj->token );
		$first_time = $first_find->first_accessed_at;

		sleep( 1 );

		$second_find = $this->repo->find_valid_token( $token_obj->token );
		$third_find  = $this->repo->find_valid_token( $token_obj->token );

		// All should have the same first_accessed_at.
		$this->assertEquals( $first_time, $second_find->first_accessed_at );
		$this->assertEquals( $first_time, $third_find->first_accessed_at );
	}

	/**
	 * Test get_tracking_by_competition returns correct data.
	 */
	public function test_get_tracking_by_competition() {
		$competition_id = 5;

		// Create tokens for different members.
		$token1 = $this->repo->find_or_create( 1, $competition_id );
		$token2 = $this->repo->find_or_create( 2, $competition_id );
		$token3 = $this->repo->find_or_create( 3, $competition_id );

		$this->assertIsObject( $token1 );
		$this->assertIsObject( $token2 );
		$this->assertIsObject( $token3 );

		// Access only some tokens.
		$this->repo->find_valid_token( $token1->token );
		$this->repo->find_valid_token( $token3->token );

		// Get tracking data.
		$tracking = $this->repo->get_tracking_by_competition( $competition_id );

		// Should have 3 members.
		$this->assertCount( 3, $tracking );

		// Member 1 should have opened link.
		$this->assertArrayHasKey( 1, $tracking );
		$this->assertNotNull( $tracking[1]->first_opened_at );

		// Member 2 should NOT have opened link.
		$this->assertArrayHasKey( 2, $tracking );
		$this->assertNull( $tracking[2]->first_opened_at );

		// Member 3 should have opened link.
		$this->assertArrayHasKey( 3, $tracking );
		$this->assertNotNull( $tracking[3]->first_opened_at );
	}

	/**
	 * Test get_tracking_by_competition with no tokens returns empty array.
	 */
	public function test_get_tracking_by_competition_empty() {
		$tracking = $this->repo->get_tracking_by_competition( 999 );
		$this->assertIsArray( $tracking );
		$this->assertEmpty( $tracking );
	}

	/**
	 * Test get_tracking_by_competition only returns data for specified competition.
	 */
	public function test_get_tracking_by_competition_filters_correctly() {
		// Create tokens for different competitions.
		$token1 = $this->repo->find_or_create( 1, 10 );
		$token2 = $this->repo->find_or_create( 2, 20 );

		$this->assertIsObject( $token1 );
		$this->assertIsObject( $token2 );

		// Get tracking for competition 10.
		$tracking = $this->repo->get_tracking_by_competition( 10 );

		// Should only have member 1.
		$this->assertCount( 1, $tracking );
		$this->assertArrayHasKey( 1, $tracking );
		$this->assertArrayNotHasKey( 2, $tracking );
	}

	// ---------------------------------------------------------------
	// delete_by_competition()
	// ---------------------------------------------------------------

	public function test_delete_by_competition_removes_tokens(): void {
		$this->repo->find_or_create( 1, 10 );
		$this->repo->find_or_create( 2, 10 );
		$this->repo->find_or_create( 3, 20 );

		$result = $this->repo->delete_by_competition( 10 );
		$this->assertTrue( $result );

		// Tokens for competition 10 should be gone, competition 20 intact.
		$tracking_10 = $this->repo->get_tracking_by_competition( 10 );
		$tracking_20 = $this->repo->get_tracking_by_competition( 20 );

		$this->assertCount( 0, $tracking_10 );
		$this->assertCount( 1, $tracking_20 );
	}

	public function test_delete_by_competition_returns_false_for_invalid_id(): void {
		$this->assertFalse( $this->repo->delete_by_competition( 0 ) );
	}
}
