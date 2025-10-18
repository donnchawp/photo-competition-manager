<?php
/**
 * Tests for Voting_Token_Repository.
 *
 * @package ClubCompetitions\Tests\Repository
 */

namespace ClubCompetitions\Tests\Repository;

use ClubCompetitions\Repository\Voting_Token_Repository;
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

		$sql = "CREATE TABLE {$wpdb->prefix}clubcompete_voting_tokens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			member_id BIGINT UNSIGNED NOT NULL,
			competition_id BIGINT UNSIGNED NOT NULL,
			category VARCHAR(100) NOT NULL,
			token_hash VARCHAR(64) NOT NULL,
			expires_at DATETIME NOT NULL,
			used_at DATETIME NULL,
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
	 * Test finding used token returns null.
	 *
	 * @return void
	 */
	public function test_find_used_token_returns_null(): void {
		$token_hash = hash( 'sha256', 'used-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repository->create( 1, 2, 'colour', $token_hash, $expires_at );
		$this->assertIsInt( $token_id );

		// Mark as used
		$result = $this->repository->mark_as_used( $token_id );
		$this->assertTrue( $result );

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
	 * Test marking token as used.
	 *
	 * @return void
	 */
	public function test_mark_token_as_used(): void {
		$token_hash = hash( 'sha256', 'mark-used-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repository->create( 1, 2, 'colour', $token_hash, $expires_at );
		$this->assertIsInt( $token_id );

		$result = $this->repository->mark_as_used( $token_id );

		$this->assertTrue( $result );

		// Verify token is now marked as used
		global $wpdb;
		$used_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT used_at FROM {$wpdb->prefix}clubcompete_voting_tokens WHERE id = %d",
				$token_id
			)
		);

		$this->assertNotNull( $used_at );
	}

	/**
	 * Test marking invalid token as used fails.
	 *
	 * @return void
	 */
	public function test_mark_invalid_token_as_used_fails(): void {
		$result = $this->repository->mark_as_used( 999999 );

		$this->assertWPError( $result );
	}

	/**
	 * Test cleanup expired tokens.
	 *
	 * @return void
	 */
	public function test_cleanup_expired_tokens(): void {
		$now = time();

		// Create expired token
		$expired_hash = hash( 'sha256', 'expired' );
		$expired_at   = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );
		$this->repository->create( 1, 2, 'colour', $expired_hash, $expired_at );

		// Create valid token
		$valid_hash = hash( 'sha256', 'valid' );
		$valid_at   = gmdate( 'Y-m-d H:i:s', $now + HOUR_IN_SECONDS );
		$this->repository->create( 1, 2, 'black-white', $valid_hash, $valid_at );

		$deleted = $this->repository->cleanup_expired();

		$this->assertEquals( 1, $deleted );

		// Verify expired token is gone
		$expired_token = $this->repository->find_valid_token( $expired_hash );
		$this->assertNull( $expired_token );

		// Verify valid token still exists
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

		// Manually backdate the created_at timestamp to 10 minutes ago
		$old_time = gmdate( 'Y-m-d H:i:s', time() - ( 10 * MINUTE_IN_SECONDS ) );
		$wpdb->update(
			"{$wpdb->prefix}clubcompete_voting_tokens",
			array( 'created_at' => $old_time ),
			array( 'id' => $token_id ),
			array( '%s' ),
			array( '%d' )
		);

		$has_recent = $this->repository->has_recent_token( 1, 2, 'colour' );

		$this->assertFalse( $has_recent );
	}

	/**
	 * Test has_recent_token for used token returns false.
	 *
	 * @return void
	 */
	public function test_has_recent_token_for_used_token(): void {
		$token_hash = hash( 'sha256', 'used-recent-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repository->create( 1, 2, 'colour', $token_hash, $expires_at );
		$this->assertIsInt( $token_id );

		$this->repository->mark_as_used( $token_id );

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
}
