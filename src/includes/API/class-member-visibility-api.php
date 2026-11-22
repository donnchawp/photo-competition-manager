<?php
/**
 * REST endpoint the member page saves past competitions choices through.
 *
 * @package PhotoCompetitionManager\API
 */

namespace PhotoCompetitionManager\API;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Service\Competition_Archive;
use PhotoCompetitionManager\Service\Member_Page_Link;
use PhotoCompetitionManager\Service\Member_Visibility;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * POST photo-comp/v1/member-visibility saves one choice: all of a member's
 * images (entry 0) or one of them. The member's page key authorises it, as it
 * does the page; there's no login.
 *
 * @since 0.5.0
 */
class Member_Visibility_API {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'photo-comp/v1';

	/**
	 * Route, under the namespace.
	 */
	const ROUTE = '/member-visibility';

	/**
	 * The member whose key the request carries, found by can_save().
	 *
	 * @var object|null
	 */
	private $member;

	/**
	 * The route's URL, for the member page.
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public static function url(): string {
		return rest_url( self::REST_NAMESPACE . self::ROUTE );
	}

	/**
	 * Register the route.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save' ),
				'permission_callback' => array( $this, 'can_save' ),
				'args'                => array(
					'key'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'entry' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
					),
					'shown' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Allow the request while past competitions are on and the key is a member's.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_save( WP_REST_Request $request ) {
		$this->member = Competition_Archive::settings()['enabled'] ? ( new Member_Page_Link() )->member( (string) $request->get_param( 'key' ) ) : null;
		if ( $this->member ) {
			return true;
		}

		return new WP_Error( 'invalid_member_key', __( "This link has expired or isn't valid.", 'photo-competition-manager' ), array( 'status' => 403 ) );
	}

	/**
	 * Save the choice.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( WP_REST_Request $request ) {
		$member     = $this->member;
		$visibility = new Member_Visibility();
		$entry_id   = (int) $request->get_param( 'entry' );
		$shown      = (bool) $request->get_param( 'shown' );

		$saved = 0 === $entry_id ? $visibility->set_all_shown( $member, $shown ) : $visibility->set_entry_shown( $member, $entry_id, $shown );
		if ( ! $saved ) {
			return new WP_Error( 'not_saved', __( "That choice couldn't be saved.", 'photo-competition-manager' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( array( 'saved' => true ) );
	}
}
