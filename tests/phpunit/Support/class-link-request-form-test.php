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
 * without ending the request. The forms' own tests cover what each sends.
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
	 * The form under test.
	 *
	 * @return Link_Request_Form
	 */
	private function form(): Link_Request_Form {
		return new Link_Request_Form( 'send_link', 'send_link_action', 'send_link_nonce' );
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

	public function test_a_stale_or_missing_nonce_gives_a_notice_and_the_address_to_fill_back_in(): void {
		foreach ( array( 'stale', null ) as $nonce ) {
			$this->post( $nonce );

			$this->assertNotSame( '', $this->form()->stale_notice() );
			$this->assertSame( 'ann@example.com', $this->form()->refill() );
		}
	}

	public function test_a_valid_nonce_or_another_form_gives_no_notice_and_nothing_to_fill_in(): void {
		$this->post( wp_create_nonce( 'send_link_action' ) );
		$this->assertSame( '', $this->form()->stale_notice() );
		$this->assertSame( '', $this->form()->refill() );

		$_POST = array( 'member_email' => 'ann@example.com' );
		$this->assertSame( '', $this->form()->stale_notice() );
		$this->assertSame( '', $this->form()->refill() );
	}
}
