<?php
/**
 * Deleting a judge.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Judges_Repository;
use WP_Error;

/**
 * Removes a judge and their competition assignments, for the Judges screen's
 * Delete and for WordPress's personal data eraser. The scores they gave stay,
 * so no competition's totals change.
 *
 * @since 0.4.0
 */
class Judge_Deletion {

	/**
	 * Judges repository.
	 *
	 * @var Judges_Repository
	 */
	private $judges;

	/**
	 * Constructor.
	 *
	 * @param Judges_Repository|null $judges Judges repository.
	 */
	public function __construct( ?Judges_Repository $judges = null ) {
		$this->judges = $judges ?? new Judges_Repository();
	}

	/**
	 * Delete a judge and their assignments. Their votes are kept.
	 *
	 * @param int $judge_id Judge ID.
	 * @return true|WP_Error
	 */
	public function delete( int $judge_id ) {
		if ( ! $this->judges->find( $judge_id ) ) {
			return new WP_Error( 'missing_judge', __( 'Judge not found.', 'photo-competition-manager' ) );
		}

		if ( ! $this->judges->delete( $judge_id ) ) {
			return new WP_Error( 'db_delete_failed', __( 'Could not delete the judge. Try again.', 'photo-competition-manager' ) );
		}

		return true;
	}

	/**
	 * Add the judges eraser to WordPress's personal data erasers.
	 *
	 * @param array<string, array> $erasers Registered erasers.
	 * @return array<string, array>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['photo-competition-manager-judges'] = array(
			'eraser_friendly_name' => __( 'Photo Competition Manager judges', 'photo-competition-manager' ),
			'callback'             => array( new self(), 'erase' ),
		);

		return $erasers;
	}

	/**
	 * WordPress's personal data eraser: delete the judges holding an email address.
	 *
	 * Each page deletes one judge record; the records left say where to carry on.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}|WP_Error
	 */
	public function erase( string $email, int $page = 1 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress passes the page; the records left say where to carry on.
		$response = array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);

		$judges = $this->judges->find_by_email( $email );
		if ( ! $judges ) {
			return $response;
		}

		$deleted = $this->delete( (int) $judges[0]->id );
		if ( is_wp_error( $deleted ) ) {
			// An array would mark the request completed and tell the person their data was erased.
			return $deleted;
		}

		$response['items_removed'] = true;
		$response['done']          = count( $judges ) <= 1;

		return $response;
	}
}
