<?php
/**
 * Tests for Registration_Shortcode: the page wiring around Registration.
 *
 * @package PhotoCompetitionManager\Tests\Frontend
 */

namespace PhotoCompetitionManager\Tests\Frontend;

use PhotoCompetitionManager\Frontend\Registration_Shortcode;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Entrants_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Registration;
use PhotoCompetitionManager\Tests\Type_Fixtures;
use WP_UnitTestCase;

/**
 * The page lists competitions open for registration, posts the form, and
 * confirms from the emailed link. The rules are tested on Registration.
 */
class Registration_Shortcode_Test extends WP_UnitTestCase {

	/**
	 * The registration page.
	 *
	 * @var int
	 */
	private $page_id;

	/**
	 * A competition open for registration.
	 *
	 * @var object
	 */
	private $competition;

	/**
	 * Every email sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $mails = array();

	public function setUp(): void {
		parent::setUp();

		$this->page_id   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '[competition_registration]',
			)
		);
		$GLOBALS['post'] = get_post( $this->page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The shortcode links back to the page it's on.

		$competitions      = new Competitions_Repository();
		$this->competition = $competitions->find(
			(int) $competitions->create(
				array(
					'type_id'           => Type_Fixtures::id(),
					'title'             => 'Autumn Interclub',
					'open_date'         => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
					'close_date'        => gmdate( 'Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS ),
					'entry_mode'        => 'selected',
					'registration_open' => true,
				)
			)
		);

		add_filter(
			'wp_mail',
			function ( $atts ) {
				$this->mails[] = $atts;
				return $atts;
			}
		);

		$_GET  = array();
		$_POST = array();
	}

	public function tearDown(): void {
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Render the page.
	 *
	 * @return string
	 */
	private function render(): string {
		return ( new Registration_Shortcode() )->render( array() );
	}

	/**
	 * Post the registration form.
	 *
	 * @param array<string, string> $extra Fields to add or change.
	 * @return string
	 */
	private function post_form( array $extra = array() ): string {
		$_POST = $extra + array(
			'photo_comp_register'       => '1',
			'photo_comp_register_nonce' => wp_create_nonce( 'photo_comp_register' ),
			'competition_id'            => (string) $this->competition->id,
			'registration_name'         => 'New Person',
			'member_email'              => 'new@example.com',
			'registration_grade'        => 'beginner',
		);

		return $this->render();
	}

	/**
	 * The key in the last email's link.
	 *
	 * @return string
	 */
	private function emailed_key(): string {
		$mail = end( $this->mails );
		preg_match( '#' . preg_quote( get_permalink( $this->page_id ), '#' ) . '[^"\s<]*#', (string) $mail['message'], $match );
		parse_str( (string) wp_parse_url( html_entity_decode( $match[0] ), PHP_URL_QUERY ), $args );

		return (string) $args[ Registration::QUERY_ARG ];
	}

	/**
	 * Press the confirm button on the page the link opens.
	 *
	 * @param string $key   Key.
	 * @param string $nonce Nonce, or '' for a valid one.
	 * @return string
	 */
	private function press_confirm( string $key, string $nonce = '' ): string {
		$_GET  = array( Registration::QUERY_ARG => $key );
		$_POST = array(
			Registration_Shortcode::CONFIRM => '1',
			'photo_comp_registration_nonce' => '' !== $nonce ? $nonce : wp_create_nonce( Registration_Shortcode::CONFIRM ),
		);

		return $this->render();
	}

	/**
	 * Whether the address is an entrant in the competition.
	 *
	 * @param string $email Address.
	 * @return bool
	 */
	private function is_entrant( string $email ): bool {
		$member = ( new Members_Repository() )->find_by_email( $email );

		return $member && ( new Entrants_Repository() )->has( (int) $this->competition->id, (int) $member->id );
	}

	public function test_registering_from_the_page_and_confirming_from_the_emailed_link(): void {
		$this->assertStringContainsString( 'Autumn Interclub', $this->render() );

		$reply = $this->post_form();
		$this->assertStringContainsString( 'Check your email', $reply );
		$this->assertCount( 1, $this->mails );
		$key = $this->emailed_key();

		$_POST = array();
		$_GET  = array( Registration::QUERY_ARG => $key );
		$this->assertStringContainsString( 'name="' . Registration_Shortcode::CONFIRM . '"', $this->render() );
		$this->assertFalse( $this->is_entrant( 'new@example.com' ), 'opening the link enters nobody' );

		$this->assertStringContainsString( "You're entered in Autumn Interclub", html_entity_decode( $this->press_confirm( $key ), ENT_QUOTES ) );
		$this->assertTrue( $this->is_entrant( 'new@example.com' ) );
	}

	public function test_confirming_without_a_valid_nonce_enters_nobody_and_offers_the_button_again(): void {
		$this->post_form();
		$key = $this->emailed_key();

		$html = $this->press_confirm( $key, 'stale' );

		$this->assertStringContainsString( 'This form has expired', $html );
		$this->assertStringContainsString( 'name="' . Registration_Shortcode::CONFIRM . '"', $html );
		$this->assertFalse( $this->is_entrant( 'new@example.com' ) );
	}

	public function test_a_filled_in_hidden_field_gets_the_usual_reply_and_sends_nothing(): void {
		$reply = $this->post_form( array( Registration_Shortcode::HONEYPOT => 'http://spam.example' ) );

		$this->assertStringContainsString( 'Check your email', $reply );
		$this->assertSame( array(), $this->mails );
	}
}
