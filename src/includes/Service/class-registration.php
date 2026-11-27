<?php
/**
 * Registration: people and clubs making themselves entrants.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competition_Types_Repository;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Entrants_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Registrations_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use PhotoCompetitionManager\Support\Email_Configuration;
use WP_Error;

/**
 * A competition for selected entrants can take registrations. Someone types
 * their name, email address and grade into the form and is emailed a link;
 * nothing else happens until they confirm from it. Confirming enters them,
 * creating a member for a new address. One address is one member, so a
 * person's address can't register a club, nor a club's a person.
 *
 * Why a registration is refused is only ever said on the page the emailed
 * link opens, so the form says nothing about who is a member.
 *
 * @since 0.6.0
 */
class Registration {

	/**
	 * Query argument the key travels in.
	 */
	const QUERY_ARG = 'registration_key';

	/**
	 * How long a link works, unless registration closes first.
	 */
	const LIFETIME = 7 * DAY_IN_SECONDS;

	/**
	 * How long an address waits before it can register for the same competition again.
	 */
	const ADDRESS_WAIT = 5 * MINUTE_IN_SECONDS;

	/**
	 * Daily cron event deleting expired registrations.
	 */
	const CLEANUP_HOOK = 'photo_comp_registration_cleanup';

	/**
	 * Current Unix time.
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Registrations waiting to be confirmed.
	 *
	 * @var Registrations_Repository
	 */
	private $registrations;

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
	 * Competition workflow.
	 *
	 * @var Competition_Workflow
	 */
	private $workflow;

	/**
	 * Who may enter.
	 *
	 * @var Competition_Entry
	 */
	private $entry;

	/**
	 * Competition labels, for grade names.
	 *
	 * @var Competition_Labels
	 */
	private $labels;

	/**
	 * Email service.
	 *
	 * @var Email_Service
	 */
	private $email;

	/**
	 * Competition types repository.
	 *
	 * @var Competition_Types_Repository
	 */
	private $types;

	/**
	 * Constructor.
	 *
	 * @param callable|null $clock Returns the current Unix time. Defaults to time().
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock         = $clock ? $clock : 'time';
		$this->registrations = new Registrations_Repository();
		$this->competitions  = new Competitions_Repository();
		$this->members       = new Members_Repository();
		$this->entrants      = new Entrants_Repository();
		$this->workflow      = new Competition_Workflow( $this->competitions );
		$this->entry         = new Competition_Entry( $this->competitions, $this->members );
		$this->labels        = new Competition_Labels();
		$this->email         = new Email_Service();
		$this->types         = new Competition_Types_Repository();
	}

	/**
	 * Whether a competition is open for registration: it's for selected
	 * entrants, takes registrations, is scheduled or accepting uploads, its
	 * entrants can still change, and its registration close date, if any,
	 * hasn't come.
	 *
	 * @param object $competition Competition row.
	 * @return bool
	 */
	public function is_open( object $competition ): bool {
		if ( ! Competition_Entry::is_selected( $competition ) || empty( $competition->registration_open ) ) {
			return false;
		}

		$closes = (string) ( $competition->registration_closes ?? '' );
		if ( '' !== $closes && $closes <= $this->now() ) {
			return false;
		}

		return in_array( $this->workflow->phase( $competition ), array( Competition_Workflow::PHASE_SCHEDULED, Competition_Workflow::PHASE_ACCEPTING_UPLOADS ), true )
			&& null === $this->entry->change_refusal( $competition );
	}

	/**
	 * The competitions open for registration, the soonest to open first.
	 *
	 * @return object[] Competition rows.
	 */
	public function open_competitions(): array {
		$open = array_values( array_filter( $this->competitions->all_unclosed(), array( $this, 'is_open' ) ) );
		usort(
			$open,
			function ( $a, $b ) {
				return strcmp( (string) $a->open_date, (string) $b->open_date );
			}
		);

		return $open;
	}

