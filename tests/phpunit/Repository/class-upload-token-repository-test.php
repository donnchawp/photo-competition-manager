<?php
/**
 * Tests for Upload_Token_Repository.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use WP_UnitTestCase;

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
	 * Test creating a token.
	 */
	public function test_create_token() {
		$member_id      = 1;
		$competition_id = 2;
		$token_hash     = hash( 'sha256', 'test_token' );
		$expires_at     = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repo->create( $member_id, $competition_id, $token_hash, $expires_at );

		$this->assertIsInt( $token_id );
		$this->assertGreaterThan( 0, $token_id );

		// Verify in database.
		global $wpdb;
		$token = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->repo->table(),
				$token_id
			)
		);

		$this->assertNotNull( $token );
		$this->assertEquals( $member_id, $token->member_id );
		$this->assertEquals( $competition_id, $token->competition_id );
		$this->assertEquals( $token_hash, $token->token_hash );
		$this->assertNull( $token->used_at );
	}

	/**
	 * Test create with invalid data returns error.
	 */
	public function test_create_with_invalid_data() {
		$result = $this->repo->create( 0, 1, 'hash', gmdate( 'Y-m-d H:i:s' ) );
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Test finding a valid token.
	 */
	public function test_find_valid_token() {
		$token_hash = hash( 'sha256', 'find_test_token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repo->create( 1, 2, $token_hash, $expires_at );
		$this->assertIsInt( $token_id );

		$found = $this->repo->find_valid_token( $token_hash );

		$this->assertNotNull( $found );
		$this->assertEquals( $token_id, $found->id );
		$this->assertEquals( 1, $found->member_id );
		$this->assertEquals( 2, $found->competition_id );
		$this->assertNull( $found->used_at );
	}

	/**
	 * Test finding expired token returns null.
	 */
	public function test_find_expired_token_returns_null() {
		$token_hash = hash( 'sha256', 'expired_token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ); // Already expired.

		$this->repo->create( 1, 2, $token_hash, $expires_at );

		$found = $this->repo->find_valid_token( $token_hash );
		$this->assertNull( $found );
	}

	/**
	 * Test finding used token returns null.
	 */
	public function test_find_used_token_returns_null() {
		$token_hash = hash( 'sha256', 'used_token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repo->create( 1, 2, $token_hash, $expires_at );
		$this->assertIsInt( $token_id );

		// Mark as used.
		$this->repo->mark_as_used( $token_id );

		// Try to find it again.
		$found = $this->repo->find_valid_token( $token_hash );
		$this->assertNull( $found );
	}

	/**
	 * Test marking token as used.
	 */
	public function test_mark_as_used() {
		$token_hash = hash( 'sha256', 'mark_used_token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repo->create( 1, 2, $token_hash, $expires_at );
		$this->assertIsInt( $token_id );

		$result = $this->repo->mark_as_used( $token_id );
		$this->assertTrue( $result );

		// Verify in database.
		global $wpdb;
		$token = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				$this->repo->table(),
				$token_id
			)
		);

		$this->assertNotNull( $token->used_at );
	}

	/**
	 * Test mark as used with invalid ID returns error.
	 */
	public function test_mark_as_used_invalid_id() {
		$result = $this->repo->mark_as_used( 0 );
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_token', $result->get_error_code() );
	}

	/**
	 * Test cleanup expired tokens.
	 */
	public function test_cleanup_expired() {
		// Create expired token.
		$expired_token = hash( 'sha256', 'cleanup_expired' );
		$this->repo->create( 1, 2, $expired_token, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		// Create valid token.
		$valid_token = hash( 'sha256', 'cleanup_valid' );
		$this->repo->create( 1, 2, $valid_token, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

		$deleted = $this->repo->cleanup_expired();
		$this->assertGreaterThan( 0, $deleted );

		// Verify expired token is gone.
		$found_expired = $this->repo->find_valid_token( $expired_token );
		$this->assertNull( $found_expired );

		// Verify valid token still exists.
		$found_valid = $this->repo->find_valid_token( $valid_token );
		$this->assertNotNull( $found_valid );
	}

	/**
	 * Test has_recent_token for rate limiting.
	 */
	public function test_has_recent_token() {
		$member_id      = 1;
		$competition_id = 2;

		// Initially should be false.
		$this->assertFalse( $this->repo->has_recent_token( $member_id, $competition_id ) );

		// Create a token.
		$token_hash = hash( 'sha256', 'rate_limit_test' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$this->repo->create( $member_id, $competition_id, $token_hash, $expires_at );

		// Should now be true.
		$this->assertTrue( $this->repo->has_recent_token( $member_id, $competition_id ) );
	}

	/**
	 * Test has_recent_token returns false for old tokens.
	 */
	public function test_has_recent_token_old_token() {
		$member_id      = 1;
		$competition_id = 2;

		// Create an old token (older than 5 minutes).
		global $wpdb;
		$old_time   = gmdate( 'Y-m-d H:i:s', time() - ( 10 * MINUTE_IN_SECONDS ) );
		$token_hash = hash( 'sha256', 'old_rate_limit_test' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$wpdb->insert(
			$this->repo->table(),
			array(
				'member_id'      => $member_id,
				'competition_id' => $competition_id,
				'token_hash'     => $token_hash,
				'expires_at'     => $expires_at,
				'created_at'     => $old_time,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		// Should be false since token is old.
		$this->assertFalse( $this->repo->has_recent_token( $member_id, $competition_id ) );
	}

	/**
	 * Test has_recent_token returns false for different competition.
	 */
	public function test_has_recent_token_different_competition() {
		$member_id = 1;

		// Create token for competition 2.
		$token_hash = hash( 'sha256', 'different_comp_test' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$this->repo->create( $member_id, 2, $token_hash, $expires_at );

		// Check for competition 3.
		$this->assertFalse( $this->repo->has_recent_token( $member_id, 3 ) );
	}

	/**
	 * Test first_accessed_at is set on first find_valid_token call.
	 */
	public function test_first_accessed_at_set_on_first_access() {
		$token_hash = hash( 'sha256', 'tracking_test_token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repo->create( 1, 2, $token_hash, $expires_at );
		$this->assertIsInt( $token_id );

		// First access should set first_accessed_at.
		$found = $this->repo->find_valid_token( $token_hash );
		$this->assertNotNull( $found );
		$this->assertNotNull( $found->first_accessed_at );

		// Store the timestamp.
		$first_timestamp = $found->first_accessed_at;

		// Wait a moment to ensure time difference.
		sleep( 1 );

		// Second access should NOT update first_accessed_at.
		$found_again = $this->repo->find_valid_token( $token_hash );
		$this->assertNotNull( $found_again );
		$this->assertEquals( $first_timestamp, $found_again->first_accessed_at );
	}

	/**
	 * Test first_accessed_at is NOT updated on subsequent accesses.
	 */
	public function test_first_accessed_at_not_updated_on_subsequent_access() {
		$token_hash = hash( 'sha256', 'tracking_update_test' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repo->create( 1, 2, $token_hash, $expires_at );

		// Access token multiple times.
		$first_find  = $this->repo->find_valid_token( $token_hash );
		$first_time  = $first_find->first_accessed_at;

		sleep( 1 );

		$second_find = $this->repo->find_valid_token( $token_hash );
		$third_find  = $this->repo->find_valid_token( $token_hash );

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
		$token1 = hash( 'sha256', 'tracking_member_1' );
		$token2 = hash( 'sha256', 'tracking_member_2' );
		$token3 = hash( 'sha256', 'tracking_member_3' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$this->repo->create( 1, $competition_id, $token1, $expires_at );
		$this->repo->create( 2, $competition_id, $token2, $expires_at );
		$this->repo->create( 3, $competition_id, $token3, $expires_at );

		// Access only some tokens.
		$this->repo->find_valid_token( $token1 );
		$this->repo->find_valid_token( $token3 );

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
		$token1 = hash( 'sha256', 'filter_comp_1' );
		$token2 = hash( 'sha256', 'filter_comp_2' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		// Create tokens for different competitions.
		$this->repo->create( 1, 10, $token1, $expires_at );
		$this->repo->create( 2, 20, $token2, $expires_at );

		// Get tracking for competition 10.
		$tracking = $this->repo->get_tracking_by_competition( 10 );

		// Should only have member 1.
		$this->assertCount( 1, $tracking );
		$this->assertArrayHasKey( 1, $tracking );
		$this->assertArrayNotHasKey( 2, $tracking );
	}
}
