<?php
/**
 * How long the club keeps log rows, and trimming older ones.
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Logs_Repository;

/**
 * Deletes log rows older than the club's chosen number of months.
 *
 * @since 0.4.0
 */
class Log_Retention {

	/**
	 * Option holding how many months logs are kept. Absent or 0 keeps them forever.
	 */
	const OPTION = 'photo_comp_log_retention_months';

	/**
	 * Daily WP-Cron event that trims old log rows.
	 */
	const HOOK = 'photo_comp_trim_logs';

	/**
	 * Logs repository.
	 *
	 * @var Logs_Repository
	 */
	private $logs;

	/**
	 * Constructor.
	 *
	 * @param Logs_Repository $logs Logs repository.
	 */
	public function __construct( Logs_Repository $logs ) {
		$this->logs = $logs;
	}

	/**
	 * How many months logs are kept, or 0 to keep them forever.
	 *
	 * @return int
	 */
	public static function months(): int {
		return max( 0, (int) get_option( self::OPTION, 0 ) );
	}

	/**
	 * The number of months to keep logs for, from the settings form.
	 *
	 * @param string $choice 'months' to keep them for a number of months; anything else keeps them forever.
	 * @param string $months Number of months, as posted.
	 * @return int|\WP_Error Months, 0 to keep logs forever, or an error when the number isn't a positive whole number.
	 */
	public static function parse( string $choice, string $months ) {
		if ( 'months' !== $choice ) {
			return 0;
		}

		$months = trim( $months );
		if ( ! ctype_digit( $months ) || 0 === (int) $months ) {
			return new \WP_Error(
				'invalid_log_retention',
				__( 'Settings not saved. Keep logs for a whole number of months, 1 or more, or choose to keep them forever.', 'photo-competition-manager' )
			);
		}

		return (int) $months;
	}

	/**
	 * Delete log rows written before the retention period.
	 *
	 * Rows' created_at is UTC, so the cut-off is too.
	 *
	 * @param int|null $now Unix time to count back from. Defaults to now.
	 * @return void
	 */
	public function trim( ?int $now = null ): void {
		$months = self::months();
		if ( 0 === $months ) {
			return;
		}

		$cutoff = ( new \DateTimeImmutable( '@' . ( $now ?? time() ) ) )->modify( "-{$months} months" );

		$this->logs->delete_older_than( $cutoff->format( 'Y-m-d H:i:s' ) );
	}
}
