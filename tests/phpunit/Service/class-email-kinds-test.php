<?php
/**
 * Tests for the table of email kinds.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Service\Email_Kinds;
use WP_UnitTestCase;

/**
 * Each kind's defaults agree with the tags it declares.
 */
class Email_Kinds_Test extends WP_UnitTestCase {

	/**
	 * The plugin sends six kinds of email: two notifications and four requested emails.
	 */
	public function test_there_are_six_kinds_of_email() {
		$notifications = array_filter(
			Email_Kinds::all(),
			function ( $kind ) {
				return $kind['notification'];
			}
		);

		$this->assertSame(
			array( 'upload_reminder', 'voting_opened', 'voting_link', 'results_published', 'results_detailed', 'submission_confirmed' ),
			array_keys( Email_Kinds::all() )
		);
		$this->assertSame( array( 'voting_opened', 'submission_confirmed' ), array_keys( $notifications ) );
	}

	/**
	 * A default subject and body use only the kind's own tags and the shared ones.
	 *
	 * @dataProvider kinds
	 *
	 * @param string $kind Kind key.
	 */
	public function test_a_kinds_defaults_use_only_tags_it_takes( string $kind ) {
		$definition = Email_Kinds::get( $kind );
		$allowed    = array_merge( array_keys( Email_Kinds::shared_tags() ), array_keys( $definition['tags'] ) );

		preg_match_all( '/\{[a-z_]+\}/', $definition['subject'] . $definition['body'], $used );

		$this->assertSame( array(), array_values( array_diff( array_unique( $used[0] ), $allowed ) ) );
	}

	/**
	 * Every kind's key.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function kinds(): array {
		$kinds = array();
		foreach ( array( 'upload_reminder', 'voting_opened', 'voting_link', 'results_published', 'results_detailed', 'submission_confirmed' ) as $kind ) {
			$kinds[ $kind ] = array( $kind );
		}
		return $kinds;
	}
}
