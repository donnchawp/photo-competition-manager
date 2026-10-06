<?php
/**
 * Tests for the shared helper functions.
 *
 * @package PhotoCompetitionManager\Tests\Support
 */

namespace PhotoCompetitionManager\Tests\Support;

use WP_UnitTestCase;

use function PhotoCompetitionManager\Support\format_site_date;

class Helpers_Test extends WP_UnitTestCase {

	public function test_a_utc_datetime_is_shown_in_the_sites_date_format_and_timezone(): void {
		update_option( 'date_format', 'j F Y' );
		update_option( 'timezone_string', 'Europe/Dublin' );

		$this->assertSame( '1 July 2026', format_site_date( '2026-06-30 23:30:00' ) );
	}

	public function test_a_missing_datetime_is_an_empty_string(): void {
		$this->assertSame( '', format_site_date( null ) );
		$this->assertSame( '', format_site_date( '' ) );
	}
}
