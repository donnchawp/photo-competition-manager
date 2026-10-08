<?php
/**
 * Exporting a member's personal data.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Recorded_Results_Repository;
use PhotoCompetitionManager\Repository\Votes_Repository;
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
	const PAGES = array( 'member_items', 'entry_items', 'result_items', 'vote_items', 'log_items' );

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Competitions already loaded, by ID; null for one that's gone.
	 *
	 * @var array<int, object|null>
	 */
	private $competition_cache = array();

	/**
	 * Constructor.
	 *
	 * @param Members_Repository|null $members Members repository.
	 */
	public function __construct( ?Members_Repository $members = null ) {
		$this->members      = $members ?? new Members_Repository();
		$this->competitions = new Competitions_Repository();
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
		$items = array();

		foreach ( $members as $member ) {
			$items[] = $this->item(
				'photo-competition-member',
				__( 'Competition member', 'photo-competition-manager' ),
				'photo-competition-member-' . $member->id,
				array(
					__( 'Name', 'photo-competition-manager' )   => $member->name,
					__( 'Email', 'photo-competition-manager' )  => Members_Repository::unmark_deactivated_email( $member->email ),
					__( 'Grade', 'photo-competition-manager' )  => $this->grade_label( (string) $member->grade ),
					__( 'Active', 'photo-competition-manager' ) => $member->active ? __( 'Yes', 'photo-competition-manager' ) : __( 'No', 'photo-competition-manager' ),
				)
			);
		}

		return $items;
	}

	/**
	 * The members' entries, with their image and, while it's kept, their original.
	 *
	 * @param array<int, object> $members Member records.
	 * @return array<int, array<string, mixed>>
	 */
	private function entry_items( array $members ): array {
		$images  = new Images_Repository();
		$entries = new Entries( null, null, $this->members );
		$items   = array();

		foreach ( $members as $member ) {
			foreach ( $images->find_by_member( (int) $member->id ) as $entry ) {
				$competition = $this->competition( (int) $entry->competition_id );
				$fields      = $this->competition_fields( $competition, (string) $entry->category ) + array(
					__( 'Entry ID', 'photo-competition-manager' ) => $entry->id,
					__( 'Uploaded', 'photo-competition-manager' ) => $entry->created_at,
				);

				$image = $competition ? $entries->urls( $competition, $entry )['full'] : '';
				if ( '' !== $image ) {
					$fields[ __( 'Image', 'photo-competition-manager' ) ] = $image;
				}

				$original = $entry->original_attachment_id ? wp_get_attachment_url( (int) $entry->original_attachment_id ) : false;
				if ( $original ) {
					$fields[ __( 'Original', 'photo-competition-manager' ) ] = $original;
				}

				$items[] = $this->item(
					'photo-competition-entries',
					__( 'Competition entries', 'photo-competition-manager' ),
					'photo-competition-entry-' . $entry->id,
					$fields
				);
			}
		}

		return $items;
	}

	/**
	 * The members' recorded results: each entry's total score, vote count,
	 * grade and position.
	 *
	 * @param array<int, object> $members Member records.
	 * @return array<int, array<string, mixed>>
	 */
	private function result_items( array $members ): array {
		$recorded = new Recorded_Results_Repository();
		$items    = array();

		foreach ( $members as $member ) {
			foreach ( $recorded->find_by_member( (int) $member->id ) as $row ) {
				$items[] = $this->item(
					'photo-competition-results',
					__( 'Competition results', 'photo-competition-manager' ),
					'photo-competition-result-' . $row->id,
					$this->competition_fields( $this->competition( (int) $row->competition_id ), (string) $row->category ) + array(
						__( 'Entry ID', 'photo-competition-manager' )    => (string) $row->entry_id,
						__( 'Total score', 'photo-competition-manager' ) => $row->total_score,
						__( 'Votes', 'photo-competition-manager' )       => $row->vote_count,
						__( 'Grade', 'photo-competition-manager' )       => $this->grade_label( (string) $row->grade ),
						__( 'Position', 'photo-competition-manager' )    => $row->position,
					)
				);
			}
		}

		return $items;
	}

	/**
	 * The votes the members cast, with their voting links or under their name
	 * as Member_Deletion matches it. Not whose entry each was for: that's
	 * another member's data.
	 *
	 * @param array<int, object> $members Member records.
	 * @return array<int, array<string, mixed>>
	 */
	private function vote_items( array $members ): array {
		$votes = new Votes_Repository();
		$items = array();

		foreach ( $members as $member ) {
			foreach ( $votes->find_cast_by_member( (int) $member->id, ( new Named_Voter( $member->name ) )->name() ) as $vote ) {
				// Two records sharing a name find the same password votes; each is exported once.
				$items[ (int) $vote->id ] = $this->item(
					'photo-competition-votes',
					__( 'Votes cast', 'photo-competition-manager' ),
					'photo-competition-vote-' . $vote->id,
					$this->competition_fields( $this->competition( (int) $vote->competition_id ), (string) $vote->category ) + array(
						__( 'Entry ID', 'photo-competition-manager' ) => $vote->image_id,
						__( 'Score', 'photo-competition-manager' )    => $vote->score,
					)
				);
			}
		}

		return array_values( $items );
	}

	/**
	 * The date and kind of each log row about the members: the rows
	 * Member_Deletion deletes. The rest of each row's metadata stays out.
	 *
	 * @param array<int, object> $members Member records.
	 * @return array<int, array<string, mixed>>
	 */
	private function log_items( array $members ): array {
		$logs  = new Logs_Repository();
		$items = array();

		foreach ( $members as $member ) {
			foreach ( $logs->find_about_member( (int) $member->id, Members_Repository::email_forms( $member->email ) ) as $row ) {
				$kind = 'email' === $row->event_category ? Email_Kinds::get( (string) $row->event_type ) : null;

				// Two records holding one address find the same rows; each is exported once.
				$items[ (int) $row->id ] = $this->item(
					'photo-competition-emails',
					__( 'Emails sent', 'photo-competition-manager' ),
					'photo-competition-log-' . $row->id,
					array(
						__( 'Date', 'photo-competition-manager' ) => $row->created_at,
						__( 'Kind', 'photo-competition-manager' ) => $kind ? $kind['label'] : $row->event_type,
					)
				);
			}
		}

		return array_values( $items );
	}

	/**
	 * A grade's label from the club's list, or its slug if the club no longer has it.
	 *
	 * @param string $grade Grade slug.
	 * @return string
	 */
	private function grade_label( string $grade ): string {
		return array_column( Competition_Settings::club_grades(), 'label', 'slug' )[ $grade ] ?? $grade;
	}

	/**
	 * A competition, archived or not, loaded once per export page.
	 *
	 * @param int $competition_id Competition ID.
	 * @return object|null
	 */
	private function competition( int $competition_id ) {
		if ( ! array_key_exists( $competition_id, $this->competition_cache ) ) {
			$this->competition_cache[ $competition_id ] = $this->competitions->find( $competition_id, true );
		}

		return $this->competition_cache[ $competition_id ];
	}

	/**
	 * The competition's title and the category's label, for an item about one.
	 *
	 * @param object|null $competition Competition record, or null if it's gone.
	 * @param string      $category    Category slug.
	 * @return array<string, string>
	 */
	private function competition_fields( $competition, string $category ): array {
		$label = $category;
		if ( $competition ) {
			$settings = Competition_Settings::parse( $competition->settings );
			$labels   = array_column( Competition_Settings::get_categories( $settings ), 'label', 'slug' );
			$label    = $labels[ $category ] ?? $category;
		}

		return array(
			__( 'Competition', 'photo-competition-manager' ) => $competition ? $competition->title : '',
			__( 'Category', 'photo-competition-manager' ) => $label,
		);
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
