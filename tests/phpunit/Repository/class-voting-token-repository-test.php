<?php
/**
 * Tests for Voting_Token_Repository.
 *
 * @package PhotoCompetitionManager\Tests\Repository
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Repository\Votes_Repository;
use PhotoCompetitionManager\Repository\Voting_Token_Repository;
use PhotoCompetitionManager\Tests\Legacy_Tables;
use WP_UnitTestCase;

class Voting_Token_Repository_Test extends WP_UnitTestCase {

	/**
	 * Repository instance.
	 *
	 * @var Voting_Token_Repository
	 */
	private $repository;

	/**
	 * Temporary table hiding the real one, if a test made it.
	 *
	 * @var string
	 */
	private $shadowed = '';

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		$this->repository = new Voting_Token_Repository();

		global $wpdb;

		// find_valid_token() only returns tokens belonging to active members.
		foreach ( array( 1, 2, 3 ) as $member_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'photocomp_members',
				array(
					'id'    => $member_id,
					'name'  => "Member {$member_id}",
					'email' => "member{$member_id}@example.com",
					'grade' => 'beginner',
				)
			);
		}
	}

	/**
	 * Drop the table shadowing the real one, if a test made it.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		global $wpdb;

		if ( '' !== $this->shadowed ) {
			$wpdb->query( "DROP TEMPORARY TABLE {$this->shadowed}" );
		}

		parent::tearDown();
	}

	/**
	 * Renewing gives the member's one token a new link, and keeps its row.
	 *
	 * @return void
	 */
	public function test_renew_replaces_the_link_on_the_members_one_token(): void {
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$first      = $this->repository->renew( 1, 2, 'colour', hash( 'sha256', 'first' ), $expires_at );

		$renewed = $this->repository->renew( 1, 2, 'colour', hash( 'sha256', 'second' ), $expires_at );

		$this->assertSame( $first, $renewed );
		$this->assertNull( $this->repository->find_valid_token( hash( 'sha256', 'first' ) ) );
		$this->assertSame( (string) $first, $this->repository->find_valid_token( hash( 'sha256', 'second' ) )->id );
	}

	/**
	 * A table from before the unique key, or whose upgrade failed, still
	 * gets one token per member, competition and category.
	 *
	 * @return void
	 */
	public function test_renew_keeps_one_token_on_a_table_without_the_unique_key(): void {
		$this->shadowed = Legacy_Tables::shadow_v5_voting_tokens();
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$this->repository->renew( 1, 2, 'colour', hash( 'sha256', 'first' ), $expires_at );

		$this->repository->renew( 1, 2, 'colour', hash( 'sha256', 'second' ), $expires_at );

		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE member_id = 1', $this->shadowed ) ) );
	}

	/**
	 * Where a table without the unique key holds several tokens for a member,
	 * the renewed link goes to the one holding their ballot, not the first.
	 *
	 * @return void
	 */
	public function test_renew_gives_the_link_to_the_token_with_the_members_ballot(): void {
		global $wpdb;
		$this->shadowed = Legacy_Tables::shadow_v5_voting_tokens();
		$expires_at     = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		foreach ( array( 'unused', 'ballot' ) as $link ) {
			$wpdb->insert(
				$this->shadowed,
				array(
					'member_id'      => 1,
					'competition_id' => 2,
					'category'       => 'colour',
					'token_hash'     => hash( 'sha256', $link ),
					'expires_at'     => $expires_at,
				)
			);
		}
		$ballot = (int) $wpdb->insert_id;
		( new Votes_Repository() )->create_anonymous_ballot( 2, 'colour', $ballot, array( 42 => 9 ) );

		$renewed = $this->repository->renew( 1, 2, 'colour', hash( 'sha256', 'renewed' ), $expires_at );

		$this->assertSame( $ballot, $renewed );
	}

	/**
	 * Tokens belonging to deactivated members are not valid, and work again on reactivation.
	 *
	 * @return void
	 */
	public function test_find_valid_token_ignores_inactive_member(): void {
		global $wpdb;
		$members    = $wpdb->prefix . 'photocomp_members';
		$token_hash = hash( 'sha256', 'inactive-member-token' );
		$this->repository->renew( 1, 2, 'colour', $token_hash, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $members, array( 'active' => 0 ), array( 'id' => 1 ) );
		$this->assertNull( $this->repository->find_valid_token( $token_hash ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $members, array( 'active' => 1 ), array( 'id' => 1 ) );
		$this->assertNotNull( $this->repository->find_valid_token( $token_hash ) );
	}

	/**
	 * Test renewing creates a token when the member has none.
	 *
	 * @return void
	 */
	public function test_renew_creates_a_token_when_the_member_has_none(): void {
		$token_hash = hash( 'sha256', 'test-token-123' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$token_id = $this->repository->renew( 1, 2, 'colour', $token_hash, $expires_at );

		$this->assertIsInt( $token_id );
		$this->assertGreaterThan( 0, $token_id );
	}

	/**
	 * When the database refuses the renewal, the member gets an error, not
	 * a link that was never saved.
	 *
	 * @return void
	 */
	public function test_renew_fails_when_the_token_cannot_be_updated(): void {
		global $wpdb;
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$this->repository->renew( 1, 2, 'colour', hash( 'sha256', 'first' ), $expires_at );
		$break_update = function ( $query ) {
			return 0 === strpos( $query, 'UPDATE' ) && false !== strpos( $query, 'photocomp_voting_tokens' )
				? 'UPDATE photocomp_no_such_table SET id = 1'
				: $query;
		};
		add_filter( 'query', $break_update );
		$suppress = $wpdb->suppress_errors( true );

		$result = $this->repository->renew( 1, 2, 'colour', hash( 'sha256', 'second' ), $expires_at );

		$wpdb->suppress_errors( $suppress );
		remove_filter( 'query', $break_update );
		$this->assertWPError( $result );
		$this->assertSame( 'db_update_failed', $result->get_error_code() );
		$this->assertNotNull( $this->repository->find_valid_token( hash( 'sha256', 'first' ) ) );
	}

	/**
	 * Test renewing a token with invalid member ID fails.
	 *
	 * @return void
	 */
	public function test_renew_with_invalid_member_id_fails(): void {
		$token_hash = hash( 'sha256', 'test-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$result = $this->repository->renew( 0, 2, 'colour', $token_hash, $expires_at );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Test renewing a token with invalid competition ID fails.
	 *
	 * @return void
	 */
	public function test_renew_with_invalid_competition_id_fails(): void {
		$token_hash = hash( 'sha256', 'test-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$result = $this->repository->renew( 1, 0, 'colour', $token_hash, $expires_at );

		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Test renewing a token with empty category fails.
	 *
	 * @return void
	 */
	public function test_renew_with_empty_category_fails(): void {
		$token_hash = hash( 'sha256', 'test-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$result = $this->repository->renew( 1, 2, '', $token_hash, $expires_at );

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

		$token_id = $this->repository->renew( 1, 2, 'black-white', $token_hash, $expires_at );
		$this->assertIsInt( $token_id );

		$token = $this->repository->find_valid_token( $token_hash );

		$this->assertNotNull( $token );
		$this->assertEquals( $token_id, $token->id );
		$this->assertEquals( 1, $token->member_id );
		$this->assertEquals( 2, $token->competition_id );
		$this->assertEquals( 'black-white', $token->category );
		$this->assertEquals( $token_hash, $token->token_hash );
	}

	/**
	 * Test finding expired token returns null.
	 *
	 * @return void
	 */
	public function test_find_expired_token_returns_null(): void {
		$token_hash = hash( 'sha256', 'expired-token' );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ); // Already expired

		$token_id = $this->repository->renew( 1, 2, 'colour', $token_hash, $expires_at );
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
		$this->repository->renew( 1, 2, 'colour', $expired_hash, $expired_at );

		// Create valid token.
		$valid_hash = hash( 'sha256', 'valid' );
		$valid_at   = gmdate( 'Y-m-d H:i:s', $now + HOUR_IN_SECONDS );
		$this->repository->renew( 1, 2, 'black-white', $valid_hash, $valid_at );

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

		$this->repository->renew( 1, 2, 'colour', $token_hash, $expires_at );

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

		$this->repository->renew( 1, 2, 'colour', $token_hash, $expires_at );

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

		$token_id = $this->repository->renew( 1, 2, 'colour', $token_hash, $expires_at );
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

		$token_id = $this->repository->renew( 1, 2, 'colour', $token_hash, $expires_at );
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

		$token_id = $this->repository->renew( 1, 2, 'colour', $token_hash, $expires_at );

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

		$this->repository->renew( 1, $competition_id, 'colour', $token1, $expires_at );
		$this->repository->renew( 2, $competition_id, 'colour', $token2, $expires_at );
		$this->repository->renew( 3, $competition_id, 'colour', $token3, $expires_at );

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

		// Renewing resets created_at and keeps one token, so nothing else
		// it could report would mean what it says.
		$this->assertSame( array( 'member_id', 'first_opened_at' ), array_keys( get_object_vars( $tracking[1] ) ) );
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
		$this->repository->renew( 1, 10, 'colour', $token1, $expires_at );
		$this->repository->renew( 2, 20, 'colour', $token2, $expires_at );

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
		$this->repository->renew( 1, 10, 'colour', hash( 'sha256', 'tok1' ), $expires_at );
		$this->repository->renew( 2, 10, 'colour', hash( 'sha256', 'tok2' ), $expires_at );
		$this->repository->renew( 3, 20, 'colour', hash( 'sha256', 'tok3' ), $expires_at );

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
		$this->repository->renew( 1, 10, 'colour', hash( 'sha256', 'c1' ), $expires_at );
		$this->repository->renew( 2, 10, 'black-white', hash( 'sha256', 'bw1' ), $expires_at );

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
