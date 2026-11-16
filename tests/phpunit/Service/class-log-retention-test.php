<?php
/**
 * Tests for trimming old log rows.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Install\Activator;
use PhotoCompetitionManager\Repository\Logs_Repository;
use PhotoCompetitionManager\Service\Log_Retention;
use WP_UnitTestCase;

/**
 * The club chooses how long logs are kept; a daily event deletes older rows.
 *
 * @covers \PhotoCompetitionManager\Service\Log_Retention
 */
class Log_Retention_Test extends WP_UnitTestCase {

	/**
	 * @var Logs_Repository
	 */
	private $logs;

	/**
	 * @var Log_Retention
	 */
	private $retention;

	public function set_up(): void {
		parent::set_up();
		Activator::activate();
		$this->logs      = new Logs_Repository();
		$this->retention = new Log_Retention( $this->logs );
	}

	public function tear_down(): void {
		delete_option( Log_Retention::OPTION );
		update_option( 'timezone_string', '' );
		parent::tear_down();
	}

	/**
	 * Seed a log row written at a UTC time.
	 *
	 * @param string $created_at UTC datetime.
	 * @return void
	 */
	private function log_at( string $created_at ): void {
		$this->assertNotFalse(
			$this->logs->create(
				array(
					'event_type'     => 'email_sent',
					'event_category' => 'email',
					'actor_type'     => 'system',
					'description'    => 'Sent at ' . $created_at,
					'created_at'     => $created_at,
				)
			)
		);
	}

	/**
	 * When each remaining row was written, oldest first.
	 *
	 * @return string[]
	 */
	private function remaining(): array {
		$rows = wp_list_pluck( $this->logs->paginate( 100 ), 'created_at' );
		sort( $rows );
		return $rows;
	}

	public function test_trim_deletes_rows_older_than_the_period_and_keeps_the_rest(): void {
		update_option( Log_Retention::OPTION, 12 );
		$this->log_at( '2025-10-08 11:59:59' );
		$this->log_at( '2025-10-08 12:00:00' );
		$this->log_at( '2026-10-01 09:00:00' );

		$this->retention->trim( strtotime( '2026-10-08 12:00:00 UTC' ) );

		$this->assertSame( array( '2025-10-08 12:00:00', '2026-10-01 09:00:00' ), $this->remaining() );
	}

	public function test_keep_forever_deletes_nothing(): void {
		update_option( Log_Retention::OPTION, 0 );
		$this->log_at( '2015-01-01 00:00:00' );

		$this->retention->trim( strtotime( '2026-10-08 12:00:00 UTC' ) );

		$this->assertSame( array( '2015-01-01 00:00:00' ), $this->remaining() );
	}

	public function test_a_site_that_never_chose_a_period_deletes_nothing(): void {
		$this->log_at( '2015-01-01 00:00:00' );

		$this->retention->trim( strtotime( '2026-10-08 12:00:00 UTC' ) );

		$this->assertSame( array( '2015-01-01 00:00:00' ), $this->remaining() );
	}

	public function test_the_cut_off_is_in_utc_whatever_the_sites_timezone(): void {
		// Fourteen hours ahead of UTC: a cut-off worked out in site time
		// would fall 14 hours later and take the row two hours inside it.
		update_option( 'timezone_string', 'Pacific/Kiritimati' );
		update_option( Log_Retention::OPTION, 1 );
		$cutoff = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->modify( '-1 month' );
		$this->log_at( $cutoff->modify( '-2 hours' )->format( 'Y-m-d H:i:s' ) );
		$kept = $cutoff->modify( '+2 hours' )->format( 'Y-m-d H:i:s' );
		$this->log_at( $kept );

		$this->retention->trim();

		$this->assertSame( array( $kept ), $this->remaining() );
	}
}
