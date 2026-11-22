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
		add_action( 'init', array( self::class, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'template_redirect', array( $this, 'not_found' ) );

		foreach ( array( 'save_post_page', 'trashed_post', 'untrashed_post', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( self::class, 'refresh_page_path' ) );
		}
	}

	/**
	 * Register the rewrite rule for the archive page's permalinks.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function add_rewrite_rule(): void {
		$path = get_option( self::PAGE_PATH_OPTION, false );
		if ( false === $path ) {
			$path = self::refresh_page_path();
		}

		if ( '' === $path ) {
			return;
		}

		add_rewrite_rule(
			'^' . preg_quote( $path, '#' ) . '/([^/]+)/?$',
			'index.php?pagename=' . $path . '&' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Find the archive page's path again, after a page is saved, trashed,
	 * restored or deleted. When it has changed, the rewrite rules are rebuilt
	 * on the next request, which registers the new path.
	 *
	 * @since 0.5.0
	 *
	 * @return string The path, or '' when there's no published archive page.
	 */
	public static function refresh_page_path(): string {
		$path = '';
		foreach ( get_pages( array( 'number' => 100 ) ) as $page ) {
			if ( has_shortcode( (string) $page->post_content, self::SHORTCODE ) ) {
				$path = (string) get_page_uri( $page );
				break;
			}
		}

		if ( get_option( self::PAGE_PATH_OPTION, false ) !== $path ) {
			update_option( self::PAGE_PATH_OPTION, $path );
			delete_option( 'rewrite_rules' );

			// Registered now as well, in case the rules are flushed later in this request.
			if ( '' !== $path ) {
				self::add_rewrite_rule();
			}
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
		if ( '' === $slug || ! $this->on_archive_page() || $this->archive->find( $slug ) ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
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
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( self::QUERY_VAR, $competition->slug, $page_url );
		}

		return trailingslashit( $page_url ) . rawurlencode( (string) $competition->slug ) . '/';
	}

	/**
	 * Render the shortcode.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string, string>|string $atts Shortcode attributes: show_names.
	 * @return string
	 */
	public function render( $atts ): string {
		if ( ! Competition_Archive::settings()['enabled'] ) {
			return '';
		}

		$atts = shortcode_atts( array( 'show_names' => 'false' ), $atts, self::SHORTCODE );

		$page_url    = (string) get_permalink();
		$slug        = (string) get_query_var( self::QUERY_VAR );
		$competition = '' !== $slug ? $this->archive->find( $slug ) : null;

		ob_start();
		echo '<div class="photo-comp-archive">';
		if ( $competition ) {
			$this->render_competition( $competition, filter_var( $atts['show_names'], FILTER_VALIDATE_BOOLEAN ), $page_url );
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
	 * One competition's images, category by category.
	 *
	 * @param object $competition Competition row.
	 * @param bool   $show_names  Whether to say who each image is by.
	 * @param string $page_url    The archive page's URL.
	 * @return void
	 */
	private function render_competition( object $competition, bool $show_names, string $page_url ): void {
		$title = $this->labels->title_for_everyone( $competition );
		?>
		<p class="photo-comp-archive-back"><a href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'All past competitions', 'photo-competition-manager' ); ?></a></p>
		<h2><?php echo esc_html( $title ); ?></h2>
		<?php foreach ( $this->archive->categories( $competition, $show_names ) as $category ) : ?>
			<?php
			if ( empty( $category['entries'] ) ) {
				continue;
			}
			?>
			<h3><?php echo esc_html( $category['label'] ); ?></h3>
			<ul class="photo-comp-archive-gallery">
				<?php foreach ( $category['entries'] as $entry ) : ?>
					<?php
					if ( '' === $entry->thumb ) {
						continue;
					}
					?>
					<?php $top3 = null !== $entry->position && $entry->position <= 3; ?>
					<li class="<?php echo esc_attr( $top3 ? 'photo-comp-archive-entry photo-comp-archive-top3' : 'photo-comp-archive-entry' ); ?>">
						<a href="<?php echo esc_url( $entry->full ); ?>" target="_blank" rel="noopener noreferrer">
							<img src="<?php echo esc_url( $entry->thumb ); ?>" alt="<?php echo esc_attr( '' !== $entry->by ? $entry->by : $category['label'] ); ?>" loading="lazy" />
						</a>
						<?php if ( '' !== $entry->by ) : ?>
							<span class="photo-comp-archive-by"><?php echo esc_html( $entry->by ); ?></span>
						<?php endif; ?>
						<?php if ( $top3 ) : ?>
							<span class="photo-comp-archive-top3-label"><?php esc_html_e( 'Top 3', 'photo-competition-manager' ); ?></span>
						<?php endif; ?>
						<?php if ( null !== $entry->position ) : ?>
							<span class="photo-comp-archive-result">
								<?php
								/* translators: 1: position, 2: grade name, 3: total score */
								echo esc_html( sprintf( __( 'Position %1$d in %2$s, total score %3$d', 'photo-competition-manager' ), $entry->position, $entry->grade, $entry->score ) );
								?>
							</span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endforeach; ?>
		<?php
	}
}
