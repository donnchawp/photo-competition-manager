<?php
/**
 * Exporting a member's personal data.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * WordPress's personal data exporter: everything the club holds about the
 * members with an email address, whether or not they're marked as deactivated.
 * Token values are never exported, nor anything about another member.
 *
 * @since 0.4.0
 */
class Member_Export {

	/**
	 * What each page exports, in order: one kind of data for every record
	 * holding the address, so no page grows with more than one kind.
	 */
	const PAGES = array( 'member_items' );

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Constructor.
	 *
	 * @param Members_Repository|null $members Members repository.
	 */
	public function __construct( ?Members_Repository $members = null ) {
		$this->members = $members ?? new Members_Repository();
	}

	/**
	 * Add the plugin's exporter to WordPress's personal data exporters.
	 *
	 * @param array<string, array> $exporters Registered exporters.
	 * @return array<string, array>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['photo-competition-manager'] = array(
			'exporter_friendly_name' => __( 'Photo Competition Manager', 'photo-competition-manager' ),
			'callback'               => array( new self(), 'export' ),
		);

		return $exporters;
	}

	/**
	 * WordPress's personal data exporter: one page of what the club holds
	 * about the members with an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$members = $this->members->find_all_by_email( $email );
		$section = self::PAGES[ $page - 1 ] ?? null;

		if ( ! $members || ! $section ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		return array(
			'data' => $this->$section( $members ),
			'done' => count( self::PAGES ) <= $page,
		);
	}

	/**
	 * The member records.
	 *
	 * @param array<int, object> $members Member records.
	 * @return array<int, array<string, mixed>>
	 */
	private function member_items( array $members ): array {
		$grades = array_column( Competition_Settings::club_grades(), 'label', 'slug' );
		$items  = array();

		foreach ( $members as $member ) {
			$items[] = $this->item(
				'photo-competition-member',
				__( 'Competition member', 'photo-competition-manager' ),
				'photo-competition-member-' . $member->id,
				array(
					__( 'Name', 'photo-competition-manager' )   => $member->name,
					__( 'Email', 'photo-competition-manager' )  => Members_Repository::unmark_deactivated_email( $member->email ),
					__( 'Grade', 'photo-competition-manager' )  => $grades[ $member->grade ] ?? $member->grade,
					__( 'Active', 'photo-competition-manager' ) => $member->active ? __( 'Yes', 'photo-competition-manager' ) : __( 'No', 'photo-competition-manager' ),
				)
			);
		}

		return $items;
	}

	/**
	 * One exported item.
	 *
	 * @param string                $group_id    Group ID.
	 * @param string                $group_label Group label.
	 * @param string                $item_id     Item ID, unique in the export.
	 * @param array<string, string> $fields      Field name => value.
	 * @return array<string, mixed>
	 */
	private function item( string $group_id, string $group_label, string $item_id, array $fields ): array {
		$data = array();
		foreach ( $fields as $name => $value ) {
			$data[] = array(
				'name'  => $name,
				'value' => (string) $value,
			);
		}

		return array(
			'group_id'    => $group_id,
			'group_label' => $group_label,
			'item_id'     => $item_id,
			'data'        => $data,
		);
	}
}
