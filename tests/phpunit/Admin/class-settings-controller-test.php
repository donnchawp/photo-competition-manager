<?php
/**
 * Tests for saving the club's default settings.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Settings_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Tests\Member_Fixtures;

/**
 * @covers \PhotoCompetitionManager\Admin\Settings_Controller
 */
class Settings_Controller_Test extends Admin_Controller_Test_Case {

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Controller under test.
	 *
	 * @var Settings_Controller
	 */
	private $controller;

	/**
	 * Set up the controller with the club's default grades.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->members    = new Members_Repository();
		$this->controller = new Settings_Controller( new Competitions_Repository(), $this->members );
	}

	/**
	 * Remove the saved settings.
	 */
	public function tear_down(): void {
		delete_option( 'photo_comp_default_settings' );
		delete_option( 'photo_comp_voting_ui_type' );
		parent::tear_down();
	}

	/**
	 * Create a member holding a grade.
	 *
	 * @param string $name   Name.
	 * @param string $grade  Grade slug.
	 * @param bool   $active Whether the member is active.
	 * @return int Member ID.
	 */
	private function create_member( string $name, string $grade, bool $active = true ): int {
		$id = $this->members->create(
			array(
				'name'   => $name,
				'email'  => sanitize_title( $name ) . '@example.com',
				'grade'  => $grade,
				'active' => $active,
			)
		);

		$this->assertIsInt( $id, 'Failed to seed member.' );
		return $id;
	}

	/**
	 * A member's grade.
	 *
	 * @param int $member_id Member ID.
	 * @return string
	 */
	private function grade_of( int $member_id ): string {
		return (string) $this->members->find( $member_id )->grade;
	}

	/**
	 * The saved club grades.
	 *
	 * @return array<int, array{label: string, slug: string}>
	 */
	private function saved_grades(): array {
		return Competition_Settings::get_grades( Competition_Settings::global_settings() );
	}

	/**
	 * Post the settings form with the given grade rows.
	 *
	 * A row with a slug is an existing grade; a row without one was added in
	 * the browser.
	 *
	 * @param array<int, array<string, string>> $grades Grade rows as posted.
	 */
	private function save_grades( array $grades ): void {
		$this->set_request(
			array(
				'photo_competition_action' => 'update_global_settings',
				'categories'               => array(
					array(
						'label' => 'Colour',
						'slug'  => 'colour',
						'quota' => '1',
					),
				),
				'grades'                   => $grades,
				'score_matrix'             => '9, 8, 7, 6, 5',
			)
		);
		$this->set_nonce( 'photo_competition_global_settings', 'photo_competition_nonce' );

		$this->capture_redirect( array( $this->controller, 'handle_actions' ) );
	}

	/**
	 * The default grades as the form posts them.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function default_grade_rows(): array {
		return array(
			array(
				'label' => 'Beginner',
				'slug'  => 'beginner',
			),
			array(
				'label' => 'Intermediate',
				'slug'  => 'intermediate',
			),
			array(
				'label' => 'Advanced',
				'slug'  => 'advanced',
			),
		);
	}

	/**
	 * Renaming a grade keeps its slug, so its members still hold it.
	 */
	public function test_renaming_a_grade_keeps_its_slug(): void {
		$member = $this->create_member( 'Ann Advanced', 'advanced' );

		$rows             = $this->default_grade_rows();
		$rows[2]['label'] = 'Senior';
		$this->save_grades( $rows );

		$this->assertSame(
			array(
				'label' => 'Senior',
				'slug'  => 'advanced',
			),
			$this->saved_grades()[2]
		);
		$this->assertSame( 'advanced', $this->grade_of( $member ) );
	}

	/**
	 * After a rename, a new grade can take the old name. Its slug is made
	 * unique because the renamed grade kept the old one.
	 */
	public function test_new_grade_can_reuse_a_renamed_grades_old_name(): void {
		$member = $this->create_member( 'Ann Advanced', 'advanced' );

		$rows             = $this->default_grade_rows();
		$rows[2]['label'] = 'Senior';
		$this->save_grades( $rows );

		$rows[] = array( 'label' => 'Advanced' );
		$this->save_grades( $rows );

		$this->assertSame(
			array(
				array(
					'label' => 'Senior',
					'slug'  => 'advanced',
				),
				array(
					'label' => 'Advanced',
					'slug'  => 'advanced-2',
				),
			),
			array_slice( $this->saved_grades(), 2 )
		);
		$this->assertSame( 'advanced', $this->grade_of( $member ) );
	}

	/**
	 * A member whose grade isn't in the list keeps it.
	 */
	public function test_member_with_unknown_grade_is_unchanged(): void {
		$member = Member_Fixtures::insert_with_grade( 'Uma Unknown', 'uma@example.com', 'expert' );

		$this->save_grades( $this->default_grade_rows() );

		$this->assertSame( 'expert', $this->grade_of( $member ) );
	}

	/**
	 * Labels differing only by case or accents are duplicates.
	 */
	public function test_labels_differing_by_accent_are_rejected(): void {
		$rows             = $this->default_grade_rows();
		$rows[0]['label'] = 'Ógánach';
		$rows[1]['label'] = 'ógánach';
		$this->save_grades( $rows );

		$this->assertSame( 'Beginner', $this->saved_grades()[0]['label'] );
		$this->assertSame( array( 'duplicate_grade' ), $this->settings_error_codes( 'photo_competition_settings' ) );
	}

