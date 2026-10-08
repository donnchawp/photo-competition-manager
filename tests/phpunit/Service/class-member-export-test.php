<?php
/**
 * Tests for exporting a member's personal data.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use WP_UnitTestCase;

/**
 * WordPress's personal data export covers everything the club holds about a member.
 *
 * @covers \PhotoCompetitionManager\Service\Member_Export
 */
class Member_Export_Test extends WP_UnitTestCase {

	/**
	 * @var Members_Repository
	 */
	private $members;

	public function setUp(): void {
		parent::setUp();

		$this->members = new Members_Repository();
	}

	public function test_exporting_a_member_gives_their_record(): void {
		$this->create_member( 'Jane Doe', 'jane@example.com' );

		$items = $this->export( 'jane@example.com' );

		$this->assertSame(
			array(
				'Name'   => 'Jane Doe',
				'Email'  => 'jane@example.com',
				'Grade'  => 'Beginner',
				'Active' => 'Yes',
			),
			$this->only( $items, 'photo-competition-member' )[0]
		);
	}

	public function test_exporting_an_address_that_isnt_a_members_gives_nothing(): void {
		$this->create_member( 'Jane Doe', 'jane@example.com' );

		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$this->exporter()( 'nobody@example.com', 1 )
		);
	}

	/**
	 * The plugin's exporter callback, as WordPress registers it.
	 *
	 * @return callable
	 */
	private function exporter(): callable {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );

		$this->assertArrayHasKey( 'photo-competition-manager', $exporters );
		$this->assertNotEmpty( $exporters['photo-competition-manager']['exporter_friendly_name'] );

		return $exporters['photo-competition-manager']['callback'];
	}

	/**
	 * Run the plugin's exporter through every page, as Tools > Export Personal Data does.
	 *
	 * @param string $email Email address.
	 * @return array<int, array<string, mixed>> Every item exported.
	 */
	private function export( string $email ): array {
		$exporter = $this->exporter();
		$items    = array();

		for ( $page = 1; $page <= 50; $page++ ) {
			$response = $exporter( $email, $page );
			$items    = array_merge( $items, $response['data'] );
			if ( $response['done'] ) {
				return $items;
			}
		}

		$this->fail( 'The export never finished.' );
	}

	/**
	 * Each item in a group, as its fields' names and values.
	 *
	 * @param array<int, array<string, mixed>> $items    Exported items.
	 * @param string                           $group_id Group ID.
	 * @return array<int, array<string, string>>
	 */
	private function only( array $items, string $group_id ): array {
		$rows = array();
		foreach ( $items as $item ) {
			if ( $group_id !== $item['group_id'] ) {
				continue;
			}
			$this->assertNotEmpty( $item['group_label'] );
			$rows[] = array_column( $item['data'], 'value', 'name' );
		}

		return $rows;
	}

	/**
	 * Create a member.
	 *
	 * @param string $name   Name.
	 * @param string $email  Email.
	 * @param int    $active Whether they're active.
	 * @return int Member ID.
	 */
	private function create_member( string $name, string $email, int $active = 1 ): int {
		return (int) $this->members->create(
			array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => $active,
			)
		);
	}
}
