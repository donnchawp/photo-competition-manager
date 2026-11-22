<?php
/**
 * The past competitions archive.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Recorded_Results_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * Past competitions shown to the public: those whose results are published,
 * while the club has the archive switched on. Archived competitions are left
 * out, as they are everywhere else.
 *
 * @since 0.5.0
 */
class Competition_Archive {

	/**
	 * Option holding the club's archive settings.
	 */
	const OPTION = 'photo_comp_archive';

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Images repository.
	 *
	 * @var Images_Repository
	 */
	private $images;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Entries module, for image URLs.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Recorded results repository.
	 *
	 * @var Recorded_Results_Repository
	 */
	private $recorded;

	/**
	 * Competition labels.
	 *
	 * @var Competition_Labels
	 */
	private $labels;

	/**
	 * Constructor.
	 *
	 * @param Competitions_Repository|null     $competitions Competitions repository.
	 * @param Images_Repository|null           $images       Images repository.
	 * @param Members_Repository|null          $members      Members repository.
	 * @param Competition_Workflow|null        $workflow     Competition workflow.
	 * @param Entries|null                     $entries      Entries module.
	 * @param Recorded_Results_Repository|null $recorded Recorded results repository.
	 * @param Competition_Labels|null          $labels       Competition labels.
	 */
	public function __construct(
		?Competitions_Repository $competitions = null,
		?Images_Repository $images = null,
		?Members_Repository $members = null,
		?Competition_Workflow $workflow = null,
		?Entries $entries = null,
		?Recorded_Results_Repository $recorded = null,
		?Competition_Labels $labels = null
	) {
		$this->competitions = $competitions ?? new Competitions_Repository();
		$this->images       = $images ?? new Images_Repository();
		$this->members      = $members ?? new Members_Repository();
		$this->workflow     = $workflow ?? new Competition_Workflow( $this->competitions, $this->images );
		$this->entries      = $entries ?? new Entries( $this->competitions, $this->images, $this->members );
		$this->recorded     = $recorded ?? new Recorded_Results_Repository();
		$this->labels       = $labels ?? new Competition_Labels();
	}

	/**
	 * The club's archive settings: whether it's on, and whether it shows winners. Both default to off.
	 *
	 * @since 0.5.0
	 *
	 * @return array{enabled: bool, show_winners: bool}
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'enabled'      => ! empty( $saved['enabled'] ),
			'show_winners' => ! empty( $saved['show_winners'] ),
		);
	}

	/**
	 * Save the club's archive settings.
	 *
	 * @since 0.5.0
	 *
	 * @param bool $enabled      Whether the archive is on.
	 * @param bool $show_winners Whether it shows positions and scores.
	 * @return void
	 */
	public static function save_settings( bool $enabled, bool $show_winners ): void {
		update_option(
			self::OPTION,
			array(
				'enabled'      => $enabled,
				'show_winners' => $show_winners,
			)
		);
	}

	/**
	 * Competitions in the archive, newest first.
	 *
	 * @since 0.5.0
	 *
	 * @return array<int, object> Competition rows; none while the archive is off.
	 */
	public function competitions(): array {
		if ( ! self::settings()['enabled'] ) {
			return array();
		}

		return array_values( array_filter( $this->competitions->all_by_opening(), array( $this->workflow, 'results_published' ) ) );
	}

	/**
	 * A competition in the archive, by slug.
	 *
	 * @since 0.5.0
	 *
	 * @param string $slug Competition slug.
	 * @return object|null The competition, or null if it isn't in the archive.
	 */
	public function find( string $slug ): ?object {
		if ( ! self::settings()['enabled'] ) {
			return null;
		}

		$competition = $this->competitions->find_by_slug( $slug );

		return $competition && $this->workflow->results_published( $competition ) ? $competition : null;
	}

	/**
	 * A competition's categories, each with its entered images in ballot order.
	 *
	 * An image shows only while its member shows their images and it isn't
	 * hidden on its own. In an interclub competition the member is the club.
	 *
	 * Each entry has its id, its image and thumbnail URLs ('' when the file is
	 * missing), the full image's width and height (0 when missing), who it's by ('' unless names are shown), and its position,
	 * total score and grade name (null unless the club shows winners, or for
	 * an entry ranked without a grade).
	 *
	 * @since 0.5.0
	 *
	 * @param object $competition Competition row, from find().
	 * @param bool   $show_names  Whether to say who each image is by.
	 * @return array<int, array{label: string, entries: array<int, object>}>
	 */
	public function categories( object $competition, bool $show_names ): array {
		$categories = array();
		$members    = $this->members->find_many( $this->images->member_ids( (int) $competition->id ) );
		$winners    = self::settings()['show_winners'];

		foreach ( Competition_Settings::get_categories( Competition_Settings::parse( $competition->settings ) ) as $category ) {
			$entered = $this->images->find_entered( (int) $competition->id, $category['slug'] );
			$results = $winners ? array_column( $this->recorded->find_by_category( (int) $competition->id, $category['slug'] ), null, 'entry_id' ) : array();
			$entries = array();

			foreach ( $this->images->shuffle_deterministic( $entered, (int) $competition->id, $category['slug'] ) as $entry ) {
				$member = $members[ (int) $entry->member_id ] ?? null;
				if ( ! $member || ! (int) $member->images_public || (int) $entry->archive_hidden ) {
					continue;
				}

				$urls   = $this->entries->public_urls( $competition, $entry );
				$size   = $this->entries->full_size( $competition, $entry );
				$result = $results[ (int) $entry->id ] ?? null;
				$result = $result && '' !== (string) $result->grade ? $result : null;

				$entries[] = (object) array(
					'id'       => (int) $entry->id,
					'full'     => $urls['full'],
					'thumb'    => $urls['thumb'],
					'width'    => $size[0],
					'height'   => $size[1],
					'by'       => $show_names ? $this->labels->entry_by( $competition, $entry, $member ) : '',
					'position' => $result ? (int) $result->position : null,
					'score'    => $result ? (int) $result->total_score : null,
					'grade'    => $result ? $this->labels->grade_label( $competition, (string) $result->grade ) : null,
				);
			}

			$categories[] = array(
				'label'   => (string) $category['label'],
				'entries' => $entries,
			);
		}

		return $categories;
	}
}
