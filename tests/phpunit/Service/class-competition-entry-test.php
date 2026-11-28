<?php
/**
 * Tests for Competition_Entry.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Entrants_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Service\Competition_Entry;
use PhotoCompetitionManager\Service\Competition_Workflow;
use PhotoCompetitionManager\Tests\Entry_Fixtures;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

/**
 * @covers \PhotoCompetitionManager\Service\Competition_Entry
 */
class Competition_Entry_Test extends WP_UnitTestCase {

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * Entrants repository.
	 *
	 * @var Entrants_Repository
	 */
	private $entrants;

	/**
	 * Service under test.
	 *
	 * @var Competition_Entry
	 */
	private $entry;

	/**
	 * Type IDs by name.
	 *
	 * @var array<string, int>
	 */
	private $types;

	public function set_up(): void {
		parent::set_up();

		Type_Fixtures::clear();
		$this->types = array(
			'Individual' => Type_Fixtures::create( 'Individual' ),
			'Team'       => Type_Fixtures::create( 'Team' ),
		);

		$this->competitions = new Competitions_Repository();
		$this->members      = new Members_Repository();
		$this->entrants     = new Entrants_Repository();
		$this->entry        = new Competition_Entry();
	}

	/**
	 * Create an open competition of a type, with an upload page and the Open category.
	 *
	 * @param string $type       Type name.
	 * @param string $entry_mode 'all' or 'selected'.
	 * @param bool   $interclub  Whether its entrants are clubs.
	 * @return object Competition row.
	 */
	private function competition( string $type = 'Individual', string $entry_mode = 'all', bool $interclub = false ): object {
		$id = $this->competitions->create(
			array(
				'type_id'    => $this->types[ $type ],
				'title'      => 'Spring Show',
				'slug'       => 'spring-show',
				'entry_mode' => $entry_mode,
				'settings'   => array(
					'categories' => array(
						array(
							'label' => 'Open',
							'slug'  => 'open',
							'quota' => 2,
						),
					),
					'urls'       => array( 'upload_page' => 'https://example.com/upload/' ),
					'interclub'  => array( 'enabled' => $interclub ),
				),
			)
		);
		$this->assertIsInt( $id );

		return $this->competitions->find( $id );
	}

	/**
	 * Reload a competition.
	 *
	 * @param object $competition Competition row.
	 * @return object
	 */
	private function reload( object $competition ): object {
		return $this->competitions->find( (int) $competition->id );
	}

	/**
	 * Create a member of the given type.
	 *
	 * @param string   $name   Name.
	 * @param string   $type   Type name.
	 * @param bool     $active Whether active.
	 * @param string   $grade  Grade slug.
	 * @return int Member ID.
	 */
	private function member( string $name, string $type = 'Individual', bool $active = true, string $grade = 'beginner' ): int {
		$id = $this->members->create(
			array(
				'name'     => $name,
				'email'    => sanitize_title( $name ) . '@example.com',
				'grade'    => $grade,
				'active'   => $active,
				'type_id'  => $this->types[ $type ],
			)
		);
		$this->assertIsInt( $id );

		return $id;
	}

	public function test_may_enter_open_to_all_requires_type_tag(): void {
		$competition = $this->competition( 'Individual' );
		$ann         = $this->member( 'Ann', 'Individual' );
		$bob         = $this->member( 'Bob', 'Team' );

		$this->assertTrue( $this->entry->may_enter( $competition, $this->members->find( $ann ) ) );
		$this->assertFalse( $this->entry->may_enter( $competition, $this->members->find( $bob ) ) );
		$this->assertSame( array( $ann ), array_map( 'intval', wp_list_pluck( $this->entry->eligible_members( $competition ), 'id' ) ) );
	}

