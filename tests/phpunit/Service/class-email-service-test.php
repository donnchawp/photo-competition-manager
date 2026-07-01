<?php
/**
 * Tests for Email_Service results email templating.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Service\Email_Service;
use WP_UnitTestCase;

/**
 * Tests that results emails route through the admin-editable template system.
 */
class Email_Service_Test extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Email_Service
	 */
	private $service;

	/**
	 * Last captured wp_mail() arguments.
	 *
	 * @var array<string, mixed>|null
	 */
	private $last_mail = null;

	/**
	 * Set up the service and capture outgoing mail.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->service = new Email_Service();

		// Capture outgoing mail instead of sending it.
		add_filter(
			'wp_mail',
			function ( $atts ) {
				$this->last_mail = $atts;
				return $atts;
			}
		);
	}

	/**
	 * Minimal member results payload with one ranked image.
	 *
	 * @return array<string, mixed>
	 */
	private function sample_results(): array {
		return array(
			'images' => array(
				array(
					'category_label' => 'Colour',
					'image_number'   => 42,
					'rank'           => 1,
					'total_in_grade' => 10,
					'grade'          => 'Advanced',
					'statistics'     => array(
						'average' => 8.5,
						'count'   => 4,
						'median'  => 8.0,
						'min'     => 7.0,
						'max'     => 10.0,
					),
					'votes'          => array(
						(object) array( 'score' => 9 ),
						(object) array( 'score' => 8 ),
					),
				),
			),
		);
	}

	/**
	 * Store a custom, enabled results_detailed template in the option store.
	 *
	 * @param string $subject Template subject.
	 * @param string $body    Template body.
	 */
	private function set_results_template( string $subject, string $body ): void {
		update_option(
			'photo_comp_email_templates',
			array(
				'results_detailed' => array(
					'enabled' => true,
					'subject' => $subject,
					'body'    => $body,
				),
			)
		);
	}

	/**
	 * When the results_detailed template is enabled, the subject comes from the
	 * admin template with merge tags resolved (not the hardcoded fallback).
	 */
	public function test_results_email_subject_uses_enabled_template() {
		$this->set_results_template( 'Your {competition_title} results are in', '<p>Hi {member_name}</p>{results_table}' );

		$this->service->send_results_email( 'alice@example.com', 'Alice', 'Spring Show', $this->sample_results() );

		$this->assertNotNull( $this->last_mail );
		$this->assertStringContainsString( 'Your Spring Show results are in', $this->last_mail['subject'] );
	}

	/**
	 * The body reflects the admin template prose with merge tags resolved.
	 */
	public function test_results_email_body_uses_enabled_template() {
		$this->set_results_template( 'Results', '<p>Dear {member_name}, welcome to {competition_title}.</p>{results_table}' );

		$this->service->send_results_email( 'alice@example.com', 'Alice', 'Spring Show', $this->sample_results() );

		$this->assertStringContainsString( 'Dear Alice, welcome to Spring Show.', $this->last_mail['message'] );
	}

	/**
	 * The {results_table} tag injects the member's detailed results into the body.
	 */
	public function test_results_email_includes_results_detail() {
		$this->set_results_template( 'Results', '<p>Hi {member_name}</p>{results_table}' );

		$this->service->send_results_email( 'alice@example.com', 'Alice', 'Spring Show', $this->sample_results() );

		$this->assertStringContainsString( 'Colour', $this->last_mail['message'] );
		$this->assertStringContainsString( 'Rank:', $this->last_mail['message'] );
	}

	/**
	 * When the template is disabled, the email still sends using the built-in
	 * detailed body and hardcoded subject (toggle respected, results not lost).
	 */
	public function test_results_email_falls_back_when_template_disabled() {
		update_option(
			'photo_comp_email_templates',
			array(
				'results_detailed' => array(
					'enabled' => false,
					'subject' => 'Ignored subject',
					'body'    => 'Ignored body',
				),
			)
		);

		$result = $this->service->send_results_email( 'alice@example.com', 'Alice', 'Spring Show', $this->sample_results() );

		$this->assertTrue( $result );
		$this->assertStringContainsString( 'Results for Spring Show', $this->last_mail['subject'] );
		$this->assertStringNotContainsString( 'Ignored', $this->last_mail['message'] );
		$this->assertStringContainsString( 'Rank:', $this->last_mail['message'] );
	}
}
