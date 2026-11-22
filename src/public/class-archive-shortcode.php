<?php
/**
 * The past competitions page and each competition's permalink.
 *
 * @package PhotoCompetitionManager\Frontend
 */

namespace PhotoCompetitionManager\Frontend;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Service\Competition_Archive;
use PhotoCompetitionManager\Service\Competition_Labels;
use WP_Post;

use function PhotoCompetitionManager\Support\format_site_date;

/**
 * Shortcode [competition_archive]. The page it's on lists past competitions,
 * and the page's path followed by a competition's slug shows that
 * competition's images, category by category.
 *
 * @since 0.5.0
 */
class Archive_Shortcode {

	const SHORTCODE = 'competition_archive';

	/**
	 * Query var holding the competition's slug.
	 */
	const QUERY_VAR = 'photocomp_archive';

	/**
	 * Option holding the archive page's path, so its rewrite rule can be
	 * registered on every request without searching the pages.
	 */
	const PAGE_PATH_OPTION = 'photo_comp_archive_page_path';

	/**
	 * The archive.
	 *
	 * @var Competition_Archive
	 */
	private $archive;

	/**
	 * Competition labels.
	 *
	 * @var Competition_Labels
	 */
	private $labels;

	/**
	 * Competitions looked up this request, by slug.
	 *
	 * @var array<string, object|null>
	 */
	private $found = array();

	/**
	 * Constructor.
	 *
	 * @param Competition_Archive|null $archive The archive.
	 * @param Competition_Labels|null  $labels  Competition labels.
	 */
	public function __construct( ?Competition_Archive $archive = null, ?Competition_Labels $labels = null ) {
		$this->archive = $archive ?? new Competition_Archive();
		$this->labels  = $labels ?? new Competition_Labels();
	}

	/**
	 * Attach hooks.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_filter( 'rewrite_rules_array', array( self::class, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'request', array( $this, 'route' ) );
		add_action( 'template_redirect', array( $this, 'not_found' ) );

		foreach ( array( 'save_post_page', 'trashed_post', 'untrashed_post', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( self::class, 'page_changed' ), 10, 2 );
		}
	}

	/**
	 * Add the archive page's permalink rule whenever WordPress builds its
	 * rewrite rules, so every flush uses the page's current path.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string, string> $rules Rewrite rules.
	 * @return array<string, string>
	 */
	public static function add_rewrite_rules( array $rules ): array {
		$path = get_option( self::PAGE_PATH_OPTION, false );
		if ( false === $path ) {
			$path = self::refresh_page_path();
		}

		if ( '' === $path ) {
			return $rules;
		}

		// The page's own feed, embed and trackback addresses keep WordPress's rules.
		$regex = '^' . preg_quote( $path, '#' ) . '/(?!(?:feed|rdf|rss|rss2|atom|embed|trackback)/?$)([^/]+)/?$';

		return array( $regex => 'index.php?pagename=' . $path . '&' . self::QUERY_VAR . '=$matches[1]' ) + $rules;
	}

	/**
	 * Hand a request whose slug isn't a competition in the archive back to
	 * WordPress as a child page of the archive page. That shows the child
	 * page if there is one, and a 404 if not.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string, mixed> $vars Query vars.
	 * @return array<string, mixed>
	 */
	public function route( array $vars ): array {
		$on_archive_page = isset( $vars['pagename'] ) && get_option( self::PAGE_PATH_OPTION, '' ) === $vars['pagename'];
		if ( empty( $vars[ self::QUERY_VAR ] ) || ! $on_archive_page || $this->find( (string) $vars[ self::QUERY_VAR ] ) ) {
			return $vars;
		}

		$vars['pagename'] .= '/' . $vars[ self::QUERY_VAR ];
		unset( $vars[ self::QUERY_VAR ] );

		return $vars;
	}

	/**
	 * Look for the archive page again when a page is saved, trashed, restored or deleted.
	 *
	 * @since 0.5.0
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    The post, when the hook passes it (deleted_post does, after it's gone).
	 * @return void
	 */
	public static function page_changed( $post_id, $post = null ): void {
		$post = $post instanceof WP_Post ? $post : get_post( (int) $post_id );
		if ( $post && 'page' === $post->post_type ) {
			self::refresh_page_path();
		}
	}