	public function test_may_enter_selected_requires_entrant(): void {
		$competition = $this->competition( 'Individual', 'selected' );
		$ann         = $this->member( 'Ann', 'Individual' );
		$bob         = $this->member( 'Bob', 'Team' );
		$this->entrants->add( (int) $competition->id, $bob );

		$this->assertFalse( $this->entry->may_enter( $competition, $this->members->find( $ann ) ) );
		$this->assertTrue( $this->entry->may_enter( $competition, $this->members->find( $bob ) ) );
		$this->assertSame( array( $bob ), array_map( 'intval', wp_list_pluck( $this->entry->eligible_members( $competition ), 'id' ) ) );
	}

	/**
	 * A member whose grade the competition's type doesn't use can't enter it,
	 * whether it's open to the type or to selected entrants (#247).
	 */
	public function test_a_member_in_a_grade_the_type_does_not_use_may_not_enter(): void {
		Type_Fixtures::clear();
		$this->types = array(
			'Individual' => Type_Fixtures::create( 'Individual' ),
			'Team'       => Type_Fixtures::create( 'Team', array(), array( 'beginner' ) ),
		);
		$competition = $this->competition( 'Team' );
		$ann         = $this->member( 'Ann', 'Team', true, 'advanced' );
		$bob         = $this->member( 'Bob', 'Team', true, 'beginner' );

		$this->assertTrue( $this->entry->may_enter( $competition, $this->members->find( $ann ) ) );
		$this->assertFalse( $this->entry->may_enter( $competition, $this->members->find( $bob ) ) );
		$this->assertSame( array( $ann ), $this->entry->eligible_member_ids( $competition ) );

		$this->competitions->update( (int) $competition->id, array( 'entry_mode' => 'selected' ) );
		$selected = $this->reload( $competition );
		$this->entrants->add( (int) $selected->id, $ann );
		$this->entrants->add( (int) $selected->id, $bob );

		$this->assertFalse( $this->entry->may_enter( $selected, $this->members->find( $bob ) ) );
		$this->assertSame( array( $ann ), $this->entry->eligible_member_ids( $selected ) );
	}

	/**
	 * An admin can't enter a member whose grade the type leaves out, since
	 * they couldn't upload (#247).
	 */
	public function test_add_entrant_refuses_a_member_in_a_grade_the_type_does_not_use(): void {
		Type_Fixtures::clear();
		$this->types = array(
			'Individual' => Type_Fixtures::create( 'Individual' ),
			'Team'       => Type_Fixtures::create( 'Team', array(), array( 'beginner' ) ),
		);
		$competition = $this->competition( 'Team', 'selected' );
		$bob         = $this->member( 'Bob', 'Team', true, 'beginner' );

		$result = $this->entry->add_entrant( $competition, $bob );

		$this->assertWPError( $result );
		$this->assertSame( 'grade_not_used', $result->get_error_code() );
		$this->assertFalse( $this->entrants->has( (int) $competition->id, $bob ) );
	}

	public function test_inactive_member_may_not_enter(): void {
		$open     = $this->competition( 'Individual' );
		$inactive = $this->member( 'Cat', 'Individual', false );

		$this->assertFalse( $this->entry->may_enter( $open, $this->members->find( $inactive ) ) );
		$this->assertSame( array(), $this->entry->eligible_members( $open ) );

		$this->competitions->update( (int) $open->id, array( 'entry_mode' => 'selected' ) );
		$selected = $this->reload( $open );
		$this->entrants->add( (int) $selected->id, $inactive );

		$this->assertFalse( $this->entry->may_enter( $selected, $this->members->find( $inactive ) ) );
		$this->assertSame( array(), $this->entry->eligible_members( $selected ) );
	}

