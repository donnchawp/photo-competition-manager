<?php
/**
 * Competition workflow states for tests.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

use PhotoCompetitionManager\Service\Competition_Workflow;
use RuntimeException;

/**
 * Put competitions into a workflow state through Competition_Workflow, the
 * way an admin would, so tests don't write raw workflow state.
 */
class Workflow_Fixtures {

	/**
	 * Close a competition's uploads and publish its results.
	 *
	 * @param int $competition_id Competition ID.
	 */
	public static function publish_results( int $competition_id ): void {
		$workflow = new Competition_Workflow();

		self::check( $workflow->close_uploads( $competition_id ) );
		self::check( $workflow->publish_results( $competition_id ) );
	}

	/**
	 * Close a competition's uploads.
	 *
	 * @param int $competition_id Competition ID.
	 */
	public static function close_uploads( int $competition_id ): void {
		self::check( ( new Competition_Workflow() )->close_uploads( $competition_id ) );
	}

	/**
	 * Walk a category through competition night up to the given stage,
	 * closing uploads first if it has to open voting. Opening voting needs
	 * the category to have images, and no other category to be voting.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $category_slug  Category slug.
	 * @param string $stage          Stage to stop at.
	 */
	public static function set_stage( int $competition_id, string $category_slug, string $stage ): void {
		$workflow = new Competition_Workflow();
		$steps    = array(
			Competition_Workflow::STAGE_PREVIEWED       => fn() => $workflow->advance( $competition_id, $category_slug, Competition_Workflow::STAGE_PREVIEWED ),
			Competition_Workflow::STAGE_VOTING          => function () use ( $workflow, $competition_id, $category_slug ) {
				$workflow->close_uploads( $competition_id );
				return $workflow->open_voting( $competition_id, $category_slug );
			},
			Competition_Workflow::STAGE_SLIDESHOW_SHOWN => fn() => $workflow->advance( $competition_id, $category_slug, Competition_Workflow::STAGE_SLIDESHOW_SHOWN ),
			Competition_Workflow::STAGE_CRITIQUE        => fn() => $workflow->close_voting( $competition_id, $category_slug ),
			Competition_Workflow::STAGE_DONE            => fn() => $workflow->advance( $competition_id, $category_slug, Competition_Workflow::STAGE_DONE ),
		);

		foreach ( $steps as $step_stage => $step ) {
			self::check( $step() );

			if ( $step_stage === $stage ) {
				return;
			}
		}
	}

	/**
	 * Fail loudly when a fixture transition is refused.
	 *
	 * @param true|\WP_Error $result Transition result.
	 * @throws RuntimeException When the transition was refused.
	 */
	private static function check( $result ): void {
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( 'Workflow fixture refused: ' . $result->get_error_code() . ' ' . $result->get_error_message() );
		}
	}
}