	/**
	 * A grade added in the browser gets a slug made from its label.
	 */
	public function test_new_grade_gets_slug_from_label(): void {
		$rows   = $this->default_grade_rows();
		$rows[] = array( 'label' => 'Salon Level' );
		$this->save_grades( $rows );

		$this->assertSame( 'salon-level', $this->saved_grades()[3]['slug'] );
	}

	/**
	 * Inserting a grade first changes no member's grade.
	 */
	public function test_inserting_a_grade_first_changes_no_member_grade(): void {
		$beginner = $this->create_member( 'Bob Beginner', 'beginner' );
		$advanced = $this->create_member( 'Ann Advanced', 'advanced' );

		$this->save_grades( array_merge( array( array( 'label' => 'Novice' ) ), $this->default_grade_rows() ) );

		$this->assertSame( 'novice', $this->saved_grades()[0]['slug'] );
		$this->assertSame( 'beginner', $this->grade_of( $beginner ) );
		$this->assertSame( 'advanced', $this->grade_of( $advanced ) );
	}

	/**
	 * Reordering the grades changes no member's grade.
	 */
	public function test_reordering_grades_changes_no_member_grade(): void {
		$beginner = $this->create_member( 'Bob Beginner', 'beginner' );
		$advanced = $this->create_member( 'Ann Advanced', 'advanced' );

		$this->save_grades( array_reverse( $this->default_grade_rows() ) );

		$this->assertSame( 'advanced', $this->saved_grades()[0]['slug'] );
		$this->assertSame( 'beginner', $this->grade_of( $beginner ) );
		$this->assertSame( 'advanced', $this->grade_of( $advanced ) );
	}

	/**
	 * A member without a grade keeps their empty grade.
	 */
	public function test_member_with_empty_grade_is_unchanged(): void {
		$member = Member_Fixtures::insert_with_grade( 'Nora None', 'nora@example.com', '' );

		$this->save_grades( $this->default_grade_rows() );

		$this->assertSame( '', $this->grade_of( $member ) );
	}

	/**
	 * Removing a grade nobody holds succeeds.
	 */
	public function test_removing_an_unheld_grade_succeeds(): void {
		$this->create_member( 'Bob Beginner', 'beginner' );

		$rows = $this->default_grade_rows();
		unset( $rows[2] );
		$this->save_grades( array_values( $rows ) );

		$this->assertSame( array( 'beginner', 'intermediate' ), wp_list_pluck( $this->saved_grades(), 'slug' ) );
		$this->assertContains( 'settings_saved', $this->settings_error_codes( 'photo_competition_settings' ) );
	}

	/**
	 * Removing a grade a member holds saves nothing and names the member.
	 */
	public function test_removing_a_held_grade_fails_and_names_the_member(): void {
		$this->create_member( 'Ann Advanced', 'advanced' );

		$rows             = $this->default_grade_rows();
		$rows[0]['label'] = 'Starter';
		unset( $rows[2] );
		$this->save_grades( array_values( $rows ) );

		$this->assertSame( array( 'Beginner', 'Intermediate', 'Advanced' ), wp_list_pluck( $this->saved_grades(), 'label' ) );

		$errors = get_settings_errors( 'photo_competition_settings' );
		$this->assertSame( array( 'grade_in_use' ), wp_list_pluck( $errors, 'code' ) );
		$this->assertStringContainsString( 'Advanced', $errors[0]['message'] );
		$this->assertStringContainsString( 'Ann Advanced', $errors[0]['message'] );
	}

	/**
	 * An inactive member also blocks removing their grade.
	 */
	public function test_inactive_member_blocks_removing_their_grade(): void {
		$this->create_member( 'Ian Inactive', 'advanced', false );

		$rows = $this->default_grade_rows();
		unset( $rows[2] );
		$this->save_grades( array_values( $rows ) );

		$this->assertCount( 3, $this->saved_grades() );
		$errors = get_settings_errors( 'photo_competition_settings' );
		$this->assertSame( array( 'grade_in_use' ), wp_list_pluck( $errors, 'code' ) );
		$this->assertStringContainsString( 'Ian Inactive', $errors[0]['message'] );
	}

	/**
	 * Two grades with the same label are rejected.
	 */
	public function test_duplicate_grade_labels_are_rejected(): void {
		$rows   = $this->default_grade_rows();
		$rows[] = array( 'label' => 'advanced' );
		$this->save_grades( $rows );

		$this->assertCount( 3, $this->saved_grades() );
		$this->assertSame( array( 'duplicate_grade' ), $this->settings_error_codes( 'photo_competition_settings' ) );
	}

	/**
	 * A renamed grade can't take another grade's label.
	 */
	public function test_renaming_to_another_grades_label_is_rejected(): void {
		$rows             = $this->default_grade_rows();
		$rows[0]['label'] = 'Advanced';
		$this->save_grades( $rows );

		$this->assertSame( 'Beginner', $this->saved_grades()[0]['label'] );
		$this->assertSame( array( 'duplicate_grade' ), $this->settings_error_codes( 'photo_competition_settings' ) );
	}
}