	public function test_add_entrant_while_uploads_open_sends_upload_link(): void {
		$competition = $this->competition( 'Individual', 'selected' );
		$bob         = $this->member( 'Bob', 'Individual' );
		reset_phpmailer_instance();

		$this->assertTrue( $this->entry->add_entrant( $competition, $bob ) );

		$this->assertTrue( $this->entrants->has( (int) $competition->id, $bob ) );
		$mailer = tests_retrieve_phpmailer_instance();
		$this->assertCount( 1, $mailer->mock_sent );
		$this->assertSame( 'bob@example.com', $mailer->mock_sent[0]['to'][0][0] );
		$this->assertStringContainsString( 'https://example.com/upload/', $mailer->mock_sent[0]['body'] );
	}

	public function test_add_entrant_while_uploads_closed_sends_nothing(): void {
		$competition = $this->competition( 'Individual', 'selected' );
		Workflow_Fixtures::close_uploads( (int) $competition->id );
		$bob = $this->member( 'Bob' );
		reset_phpmailer_instance();

		$this->assertTrue( $this->entry->add_entrant( $this->reload( $competition ), $bob ) );

		$this->assertTrue( $this->entrants->has( (int) $competition->id, $bob ) );
		$this->assertSame( array(), tests_retrieve_phpmailer_instance()->mock_sent );
	}

	/**
	 * A competition's entrants come from its type, so a member who isn't tagged
	 * with it can't be entered, whether or not its entrants are clubs (#246).
	 */
	public function test_add_entrant_enters_only_members_of_the_type(): void {
		$competition = $this->competition( 'Team', 'selected' );
		$member      = $this->member( 'Ann', 'Team' );
		$outsider    = $this->member( 'Bob', 'Individual' );

		$result = $this->entry->add_entrant( $competition, $outsider );

		$this->assertWPError( $result );
		$this->assertSame( 'not_in_type', $result->get_error_code() );
		$this->assertFalse( $this->entrants->has( (int) $competition->id, $outsider ) );
		$this->assertTrue( $this->entry->add_entrant( $competition, $member ) );
	}

	/**
	 * A member with no type isn't told they're in another type: the admin
	 * has to give them one, not change it.
	 */
	public function test_add_entrant_refuses_a_member_with_no_type_as_having_none(): void {
		$competition = $this->competition( 'Team', 'selected' );
		$member      = $this->member( 'Ann', 'Team' );
		$this->members->set_type( $member, null );

		$result = $this->entry->add_entrant( $competition, $member );

		$this->assertWPError( $result );
		$this->assertSame( 'no_type', $result->get_error_code() );
		$this->assertFalse( $this->entrants->has( (int) $competition->id, $member ) );
	}

	public function test_add_entrant_refused_when_open_to_all(): void {
		$competition = $this->competition( 'Individual', 'all' );
		$bob         = $this->member( 'Bob' );

		$result = $this->entry->add_entrant( $competition, $bob );

		$this->assertWPError( $result );
		$this->assertSame( 'not_selected_mode', $result->get_error_code() );
		$this->assertFalse( $this->entrants->has( (int) $competition->id, $bob ) );
	}

	public function test_add_entrant_after_voting_started_is_refused(): void {
		$competition = $this->competition( 'Individual', 'selected' );
		$ann         = $this->member( 'Ann' );
		$bob         = $this->member( 'Bob' );
		$this->entrants->add( (int) $competition->id, $ann );
		Entry_Fixtures::insert_entry( (int) $competition->id, 'open', $ann, array() );
		Workflow_Fixtures::set_stage( (int) $competition->id, 'open', Competition_Workflow::STAGE_VOTING );

		$result = $this->entry->add_entrant( $this->reload( $competition ), $bob );

		$this->assertWPError( $result );
		$this->assertSame( 'entry_closed', $result->get_error_code() );
		$this->assertSame( 'Voting has started, so who enters this competition can\'t change.', $result->get_error_message() );
		$this->assertFalse( $this->entrants->has( (int) $competition->id, $bob ) );
	}

