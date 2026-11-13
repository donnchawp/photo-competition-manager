<?php
/**
 * The names a competition shows for grades.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competition_Types_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;

/**
 * A competition type may show a grade under its own name. Recorded results keep
 * the grade's slug, so a name is applied when shown and renaming relabels old results.
 *
 * @since 0.5.0
 *
 * @package PhotoCompetitionManager\Service
 */
class Competition_Labels {

	/**
	 * Types repository.
	 *
	 * @var Competition_Types_Repository
	 */
	private $types;

	/**
	 * Each type's grade names, by type ID, once looked up.
	 *
	 * @var array<int, array<string,string>>
	 */
	private $names = array();

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param Competition_Types_Repository|null $types Types repository.
	 */
	public function __construct( ?Competition_Types_Repository $types = null ) {
		$this->types = $types ?? new Competition_Types_Repository();
	}

	/**
	 * A grade's name for a competition: its type's name, else the club's label, else the slug.
	 *
	 * @since 0.5.0
	 * @param object $competition Competition row.
	 * @param string $grade_slug  Grade slug.
	 * @return string
	 */
	public function grade_label( object $competition, string $grade_slug ): string {
		return $this->grade_labels( $competition )[ $grade_slug ] ?? $grade_slug;
	}

	/**
	 * The club's grades as slug => name for a competition, with its type's renames applied.
	 *
	 * @since 0.5.0
	 * @param object $competition Competition row.
	 * @return array<string,string>
	 */
	public function grade_labels( object $competition ): array {
		$labels = Competition_Settings::grade_labels();

		foreach ( $this->type_names( $competition ) as $slug => $name ) {
			if ( isset( $labels[ $slug ] ) ) {
				$labels[ $slug ] = $name;
			}
		}

		return $labels;
	}

	/**
	 * A grade's name for the admin: "Cup (Advanced)" when the type renames it, else the club's label.
	 *
	 * @since 0.5.0
	 * @param object $competition Competition row.
	 * @param string $grade_slug  Grade slug.
	 * @return string
	 */
	public function admin_grade_label( object $competition, string $grade_slug ): string {
		$club   = Competition_Settings::grade_labels()[ $grade_slug ] ?? $grade_slug;
		$custom = $this->type_names( $competition )[ $grade_slug ] ?? '';

		if ( '' === $custom || $custom === $club || ! isset( Competition_Settings::grade_labels()[ $grade_slug ] ) ) {
			return $club;
		}

		/* translators: 1: the grade name the competition type shows, 2: the club's name for the grade */
		return sprintf( __( '%1$s (%2$s)', 'photo-competition-manager' ), $custom, $club );
	}

	/**
	 * The non-blank grade names of a competition's type.
	 *
	 * @param object $competition Competition row.
	 * @return array<string,string>
	 */
	private function type_names( object $competition ): array {
		$type_id = (int) ( $competition->type_id ?? 0 );

		if ( ! isset( $this->names[ $type_id ] ) ) {
			$type                    = $type_id ? $this->types->find( $type_id ) : null;
			$this->names[ $type_id ] = $type ? array_filter( array_map( 'strval', (array) $type->grade_names ), 'strlen' ) : array();
		}

		return $this->names[ $type_id ];
	}
}
