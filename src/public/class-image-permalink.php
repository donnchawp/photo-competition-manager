<?php
/**
 * Serves entry images by entry ID, so a file name never reaches a page.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Service\Entries;

/**
 * Entry files are named after the member, so the voting page and the slideshow link to
 * /photocomp-image/<entry id>/ instead, and this streams the file.
 *
 * @since 0.4.0
 */
class Image_Permalink {

	const QUERY_VAR      = 'photocomp_image';
	const SIZE_QUERY_VAR = 'photocomp_image_size';

	/**
	 * Entries module.
	 *
	 * @var Entries
	 */
	private $entries;

	/**
	 * Constructor.
	 *
	 * @param Entries|null $entries Entries module.
	 */
	public function __construct( ?Entries $entries = null ) {
		$this->entries = $entries ? $entries : new Entries();
	}

	/**
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );
	}

	/**
	 * Register the rewrite rule for image permalinks.
	 *
	 * @return void
	 */
	public static function add_rewrite_rule(): void {
		add_rewrite_rule(
			'^photocomp-image/([0-9]+)(?:/(thumb))?/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]&' . self::SIZE_QUERY_VAR . '=$matches[2]',
			'top'
		);
	}

	/**
	 * Allow the permalink's query vars.
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string>
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::SIZE_QUERY_VAR;

		return $vars;
	}

	/**
	 * Serve the image when the request is for one.
	 *
	 * @return void
	 */
	public function maybe_serve(): void {
		$entry_id = (int) get_query_var( self::QUERY_VAR );
		if ( $entry_id <= 0 ) {
			return;
		}

		$this->serve( $entry_id, (string) get_query_var( self::SIZE_QUERY_VAR ) );
	}

	/**
	 * Path of the file to serve.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $size     'thumb' for the thumbnail, anything else for the full image.
	 * @return string|null Null when the entry, its competition (soft-deleted counts as missing) or the file is missing.
	 */
	public function resolve( int $entry_id, string $size ): ?string {
		return $this->entries->file_path( $entry_id, $size );
	}

	/**
	 * Stream an entry's image, or send a 404.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $size     'thumb' for the thumbnail, anything else for the full image.
	 * @return void
	 */
	public function serve( int $entry_id, string $size ): void {
		$path = $this->resolve( $entry_id, $size );
		if ( null === $path ) {
			status_header( 404 );
			nocache_headers();
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Cache-Control: public, max-age=31536000' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streams a file whose path comes from Entries.
		exit;
	}
}
