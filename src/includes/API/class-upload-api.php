<?php
/**
 * REST API controller for image uploads.
 *
 * @package PhotoCompetitionManager\API
 */

namespace PhotoCompetitionManager\API;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Service\Upload_Handler;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Upload API Controller.
 *
 * @since 0.1.0
 */
class Upload_API extends WP_REST_Controller {

	/**
	 * Namespace for API endpoints.
	 *
	 * @var string
	 */
	protected $namespace = 'photo-comp/v1';

	/**
	 * REST base for upload endpoints.
	 *
	 * @var string
	 */
	protected $rest_base = 'upload';

	/**
	 * Upload handler.
	 *
	 * @var Upload_Handler
	 */
	private $upload_handler;

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
	 * Upload token repository.
	 *
	 * @var Upload_Token_Repository
	 */
	private $token_repo;

	/**
	 * Constructor.
	 *
	 * @param Upload_Handler|null          $upload_handler    Upload handler.
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 * @param Upload_Token_Repository|null $token_repo        Token repository.
	 */
	public function __construct(
		?Upload_Handler $upload_handler = null,
		?Competitions_Repository $competitions_repo = null,
		?Members_Repository $members_repo = null,
		?Upload_Token_Repository $token_repo = null
	) {
		$this->upload_handler    = $upload_handler ?? new Upload_Handler();
		$this->competitions_repo = $competitions_repo ?? new Competitions_Repository();
		$this->members_repo      = $members_repo ?? new Members_Repository();
		$this->token_repo        = $token_repo ?? new Upload_Token_Repository();
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes(): void {
		// Get quota status for all categories.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/quota',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_quota_status' ),
					'permission_callback' => array( $this, 'validate_token_permission' ),
					'args'                => $this->get_quota_params(),
				),
			)
		);

		// Batch upload endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/batch',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'batch_upload' ),
					'permission_callback' => array( $this, 'validate_token_permission' ),
					'args'                => $this->get_batch_upload_params(),
				),
			)
		);

		// Update submission category endpoint.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<submission_id>\d+)/category',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_category' ),
					'permission_callback' => array( $this, 'validate_token_permission' ),
					'args'                => $this->get_update_category_params(),
				),
			)
		);
	}

	/**
	 * Validate upload token permission.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function validate_token_permission( WP_REST_Request $request ) {
		$token_string = $request->get_param( 'token' );

		if ( empty( $token_string ) ) {
			return new WP_Error(
				'missing_token',
				__( 'Upload token is required.', 'photo-competition-manager' ),
				array( 'status' => 401 )
			);
		}

		$token_record = $this->token_repo->find_valid_token( $token_string );

		if ( ! $token_record ) {
			return new WP_Error(
				'invalid_token',
				__( 'Invalid or expired upload token.', 'photo-competition-manager' ),
				array( 'status' => 401 )
			);
		}

		// Store token record in request for later use.
		$request->set_param( '_token_record', $token_record );

		return true;
	}

	/**
	 * Get quota status for all categories.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_quota_status( WP_REST_Request $request ) {
		$token_record = $request->get_param( '_token_record' );
		$member_id    = (int) $token_record->member_id;
		$competition  = $this->competitions_repo->find( (int) $token_record->competition_id );

		if ( ! $competition ) {
			return new WP_Error(
				'invalid_competition',
				__( 'Competition not found.', 'photo-competition-manager' ),
				array( 'status' => 404 )
			);
		}

		$settings   = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings );
		$categories = \PhotoCompetitionManager\Support\Competition_Settings::get_categories( $settings );

		$quota_status = array();
		foreach ( $categories as $cat ) {
			$status = $this->upload_handler->get_quota_status(
				(int) $competition->id,
				$member_id,
				$cat['slug']
			);

			if ( ! is_wp_error( $status ) ) {
				$quota_status[ $cat['slug'] ] = array(
					'label'     => $cat['label'],
					'slug'      => $cat['slug'],
					'current'   => $status['current'],
					'quota'     => $status['quota'],
					'remaining' => $status['remaining'],
				);
			}
		}

		return new WP_REST_Response(
			array(
				'competition_id' => $competition->id,
				'quotas'         => $quota_status,
			),
			200
		);
	}

	/**
	 * Handle batch upload.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function batch_upload( WP_REST_Request $request ) {
		$token_record = $request->get_param( '_token_record' );
		$member_id    = (int) $token_record->member_id;
		$competition  = $this->competitions_repo->find( (int) $token_record->competition_id );

		if ( ! $competition ) {
			return new WP_Error(
				'invalid_competition',
				__( 'Competition not found.', 'photo-competition-manager' ),
				array( 'status' => 404 )
			);
		}

		// Get category assignments from request parameters.
		$assignments = $request->get_param( 'assignments' );

		if ( empty( $assignments ) || ! is_array( $assignments ) ) {
			// Try to get from body params as well.
			$body_params = $request->get_body_params();
			$assignments = isset( $body_params['assignments'] ) ? $body_params['assignments'] : array();
		}

		if ( empty( $assignments ) || ! is_array( $assignments ) ) {
			return new WP_Error(
				'invalid_assignments',
				__( 'Category assignments are required.', 'photo-competition-manager' ),
				array( 'status' => 400 )
			);
		}

		// Get uploaded files.
		$files = $request->get_file_params();

		if ( empty( $files ) ) {
			return new WP_Error(
				'no_files',
				__( 'No files were uploaded.', 'photo-competition-manager' ),
				array( 'status' => 400 )
			);
		}

		// Process batch upload (max 20 images for safety).
		$max_batch_size = 20;
		$results        = array();
		$success_count  = 0;
		$error_count    = 0;

		// Validate total batch size.
		if ( count( $files ) > $max_batch_size ) {
			return new WP_Error(
				'batch_too_large',
				sprintf(
					/* translators: %d: maximum batch size */
					__( 'Maximum batch size is %d images.', 'photo-competition-manager' ),
					$max_batch_size
				),
				array( 'status' => 400 )
			);
		}

		// Count files per category in this batch to validate quotas upfront.
		$batch_category_counts = array();
		foreach ( $assignments as $file_key => $category ) {
			$category = sanitize_text_field( $category );
			if ( ! empty( $category ) ) {
				$batch_category_counts[ $category ] = isset( $batch_category_counts[ $category ] ) ? $batch_category_counts[ $category ] + 1 : 1;
			}
		}

		// Validate quotas for all categories in batch before processing any files.
		$settings   = \PhotoCompetitionManager\Support\Competition_Settings::parse( $competition->settings );
		$categories = \PhotoCompetitionManager\Support\Competition_Settings::get_categories( $settings );

		foreach ( $batch_category_counts as $category => $batch_count ) {
			// Find category config.
			$category_config = null;
			foreach ( $categories as $cat ) {
				if ( $cat['slug'] === $category ) {
					$category_config = $cat;
					break;
				}
			}

			if ( ! $category_config ) {
				return new WP_Error(
					'invalid_category',
					sprintf(
						/* translators: %s: category slug */
						__( 'Invalid category: %s', 'photo-competition-manager' ),
						$category
					),
					array( 'status' => 400 )
				);
			}

			// Check if batch would exceed quota for this category.
			$current_count = $this->upload_handler->get_category_count( (int) $competition->id, $member_id, $category );
			$quota         = $category_config['quota'] ?? 1;
			$available     = $quota - $current_count;

			if ( $batch_count > $available ) {
				return new WP_Error(
					'batch_quota_exceeded',
					sprintf(
						/* translators: 1: category label, 2: requested count, 3: available slots */
						__( 'Cannot upload %2$d image(s) to %1$s. You have %3$d slot(s) available.', 'photo-competition-manager' ),
						$category_config['label'],
						$batch_count,
						$available
					),
					array( 'status' => 400 )
				);
			}
		}

		// Process each file.
		foreach ( $files as $file_key => $file ) {
			// Get category assignment for this file.
			$category = isset( $assignments[ $file_key ] ) ? sanitize_text_field( $assignments[ $file_key ] ) : '';

			if ( empty( $category ) ) {
				$results[ $file_key ] = array(
					'success' => false,
					'error'   => __( 'No category assigned.', 'photo-competition-manager' ),
				);
				++$error_count;
				continue;
			}

			// Attempt upload.
			$result = $this->upload_handler->handle_upload(
				(int) $competition->id,
				$member_id,
				$category,
				$file
			);

			if ( is_wp_error( $result ) ) {
				$results[ $file_key ] = array(
					'success' => false,
					'error'   => $result->get_error_message(),
				);
				++$error_count;
			} else {
				$results[ $file_key ] = array(
					'success'  => true,
					'image_id' => $result,
				);
				++$success_count;
			}
		}

		return new WP_REST_Response(
			array(
				'results'       => $results,
				'success_count' => $success_count,
				'error_count'   => $error_count,
				'total'         => count( $files ),
			),
			200
		);
	}

	/**
	 * Get parameters for quota endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_quota_params(): array {
		return array(
			'token' => array(
				'description'       => __( 'Upload token string.', 'photo-competition-manager' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Get parameters for batch upload endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_batch_upload_params(): array {
		return array(
			'token'       => array(
				'description'       => __( 'Upload token string.', 'photo-competition-manager' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'assignments' => array(
				'description' => __( 'Array mapping file keys to category slugs.', 'photo-competition-manager' ),
				'type'        => 'object',
				'required'    => true,
			),
		);
	}

	/**
	 * Update submission category.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_category( WP_REST_Request $request ) {
		$submission_id = $request->get_param( 'submission_id' );
		$category      = $request->get_param( 'category' );
		$token_record  = $request->get_param( '_token_record' );

		if ( ! $token_record ) {
			return new WP_Error(
				'invalid_token',
				__( 'Invalid or expired upload token.', 'photo-competition-manager' ),
				array( 'status' => 401 )
			);
		}

		$member_id      = (int) $token_record->member_id;
		$competition_id = (int) $token_record->competition_id;

		// Update the submission category.
		$result = $this->upload_handler->update_submission_category(
			$submission_id,
			$member_id,
			$competition_id,
			$category
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Category updated successfully.', 'photo-competition-manager' ),
			),
			200
		);
	}

	/**
	 * Get parameters for update category endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_update_category_params(): array {
		return array(
			'token'    => array(
				'description'       => __( 'Upload token string.', 'photo-competition-manager' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'category' => array(
				'description'       => __( 'New category slug.', 'photo-competition-manager' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
