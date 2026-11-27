<?php
/**
 * Tests for Registration: people and clubs making themselves entrants.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competition_Types_Repository;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Entrants_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Registrations_Repository;
use PhotoCompetitionManager\Service\Registration;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use PhotoCompetitionManager\Tests\Workflow_Fixtures;
use WP_UnitTestCase;

/**
 * Registration is open for selected-entrants competitions with the switch on;
 * a registration counts once its emailed link is confirmed.
 *
 * @covers \PhotoCompetitionManager\Service\Registration
 */
class Registration_Test extends WP_UnitTestCase {

	const PAGE = 'https://example.com/register/';

	/**
	 * @var Competitions_Repository
	 */
	private $competitions;

	/**
	 * @var Members_Repository
	 */
	private $members;

	/**
	 * @var Entrants_Repository
	 */
	private $entrants;

	/**
	 * Type IDs by name.
	 *
	 * @var array<string, int>
	 */
	private $types;

	/**
	 * Emails sent during the test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function set_up(): void {
		parent::set_up();

		Type_Fixtures::clear();
		$this->types = array(
			'Individual' => Type_Fixtures::create( 'Individual', array(), array( 'advanced' ) ),
			'Interclub'  => (int) ( new Competition_Types_Repository() )->create(
				'Interclub',
				array(
					'categories' => array(
						array(
							'label' => 'Open',
							'slug'  => 'open',
							'quota' => 2,
						),
					),
					'interclub'  => array( 'enabled' => true ),
				)
			),
		);

		$this->competitions = new Competitions_Repository();
		$this->members      = new Members_Repository();
		$this->entrants     = new Entrants_Repository();

		add_filter( 'wp_mail', array( $this, 'capture_mail' ) );
	}

	public function tear_down(): void {
		remove_filter( 'wp_mail', array( $this, 'capture_mail' ) );
		parent::tear_down();
	}

	/**
	 * Record an email.
	 *
	 * @param array<string, mixed> $atts wp_mail() arguments.
	 * @return array<string, mixed>
	 */
	public function capture_mail( array $atts ): array {
		$this->mails[] = $atts;

		return $atts;
	}

	/**
	 * Create a competition.
	 *
	 * @param array<string, mixed> $overrides Fields to change: open/close as day offsets, type, interclub, and repository fields.
	 * @return object Competition row.
	 */
	private function competition( array $overrides = array() ): object {
		static $count = 0;
		++$count;

		$overrides += array(
			'open'      => -1,
			'close'     => 10,
			'type'      => 'Individual',
			'interclub' => false,
		);

		$id = $this->competitions->create(
			array_diff_key( $overrides, array_flip( array( 'open', 'close', 'type', 'interclub' ) ) ) + array(
				'type_id'           => $this->types[ $overrides['type'] ],
				'title'             => 'Show ' . $count,
				'open_date'         => gmdate( 'Y-m-d H:i:s', time() + $overrides['open'] * DAY_IN_SECONDS ),
				'close_date'        => gmdate( 'Y-m-d H:i:s', time() + $overrides['close'] * DAY_IN_SECONDS ),
				'entry_mode'        => 'selected',
				'registration_open' => true,
				'settings'          => array(
					'categories' => array(
						array(
							'label' => 'Open',
							'slug'  => 'open',
							'quota' => 2,
						),
					),
					'urls'       => array( 'upload_page' => 'https://example.com/upload/' ),
					'interclub'  => array( 'enabled' => $overrides['interclub'] ),
				),
			)
		);
		$this->assertIsInt( $id );

		return $this->competitions->find( $id );
	}

	/**
	 * Create a member.
	 *
	 * @param string   $email  Email.
	 * @param string[] $types  Type names.
	 * @param string   $grade  Grade slug.
	 * @param bool     $active Whether active.
	 * @return int Member ID.
	 */
	private function member( string $email, array $types = array( 'Individual' ), string $grade = 'beginner', bool $active = true ): int {
		$id = $this->members->create(
			array(
				'name'     => 'Ann On File',
				'email'    => $email,
				'grade'    => $grade,
				'active'   => $active,
				'type_ids' => array_map(
					function ( $type ) {
						return $this->types[ $type ];
					},
					$types
				),
			)
		);
		$this->assertIsInt( $id );

		return $id;
	}