	public function test_changes_after_the_competition_closed_say_it_has_closed(): void {
		$competition = $this->competition( 'Individual', 'selected' );
		$ann         = $this->member( 'Ann' );
		$bob         = $this->member( 'Bob' );
		$this->entrants->add( (int) $competition->id, $ann );
		$this->entrants->add( (int) $competition->id, $bob );
		Entry_Fixtures::insert_entry( (int) $competition->id, 'open', $ann, array() );
		Workflow_Fixtures::set_stage( (int) $competition->id, 'open', Competition_Workflow::STAGE_DONE );
		$this->competitions->update(
			(int) $competition->id,
			array(
				'open_date'  => gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ),
				'close_date' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
			)
		);

		$result = $this->entry->remove_entrant( $this->reload( $competition ), $bob );

		$this->assertWPError( $result );
		$this->assertSame( 'entry_closed', $result->get_error_code() );
		$this->assertSame( 'This competition has closed, so who enters it can\'t change.', $result->get_error_message() );
		$this->assertSame( $result->get_error_message(), $this->entry->change_refusal( $this->reload( $competition ) )->get_error_message() );
		$this->assertTrue( $this->entrants->has( (int) $competition->id, $bob ) );
	}

	public function test_add_entrant_while_previewed_is_allowed(): void {
		$competition = $this->competition( 'Individual', 'selected' );
		Workflow_Fixtures::set_stage( (int) $competition->id, 'open', Competition_Workflow::STAGE_PREVIEWED );
		$bob = $this->member( 'Bob' );

		$this->assertTrue( $this->entry->add_entrant( $this->reload( $competition ), $bob ) );
	}

	public function test_remove_entrant_with_entries_is_refused(): void {
		$competition = $this->competition( 'Individual', 'selected' );
		$ann         = $this->member( 'Ann' );
		$this->entrants->add( (int) $competition->id, $ann );
		Entry_Fixtures::insert_entry( (int) $competition->id, 'open', $ann, array() );

		$result = $this->entry->remove_entrant( $competition, $ann );

		$this->assertWPError( $result );
		$this->assertSame( 'entrant_has_entries', $result->get_error_code() );
		$this->assertTrue( $this->entrants->has( (int) $competition->id, $ann ) );
	}

	public function test_remove_entrant_after_voting_started_is_refused(): void {
		$competition = $this->competition( 'Individual', 'selected' );
		$ann         = $this->member( 'Ann' );
		$bob         = $this->member( 'Bob' );
		$this->entrants->add( (int) $competition->id, $ann );
		$this->entrants->add( (int) $competition->id, $bob );
		Entry_Fixtures::insert_entry( (int) $competition->id, 'open', $ann, array() );
		Workflow_Fixtures::set_stage( (int) $competition->id, 'open', Competition_Workflow::STAGE_VOTING );

		$result = $this->entry->remove_entrant( $this->reload( $competition ), $bob );

		$this->assertWPError( $result );
		$this->assertSame( 'entry_closed', $result->get_error_code() );
		$this->assertTrue( $this->entrants->has( (int) $competition->id, $bob ) );
	}

	public function test_remove_entrant_revokes_upload_token(): void {
		$competition = $this->competition( 'Individual', 'selected' );
		$ann         = $this->member( 'Ann' );
		$bob         = $this->member( 'Bob' );
		$this->entrants->add( (int) $competition->id, $ann );
		$this->entrants->add( (int) $competition->id, $bob );
		$tokens    = new Upload_Token_Repository();
		$ann_token = $tokens->find_or_create( $ann, (int) $competition->id );
		$bob_token = $tokens->find_or_create( $bob, (int) $competition->id );

		$this->assertTrue( $this->entry->remove_entrant( $competition, $ann ) );

		$this->assertFalse( $this->entrants->has( (int) $competition->id, $ann ) );
		$this->assertNull( $tokens->find_valid_token( $ann_token->token ) );
		$this->assertNotNull( $tokens->find_valid_token( $bob_token->token ) );
	}

	public function test_switching_to_selected_keeps_members_with_entries(): void {
		$competition = $this->competition( 'Individual', 'all' );
		$ann         = $this->member( 'Ann' );
		$this->member( 'Bob' );
		Entry_Fixtures::insert_entry( (int) $competition->id, 'open', $ann, array() );
		Entry_Fixtures::insert_entry( (int) $competition->id, 'open', $ann, array() );

		$this->assertTrue( $this->entry->set_mode( $competition, 'selected' ) );

		$this->assertSame( 'selected', $this->reload( $competition )->entry_mode );
		$this->assertSame( array( $ann ), $this->entrants->member_ids( (int) $competition->id ) );
	}

	public function test_set_mode_after_voting_started_is_refused(): void {
		$competition = $this->competition( 'Individual', 'all' );
		$ann         = $this->member( 'Ann' );
		Entry_Fixtures::insert_entry( (int) $competition->id, 'open', $ann, array() );
		Workflow_Fixtures::set_stage( (int) $competition->id, 'open', Competition_Workflow::STAGE_VOTING );

		$result = $this->entry->set_mode( $this->reload( $competition ), 'selected' );

		$this->assertWPError( $result );
		$this->assertSame( 'entry_closed', $result->get_error_code() );
		$this->assertSame( 'all', $this->reload( $competition )->entry_mode );
	}

	/*
	 * -----------------------------------------------------------------
	 * Entry fee (#315).
	 * -----------------------------------------------------------------
	 */

	/**
	 * Set a competition's fee and return it reloaded.
	 *
	 * @param object $competition  Competition row.
	 * @param string $fee          Fee.
	 * @param string $instructions Payment instructions.
	 * @return object
	 */
	private function with_fee( object $competition, string $fee = '€20', string $instructions = 'Pay by PayPal.' ): object {
		$this->assertTrue( $this->entry->set_fee( $competition, $fee, $instructions ) );

		return $this->reload( $competition );
	}

	public function test_a_selected_entrants_competition_keeps_its_fee_and_payment_instructions(): void {
		$competition = $this->with_fee( $this->competition( 'Individual', 'selected' ), '€20 per club', 'Pay at <a href="https://paypal.me/club">paypal.me/club</a>.' );

		$this->assertTrue( Competition_Entry::has_fee( $competition ) );
		$this->assertSame( '€20 per club', $competition->entry_fee );
		$this->assertSame( 'Pay at <a href="https://paypal.me/club">paypal.me/club</a>.', $competition->payment_instructions );
	}

	public function test_payment_instructions_lose_scripts(): void {
		$competition = $this->with_fee( $this->competition( 'Individual', 'selected' ), '€20', 'Pay now<script>alert(1)</script>' );

		$this->assertStringNotContainsString( '<script', $competition->payment_instructions );
	}

	public function test_a_competition_open_to_everyone_takes_no_fee(): void {
		$competition = $this->competition( 'Individual', 'all' );

		$result = $this->entry->set_fee( $competition, '€20', 'Pay by PayPal.' );

		$this->assertWPError( $result );
		$this->assertSame( 'not_selected_mode', $result->get_error_code() );
		$this->assertFalse( Competition_Entry::has_fee( $this->reload( $competition ) ) );
	}

	public function test_a_fee_stops_counting_when_the_competition_opens_to_everyone(): void {
		$competition = $this->with_fee( $this->competition( 'Individual', 'selected' ) );

		$this->assertTrue( $this->entry->set_mode( $competition, 'all' ) );

		$this->assertFalse( Competition_Entry::has_fee( $this->reload( $competition ) ) );
	}

	public function test_an_entrant_added_by_an_admin_starts_paid(): void {
		$competition = $this->with_fee( $this->competition( 'Individual', 'selected' ) );
		$ann         = $this->member( 'Ann' );

		$this->assertTrue( $this->entry->add_entrant( $competition, $ann ) );

		$this->assertSame( array(), $this->entry->unpaid_member_ids( $competition ) );
	}

	public function test_an_admin_can_mark_an_entrant_unpaid_and_paid_again(): void {
		$competition = $this->with_fee( $this->competition( 'Individual', 'selected' ) );
		$ann         = $this->member( 'Ann' );
		$bob         = $this->member( 'Bob' );
		$this->entrants->add( (int) $competition->id, $ann );
		$this->entrants->add( (int) $competition->id, $bob );

		$this->assertTrue( $this->entry->set_paid( $competition, $ann, false ) );
		$this->assertSame( array( $ann ), $this->entry->unpaid_member_ids( $competition ) );

		$this->assertTrue( $this->entry->set_paid( $competition, $ann, true ) );
		$this->assertSame( array(), $this->entry->unpaid_member_ids( $competition ) );
	}

	/**
	 * Payment is often checked after the competition night, so paid status
	 * still changes once voting has started.
	 */
	public function test_paid_status_changes_after_voting_started(): void {
		$competition = $this->with_fee( $this->competition( 'Individual', 'selected' ) );
		$ann         = $this->member( 'Ann' );
		$this->entrants->add( (int) $competition->id, $ann );
		Entry_Fixtures::insert_entry( (int) $competition->id, 'open', $ann, array() );
		Workflow_Fixtures::set_stage( (int) $competition->id, 'open', Competition_Workflow::STAGE_VOTING );

		$this->assertTrue( $this->entry->set_paid( $this->reload( $competition ), $ann, false ) );
		$this->assertSame( array( $ann ), $this->entry->unpaid_member_ids( $competition ) );
	}

	public function test_paid_status_is_refused_without_a_fee(): void {
		$competition = $this->competition( 'Individual', 'selected' );
		$ann         = $this->member( 'Ann' );
		$this->entrants->add( (int) $competition->id, $ann );

		$result = $this->entry->set_paid( $competition, $ann, false );

		$this->assertWPError( $result );
		$this->assertSame( 'no_entry_fee', $result->get_error_code() );
	}

	public function test_paid_status_is_refused_for_someone_who_is_not_an_entrant(): void {
		$competition = $this->with_fee( $this->competition( 'Individual', 'selected' ) );
		$ann         = $this->member( 'Ann' );

		$result = $this->entry->set_paid( $competition, $ann, false );

		$this->assertWPError( $result );
		$this->assertSame( 'not_an_entrant', $result->get_error_code() );
	}

	public function test_without_a_fee_nobody_is_unpaid(): void {
		$competition = $this->with_fee( $this->competition( 'Individual', 'selected' ) );
		$ann         = $this->member( 'Ann' );
		$this->entrants->add( (int) $competition->id, $ann );
		$this->entry->set_paid( $competition, $ann, false );

		$competition = $this->with_fee( $competition, '', '' );

		$this->assertFalse( Competition_Entry::has_fee( $competition ) );
		$this->assertSame( array(), $this->entry->unpaid_member_ids( $competition ) );
	}

	public function test_putting_a_cleared_fee_back_brings_back_who_had_not_paid(): void {
		$competition = $this->with_fee( $this->competition( 'Individual', 'selected' ) );
		$ann         = $this->member( 'Ann' );
		$this->entrants->add( (int) $competition->id, $ann );
		$this->entry->set_paid( $competition, $ann, false );

		$this->with_fee( $competition, '', '' );
		$competition = $this->with_fee( $competition );

		$this->assertSame( array( $ann ), $this->entry->unpaid_member_ids( $competition ) );
	}

	public function test_an_unpaid_entrant_may_still_enter(): void {
		$competition = $this->with_fee( $this->competition( 'Individual', 'selected' ) );
		$ann         = $this->member( 'Ann' );
		$this->entrants->add( (int) $competition->id, $ann );
		$this->entry->set_paid( $competition, $ann, false );

		$this->assertTrue( $this->entry->may_enter( $competition, $this->members->find( $ann ) ) );
	}
}