	/**
	 * The grades someone can register in: those the competition's type
	 * uses, with the type's names for them.
	 *
	 * @param object $competition Competition row.
	 * @return array<string, string> Grade slug => name.
	 */
	public function grade_choices( object $competition ): array {
		return array_intersect_key( $this->labels->grade_labels( $competition ), array_flip( $this->labels->used_grades( $competition ) ) );
	}

	/**
	 * Turn registration on or off, with the day it closes, or none.
	 *
	 * @param object      $competition Competition row.
	 * @param bool        $open        Whether it takes registrations.
	 * @param string|null $closes      When registration closes (UTC), or null for when uploads close.
	 * @return true|WP_Error 'not_selected_mode', or the update's error.
	 */
	public function set_registration( object $competition, bool $open, ?string $closes ) {
		if ( ! Competition_Entry::is_selected( $competition ) ) {
			return new WP_Error( 'not_selected_mode', __( 'Only a competition for selected entrants can take registrations.', 'photo-competition-manager' ) );
		}

		$updated = $this->competitions->update(
			(int) $competition->id,
			array(
				'registration_open'   => $open,
				'registration_closes' => $closes,
			)
		);

		return is_wp_error( $updated ) ? $updated : true;
	}

	/**
	 * Register for a competition: email a link to confirm the registration.
	 *
	 * A form that can't be right is refused, saying why. Otherwise the
	 * answer is always the same, whatever the address: who is a member,
	 * and why they couldn't enter, is only said to the address's owner.
	 * An address can register for a competition once every few minutes.
	 *
	 * @param int    $competition_id Competition ID.
	 * @param string $name           Name typed in.
	 * @param string $email          Email address typed in.
	 * @param string $grade          Grade chosen.
	 * @param string $page_url       URL of the registration page.
	 * @return true|WP_Error 'registration_closed', 'invalid_name', 'invalid_email' or 'invalid_grade'.
	 */
	public function request( int $competition_id, string $name, string $email, string $grade, string $page_url ) {
		$name        = mb_substr( sanitize_text_field( $name ), 0, 191 );
		$email       = strtolower( sanitize_email( $email ) );
		$competition = $this->competitions->find( $competition_id );

		if ( ! $competition || ! $this->is_open( $competition ) ) {
			return new WP_Error( 'registration_closed', __( 'Registration for this competition has closed.', 'photo-competition-manager' ) );
		}

		if ( '' === $name ) {
			return new WP_Error( 'invalid_name', __( 'Enter a name.', 'photo-competition-manager' ) );
		}

		if ( ! is_email( $email ) || strlen( $email ) > Members_Repository::EMAIL_MAX_LENGTH ) {
			return new WP_Error( 'invalid_email', __( 'Enter a valid email address.', 'photo-competition-manager' ) );
		}

		if ( ! isset( $this->grade_choices( $competition )[ $grade ] ) ) {
			return new WP_Error( 'invalid_grade', __( 'Choose a grade.', 'photo-competition-manager' ) );
		}

		$address_key = 'photo_comp_registration_email_' . md5( $email . '|' . (int) $competition->id );
		if ( get_transient( $address_key ) ) {
			return true;
		}
		set_transient( $address_key, 1, self::ADDRESS_WAIT );

		// Registration closing stops the link too: confirm() refuses it, saying so.
		$key = bin2hex( random_bytes( 32 ) );
		$id  = $this->registrations->create(
			array(
				'competition_id' => (int) $competition->id,
				'name'           => $name,
				'email'          => $email,
				'grade'          => $grade,
				'token_hash'     => hash( 'sha256', $key ),
				'expires_at'     => gmdate( 'Y-m-d H:i:s', ( $this->clock )() + self::LIFETIME ),
				'created_at'     => $this->now(),
			)
		);
		if ( ! $id ) {
			return true;
		}

		$recipient = (object) array(
			'name'  => $name,
			'email' => $email,
			'grade' => $grade,
		);
		$this->email->send( 'registration_link', $recipient, $competition, array( '{registration_link}' => add_query_arg( self::QUERY_ARG, $key, $page_url ) ) );

		return true;
	}

