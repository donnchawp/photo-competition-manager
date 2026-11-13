<?php
/**
 * Competition types for tests.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests;

use PhotoCompetitionManager\Repository\Competition_Types_Repository;

/**
 * Give tests a type to create competitions under, as every competition needs one.
 */
class Type_Fixtures {

	/**
	 * The ID of an existing type, or of a new one when the site has none.
	 *
	 * @return int
	 */
	public static function id(): int {
		$types = new Competition_Types_Repository();
		$all   = $types->all();

		if ( $all ) {
			return (int) $all[0]->id;
		}

		$id = $types->create(
			'Individual',
			array(
				'categories' => array(
					array(
						'label' => 'Open',
						'slug'  => 'open',
						'quota' => 2,
					),
				),
			)
		);

		return (int) $id;
	}
}
