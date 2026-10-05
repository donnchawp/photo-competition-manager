<?php
/**
 * Who is acting on an entry: a member or an admin.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

/**
 * Actor value object. Callers decide who is acting; the Entries module applies the rules for them.
 *
 * @since 0.4.0
 */
final class Actor {

	/**
	 * The acting member's ID, or null for an admin.
	 *
	 * @var int|null
	 */
	private $member_id;

	/**
	 * Constructor.
	 *
	 * @param int|null $member_id The acting member's ID, or null for an admin.
	 */
	private function __construct( ?int $member_id ) {
		$this->member_id = $member_id;
	}

	/**
	 * A member acting on their own entries.
	 *
	 * @param int $member_id Member ID.
	 * @return self
	 */
	public static function member( int $member_id ): self {
		return new self( $member_id );
	}

	/**
	 * An admin, who may act on any member's entries.
	 *
	 * @return self
	 */
	public static function admin(): self {
		return new self( null );
	}

	/**
	 * Whoever is using a member's upload link: an admin when the logged-in user can manage
	 * competitions (the Submissions screen links admins to it), otherwise that member.
	 *
	 * @since 0.4.0
	 *
	 * @param int $member_id The member the upload link belongs to.
	 * @return self
	 */
	public static function for_upload_link( int $member_id ): self {
		return current_user_can( 'manage_photo_competitions' ) ? self::admin() : self::member( $member_id );
	}

	/**
	 * Whether this actor is an admin.
	 *
	 * @return bool
	 */
	public function is_admin(): bool {
		return null === $this->member_id;
	}

	/**
	 * Whether this actor is the given member.
	 *
	 * @param int $member_id Member ID.
	 * @return bool
	 */
	public function is_member( int $member_id ): bool {
		return $this->member_id === $member_id;
	}
}