	/**
	 * The registration a key belongs to, if it's waiting and hasn't expired
	 * and its competition still exists.
	 *
	 * @param string $key Key from the link.
	 * @return object|null Registration row, with its competition row in `competition`, or null.
	 */
	public function pending( string $key ): ?object {
		if ( ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) {
			return null;
		}

		$registration = $this->registrations->find_live( hash( 'sha256', $key ), $this->now() );
		$competition  = $registration ? $this->competitions->find( (int) $registration->competition_id ) : null;
		if ( ! $competition ) {
			return null;
		}

		$registration->competition = $competition;

		return $registration;
	}

	/**
	 * Confirm a registration from its emailed link. A link works once,
	 * whatever the outcome.
	 *
	 * @param string $key Key from the link.
	 * @return array{status: string, message: string} Status 'registered',
	 *         'already_entrant', 'refused' (the message says why) or
	 *         'invalid' (expired, used or never issued).
	 */
	public function confirm( string $key ): array {
		$registration = $this->pending( $key );

		// Deleted first, so a second request with the same link finds nothing.
		if ( ! $registration || ! $this->registrations->delete( (int) $registration->id ) ) {
			return self::outcome( 'invalid', __( 'This link has expired or has already been used. Register again to get a new one.', 'photo-competition-manager' ) );
		}

		$competition = $registration->competition;
		$title       = $this->labels->title_for_everyone( $competition );
		$member      = $this->members->find_by_email( (string) $registration->email );
		$type_id     = $member ? $this->members->type_id( (int) $member->id ) : null;

		$refusal = $this->refusal( $competition, $title, $registration, $member, $type_id );
		if ( null !== $refusal ) {
			return self::outcome( 'refused', $refusal );
		}

		if ( $member && $this->entrants->has( (int) $competition->id, (int) $member->id ) ) {
			/* translators: %s: Competition title */
			return self::outcome( 'already_entrant', sprintf( __( "You're already entered in %s, so nothing has changed.", 'photo-competition-manager' ), $title ) );
		}

		$had_no_type = $member && null === $type_id;
		$member      = $member ? $member : $this->create_member( $competition, $registration );
		if ( is_wp_error( $member ) ) {
			return self::outcome( 'refused', $member->get_error_message() );
		}

		$this->enter( $competition, $member, $had_no_type );

		/* translators: %s: Competition title */
		return self::outcome( 'registered', sprintf( __( "You're entered in %s. We've emailed you the details.", 'photo-competition-manager' ), $title ) );
	}

	/**
	 * Why a registration can't be confirmed, or null when it can.
	 *
	 * @param object      $competition  Competition row.
	 * @param string      $title        Competition title.
	 * @param object      $registration Registration row.
	 * @param object|null $member       The member holding its address, if any.
	 * @param int|null    $type_id      The member's type, if they have one.
	 * @return string|null
	 */
	private function refusal( object $competition, string $title, object $registration, ?object $member, ?int $type_id ): ?string {
		if ( ! $this->is_open( $competition ) ) {
			/* translators: %s: Competition title */
			return sprintf( __( 'Registration for %s has closed, so you weren\'t entered.', 'photo-competition-manager' ), $title );
		}

		if ( ! $member ) {
			return isset( $this->grade_choices( $competition )[ (string) $registration->grade ] )
				? null
				/* translators: %s: Competition title */
				: sprintf( __( 'The grade you chose isn\'t one %s uses any more. Register again and choose another.', 'photo-competition-manager' ), $title );
		}

		if ( empty( $member->active ) ) {
			return __( 'This email address belongs to a deactivated member. Ask the competition organiser to reactivate it.', 'photo-competition-manager' );
		}

		// A member has one type, and only an admin changes it.
		if ( null !== $type_id && (int) $competition->type_id !== $type_id ) {
			$type = $this->types->find( $type_id );

			if ( Competition_Settings::is_interclub( $competition ) ) {
				/* translators: %s: Competition title */
				return sprintf( __( '%s is a competition between clubs, and this email address is a person\'s, not a club\'s. Register the club with an address only the club uses.', 'photo-competition-manager' ), $title );
			}

			if ( $type && Competition_Settings::type_is_interclub( $type ) ) {
				/* translators: %s: Competition title */
				return sprintf( __( 'This email address is a club\'s, and %s is for individual photographers. Register with your own address.', 'photo-competition-manager' ), $title );
			}

			/* translators: 1: Competition title, 2: Competition type name */
			return sprintf( __( 'This email address belongs to a member of %2$s, so it can\'t register for %1$s. Ask the competition organiser if you should be moved.', 'photo-competition-manager' ), $title, $type ? $type->name : '' );
		}

		if ( ! $this->entry->uses_grade_of( $competition, $member ) ) {
			/* translators: %s: Competition title */
			return sprintf( __( 'Your grade isn\'t one %s uses, so you can\'t enter it. Ask the competition organiser if you think your grade is wrong.', 'photo-competition-manager' ), $title );
		}

		return null;
	}

