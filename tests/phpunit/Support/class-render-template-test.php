<?php
/**
 * Tests for the shared template partial renderer.
 *
 * @package PhotoCompetitionManager\Tests\Support
 */

namespace PhotoCompetitionManager\Tests\Support;

use WP_UnitTestCase;
use function PhotoCompetitionManager\Support\render_template;

/**
 * @covers ::PhotoCompetitionManager\Support\render_template
 */
class Render_Template_Test extends WP_UnitTestCase {

	const DIR = PHOTO_COMPETITION_MANAGER_DIR . '/templates/__test__';

	public function set_up(): void {
		parent::set_up();
		wp_mkdir_p( self::DIR );
		file_put_contents( self::DIR . '/greet.php', '<?php echo "Hello " . esc_html( $data["name"] );' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public function tear_down(): void {
		unlink( self::DIR . '/greet.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		rmdir( self::DIR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		parent::tear_down();
	}

	public function test_renders_a_partial_under_src_templates_with_its_data(): void {
		$this->assertSame( 'Hello Ada &amp; Bob', render_template( '__test__/greet.php', array( 'name' => 'Ada & Bob' ) ) );
	}

	public function test_a_leading_slash_on_the_path_is_ignored(): void {
		$this->assertSame( 'Hello Ada', render_template( '/__test__/greet.php', array( 'name' => 'Ada' ) ) );
	}

	public function test_output_is_not_left_buffered_when_the_partial_throws(): void {
		file_put_contents( self::DIR . '/throws.php', '<?php echo "partial"; throw new \RuntimeException( "boom" );' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$level = ob_get_level();

		try {
			render_template( '__test__/throws.php' );
			$this->fail( 'The exception was swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		} finally {
			unlink( self::DIR . '/throws.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}

		$this->assertSame( $level, ob_get_level() );
	}
}