	/**
	 * Register, returning the key from the emailed link.
	 *
	 * @param object        $competition Competition row.
	 * @param string        $email       Email typed in.
	 * @param string        $grade       Grade chosen.
	 * @param callable|null $clock       Clock for the request.
	 * @return string Key, or '' when no link was emailed.
	 */
	private function register( object $competition, string $email = 'new@example.com', string $grade = 'beginner', ?callable $clock = null ): string {
		$before = count( $this->mails );
		$this->assertTrue( ( new Registration( $clock ) )->request( (int) $competition->id, 'New Person', $email, $grade, self::PAGE ) );

		$sent = array_slice( $this->mails, $before );
		if ( ! $sent ) {
			return '';
		}

		preg_match( '#' . preg_quote( self::PAGE, '#' ) . '[^"\s<]*#', (string) $sent[0]['message'], $match );
		parse_str( (string) wp_parse_url( html_entity_decode( $match[0] ?? '' ), PHP_URL_QUERY ), $args );

		return (string) ( $args[ Registration::QUERY_ARG ] ?? '' );
	}

	/**
	 * Emails sent to an address.
	 *
	 * @param string $to Address.
	 * @return array<int, array<string, mixed>>
	 */
	private function mails_to( string $to ): array {
		return array_values(
			array_filter(
				$this->mails,
				function ( $mail ) use ( $to ) {
					return $to === $mail['to'];
				}
			)
		);
	}

	public function test_only_competitions_open_for_registration_are_listed(): void {
		$open       = $this->competition();
		$scheduled  = $this->competition(
			array(
				'open'  => 20,
				'close' => 30,
			)
		);
		$everyone   = $this->competition(
			array(
				'open'       => 40,
				'close'      => 50,
				'entry_mode' => 'all',
			)
		);
		$switch_off = $this->competition(
			array(
				'open'              => 60,
				'close'             => 70,
				'registration_open' => false,
			)
		);
		$past_close = $this->competition(
			array(
				'open'                => 80,
				'close'               => 90,
				'registration_closes' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			)
		);

		$listed = wp_list_pluck( ( new Registration() )->open_competitions(), 'id' );

		$this->assertEqualsCanonicalizing( array( $open->id, $scheduled->id ), $listed );
		$this->assertNotContains( $everyone->id, $listed );
		$this->assertNotContains( $switch_off->id, $listed );
		$this->assertNotContains( $past_close->id, $listed );

		Workflow_Fixtures::close_uploads( (int) $open->id );
		$this->assertSame( array( $scheduled->id ), wp_list_pluck( ( new Registration() )->open_competitions(), 'id' ), 'closing uploads closes registration' );
	}

	public function test_registering_creates_nothing_until_the_emailed_link_is_confirmed(): void {
		$competition = $this->competition();

		$key = $this->register( $competition );

		$this->assertNotSame( '', $key );
		$this->assertCount( 1, $this->mails_to( 'new@example.com' ) );
		$this->assertNull( $this->members->find_by_email( 'new@example.com' ) );
		$this->assertSame( array(), $this->entrants->member_ids( (int) $competition->id ) );
	}

	public function test_a_new_address_a_member_and_a_deactivated_member_get_the_same_response(): void {
		$competition = $this->competition();
		$this->member( 'member@example.com' );
		$this->member( 'gone@example.com', array( 'Individual' ), 'beginner', false );

		// register() asserts the request returns true for each.
		foreach ( array( 'new@example.com', 'member@example.com', 'gone@example.com' ) as $email ) {
			$this->assertNotSame( '', $this->register( $competition, $email ), $email );
		}
	}