	/**
	 * Create the member a registration from a new address asked for.
	 *
	 * @param object $competition  Competition row.
	 * @param object $registration Registration row.
	 * @return object|WP_Error Member row, or why it couldn't be created.
	 */
	private function create_member( object $competition, object $registration ) {
		$member_id = $this->members->create(
			array(
				'name'    => (string) $registration->name,
				'email'   => (string) $registration->email,
				'grade'   => (string) $registration->grade,
				'type_id' => (int) $competition->type_id,
			)
		);
		if ( is_wp_error( $member_id ) ) {
			return $member_id;
		}

		$member = $this->members->find( $member_id );

		$this->log(
			$competition,
			'member_registered',
			/* translators: 1: Member name, 2: Email address */
			sprintf( __( '%1$s (%2$s) registered and was added as a member.', 'photo-competition-manager' ), $member->name, $member->email ),
			$member
		);

		return $member;
	}

	/**
	 * Enter a member who registered, giving them the competition's type if
	 * they had none, and email them and the organiser.
	 *
	 * @param object $competition Competition row.
	 * @param object $member      Member row.
	 * @param bool   $had_no_type Whether an existing member had no type before registering.
	 * @return void
	 */
	private function enter( object $competition, object $member, bool $had_no_type ): void {
		$type_id = (int) $competition->type_id;
		if ( $had_no_type ) {
			$this->members->set_type( (int) $member->id, $type_id );
			$type = $this->types->find( $type_id );
			$this->log(
				$competition,
				'member_type_added',
				/* translators: 1: Member name, 2: Competition type name */
				sprintf( __( '%1$s registered and was added to the %2$s type.', 'photo-competition-manager' ), $member->name, $type ? $type->name : '' ),
				$member
			);
		}

		$this->entrants->add( (int) $competition->id, (int) $member->id, Entrants_Repository::SOURCE_REGISTRATION, false );
		$this->log(
			$competition,
			'entrant_registered',
			/* translators: %s: Member name */
			sprintf( __( '%s registered and was entered.', 'photo-competition-manager' ), $member->name ),
			$member
		);

		$this->email->send(
			'registration_confirmed',
			$member,
			$competition,
			array(
				'{fee_info}'    => Competition_Entry::fee_paragraph( $competition ),
				'{upload_info}' => $this->upload_paragraph( $competition, (int) $member->id ),
			)
		);

		$has_fee = Competition_Entry::has_fee( $competition );
		$this->email->send(
			'registration_received',
			(object) array(
				'name'  => '',
				'email' => Email_Configuration::organiser_email(),
			),
			$competition,
			array(
				'{entrant_name}'  => (string) $member->name,
				'{entrant_email}' => (string) $member->email,
				'{entrant_grade}' => $this->labels->grade_label( $competition, (string) $member->grade ),
				/* translators: %s: Entry fee, such as "€20" */
				'{fee_status}'    => $has_fee ? sprintf( __( 'Entry fee %s: Unpaid.', 'photo-competition-manager' ), $competition->entry_fee ) : '',
			)
		);
	}

