<?php
/**
 * Repository for votes table.
 *
 * @package PhotoCompetitionManager\Repository
 */

namespace PhotoCompetitionManager\Repository;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use WP_Error;

/**
 * Repository for votes.
 *
 * @package PhotoCompetitionManager\Repository
 */
class Votes_Repository extends Abstract_Repository {

	/**
	 * Table suffix.
	 *
	 * @return string
	 */
	protected function table_suffix(): string {
		return 'photocomp_votes';
	}

	/**
	 * Record an anonymous vote using token.
	 *
	 * @param int    $competition_id   Competition ID.
	 * @param string $category         Category slug.
	 * @param int    $voting_token_id  Voting token ID.
	 * @param int    $image_id         Image ID.
	 * @param float  $score            Score value.
	 * @return int|WP_Error Vote ID or error.
	 */
	public function create_anonymous( int $competition_id, string $category, int $voting_token_id, int $image_id, float $score ) {
		if ( ! $this->table_exists() ) {
			return new WP_Error( 'table_missing', __( 'Votes table does not exist.', 'photo-competition-manager' ) );
		}

		if ( $voting_token_id <= 0 ) {
			return new WP_Error( 'missing_token_id', __( 'Voting token ID is required.', 'photo-competition-manager' ) );
		}

		if ( $score < 0 ) {
			return new WP_Error( 'invalid_score', __( 'Score must be non-negative.', 'photo-competition-manager' ) );
		}

		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'competition_id'  => $competition_id,
				'category'        => $category,
				'voting_token_id' => $voting_token_id,
				'image_id'        => $image_id,
				'score'           => $score,
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%d', '%d', '%f', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'insert_failed', $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Record a vote with voter name (for password-based voting).
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @param string $voter_name     Voter name.
	 * @param int    $image_id       Image ID.
	 * @param float  $score          Score value.
	 * @return int|WP_Error Vote ID or error.
	 */
	public function create( int $competition_id, string $category, string $voter_name, int $image_id, float $score ) {
		if ( ! $this->table_exists() ) {
			return new WP_Error( 'table_missing', __( 'Votes table does not exist.', 'photo-competition-manager' ) );
		}

		if ( empty( $voter_name ) ) {
			return new WP_Error( 'missing_voter_name', __( 'Voter name is required.', 'photo-competition-manager' ) );
		}

		if ( $score < 0 ) {
			return new WP_Error( 'invalid_score', __( 'Score must be non-negative.', 'photo-competition-manager' ) );
		}

		$inserted = $this->wpdb->insert(
			$this->table(),
			array(
				'competition_id' => $competition_id,
				'category'       => $category,
				'voter_name'     => $voter_name,
				'image_id'       => $image_id,
				'score'          => $score,
				'created_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%d', '%f', '%s' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'insert_failed', $this->wpdb->last_error );
		}

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Get all votes for a competition.
	 *
	 * @param int         $competition_id Competition ID.
	 * @param string|null $category       Optional category filter.
	 * @return array<object>
	 */
	public function find_by_competition( int $competition_id, ?string $category = null ): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		$conditions = array( 'competition_id = %d' );
		$params     = array( $this->table(), $competition_id );

		if ( null !== $category ) {
			$conditions[] = 'category = %s';
			$params[]     = $category;
		}

		$where = implode( ' AND ', $conditions );
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM %i WHERE ' . $where . ' ORDER BY created_at DESC',
				...$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get all votes for a specific image.
	 *
	 * @param int $image_id Image ID.
	 * @return array<object>
	 */
	public function find_by_image( int $image_id ): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $this->wpdb->prepare(
			'SELECT * FROM %i WHERE image_id = %d ORDER BY created_at DESC',
			$this->table(),
			$image_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $sql );
	}

	/**
	 * Check if voting token has already been used to vote.
	 *
	 * @param int $voting_token_id Voting token ID.
	 * @return bool
	 */
	public function has_voted_with_token( int $voting_token_id ): bool {
		if ( ! $this->table_exists() ) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE voting_token_id = %d',
			$this->table(),
			$voting_token_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $this->wpdb->get_var( $sql );

		return $count > 0;
	}

	/**
	 * Remove existing anonymous votes recorded with a given token.
	 *
	 * @param int $voting_token_id Voting token ID.
	 * @return void
	 */
	public function delete_by_token( int $voting_token_id ): void {
		if ( ! $this->table_exists() ) {
			return;
		}

		$this->wpdb->delete(
			$this->table(),
			array( 'voting_token_id' => $voting_token_id ),
			array( '%d' )
		);
	}

	/**
	 * Delete existing votes for a named voter within a competition/category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @param string $voter_name     Voter name.
	 * @return void
	 */
	public function delete_by_voter( int $competition_id, string $category, string $voter_name ): void {
		if ( ! $this->table_exists() ) {
			return;
		}

		$this->wpdb->delete(
			$this->table(),
			array(
				'competition_id' => $competition_id,
				'category'       => $category,
				'voter_name'     => $voter_name,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Retrieve votes recorded with a specific token.
	 *
	 * @param int $voting_token_id Voting token ID.
	 * @return array<int, float> Map of image ID to score.
	 */
	public function get_votes_by_token( int $voting_token_id ): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $this->wpdb->prepare(
			'SELECT image_id, score FROM %i WHERE voting_token_id = %d',
			$this->table(),
			$voting_token_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $results ) ) {
			return array();
		}

		$votes = array();
		foreach ( $results as $row ) {
			$votes[ (int) $row['image_id'] ] = (float) $row['score'];
		}

		return $votes;
	}

	/**
	 * Retrieve votes for a named voter within a competition/category.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @param string $voter_name     Voter name.
	 * @return array<int, float> Map of image ID to score.
	 */
	public function get_votes_by_voter( int $competition_id, string $category, string $voter_name ): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $this->wpdb->prepare(
			'SELECT image_id, score FROM %i WHERE competition_id = %d AND category = %s AND voter_name = %s',
			$this->table(),
			$competition_id,
			$category,
			$voter_name
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $results ) ) {
			return array();
		}

		$votes = array();
		foreach ( $results as $row ) {
			$votes[ (int) $row['image_id'] ] = (float) $row['score'];
		}

		return $votes;
	}