	/**
	 * Find the archive page's path again. When it has changed, the rewrite
	 * rules are rebuilt on the next request, with the new path.
	 *
	 * @since 0.5.0
	 *
	 * @return string The path, or '' when there's no published archive page.
	 */
	public static function refresh_page_path(): string {
		global $wpdb;

		// Only pages mentioning the shortcode, however many pages the site has.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Runs only when a page changes.
		$ids  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM %i WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY menu_order, post_title, ID",
				$wpdb->posts,
				'%' . $wpdb->esc_like( '[' . self::SHORTCODE ) . '%'
			)
		);
		$path = '';
		foreach ( $ids as $id ) {
			$page = get_post( (int) $id );
			if ( $page && has_shortcode( (string) $page->post_content, self::SHORTCODE ) ) {
				$path = (string) get_page_uri( $page );
				break;
			}
		}

		if ( get_option( self::PAGE_PATH_OPTION, false ) !== $path ) {
			update_option( self::PAGE_PATH_OPTION, $path );
			delete_option( 'rewrite_rules' );
		}

		return $path;
	}

	/**
	 * Allow the query var.
	 *
	 * @since 0.5.0
	 *
	 * @param array<int, string> $vars Public query vars.
	 * @return array<int, string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Make a permalink to a competition that isn't in the archive a 404.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function not_found(): void {
		$slug = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $slug || ! $this->on_archive_page() || $this->find( $slug ) ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * A competition in the archive, looked up once per request.
	 *
	 * @param string $slug Competition slug.
	 * @return object|null
	 */
	private function find( string $slug ): ?object {
		if ( ! array_key_exists( $slug, $this->found ) ) {
			$this->found[ $slug ] = $this->archive->find( $slug );
		}

		return $this->found[ $slug ];
	}

	/**
	 * Whether this request is for the archive page, so the query var means something.
	 *
	 * @return bool
	 */
	private function on_archive_page(): bool {
		$page = get_queried_object();

		return $page instanceof \WP_Post && has_shortcode( (string) $page->post_content, self::SHORTCODE );
	}

	/**
	 * A competition's permalink on the archive page.
	 *
	 * @param string $page_url    The archive page's URL.
	 * @param object $competition Competition row.
	 * @return string
	 */
	private function permalink( string $page_url, object $competition ): string {
		$path = (string) get_option( self::PAGE_PATH_OPTION, '' );
		if ( '' === (string) get_option( 'permalink_structure' ) || '' === $path ) {
			return add_query_arg( self::QUERY_VAR, $competition->slug, $page_url );
		}

		// From the page's path, which the rewrite rule matches, not its URL:
		// a front page's URL is the home address.
		return home_url( '/' . $path . '/' . rawurlencode( (string) $competition->slug ) . '/' );
	}

	/**
	 * Render the shortcode.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string, string>|string $atts Shortcode attributes: show_names, and columns (1 to 8, default 3).
	 * @return string
	 */
	public function render( $atts ): string {
		if ( ! Competition_Archive::settings()['enabled'] ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'show_names' => 'false',
				'columns'    => '3',
			),
			$atts,
			self::SHORTCODE
		);

		$page_url    = (string) get_permalink();
		$slug        = (string) get_query_var( self::QUERY_VAR );
		$competition = '' !== $slug ? $this->find( $slug ) : null;

		ob_start();
		echo '<div class="photo-comp-archive">';
		if ( $competition ) {
			$this->render_competition( $competition, filter_var( $atts['show_names'], FILTER_VALIDATE_BOOLEAN ), max( 1, min( 8, (int) $atts['columns'] ) ), $page_url );
		} else {
			$this->render_list( $page_url );
		}
		echo '</div>';
		$output = ob_get_clean();

		return false !== $output ? $output : '';
	}

	/**
	 * The list of past competitions.
	 *
	 * @param string $page_url The archive page's URL.
	 * @return void
	 */
	private function render_list( string $page_url ): void {
		$competitions = $this->archive->competitions();

		if ( empty( $competitions ) ) {
			echo '<p>' . esc_html__( 'No past competitions yet.', 'photo-competition-manager' ) . '</p>';
			return;
		}
		?>
		<ul class="photo-comp-archive-list">
			<?php foreach ( $competitions as $competition ) : ?>
				<li>
					<a href="<?php echo esc_url( $this->permalink( $page_url, $competition ) ); ?>"><?php echo esc_html( $this->labels->title_for_everyone( $competition ) ); ?></a>
					<span class="photo-comp-archive-date"><?php echo esc_html( format_site_date( $competition->open_date ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * One competition's images, category by category, each as a core Gallery block.
	 *
	 * Clicking an image opens it full size in the core block lightbox.
	 *
	 * @param object $competition Competition row.
	 * @param bool   $show_names  Whether to say who each image is by.
	 * @param int    $columns     Gallery columns.
	 * @param string $page_url    The archive page's URL.
	 * @return void
	 */
	private function render_competition( object $competition, bool $show_names, int $columns, string $page_url ): void {
		echo '<p class="photo-comp-archive-back"><a href="' . esc_url( $page_url ) . '">' . esc_html__( 'All past competitions', 'photo-competition-manager' ) . '</a></p>';
		echo '<h2>' . esc_html( $this->labels->title_for_everyone( $competition ) ) . '</h2>';

		foreach ( $this->archive->categories( $competition, $show_names ) as $category ) {
			$images = array_filter(
				$category['entries'],
				static function ( $entry ): bool {
					return '' !== $entry->thumb && '' !== $entry->full;
				}
			);
			if ( empty( $images ) ) {
				continue;
			}

			echo '<h3>' . esc_html( $category['label'] ) . '</h3>';
			echo do_blocks( $this->gallery_block( $images, $category['label'], $columns ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Block markup built from escaped parts, rendered by core.
			$this->size_lightbox_images( $images );
		}
	}

	/**
	 * Give the lightbox each full image's size.
	 *
	 * Core reads an image's size from its attachment, and these images have
	 * none, so without this the lightbox opens them at the thumbnail's size.
	 * The rendered Image blocks have put each one in the interactivity state,
	 * keyed by an ID core made up, with the URL it enlarges.
	 *
	 * @param array<int, object> $entries Entries just rendered.
	 * @return void
	 */
	private function size_lightbox_images( array $entries ): void {
		$sizes = array();
		foreach ( $entries as $entry ) {
			if ( $entry->width > 0 ) {
				$sizes[ $entry->full ] = $entry;
			}
		}

		$state = wp_interactivity_state( 'core/image' );
		foreach ( $state['metadata'] ?? array() as $id => $image ) {
			$entry = $sizes[ $image['uploadedSrc'] ?? '' ] ?? null;
			if ( $entry ) {
				wp_interactivity_state(
					'core/image',
					array(
						'metadata' => array(
							$id => array(
								'targetWidth'  => $entry->width,
								'targetHeight' => $entry->height,
							),
						),
					)
				);
			}
		}
	}

	/**
	 * Serialized core Gallery block markup for a category's images.
	 *
	 * @param array<int, object> $entries  Entries with a thumbnail, from Competition_Archive::categories().
	 * @param string             $category Category label, the alt text when names are hidden.
	 * @param int                $columns  Gallery columns.
	 * @return string
	 */
	private function gallery_block( array $entries, string $category, int $columns ): string {
		// A column's width in a content-width gallery, so the thumbnail is chosen only where it's big enough.
		$sizes  = '(max-width: 600px) 50vw, ' . (int) ceil( 700 / $columns ) . 'px';
		$images = '';
		foreach ( $entries as $entry ) {
			$caption = array();
			if ( '' !== $entry->by ) {
				$caption[] = esc_html( $entry->by );
			}
			if ( null !== $entry->position ) {
				if ( $entry->position <= 3 ) {
					$caption[] = '<strong>' . esc_html__( 'Top 3', 'photo-competition-manager' ) . '</strong>';
				}
				/* translators: 1: position, 2: grade name, 3: total score */
				$caption[] = esc_html( sprintf( __( 'Position %1$d in %2$s, total score %3$d', 'photo-competition-manager' ), $entry->position, $entry->grade, $entry->score ) );
			}

			$figcaption = $caption ? '<figcaption class="wp-element-caption">' . implode( '<br>', $caption ) . '</figcaption>' : '';
			$alt        = '' !== $entry->by ? $entry->by : $category;

			// The images aren't attachments, so the lightbox enlarges the image's
			// src. That's the full image; srcset offers the thumbnail for the gallery.
			// A thumbnail fits a 400px box, so its width follows from the full image's shape.
			$full_width  = $entry->width > 0 ? $entry->width : 1920;
			$thumb_width = $entry->width > 0 ? (int) round( 400 * min( 1, $entry->width / max( $entry->width, $entry->height ) ) ) : 400;
			$images     .= '<!-- wp:image {"lightbox":{"enabled":true}} -->'
				. '<figure class="wp-block-image"><img src="' . esc_url( $entry->full ) . '" srcset="' . esc_url( $entry->thumb ) . ' ' . $thumb_width . 'w, ' . esc_url( $entry->full ) . ' ' . $full_width . 'w" sizes="' . esc_attr( $sizes ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy"/>' . $figcaption . '</figure>'
				. '<!-- /wp:image -->';
		}

		// Not cropped: the lightbox keeps the shape an image has in the gallery, and a photo shouldn't be cropped.
		return '<!-- wp:gallery {"columns":' . $columns . ',"imageCrop":false,"linkTo":"none"} -->'
			. '<figure class="wp-block-gallery has-nested-images columns-' . $columns . '">' . $images . '</figure>'
			. '<!-- /wp:gallery -->';
	}
}