	/**
	 * The paragraph with the member's upload link, while uploads are open.
	 *
	 * @param object $competition Competition row.
	 * @param int    $member_id   Member ID.
	 * @return string HTML, or '' when uploads aren't open or there's no upload page.
	 */
	private function upload_paragraph( object $competition, int $member_id ): string {
		if ( ! $this->workflow->is_accepting_uploads( $competition ) ) {
			return '';
		}

		$url = ( new Upload_Link_Service( null, $this->competitions, $this->members, $this->email ) )->upload_url( $competition, $member_id );
		if ( is_wp_error( $url ) ) {
			return '';
		}

		return '<p>' . esc_html__( 'Uploads are open. Upload your images here:', 'photo-competition-manager' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></p>';
	}

	/**
	 * Delete the registrations that have expired. Runs daily.
	 *
	 * @return int How many were deleted.
	 */
	public function delete_expired(): int {
		return $this->registrations->delete_expired( $this->now() );
	}

	/**
	 * Add the registrations exporter to WordPress's personal data exporters.
	 *
	 * @param array<string, array> $exporters Registered exporters.
	 * @return array<string, array>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters['photo-competition-manager-registrations'] = array(
			'exporter_friendly_name' => __( 'Photo Competition Manager registrations', 'photo-competition-manager' ),
			'callback'               => array( new self(), 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the registrations eraser to WordPress's personal data erasers.
	 *
	 * @param array<string, array> $erasers Registered erasers.
	 * @return array<string, array>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers['photo-competition-manager-registrations'] = array(
			'eraser_friendly_name' => __( 'Photo Competition Manager registrations', 'photo-competition-manager' ),
			'callback'             => array( new self(), 'erase' ),
		);

		return $erasers;
	}

	/**
	 * WordPress's personal data exporter: the registrations waiting to be
	 * confirmed from an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1; there is only one.
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress passes the page; there is only one.
		$data = array();

		foreach ( $this->registrations->find_by_email( strtolower( $email ) ) as $registration ) {
			$competition = $this->competitions->find( (int) $registration->competition_id, true );
			$fields      = array(
				__( 'Competition', 'photo-competition-manager' ) => $competition ? $this->labels->title_for_everyone( $competition ) : '',
				__( 'Name', 'photo-competition-manager' )  => $registration->name,
				__( 'Email', 'photo-competition-manager' ) => $registration->email,
				__( 'Grade', 'photo-competition-manager' ) => $competition ? $this->labels->grade_label( $competition, (string) $registration->grade ) : $registration->grade,
				__( 'Registered', 'photo-competition-manager' ) => $registration->created_at,
			);

			$data[] = Member_Export::item(
				'photo-competition-registrations',
				__( 'Competition registrations waiting to be confirmed', 'photo-competition-manager' ),
				'photo-competition-registration-' . $registration->id,
				$fields
			);
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * WordPress's personal data eraser: delete the registrations waiting to
	 * be confirmed from an email address.
	 *
	 * @param string $email Email address.
	 * @param int    $page  Page, from 1; there is only one.
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress passes the page; there is only one.
		return array(
			'items_removed'  => $this->registrations->delete_by_email( strtolower( $email ) ) > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Log a change a registration made.
	 *
	 * @param object $competition Competition row.
	 * @param string $event_type  Event type.
	 * @param string $description What happened.
	 * @param object $member      Member row.
	 * @return void
	 */
	private function log( object $competition, string $event_type, string $description, object $member ): void {
		( new Event_Logger() )->log(
			(int) $competition->id,
			$event_type,
			'competition',
			$description,
			array(
				'member_id' => (int) $member->id,
				'email'     => (string) $member->email,
			)
		);
	}

	/**
	 * The clock's time as a UTC datetime.
	 *
	 * @return string
	 */
	private function now(): string {
		return gmdate( 'Y-m-d H:i:s', ( $this->clock )() );
	}

	/**
	 * A confirmation's outcome.
	 *
	 * @param string $status  Status.
	 * @param string $message What to tell the person.
	 * @return array{status: string, message: string}
	 */
	private static function outcome( string $status, string $message ): array {
		return array(
			'status'  => $status,
			'message' => $message,
		);
	}
}
