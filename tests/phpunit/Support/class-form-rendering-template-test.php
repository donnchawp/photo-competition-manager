<?php
/**
 * Unit test for the Form_Rendering template seam.
 *
 * @package PhotoCompetitionManager\Tests\Support
 */

namespace PhotoCompetitionManager\Tests\Support;

use PhotoCompetitionManager\Admin\Traits\Form_Rendering;
use WP_UnitTestCase;

/**
 * @covers \PhotoCompetitionManager\Admin\Traits\Form_Rendering
 */
class Form_Rendering_Template_Test extends WP_UnitTestCase {

	/**
	 * Anonymous host exposing the trait's protected methods.
	 *
	 * @var object
	 */
	private $host;

	public function set_up(): void {
		parent::set_up();
		$this->host = new class() {
			use Form_Rendering;
			public function render( string $rel, array $data ): string {
				return $this->render_template( $rel, $data );
			}
		};
	}

	public function test_render_template_returns_partial_output_with_data(): void {
		$dir = PHOTO_COMPETITION_MANAGER_DIR . '/templates/__test__';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/greet.php', '<?php echo "Hello " . esc_html( $data["name"] );' );

		$html = $this->host->render( '__test__/greet.php', array( 'name' => 'Ada' ) );

		unlink( $dir . '/greet.php' );
		rmdir( $dir );

		$this->assertSame( 'Hello Ada', $html );
	}
}
