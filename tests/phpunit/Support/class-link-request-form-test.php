<?php
/**
 * Tests for Link_Request_Form: a link request form posted with a stale nonce.
 *
 * @package PhotoCompetitionManager\Tests\Support
 */

namespace PhotoCompetitionManager\Tests\Support;

use PhotoCompetitionManager\Support\Link_Request_Form;
use WP_UnitTestCase;

/**
 * A stale or missing nonce gives a notice and the address to fill back in,
 * without ending the request or sending. The forms' own tests cover what each sends.
 */
class Link_Request_Form_Test extends WP_UnitTestCase {

	/**
	 * Clear the request.
	 */
	public function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Post the form.
	 *
	 * @param string|null $nonce Nonce, or null to leave it out.
	 */
	private function post( ?string $nonce ): void {
		$_POST = array(
			'send_link'    => '1',
			'member_email' => 'ann@example.com',
		);
		if ( null !== $nonce ) {
			$_POST['send_link_nonce'] = $nonce;
		}
	}

	/**
	 * Handle the form, recording what it sent.
	 *
	 * @param Link_Request_Form $form Form.
	 * @param string[]          $sent Addresses sent to.
	 * @return string|null
	 */
	private function handle( Link_Request_Form $form, array &$sent ): ?string {
		return $form->handle(
			function ( string $email ) use ( &$sent ): string {
				$sent[] = $email;
				return 'sent';
			}
		);
	}

	public function test_a_stale_or_missing_nonce_sends_nothing_and_keeps_the_address_to_fill_back_in(): void {
		foreach ( array( 'stale', null ) as $nonce ) {
			$this->post( $nonce );
			$form = new Link_Request_Form( 'send_link', 'send_link_nonce' );
			$sent = array();

			$this->assertStringContainsString( 'class="error"', (string) $this->handle( $form, $sent ) );
			$this->assertSame( array(), $sent );
			$this->assertSame( 'ann@example.com', $form->refill( 'member_email' ) );
		}
	}

	public function test_a_valid_nonce_sends_to_the_posted_address(): void {
		$this->post( wp_create_nonce( 'send_link' ) );
		$form = new Link_Request_Form( 'send_link', 'send_link_nonce' );
		$sent = array();

		$this->assertSame( 'sent', $this->handle( $form, $sent ) );
		$this->assertSame( array( 'ann@example.com' ), $sent );
		$this->assertSame( '', $form->refill( 'member_email' ) );
	}

	public function test_another_form_posted_is_left_alone(): void {
		$_POST = array( 'member_email' => 'ann@example.com' );
		$form  = new Link_Request_Form( 'send_link', 'send_link_nonce' );
		$sent  = array();

		$this->assertNull( $this->handle( $form, $sent ) );
		$this->assertSame( array(), $sent );
	}
}