	public function test_a_blank_name_a_bad_address_or_a_grade_the_type_does_not_use_is_refused_on_the_form(): void {
		$competition  = $this->competition();
		$registration = new Registration();

		$this->assertSame( 'invalid_name', $registration->request( (int) $competition->id, ' ', 'new@example.com', 'beginner', self::PAGE )->get_error_code() );
		$this->assertSame( 'invalid_email', $registration->request( (int) $competition->id, 'New', 'not an address', 'beginner', self::PAGE )->get_error_code() );
		$this->assertSame( 'invalid_grade', $registration->request( (int) $competition->id, 'New', 'new@example.com', 'advanced', self::PAGE )->get_error_code() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_registering_for_a_competition_not_open_for_registration_is_refused(): void {
		$competition = $this->competition( array( 'registration_open' => false ) );

		$result = ( new Registration() )->request( (int) $competition->id, 'New', 'new@example.com', 'beginner', self::PAGE );

		$this->assertSame( 'registration_closed', $result->get_error_code() );
		$this->assertSame( array(), $this->mails );
	}

	public function test_confirming_as_a_new_address_creates_a_member_tagged_with_the_type_and_an_unpaid_entrant(): void {
		$competition = $this->competition( array( 'entry_fee' => '€20' ) );
		$key         = $this->register( $competition, 'new@example.com', 'intermediate' );

		$result = ( new Registration() )->confirm( $key );

		$this->assertSame( 'registered', $result['status'] );
		$member = $this->members->find_by_email( 'new@example.com' );
		$this->assertSame( 'New Person', $member->name );
		$this->assertSame( 'intermediate', $member->grade );
		$this->assertSame( array( $this->types['Individual'] ), $this->members->type_ids( (int) $member->id ) );
		$this->assertSame( array( (int) $member->id ), $this->entrants->member_ids( (int) $competition->id ) );
		$this->assertSame( array( (int) $member->id ), $this->entrants->unpaid_member_ids( (int) $competition->id ) );
		$this->assertSame( array( (int) $member->id ), $this->entrants->registered_member_ids( (int) $competition->id ) );
	}

	public function test_confirming_as_a_member_enters_and_tags_them_without_changing_their_record(): void {
		$competition = $this->competition();
		$member_id   = $this->member( 'ann@example.com', array(), 'beginner' );
		$key         = $this->register( $competition, 'ann@example.com', 'intermediate' );

		$result = ( new Registration() )->confirm( $key );

		$this->assertSame( 'registered', $result['status'] );
		$member = $this->members->find( $member_id );
		$this->assertSame( 'Ann On File', $member->name );
		$this->assertSame( 'beginner', $member->grade );
		$this->assertSame( array( $this->types['Individual'] ), $this->members->type_ids( $member_id ) );
		$this->assertSame( array( $member_id ), $this->entrants->member_ids( (int) $competition->id ) );
	}

	public function test_confirming_when_already_an_entrant_changes_nothing_and_says_so(): void {
		$competition = $this->competition( array( 'entry_fee' => '€20' ) );
		$member_id   = $this->member( 'ann@example.com' );
		$this->entrants->add( (int) $competition->id, $member_id );
		$key = $this->register( $competition, 'ann@example.com' );

		$result = ( new Registration() )->confirm( $key );

		$this->assertSame( 'already_entrant', $result['status'] );
		$this->assertSame( array(), $this->entrants->unpaid_member_ids( (int) $competition->id ), 'still paid' );
		$this->assertSame( array(), $this->entrants->registered_member_ids( (int) $competition->id ), 'still added by admin' );
	}

	/**
	 * Confirm a registration that should be refused, and check nothing changed.
	 *
	 * @param object $competition Competition row.
	 * @param string $key         Key from the link.
	 * @param string $reason      Text the refusal should contain.
	 * @return void
	 */
	private function assert_refused( object $competition, string $key, string $reason ): void {
		$members_before = count( $this->members->all( 1000, false ) );

		$result = ( new Registration() )->confirm( $key );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertStringContainsString( $reason, $result['message'] );
		$this->assertSame( array(), $this->entrants->member_ids( (int) $competition->id ) );
		$this->assertCount( $members_before, $this->members->all( 1000, false ) );
	}

	public function test_a_deactivated_member_is_refused(): void {
		$competition = $this->competition();
		$this->member( 'gone@example.com', array( 'Individual' ), 'beginner', false );

		$this->assert_refused( $competition, $this->register( $competition, 'gone@example.com' ), 'deactivated' );
	}

	public function test_a_member_in_a_grade_the_type_does_not_use_is_refused(): void {
		$competition = $this->competition();
		$this->member( 'ann@example.com', array( 'Individual' ), 'advanced' );

		$this->assert_refused( $competition, $this->register( $competition, 'ann@example.com' ), 'grade' );
	}

	public function test_a_person_registering_for_an_interclub_competition_is_refused(): void {
		$competition = $this->competition(
			array(
				'type'      => 'Interclub',
				'interclub' => true,
			)
		);
		$this->member( 'ann@example.com', array( 'Individual' ) );

		$this->assert_refused( $competition, $this->register( $competition, 'ann@example.com' ), 'club' );
		$this->assertSame( array( $this->types['Individual'] ), $this->members->type_ids( (int) $this->members->find_by_email( 'ann@example.com' )->id ), 'no tag added' );
	}

	public function test_a_club_registering_for_a_competition_between_people_is_refused(): void {
		$competition = $this->competition();
		$this->member( 'club@example.com', array( 'Interclub' ) );

		$this->assert_refused( $competition, $this->register( $competition, 'club@example.com' ), 'club' );
	}

	public function test_a_link_confirmed_after_registration_closed_is_refused(): void {
		$competition = $this->competition();
		$key         = $this->register( $competition );
		$this->competitions->update( (int) $competition->id, array( 'registration_open' => false ) );

		$this->assert_refused( $competition, $key, 'closed' );
	}

	public function test_a_link_works_once(): void {
		$competition = $this->competition();
		$key         = $this->register( $competition );
		( new Registration() )->confirm( $key );
		$this->entrants->remove( (int) $competition->id, (int) $this->members->find_by_email( 'new@example.com' )->id );

		$this->assertSame( 'invalid', ( new Registration() )->confirm( $key )['status'] );
		$this->assertSame( array(), $this->entrants->member_ids( (int) $competition->id ) );
	}

	public function test_a_link_older_than_seven_days_does_nothing(): void {
		$competition = $this->competition();
		$key         = $this->register(
			$competition,
			'new@example.com',
			'beginner',
			static function (): int {
				return time() - 7 * DAY_IN_SECONDS - 60;
			}
		);

		$this->assertSame( 'invalid', ( new Registration() )->confirm( $key )['status'] );
		$this->assertNull( $this->members->find_by_email( 'new@example.com' ) );
		$this->assertNull( ( new Registration() )->pending( $key ) );
	}

	public function test_a_link_used_after_the_registration_close_date_says_registration_has_closed(): void {
		$competition = $this->competition( array( 'registration_closes' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		$key         = $this->register( $competition );
		$later       = static function (): int {
			return time() + 2 * DAY_IN_SECONDS;
		};

		$result = ( new Registration( $later ) )->confirm( $key );

		$this->assertSame( 'refused', $result['status'] );
		$this->assertStringContainsString( 'closed', $result['message'] );
		$this->assertNull( $this->members->find_by_email( 'new@example.com' ) );
	}

	public function test_asking_again_from_the_same_address_soon_after_sends_nothing(): void {
		$competition = $this->competition();
		$this->register( $competition, 'new@example.com' );

		$this->assertSame( '', $this->register( $competition, 'NEW@example.com' ) );
		$this->assertCount( 1, $this->mails );
	}

	public function test_the_entrant_is_emailed_the_fee_how_to_pay_and_their_upload_link(): void {
		$competition = $this->competition(
			array(
				'entry_fee'            => '€20',
				'payment_instructions' => 'Pay by PayPal to treasurer@example.com',
			)
		);
		$key         = $this->register( $competition );
		$this->mails = array();

		( new Registration() )->confirm( $key );

		$confirmed = $this->mails_to( 'new@example.com' );
		$this->assertCount( 1, $confirmed );
		$this->assertStringContainsString( '€20', $confirmed[0]['message'] );
		$this->assertStringContainsString( 'Pay by PayPal to treasurer@example.com', $confirmed[0]['message'] );
		$this->assertStringContainsString( 'https://example.com/upload/', $confirmed[0]['message'] );
	}

	public function test_without_a_fee_or_open_uploads_the_entrant_email_has_neither(): void {
		$competition = $this->competition(
			array(
				'open'  => 20,
				'close' => 30,
			)
		);
		$key         = $this->register( $competition );
		$this->mails = array();

		( new Registration() )->confirm( $key );

		$confirmed = $this->mails_to( 'new@example.com' );
		$this->assertCount( 1, $confirmed );
		$this->assertStringNotContainsString( 'fee', $confirmed[0]['message'] );
		$this->assertStringNotContainsString( 'https://example.com/upload/', $confirmed[0]['message'] );
	}

	/**
	 * Save the club's email settings.
	 *
	 * @param array<string, string> $email Email settings.
	 * @return void
	 */
	private function email_settings( array $email ): void {
		update_option( 'photo_comp_default_settings', wp_json_encode( array( 'email' => $email ) ) );
	}

	public function test_the_organiser_is_told_of_each_confirmed_registration(): void {
		$this->email_settings(
			array(
				'from_email'      => 'club@example.com',
				'organiser_email' => 'organiser@example.com',
			)
		);
		$competition = $this->competition( array( 'entry_fee' => '€20' ) );
		$key         = $this->register( $competition, 'new@example.com', 'intermediate' );

		( new Registration() )->confirm( $key );

		$told = $this->mails_to( 'organiser@example.com' );
		$this->assertCount( 1, $told );
		$this->assertStringContainsString( 'New Person', $told[0]['message'] );
		$this->assertStringContainsString( 'new@example.com', $told[0]['message'] );
		$this->assertStringContainsString( 'Unpaid', $told[0]['message'] );
		$this->assertSame( array(), $this->mails_to( 'club@example.com' ) );
	}

	public function test_without_an_organiser_email_the_from_address_is_told(): void {
		$this->email_settings( array( 'from_email' => 'club@example.com' ) );
		$key = $this->register( $this->competition() );

		( new Registration() )->confirm( $key );

		$this->assertCount( 1, $this->mails_to( 'club@example.com' ) );
	}

	public function test_nobody_is_told_when_the_notification_is_switched_off(): void {
		$this->email_settings( array( 'organiser_email' => 'organiser@example.com' ) );
		update_option( 'photo_comp_email_templates', array( 'registration_received' => array( 'enabled' => false ) ) );
		$key = $this->register( $this->competition() );

		( new Registration() )->confirm( $key );

		$this->assertSame( array(), $this->mails_to( 'organiser@example.com' ) );
		$this->assertNotNull( $this->members->find_by_email( 'new@example.com' ), 'still registered' );
	}

	public function test_expired_registrations_are_deleted(): void {
		$competition = $this->competition();
		$old         = $this->register(
			$competition,
			'old@example.com',
			'beginner',
			static function (): int {
				return time() - 8 * DAY_IN_SECONDS;
			}
		);
		$this->register( $competition, 'new@example.com' );

		( new Registration() )->delete_expired();

		$this->assertSame( array(), ( new Registrations_Repository() )->find_by_email( 'old@example.com' ) );
		$this->assertCount( 1, ( new Registrations_Repository() )->find_by_email( 'new@example.com' ) );
		$this->assertNotSame( '', $old );
	}

	public function test_a_pending_registration_is_exported_and_erased_by_email(): void {
		$this->register( $this->competition(), 'new@example.com' );
		$registration = new Registration();

		$export = $registration->export( 'new@example.com' );
		$values = wp_list_pluck( $export['data'][0]['data'], 'value' );
		$this->assertContains( 'New Person', $values );
		$this->assertContains( 'new@example.com', $values );

		$erased = $registration->erase( 'new@example.com' );
		$this->assertTrue( $erased['items_removed'] );
		$this->assertSame( array(), $registration->export( 'new@example.com' )['data'] );
	}
}