	/**
	 * Check if voter has already voted in a category (for password-based voting).
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category       Category slug.
	 * @param string $voter_name     Voter name.
	 * @return bool
	 */
	public function has_voted( int $competition_id, string $category, string $voter_name ): bool {
		if ( ! $this->table_exists() ) {
			return false;
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE competition_id = %d AND category = %s AND voter_name = %s',
			$this->table(),
			$competition_id,
			$category,
			$voter_name
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $this->wpdb->get_var( $sql );

		return $count > 0;
	}

	/**
	 * Calculate average scores for images in a competition.
	 *
	 * @param int         $competition_id Competition ID.
	 * @param string|null $category       Optional category filter.
	 * @return array<int, array{image_id: int, average_score: float, vote_count: int}>
	 */
	public function calculate_averages( int $competition_id, ?string $category = null ): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		$conditions = array( 'competition_id = %d' );
		$params     = array( $this->table(), $competition_id );

		if ( null !== $category ) {
			$conditions[] = 'category = %s';
			$params[]     = $category;
		}

		$where = implode( ' AND ', $conditions );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT image_id, AVG(score) as average_score, COUNT(*) as vote_count FROM %i WHERE ' . $where . ' GROUP BY image_id ORDER BY average_score DESC',
				...$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $results ) ) {
			return array();
		}

		$processed = array();
		foreach ( $results as $row ) {
			$processed[ (int) $row['image_id'] ] = array(
				'image_id'      => (int) $row['image_id'],
				'average_score' => (float) $row['average_score'],
				'vote_count'    => (int) $row['vote_count'],
			);
		}

		return $processed;
	}

	/**
	 * Delete all votes for a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @return bool
	 */
	public function delete_by_competition( int $competition_id ): bool {
		if ( ! $this->table_exists() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $this->wpdb->delete(
			$this->table(),
			array( 'competition_id' => $competition_id ),
			array( '%d' )
		);

		return false !== $deleted;
	}

	/**
	 * Get unique voter names for a competition.
	 *
	 * @param int $competition_id Competition ID.
	 * @return array<string>
	 */
	public function get_voters( int $competition_id ): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $this->wpdb->prepare(
			'SELECT DISTINCT voter_name FROM %i WHERE competition_id = %d ORDER BY voter_name',
			$this->table(),
			$competition_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_col( $sql );

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Get all votes for a competition, including voter name.
	 *
	 * @param int $competition_id Competition ID.
	 * @return array<object>
	 */
	public function get_votes_by_competition( int $competition_id ): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL
		$sql = $this->wpdb->prepare(
			'SELECT * FROM %i WHERE competition_id = %d ORDER BY voter_name, created_at',
			$this->table(),
			$competition_id
		);
		// phpcs:enable WordPress.DB.PreparedSQL

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $sql );
	}

	/**
	 * Delete all votes for a specific image.
	 *
	 * @param int $image_id Image ID.
	 * @return bool
	 */
	public function delete_by_image( int $image_id ): bool {
		if ( ! $this->table_exists() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $this->wpdb->delete(
			$this->table(),
			array( 'image_id' => $image_id ),
			array( '%d' )
		);

		return false !== $deleted;
	}
}
