<?php
/**
 * Category tabs partial for the admin voting controls page.
 *
 * Reads $data keys: all_categories, current_key, open_category_slug,
 * open_competition_id, voting_open_globally.
 *
 * @package PhotoCompetitionManager
 */

defined( 'ABSPATH' ) || exit;
?>
		<nav class="nav-tab-wrapper photo-comp-category-tabs">
			<?php
			foreach ( $data['all_categories'] as $cat_data ) :
				$tab_key         = $cat_data['key'];
				$tab_cat         = $cat_data['category'];
				$tab_count       = $cat_data['image_count'];
				$tab_is_active   = ( $tab_key === $data['current_key'] );
				$tab_has_voting  = $data['voting_open_globally'] && (int) $cat_data['competition']->id === $data['open_competition_id'] && ( $tab_cat['slug'] ?? '' ) === $data['open_category_slug'];
				$tab_is_complete = ( $cat_data['current_step'] ?? 1 ) >= 6;
				$tab_url         = add_query_arg(
					array(
						'page'  => 'photo-competition-manager-voting',
						'focus' => $tab_key,
					),
					admin_url( 'admin.php' )
				);
				?>
				<a href="<?php echo esc_url( $tab_url ); ?>" class="nav-tab <?php echo $tab_is_active ? 'nav-tab-active' : ''; ?>">
					<?php if ( $tab_is_complete ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color: #00a32a; font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom;"></span>
					<?php endif; ?>
					<?php echo esc_html( $tab_cat['label'] ?? '' ); ?>
					<span class="photo-comp-tab-count">(<?php echo (int) $tab_count; ?>)</span>
					<?php if ( $tab_has_voting ) : ?>
						<span class="photo-comp-voting-dot" title="<?php esc_attr_e( 'Voting open', 'photo-competition-manager' ); ?>"></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
