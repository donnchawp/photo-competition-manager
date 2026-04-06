<?php
/**
 * Tests for Voting_Token_Repository.
 *
 * @package PhotoCompetitionManager\Tests\Repository
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use WP_UnitTestCase;

class Voting_Token_Repository_Test extends WP_UnitTestCase {

	/**
	 * Repository instance.
	 *
	 * @var Voting_Token_Repository
	 */
	private $repository;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->repository = new Voting_Token_Repository();

		// Ensure table exists for testing.
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$wpdb->prefix}photocomp_voting_tokens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			competition_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(100) NOT NULL,
			token_hash VARCHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME NULL,
			first_accessed_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY token_hash (token_hash),
			KEY member_competition_category (member_id, competition_id, category),
			KEY expires_at (expires_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Test creating a token.
	 *
	 * @return void
	 */
	public function test_create_token(): void {
		$token_hash = hash( 'sha256', 'test-token-123' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repository->create( 1, 2, 'colour', $token_hash, $expires_at );

		$this->assertIsInt( $token_id );
		$this->assertGreaterThan( 0, $token_id );
	}

	/**
	 * Test creating token with invalid member ID fails.
	 *
	 * @return void
	 */
	public function test_create_with_invalid_member_id_fails(): void {
		$token_hash = hash( 'sha256', 'test-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$result = $this->repository->create( 0, 2, 'colour', $token_hash, $expires_at );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Test creating token with invalid competition ID fails.
	 *
	 * @return void
	 */
	public function test_create_with_invalid_competition_id_fails(): void {
		$token_hash = hash( 'sha256', 'test-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$result = $this->repository->create( 1, 0, 'colour', $token_hash, $expires_at );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Test creating token with empty category fails.
	 *
	 * @return void
	 */
	public function test_create_with_empty_category_fails(): void {
		$token_hash = hash( 'sha256', 'test-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$result = $this->repository->create( 1, 2, '', $token_hash, $expires_at );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Test finding valid token.
	 *
	 * @return void
	 */
	public function test_find_valid_token(): void {
		$token_hash = hash( 'sha256', 'test-token-valid' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repository->create( 1, 2, 'black-white', $token_hash, $expires_at );
		$this->assertIsInt( $token_id );

		$token = $this->repository->find_valid_token( $token_hash );

		$this->assertNotNull( $token );
		$this->assertEquals( $token_id, $token->id );
		$this->assertEquals( 1, $token->member_id );
		$this->assertEquals( 2, $token->competition_id );
		$this->assertEquals( 'black-white', $token->category );
		$this->assertEquals( $token_hash, $token->token_hash );
		$this->assertNull( $token->used_at );
	}

	/**
	 * Test finding expired token returns null.
	 *
	 * @return void
	 */
	public function test_find_expired_token_returns_null(): void {
		$token_hash = hash( 'sha256', 'expired-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ); // Already expired

		$token_id = $this->repository->create( 1, 2, 'colour', $token_hash, $expires_at );
		$this->assertIsInt( $token_id );

		$token = $this->repository->find_valid_token( $token_hash );

		$this->assertNull( $token );
	}

	/**
	 * Test finding nonexistent token returns null.
	 *
	 * @return void
	 */
	public function test_find_nonexistent_token_returns_null(): void {
		$token_hash = hash( 'sha256', 'nonexistent-token' );

		$token = $this->repository->find_valid_token( $token_hash );

		$this->assertNull( $token );
	}

	/**
	 * Test cleanup expired tokens.
	 *
	 * @return void
	 */
	public function test_cleanup_expired_tokens(): void {
		$now = time();

		// Create expired token.
		$expired_hash = hash( 'sha256', 'expired' );
		$expired_at   = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );
		$this->repository->create( 1, 2, 'colour', $expired_hash, $expired_at );

		// Create valid token.
		$valid_hash = hash( 'sha256', 'valid' );
		$valid_at   = gmdate( 'Y-m-d H:i:s', $now + HOUR_IN_SECONDS );
		$this->repository->create( 1, 2, 'black-white', $valid_hash, $valid_at );

		$deleted = $this->repository->cleanup_expired();

		$this->assertEquals( 1, $deleted );

		// Verify expired token is gone.
		$expired_token = $this->repository->find_valid_token( $expired_hash );
		$this->assertNull( $expired_token );

		// Verify valid token still exists.
		$valid_token = $this->repository->find_valid_token( $valid_hash );
		$this->assertNotNull( $valid_token );
	}

	/**
	 * Test has_recent_token for fresh token.
	 *
	 * @return void
	 */
	public function test_has_recent_token_for_fresh_token(): void {
		$token_hash = hash( 'sha256', 'recent-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$this->repository->create( 1, 2, 'colour', $token_hash, $expires_at );

		$has_recent = $this->repository->has_recent_token( 1, 2, 'colour' );

		$this->assertTrue( $has_recent );
	}

	/**
	 * Test has_recent_token for different category returns false.
	 *
	 * @return void
	 */
	public function test_has_recent_token_for_different_category(): void {
		$token_hash = hash( 'sha256', 'category-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$this->repository->create( 1, 2, 'colour', $token_hash, $expires_at );

		$has_recent = $this->repository->has_recent_token( 1, 2, 'black-white' );

		$this->assertFalse( $has_recent );
	}

	/**
	 * Test has_recent_token for old token returns false.
	 *
	 * @return void
	 */
	public function test_has_recent_token_for_old_token(): void {
		global $wpdb;

		$token_hash = hash( 'sha256', 'old-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repository->create( 1, 2, 'colour', $token_hash, $expires_at );
		$this->assertIsInt( $token_id );

		// Manually backdate the created_at timestamp to 10 minutes ago.
		$old_time = gmdate( 'Y-m-d H:i:s', time() - ( 10 * MINUTE_IN_SECONDS ) );
		$wpdb->update(
			$wpdb->prefix . 'photocomp_voting_tokens',
			array( 'created_at' => $old_time ),
			array( 'id' => $token_id ),
			array( '%s' ),
			array( '%d' )
		);

		$has_recent = $this->repository->has_recent_token( 1, 2, 'colour' );

		$this->assertFalse( $has_recent );
	}

	/**
	 * Test has_recent_token for nonexistent member.
	 *
	 * @return void
	 */
	public function test_has_recent_token_for_nonexistent_member(): void {
		$has_recent = $this->repository->has_recent_token( 999, 2, 'colour' );

		$this->assertFalse( $has_recent );
	}

	/**
	 * Test first_accessed_at is set on first find_valid_token call.
	 *
	 * @return void
	 */
	public function test_first_accessed_at_set_on_first_access(): void {
		$token_hash = hash( 'sha256', 'tracking_test_token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repository->create( 1, 2, 'colour', $token_hash, $expires_at );
		$this->assertIsInt( $token_id );

		// First access should set first_accessed_at.
		$found = $this->repository->find_valid_token( $token_hash );
		$this->assertNotNull( $found );
		$this->assertNotNull( $found->first_accessed_at );

		// Store the timestamp.
		$first_timestamp = $found->first_accessed_at;

		// Wait a moment to ensure time difference.
		sleep( 1 );

		// Second access should NOT update first_accessed_at.
		$found_again = $this->repository->find_valid_token( $token_hash );
		$this->assertNotNull( $found_again );
		$this->assertEquals( $first_timestamp, $found_again->first_accessed_at );
	}

	/**
	 * Test first_accessed_at is NOT updated on subsequent accesses.
	 *
	 * @return void
	 */
	public function test_first_accessed_at_not_updated_on_subsequent_access(): void {
		$token_hash = hash( 'sha256', 'tracking_update_test' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repository->create( 1, 2, 'colour', $token_hash, $expires_at );

		// Access token multiple times.
		$first_find  = $this->repository->find_valid_token( $token_hash );
		$first_time  = $first_find->first_accessed_at;

		sleep( 1 );

		$second_find = $this->repository->find_valid_token( $token_hash );
		$third_find  = $this->repository->find_valid_token( $token_hash );

		// All should have the same first_accessed_at.
		$this->assertEquals( $first_time, $second_find->first_accessed_at );
		$this->assertEquals( $first_time, $third_find->first_accessed_at );
	}

	/**
	 * Test get_tracking_by_competition returns correct data.
	 *
	 * @return void
	 */
	public function test_get_tracking_by_competition(): void {
		$competition_id = 5;

		// Create tokens for different members.
		$token1 = hash( 'sha256', 'tracking_member_1' );
		$token2 = hash( 'sha256', 'tracking_member_2' );
		$token3 = hash( 'sha256', 'tracking_member_3' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$this->repository->create( 1, $competition_id, 'colour', $token1, $expires_at );
		$this->repository->create( 2, $competition_id, 'colour', $token2, $expires_at );
		$this->repository->create( 3, $competition_id, 'colour', $token3, $expires_at );

		// Access only some tokens.
		$this->repository->find_valid_token( $token1 );
		$this->repository->find_valid_token( $token3 );

		// Get tracking data.
		$tracking = $this->repository->get_tracking_by_competition( $competition_id );

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
	 *
	 * @return void
	 */
	public function test_get_tracking_by_competition_empty(): void {
		$tracking = $this->repository->get_tracking_by_competition( 999 );
		$this->assertIsArray( $tracking );
		$this->assertEmpty( $tracking );
	}

	/**
	 * Test get_tracking_by_competition only returns data for specified competition.
	 *
	 * @return void
	 */
	public function test_get_tracking_by_competition_filters_correctly(): void {
		$token1 = hash( 'sha256', 'filter_comp_1' );
		$token2 = hash( 'sha256', 'filter_comp_2' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		// Create tokens for different competitions.
		$this->repository->create( 1, 10, 'colour', $token1, $expires_at );
		$this->repository->create( 2, 20, 'colour', $token2, $expires_at );

		// Get tracking for competition 10.
		$tracking = $this->repository->get_tracking_by_competition( 10 );

		// Should only have member 1.
		$this->assertCount( 1, $tracking );
		$this->assertArrayHasKey( 1, $tracking );
		$this->assertArrayNotHasKey( 2, $tracking );
	}

	// ---------------------------------------------------------------
	// delete_by_competition()
	// ---------------------------------------------------------------

	public function test_delete_by_competition_removes_tokens(): void {
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$this->repository->create( 1, 10, 'colour', hash( 'sha256', 'tok1' ), $expires_at );
		$this->repository->create( 2, 10, 'colour', hash( 'sha256', 'tok2' ), $expires_at );
		$this->repository->create( 3, 20, 'colour', hash( 'sha256', 'tok3' ), $expires_at );

		$result = $this->repository->delete_by_competition( 10 );
		$this->assertTrue( $result );

		$tracking_10 = $this->repository->get_tracking_by_competition( 10 );
		$tracking_20 = $this->repository->get_tracking_by_competition( 20 );

		$this->assertCount( 0, $tracking_10 );
		$this->assertCount( 1, $tracking_20 );
	}

	public function test_delete_by_competition_returns_false_for_invalid_id(): void {
		$this->assertFalse( $this->repository->delete_by_competition( 0 ) );
	}

	// ---------------------------------------------------------------
	// delete_by_competition_and_category()
	// ---------------------------------------------------------------

	public function test_delete_by_competition_and_category_removes_matching(): void {
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$this->repository->create( 1, 10, 'colour', hash( 'sha256', 'c1' ), $expires_at );
		$this->repository->create( 2, 10, 'black-white', hash( 'sha256', 'bw1' ), $expires_at );

		$result = $this->repository->delete_by_competition_and_category( 10, 'colour' );
		$this->assertTrue( $result );

		// colour token gone, black-white still there.
		$token_colour = $this->repository->find_valid_token( hash( 'sha256', 'c1' ) );
		$token_bw     = $this->repository->find_valid_token( hash( 'sha256', 'bw1' ) );

		$this->assertNull( $token_colour );
		$this->assertNotNull( $token_bw );
	}

	public function test_delete_by_competition_and_category_returns_false_for_invalid(): void {
		$this->assertFalse( $this->repository->delete_by_competition_and_category( 0, 'colour' ) );
		$this->assertFalse( $this->repository->delete_by_competition_and_category( 1, '' ) );
	}
}
