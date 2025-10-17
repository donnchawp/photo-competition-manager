<?php
/**
 * Repository for votes table.
 *
 * @package ClubCompetitions\Repository
 */

namespace ClubCompetitions\Repository;

use WP_Error;

class VotesRepository extends AbstractRepository {

	/**
	 * Table suffix.
	 *
	 * @return string
	 */
	protected function table_suffix(): string {
		return 'clubcompete_votes';
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
			return new WP_Error( 'table_missing', __( 'Votes table does not exist.', 'club-competitions' ) );
		}

		if ( $voting_token_id <= 0 ) {
			return new WP_Error( 'missing_token_id', __( 'Voting token ID is required.', 'club-competitions' ) );
		}

		if ( $score < 0 ) {
			return new WP_Error( 'invalid_score', __( 'Score must be non-negative.', 'club-competitions' ) );
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
			return new WP_Error( 'table_missing', __( 'Votes table does not exist.', 'club-competitions' ) );
		}

		if ( empty( $voter_name ) ) {
			return new WP_Error( 'missing_voter_name', __( 'Voter name is required.', 'club-competitions' ) );
		}

		if ( $score < 0 ) {
			return new WP_Error( 'invalid_score', __( 'Score must be non-negative.', 'club-competitions' ) );
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

		$where = $this->wpdb->prepare( 'competition_id = %d', $competition_id );

		if ( null !== $category ) {
			$where .= $this->wpdb->prepare( ' AND category = %s', $category );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = sprintf(
			'SELECT * FROM %s WHERE %s ORDER BY created_at DESC',
			$this->table(),
			$where
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $this->wpdb->get_results( $sql );
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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $this->wpdb->prepare(
			sprintf( 'SELECT * FROM %s WHERE image_id = %%d ORDER BY created_at DESC', $this->table() ),
			$image_id
		);

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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $this->wpdb->prepare(
			sprintf(
				'SELECT COUNT(*) FROM %s WHERE voting_token_id = %%d',
				$this->table()
			),
			$voting_token_id
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $this->wpdb->get_var( $sql );

		return $count > 0;
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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $this->wpdb->prepare(
			sprintf(
				'SELECT COUNT(*) FROM %s WHERE competition_id = %%d AND category = %%s AND voter_name = %%s',
				$this->table()
			),
			$competition_id,
			$category,
			$voter_name
		);

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

		$where = $this->wpdb->prepare( 'competition_id = %d', $competition_id );

		if ( null !== $category ) {
			$where .= $this->wpdb->prepare( ' AND category = %s', $category );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = sprintf(
			'SELECT image_id, AVG(score) as average_score, COUNT(*) as vote_count FROM %s WHERE %s GROUP BY image_id ORDER BY average_score DESC',
			$this->table(),
			$where
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results( $sql, ARRAY_A );

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

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = $this->wpdb->prepare(
			sprintf(
				'SELECT DISTINCT voter_name FROM %s WHERE competition_id = %%d ORDER BY voter_name',
				$this->table()
			),
			$competition_id
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_col( $sql );

		return is_array( $results ) ? $results : array();
	}
}
