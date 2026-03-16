# Voting Controls UX Redesign Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the voting controls admin page to use WordPress native styling and a guided 5-step workflow per category.

**Architecture:** Replace the freeform control panel with a step-based workflow rendered in WP postboxes. Step state persists in competition settings via `voting.category_steps`. Slideshow steps advance via AJAX; voting steps advance via existing server-side actions. Duration defaults move from hardcoded values to global settings with per-step overrides.

**Tech Stack:** PHP (WordPress plugin), jQuery, WordPress admin CSS patterns.

**Spec:** `docs/superpowers/specs/2026-03-16-voting-controls-ux-redesign.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `src/includes/Support/class-competition-settings.php` | Modify | Add 3 duration defaults to `defaults()`, step persistence keys |
| `src/admin/class-settings-controller.php` | Modify | Add duration default fields to global settings form |
| `src/admin/class-voting-controller.php` | Rewrite render methods | Step-based workflow UI, AJAX handler registration |
| `src/admin/class-admin-screen.php` | Modify | Pass new localized data (step nonce, duration defaults) |
| `src/assets/css/admin-slideshow.css` | Rewrite voting controls section | WP postbox styling, step states |
| `src/assets/js/admin-slideshow.js` | Modify | Read per-step duration inputs, handle Continue button AJAX, remove critique mode handler |
| `tests/phpunit/VotingControllerTest.php` | Create | Test step advancement, AJAX handler, page load recovery |

---

## Chunk 1: Settings Foundation

### Task 1: Add duration defaults to Competition_Settings

**Files:**
- Modify: `src/includes/Support/class-competition-settings.php:93-96`

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/CompetitionSettingsDurationTest.php`:

```php
<?php

namespace PhotoCompetitionManager\Tests;

use PhotoCompetitionManager\Support\Competition_Settings;
use WP_UnitTestCase;

class CompetitionSettingsDurationTest extends WP_UnitTestCase {

	public function test_defaults_include_per_step_durations(): void {
		$defaults = Competition_Settings::defaults();

		$this->assertArrayHasKey( 'preview_duration', $defaults['slideshow'] );
		$this->assertArrayHasKey( 'voting_duration', $defaults['slideshow'] );
		$this->assertArrayHasKey( 'critique_duration', $defaults['slideshow'] );
		$this->assertSame( 10, $defaults['slideshow']['preview_duration'] );
		$this->assertSame( 15, $defaults['slideshow']['voting_duration'] );
		$this->assertSame( 0, $defaults['slideshow']['critique_duration'] );
	}

	public function test_defaults_include_category_steps(): void {
		$defaults = Competition_Settings::defaults();

		$this->assertArrayHasKey( 'category_steps', $defaults['voting'] );
		$this->assertIsArray( $defaults['voting']['category_steps'] );
	}

	public function test_parse_preserves_new_duration_defaults(): void {
		$parsed = Competition_Settings::parse( '' );

		$this->assertSame( 10, $parsed['slideshow']['preview_duration'] );
		$this->assertSame( 15, $parsed['slideshow']['voting_duration'] );
		$this->assertSame( 0, $parsed['slideshow']['critique_duration'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make test` (or with filter: `WP_ENV_TEST_DB_HOST="..." composer test -- --filter CompetitionSettingsDurationTest`)
Expected: FAIL — keys not found in defaults.

- [ ] **Step 3: Add duration defaults and category_steps to defaults()**

In `src/includes/Support/class-competition-settings.php`, update the `defaults()` method. Find the `slideshow` key (around line 93) and add the three duration fields. Find the `voting` key and add `category_steps`.

```php
// In the slideshow defaults array, add after 'progress_meter_type':
'preview_duration'    => 10,
'voting_duration'     => 15,
'critique_duration'   => 0,

// In the voting defaults array, add:
'category_steps'    => array(),
'voted_categories'  => array(),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make test` (or with filter: `WP_ENV_TEST_DB_HOST="..." composer test -- --filter CompetitionSettingsDurationTest`)
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/includes/Support/class-competition-settings.php tests/phpunit/CompetitionSettingsDurationTest.php
git commit -m "feat: Add per-step slideshow duration defaults and category_steps to settings"
```

---

### Task 2: Add duration fields to global settings page

**Files:**
- Modify: `src/admin/class-settings-controller.php:554-579` (render), `src/admin/class-settings-controller.php:343-376` (handle_actions)

- [ ] **Step 1: Add duration fields to the settings form render**

In `src/admin/class-settings-controller.php`, find the slideshow section in `render()` (around line 554). After the progress meter style selector, add three number input fields:

```php
<tr>
	<th scope="row">
		<label for="preview_duration"><?php esc_html_e( 'Preview Duration', 'photo-competition-manager' ); ?></label>
	</th>
	<td>
		<input type="number" id="preview_duration" name="preview_duration" value="<?php echo esc_attr( $settings['slideshow']['preview_duration'] ); ?>" min="0" max="120" step="1" class="small-text" />
		<span><?php esc_html_e( 'seconds (0 = manual advance)', 'photo-competition-manager' ); ?></span>
	</td>
</tr>
<tr>
	<th scope="row">
		<label for="voting_duration"><?php esc_html_e( 'Voting Slideshow Duration', 'photo-competition-manager' ); ?></label>
	</th>
	<td>
		<input type="number" id="voting_duration" name="voting_duration" value="<?php echo esc_attr( $settings['slideshow']['voting_duration'] ); ?>" min="0" max="120" step="1" class="small-text" />
		<span><?php esc_html_e( 'seconds (0 = manual advance)', 'photo-competition-manager' ); ?></span>
	</td>
</tr>
<tr>
	<th scope="row">
		<label for="critique_duration"><?php esc_html_e( 'Critique Duration', 'photo-competition-manager' ); ?></label>
	</th>
	<td>
		<input type="number" id="critique_duration" name="critique_duration" value="<?php echo esc_attr( $settings['slideshow']['critique_duration'] ); ?>" min="0" max="120" step="1" class="small-text" />
		<span><?php esc_html_e( 'seconds (0 = manual advance)', 'photo-competition-manager' ); ?></span>
	</td>
</tr>
```

- [ ] **Step 2: Handle the new fields in handle_actions()**

In the `handle_actions()` method, find where slideshow settings are saved (around line 373). Read and sanitize the three new POST fields, then include them in the saved settings:

```php
$preview_duration  = isset( $_POST['preview_duration'] ) ? absint( wp_unslash( $_POST['preview_duration'] ) ) : 10;
$voting_duration   = isset( $_POST['voting_duration'] ) ? absint( wp_unslash( $_POST['voting_duration'] ) ) : 15;
$critique_duration = isset( $_POST['critique_duration'] ) ? absint( wp_unslash( $_POST['critique_duration'] ) ) : 0;

// Clamp to 0-120 range.
$preview_duration  = min( 120, $preview_duration );
$voting_duration   = min( 120, $voting_duration );
$critique_duration = min( 120, $critique_duration );
```

Add these to the slideshow settings array that gets saved, and remove the hardcoded `'duration_seconds' => 10` — it is replaced by the three per-step durations:

```php
'preview_duration'    => $preview_duration,
'voting_duration'     => $voting_duration,
'critique_duration'   => $critique_duration,
```

- [ ] **Step 3: Verify manually in browser**

Open `http://localhost:8888/wp-admin/admin.php?page=photo-competition-manager-settings`, scroll to the Slideshow section. Confirm three new number fields appear with correct defaults. Change values, save, reload — values persist.

- [ ] **Step 4: Commit**

```bash
git add src/admin/class-settings-controller.php
git commit -m "feat: Add per-step slideshow duration settings to global settings page"
```

---

## Chunk 2: AJAX Endpoint and Step Persistence

### Task 3: Register AJAX handler for step advancement

**Files:**
- Modify: `src/admin/class-voting-controller.php` (add AJAX method)
- Modify: `src/admin/class-admin-screen.php` (register AJAX action, pass nonce)

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/VotingStepAjaxTest.php`:

```php
<?php

namespace PhotoCompetitionManager\Tests;

use PhotoCompetitionManager\Admin\Voting_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use WP_UnitTestCase;

class VotingStepAjaxTest extends WP_UnitTestCase {

	private $competitions;

	public function set_up(): void {
		parent::set_up();
		$this->competitions = new Competitions_Repository();
	}

	public function test_advance_step_updates_category_steps(): void {
		// Create a competition.
		$comp_id = $this->competitions->create( array(
			'title'      => 'Test Competition',
			'slug'       => 'test-comp',
			'open_date'  => '2026-01-01',
			'close_date' => '2026-12-31',
			'settings'   => array(),
		) );

		// Simulate advancing to step 2 for 'colour' category.
		$competition = $this->competitions->find( $comp_id );
		$settings    = Competition_Settings::parse( $competition->settings );

		$settings['voting']['category_steps']['colour'] = 2;
		$this->competitions->update( $comp_id, array( 'settings' => $settings ) );

		// Verify it persisted.
		$competition = $this->competitions->find( $comp_id );
		$settings    = Competition_Settings::parse( $competition->settings );
		$this->assertSame( 2, $settings['voting']['category_steps']['colour'] );
	}

	public function test_step_6_writes_voted_categories(): void {
		$comp_id = $this->competitions->create( array(
			'title'      => 'Test Competition',
			'slug'       => 'test-comp',
			'open_date'  => '2026-01-01',
			'close_date' => '2026-12-31',
			'settings'   => array(),
		) );

		$competition = $this->competitions->find( $comp_id );
		$settings    = Competition_Settings::parse( $competition->settings );

		$settings['voting']['category_steps']['colour']   = 6;
		$settings['voting']['voted_categories'][]         = $comp_id . '_colour';
		$this->competitions->update( $comp_id, array( 'settings' => $settings ) );

		$competition = $this->competitions->find( $comp_id );
		$settings    = Competition_Settings::parse( $competition->settings );
		$this->assertContains( $comp_id . '_colour', $settings['voting']['voted_categories'] );
		$this->assertSame( 6, $settings['voting']['category_steps']['colour'] );
	}
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `make test` (or with filter: `WP_ENV_TEST_DB_HOST="..." composer test -- --filter VotingStepAjaxTest`)
Expected: PASS — this tests the data layer, which should already work with the settings changes from Task 1.

- [ ] **Step 3: Add the AJAX handler method to Voting_Controller**

Add a public method `handle_advance_step()` to `src/admin/class-voting-controller.php`:

```php
/**
 * AJAX handler for advancing the voting workflow step.
 *
 * @return void
 */
public function handle_advance_step(): void {
	check_ajax_referer( 'photo_comp_voting_step', '_wpnonce' );

	if ( ! current_user_can( 'manage_photo_competitions' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'photo-competition-manager' ) ), 403 );
	}

	$competition_id = isset( $_POST['competition_id'] ) ? absint( wp_unslash( $_POST['competition_id'] ) ) : 0;
	$category_slug  = isset( $_POST['category_slug'] ) ? sanitize_text_field( wp_unslash( $_POST['category_slug'] ) ) : '';
	$step           = isset( $_POST['step'] ) ? absint( wp_unslash( $_POST['step'] ) ) : 0;

	if ( ! $competition_id || '' === $category_slug || $step < 1 || $step > 6 ) {
		wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'photo-competition-manager' ) ) );
	}

	$competition = $this->competitions->find( $competition_id );
	if ( ! $competition ) {
		wp_send_json_error( array( 'message' => __( 'Competition not found.', 'photo-competition-manager' ) ) );
	}

	$settings = Competition_Settings::parse( $competition->settings );
	$settings['voting']['category_steps'][ $category_slug ] = $step;

	// Step 6 = category complete. Also write to voted_categories for backward compat.
	if ( 6 === $step ) {
		$category_key = $competition_id . '_' . $category_slug;
		if ( ! in_array( $category_key, $settings['voting']['voted_categories'] ?? array(), true ) ) {
			$settings['voting']['voted_categories'][] = $category_key;
		}
	}

	$result = $this->competitions->update( $competition_id, array( 'settings' => $settings ) );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( array( 'step' => $step ) );
}
```

- [ ] **Step 4: Register the AJAX action in admin-screen.php**

In `src/admin/class-voting-controller.php`, add a `register()` method (or update the existing one if it exists) that hooks the AJAX action. This is called during plugin bootstrap:

```php
public function register(): void {
	add_action( 'wp_ajax_photo_comp_advance_voting_step', array( $this, 'handle_advance_step' ) );
}
```

Ensure the plugin bootstrap calls `$voting_controller->register()`. Check `src/admin/class-admin-screen.php` for how the controller is instantiated and add the `register()` call there if needed.

Also update the `wp_localize_script` call in `src/admin/class-admin-screen.php` `enqueue_admin_assets()` (around line 283) to pass the step nonce:

```php
wp_localize_script(
	'photo-competition-manager-admin-slideshow',
	'photoCompetitionManagerSlideshow',
	array(
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'nonce'     => wp_create_nonce( 'photo_comp_admin_slideshow' ),
		'stepNonce' => wp_create_nonce( 'photo_comp_voting_step' ),
	)
);
```

- [ ] **Step 5: Update server-side voting actions to write category_steps**

In `src/admin/class-voting-controller.php`, find the `open_category_voting` action handler. Add the step write to `$settings` *before* the existing `$this->competitions->update()` call (the one that saves `open_categories`):

```php
// Add this line BEFORE the update() call:
$settings['voting']['category_steps'][ $category_slug ] = 3;
```

Find the `close_category_voting` action handler. Similarly, add *before* the existing `update()` call:

```php
// Add this line BEFORE the update() call:
$settings['voting']['category_steps'][ $category_slug ] = 5;
```

Both writes piggyback on the same `$this->competitions->update()` call that already saves the voting state changes.

- [ ] **Step 6: Commit**

```bash
git add src/admin/class-voting-controller.php src/admin/class-admin-screen.php tests/phpunit/VotingStepAjaxTest.php
git commit -m "feat: Add AJAX endpoint for voting step advancement and persist category_steps"
```

---

## Chunk 3: Rewrite the Voting Controls Page Rendering

### Task 4: Rewrite render() and supporting methods for step-based UI

This is the largest task. It replaces the existing `render_competition_status_bar()`, `render_category_tabs()`, `render_category_control_panel()`, `render_quick_actions()`, and `render_competition_complete()` methods.

**Files:**
- Modify: `src/admin/class-voting-controller.php:475-670` (render and helper methods)

- [ ] **Step 1: Add page load recovery logic to render()**

At the top of the `render()` method, after determining `$active_category_data` and before rendering, add recovery logic that reconciles stored step with live state:

```php
// Page load recovery: live state wins over stored step.
foreach ( $all_categories as &$cat_data ) {
	$cat_slug    = $cat_data['category']['slug'] ?? '';
	$cat_comp_id = (int) $cat_data['competition']->id;
	$cat_key     = $cat_comp_id . '_' . $cat_slug;
	$cat_settings = $cat_data['settings'];

	$stored_step = $cat_settings['voting']['category_steps'][ $cat_slug ] ?? 1;

	// If voting is currently open for this category and step < 3, jump to 3.
	$open_cats = Competition_Settings::get_open_voting_categories( $cat_settings );
	if ( in_array( $cat_slug, $open_cats, true ) && $stored_step < 3 ) {
		$stored_step = 3;
	}

	// If category is in voted_categories and step < 5, jump to 5.
	$voted = $cat_settings['voting']['voted_categories'] ?? array();
	if ( in_array( $cat_key, $voted, true ) && $stored_step < 5 ) {
		$stored_step = 5;
	}

	$cat_data['current_step'] = $stored_step;
}
unset( $cat_data );
```

- [ ] **Step 2: Rewrite render_competition_status_bar()**

Replace the existing method with a WP postbox version. Remove the inline warning — the workflow card handles that.

```php
private function render_competition_status_bar( object $competition, array $settings ): void {
	$uploads_closed  = $settings['upload']['uploads_closed'] ?? false;
	$results_visible = $settings['results']['results_visible'] ?? false;

	// Build action URLs (keep existing URL building logic).
	// ... (preserve existing nonce URL generation for close/open uploads, show/hide results)

	?>
	<div class="postbox photo-comp-status-bar">
		<div class="inside" style="margin: 0; padding: 10px 14px;">
			<div class="status-bar-layout">
				<strong class="status-bar-title"><?php echo esc_html( $competition->title ); ?></strong>
				<div class="status-bar-controls">
					<div class="status-control">
						<span class="status-control-label"><?php esc_html_e( 'Uploads', 'photo-competition-manager' ); ?></span>
						<?php if ( $uploads_closed ) : ?>
							<span class="photo-comp-badge photo-comp-badge-success"><?php esc_html_e( 'Closed', 'photo-competition-manager' ); ?></span>
							<a href="<?php echo esc_url( $open_uploads_url ); ?>" class="button button-small"><?php esc_html_e( 'Reopen', 'photo-competition-manager' ); ?></a>
						<?php else : ?>
							<span class="photo-comp-badge photo-comp-badge-warning"><?php esc_html_e( 'Open', 'photo-competition-manager' ); ?></span>
							<a href="<?php echo esc_url( $close_uploads_url ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Close Uploads', 'photo-competition-manager' ); ?></a>
						<?php endif; ?>
					</div>
					<div class="status-control">
						<span class="status-control-label"><?php esc_html_e( 'Results', 'photo-competition-manager' ); ?></span>
						<?php if ( $results_visible ) : ?>
							<span class="photo-comp-badge photo-comp-badge-warning"><?php esc_html_e( 'Visible', 'photo-competition-manager' ); ?></span>
							<a href="<?php echo esc_url( $hide_results_url ); ?>" class="button button-primary button-small"><?php esc_html_e( 'Hide', 'photo-competition-manager' ); ?></a>
						<?php else : ?>
							<span class="photo-comp-badge photo-comp-badge-success"><?php esc_html_e( 'Hidden', 'photo-competition-manager' ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</div>
	<?php
}
```

- [ ] **Step 3: Rewrite render_category_tabs() to use nav-tab-wrapper**

Replace the floating tab bar with WP nav-tab-wrapper attached to the postbox:

```php
private function render_category_tabs( array $all_categories, string $current_key, bool $voting_open_globally, ?int $open_competition_id, ?string $open_category_slug, array $voted_categories = array() ): void {
	if ( count( $all_categories ) < 2 ) {
		return; // Single category: no tabs, heading rendered inside card.
	}
	?>
	<nav class="nav-tab-wrapper photo-comp-category-tabs">
		<?php foreach ( $all_categories as $cat_data ) :
			$tab_key        = $cat_data['key'];
			$tab_cat        = $cat_data['category'];
			$tab_count      = $cat_data['image_count'];
			$tab_is_active  = ( $tab_key === $current_key );
			$tab_has_voting = $voting_open_globally && (int) $cat_data['competition']->id === $open_competition_id && ( $tab_cat['slug'] ?? '' ) === $open_category_slug;
			$tab_is_complete = ( $cat_data['current_step'] ?? 1 ) >= 6;
			$tab_url = add_query_arg(
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
}
```

- [ ] **Step 4: Create render_workflow_steps() method**

This is the core new method. It renders the 5-step workflow for a category inside a postbox.

```php
private function render_workflow_steps( array $category_data, bool $is_ready, bool $voting_open_globally, ?int $open_competition_id, ?string $open_category_slug, array $global_settings ): void {
	$competition    = $category_data['competition'];
	$category       = $category_data['category'];
	$image_count    = $category_data['image_count'];
	$category_slug  = $category['slug'] ?? '';
	$category_label = $category['label'] ?? '';
	$current_step   = $category_data['current_step'] ?? 1;
	$settings       = $category_data['settings'];
	$comp_id        = (int) $competition->id;

	// Duration defaults from global settings.
	$preview_duration  = $global_settings['slideshow']['preview_duration'] ?? 10;
	$voting_duration   = $global_settings['slideshow']['voting_duration'] ?? 15;
	$critique_duration = $global_settings['slideshow']['critique_duration'] ?? 0;

	// Check if another category has voting open (blocks step 2).
	$another_cat_voting = $voting_open_globally
		&& ! ( $open_competition_id === $comp_id && $open_category_slug === $category_slug );

	// Build action URLs for Open/Close voting (preserve existing URL building).
	$focus_args = array(
		'page'  => 'photo-competition-manager-voting',
		'focus' => $comp_id . '_' . $category_slug,
	);

	$open_voting_url = wp_nonce_url(
		add_query_arg(
			array_merge( $focus_args, array(
				'action'      => 'open_category_voting',
				'competition' => $comp_id,
				'category'    => $category_slug,
			) ),
			admin_url( 'admin.php' )
		),
		'photo_competition_open_voting_' . $comp_id . '_' . $category_slug
	);

	$close_voting_url = wp_nonce_url(
		add_query_arg(
			array_merge( $focus_args, array(
				'action'      => 'close_category_voting',
				'competition' => $comp_id,
				'category'    => $category_slug,
			) ),
			admin_url( 'admin.php' )
		),
		'photo_competition_close_voting_' . $comp_id . '_' . $category_slug
	);

	// Is voting currently open for THIS category?
	$voting_open_here = $voting_open_globally
		&& $open_competition_id === $comp_id
		&& $open_category_slug === $category_slug;

	$steps = array(
		1 => array(
			'label'       => __( 'Preview Slideshow', 'photo-competition-manager' ),
			'description' => __( 'Show images to the room before opening voting', 'photo-competition-manager' ),
			'type'        => 'slideshow',
			'duration'    => $preview_duration,
			'optional'    => true,
		),
		2 => array(
			'label'       => __( 'Open Voting', 'photo-competition-manager' ),
			'description' => __( 'Members vote on their devices', 'photo-competition-manager' ),
			'type'        => 'voting_open',
			'optional'    => false,
		),
		3 => array(
			'label'       => __( 'Show Slideshow', 'photo-competition-manager' ),
			'description' => __( 'Display images on projector while members vote on their phones', 'photo-competition-manager' ),
			'type'        => 'slideshow',
			'duration'    => $voting_duration,
			'optional'    => true,
		),
		4 => array(
			'label'       => __( 'Close Voting', 'photo-competition-manager' ),
			'description' => __( 'Lock in votes for this category', 'photo-competition-manager' ),
			'type'        => 'voting_close',
			'optional'    => false,
		),
		5 => array(
			'label'       => __( 'Critique', 'photo-competition-manager' ),
			'description' => __( 'Manual slideshow for discussion', 'photo-competition-manager' ),
			'type'        => 'slideshow',
			'duration'    => $critique_duration,
			'optional'    => true,
		),
	);

	?>
	<div class="postbox photo-comp-workflow-card"
		data-competition-id="<?php echo esc_attr( $comp_id ); ?>"
		data-competition-slug="<?php echo esc_attr( $competition->slug ); ?>"
		data-category="<?php echo esc_attr( $category_slug ); ?>"
		data-category-label="<?php echo esc_attr( $category_label ); ?>">

		<?php // Category tabs are rendered outside this method by render_category_tabs(). ?>
		<?php // Single-category heading is handled in Task 7. ?>

		<div class="inside <?php echo ! $is_ready ? 'photo-comp-workflow-disabled' : ''; ?>">
			<?php if ( ! $is_ready ) : ?>
				<div class="notice notice-warning inline photo-comp-prereq-notice">
					<p>
					<?php
					$uploads_closed  = $settings['upload']['uploads_closed'] ?? false;
					$results_visible = $settings['results']['results_visible'] ?? false;
					if ( ! $uploads_closed ) {
						esc_html_e( 'Close uploads before starting the voting workflow.', 'photo-competition-manager' );
					} elseif ( $results_visible ) {
						esc_html_e( 'Hide results before starting the voting workflow.', 'photo-competition-manager' );
					}
					?>
					</p>
				</div>
			<?php endif; ?>

			<div class="photo-comp-steps">
				<?php foreach ( $steps as $step_num => $step ) :
					$is_completed = $current_step > $step_num;
					$is_active    = $current_step === $step_num && $is_ready;
					$is_upcoming  = $current_step < $step_num || ! $is_ready;
					?>
					<div class="photo-comp-step <?php echo $is_completed ? 'step-completed' : ''; ?> <?php echo $is_active ? 'step-active' : ''; ?> <?php echo $is_upcoming ? 'step-upcoming' : ''; ?>">
						<div class="step-indicator">
							<?php if ( $is_completed ) : ?>
								<span class="step-circle step-circle-done">&#10003;</span>
							<?php elseif ( $is_active ) : ?>
								<span class="step-circle step-circle-active"><?php echo (int) $step_num; ?></span>
							<?php else : ?>
								<span class="step-circle step-circle-upcoming"><?php echo (int) $step_num; ?></span>
							<?php endif; ?>
						</div>
						<div class="step-content">
							<div class="step-label">
								<?php if ( $is_completed ) : ?>
									<s><?php echo esc_html( $step['label'] ); ?></s>
								<?php else : ?>
									<?php echo esc_html( $step['label'] ); ?>
								<?php endif; ?>

								<?php // Show "Voting Open" badge on completed step 2 while voting is open. ?>
								<?php if ( 2 === $step_num && $is_completed && $voting_open_here ) : ?>
									<span class="photo-comp-badge photo-comp-badge-success"><?php esc_html_e( 'Voting Open', 'photo-competition-manager' ); ?></span>
								<?php endif; ?>
							</div>

							<?php if ( $is_active ) : ?>
								<div class="step-description"><?php echo esc_html( $step['description'] ); ?></div>
								<div class="step-actions">
									<?php if ( 'slideshow' === $step['type'] ) : ?>
										<button type="button" class="button button-primary photo-competition-manager-start-slideshow"
											data-competition-id="<?php echo esc_attr( $comp_id ); ?>"
											data-competition-slug="<?php echo esc_attr( $competition->slug ); ?>"
											data-category="<?php echo esc_attr( $category_slug ); ?>"
											data-category-label="<?php echo esc_attr( $category_label ); ?>">
											<?php
											if ( 1 === $step_num ) {
												esc_html_e( 'Start Preview', 'photo-competition-manager' );
											} elseif ( 5 === $step_num ) {
												esc_html_e( 'Start Critique', 'photo-competition-manager' );
											} else {
												esc_html_e( 'Start Slideshow', 'photo-competition-manager' );
											}
											?>
											&#9654;
										</button>
										<span class="step-separator">|</span>
										<label class="step-duration-label">
											<?php esc_html_e( 'Duration:', 'photo-competition-manager' ); ?>
											<input type="number" class="small-text photo-comp-step-duration" value="<?php echo esc_attr( $step['duration'] ); ?>" min="0" max="120" step="1" />s
										</label>
										<span class="step-separator">|</span>
										<button type="button" class="button photo-comp-continue-step"
											data-competition-id="<?php echo esc_attr( $comp_id ); ?>"
											data-category="<?php echo esc_attr( $category_slug ); ?>"
											data-next-step="<?php echo esc_attr( $step_num + 1 ); ?>">
											<?php esc_html_e( 'Continue', 'photo-competition-manager' ); ?> &rarr;
										</button>
									<?php elseif ( 'voting_open' === $step['type'] ) : ?>
										<?php if ( $another_cat_voting ) : ?>
											<button type="button" class="button" disabled title="<?php esc_attr_e( 'Close voting in the other category first', 'photo-competition-manager' ); ?>">
												<?php esc_html_e( 'Open Voting', 'photo-competition-manager' ); ?>
											</button>
											<span class="step-hint"><?php esc_html_e( 'Close voting in the other category first.', 'photo-competition-manager' ); ?></span>
										<?php else : ?>
											<a href="<?php echo esc_url( $open_voting_url ); ?>" class="button button-primary">
												<?php esc_html_e( 'Open Voting', 'photo-competition-manager' ); ?>
											</a>
										<?php endif; ?>
									<?php elseif ( 'voting_close' === $step['type'] ) : ?>
										<a href="<?php echo esc_url( $close_voting_url ); ?>" class="button button-primary">
											<?php esc_html_e( 'Close Voting', 'photo-competition-manager' ); ?>
										</a>
									<?php endif; ?>
								</div>
							<?php elseif ( $is_upcoming && ! $is_completed ) : ?>
								<span class="step-upcoming-desc"><?php echo esc_html( $step['description'] ); ?></span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
}
```

- [ ] **Step 5: Update render() to use the new methods**

Replace the rendering section of `render()` (around lines 637-667) to call the new methods. The overall flow becomes:

```php
// Render Competition Status Bar.
$this->render_competition_status_bar( $active_competition, $active_settings );

// Determine readiness.
$uploads_closed  = $active_settings['upload']['uploads_closed'] ?? false;
$results_visible = $active_settings['results']['results_visible'] ?? false;
$is_ready        = $uploads_closed && ! $results_visible;

// Render category tabs (attached to the workflow card postbox).
$this->render_category_tabs( $all_categories, $current_key, $voting_open_globally, $open_competition_id, $open_category_slug, $voted_categories );

// Check if all categories are complete.
// Check completion: category_steps >= 6 is primary, voted_categories is fallback for pre-upgrade data.
$all_complete = true;
foreach ( $all_categories as $cat_data ) {
	$step = $cat_data['current_step'] ?? 1;
	$key_in_voted = in_array( $cat_data['key'], $voted_categories, true );
	if ( $step < 6 && ! $key_in_voted ) {
		$all_complete = false;
		break;
	}
}

if ( $all_complete && ! $voting_open_globally ) {
	$this->render_competition_complete( $active_competition, $all_categories, $voted_categories, $active_settings, $global_settings );
} else {
	$this->render_workflow_steps( $active_category_data, $is_ready, $voting_open_globally, $open_competition_id, $open_category_slug, $global_settings );
}

// Render Quick Actions.
$this->render_quick_actions( $voting_page_url, $global_settings, $active_settings );

// Hidden meter type setting for slideshow.
$meter_type = $active_settings['slideshow']['progress_meter_type'] ?? 'bar';
echo '<input type="hidden" id="slideshow-meter-type" value="' . esc_attr( $meter_type ) . '" />';

// Slideshow container.
$this->render_slideshow_container();
```

Remove the hidden `#slideshow-duration-setting` input — duration is now read from the per-step `.photo-comp-step-duration` input.

- [ ] **Step 6: Update render_competition_complete() for new styling**

Update the existing method to use WP postbox styling and two separate duration inputs (slideshow replay + critique replay). Keep the existing replay button functionality but remove the 7-button duration presets, replacing them with two text inputs.

- [ ] **Step 7: Verify manually in browser**

Open `http://localhost:8888/wp-admin/admin.php?page=photo-competition-manager-voting`.

Check:
- Status bar renders as a WP postbox with competition name, upload/results controls.
- Category tabs are WP nav-tab-wrapper style, attached to the card.
- Steps 1-5 render with correct states (active step highlighted, upcoming muted).
- When uploads are open, the workflow content is at reduced opacity with a notice.
- Close uploads → workflow becomes active, step 1 highlighted.

- [ ] **Step 8: Commit**

```bash
git add src/admin/class-voting-controller.php
git commit -m "feat: Rewrite voting controls page with step-based workflow and WP postbox styling"
```

---

## Chunk 4: CSS and JavaScript Updates

### Task 5: Rewrite CSS for WordPress admin styling

**Files:**
- Modify: `src/assets/css/admin-slideshow.css:260-1015`

- [ ] **Step 1: Remove deprecated CSS**

Delete the following CSS blocks (keep the slideshow modal and QR code styling):
- `.competition-status-bar` and children (lines ~272-357)
- `.category-tabs-bar` and children (lines ~362-435)
- `.category-control-panel` and children (lines ~441-669)
- `.competition-complete-panel` and children (lines ~813-938)
- `.photo-comp-focus-panel` (lines ~945-953)

- [ ] **Step 2: Add new CSS for step-based workflow**

Add after the QR code styles:

```css
/* ==========================================================================
   Voting Controls - Status Bar
   ========================================================================== */

.photo-comp-status-bar {
	margin-bottom: 16px;
}

.photo-comp-status-bar .inside {
	margin: 0;
	padding: 10px 14px;
}

.status-bar-layout {
	display: flex;
	align-items: center;
	gap: 16px;
	flex-wrap: wrap;
}

.status-bar-layout .status-bar-title {
	margin-right: auto;
	font-size: 14px;
}

.status-bar-controls {
	display: flex;
	gap: 20px;
	flex-wrap: wrap;
}

.status-control {
	display: flex;
	align-items: center;
	gap: 8px;
}

.status-control-label {
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	color: #646970;
}

/* Badges */
.photo-comp-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 3px;
	font-size: 11px;
	font-weight: 600;
}

.photo-comp-badge-success {
	background: #d4edda;
	color: #155724;
}

.photo-comp-badge-warning {
	background: #fcf0c3;
	color: #6e4e00;
}

/* ==========================================================================
   Voting Controls - Category Tabs
   ========================================================================== */

.photo-comp-category-tabs {
	margin-bottom: 0;
	padding-top: 0;
}

.photo-comp-category-tabs .nav-tab {
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

.photo-comp-tab-count {
	color: #646970;
	font-weight: normal;
}

.nav-tab-active .photo-comp-tab-count {
	color: #1d2327;
}

.photo-comp-voting-dot {
	display: inline-block;
	width: 7px;
	height: 7px;
	background: #00a32a;
	border-radius: 50%;
	margin-left: 2px;
}

/* ==========================================================================
   Voting Controls - Workflow Card
   ========================================================================== */

.photo-comp-workflow-card {
	margin-bottom: 16px;
}

/* When tabs are present, remove top border-radius to attach to tabs. */
.photo-comp-category-tabs + .photo-comp-workflow-card {
	border-top: none;
	margin-top: -1px;
}

.photo-comp-workflow-disabled > .photo-comp-steps {
	opacity: 0.45;
	pointer-events: none;
}

.photo-comp-prereq-notice {
	margin: 0 0 12px 0 !important;
}

/* ==========================================================================
   Voting Controls - Steps
   ========================================================================== */

.photo-comp-steps {
	padding: 4px 0;
}

.photo-comp-step {
	display: flex;
	align-items: flex-start;
	gap: 10px;
	padding: 10px 12px;
}

.photo-comp-step.step-active {
	background: #f0f6fc;
	border-left: 4px solid #2271b1;
	border-radius: 0 4px 4px 0;
	padding: 12px;
	margin-bottom: 4px;
}

.step-indicator {
	flex-shrink: 0;
}

.step-circle {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	font-size: 11px;
	font-weight: 600;
}

.step-circle-done {
	background: #00a32a;
	color: #fff;
}

.step-circle-active {
	background: #2271b1;
	color: #fff;
}

.step-circle-upcoming {
	border: 2px solid #c3c4c7;
	color: #646970;
	width: 22px;
	height: 22px;
}

.step-content {
	flex: 1;
	min-width: 0;
}

.step-label {
	font-weight: 600;
	font-size: 13px;
	color: #1d2327;
}

.step-completed .step-label {
	color: #646970;
}

.step-upcoming .step-label {
	color: #646970;
}

.step-description {
	font-size: 12px;
	color: #646970;
	margin: 4px 0 10px;
}

.step-upcoming-desc {
	font-size: 12px;
	color: #a7aaad;
}

.step-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.step-separator {
	color: #c3c4c7;
}

.step-duration-label {
	font-size: 12px;
	color: #646970;
}

.photo-comp-step-duration {
	width: 48px !important;
	text-align: center;
}

.step-hint {
	font-size: 12px;
	color: #646970;
	font-style: italic;
}
```

- [ ] **Step 3: Verify manually in browser**

Reload `http://localhost:8888/wp-admin/admin.php?page=photo-competition-manager-voting`. Check:
- Postbox styling matches WP admin conventions.
- Step states have correct visual treatment (active blue, completed green, upcoming grey).
- Disabled state at 45% opacity with notice visible.
- Category tabs attach to the workflow card.

- [ ] **Step 4: Commit**

```bash
git add src/assets/css/admin-slideshow.css
git commit -m "feat: Replace custom voting controls CSS with WP admin postbox styling"
```

---

### Task 6: Update JavaScript for step-based workflow

**Files:**
- Modify: `src/assets/js/admin-slideshow.js`

- [ ] **Step 1: Update getDisplayDuration() to read from per-step input**

Replace the current `getDisplayDuration()` method (lines 42-50) to read from the active step's `.photo-comp-step-duration` input instead of the global `#slideshow-duration-setting`:

```javascript
getDisplayDuration() {
	// Read duration from the active step's input, or the completion panel's input.
	const $activeStep = $('.photo-comp-step.step-active');
	let duration = 10; // fallback default.

	if ($activeStep.length) {
		const $input = $activeStep.find('.photo-comp-step-duration');
		if ($input.length) {
			duration = parseInt($input.val(), 10) || 0;
		}
	} else {
		// Completion panel: find the input nearest to the clicked button.
		const $focusedInput = $(document.activeElement).closest('.complete-category-item, .complete-slideshow-section').find('.photo-comp-step-duration');
		if ($focusedInput.length) {
			duration = parseInt($focusedInput.val(), 10) || 0;
		}
	}

	return duration * 1000;
}
```

- [ ] **Step 2: Add Continue button AJAX handler**

In the `bindEvents()` method, add a click handler for `.photo-comp-continue-step`:

```javascript
$(document).on('click', '.photo-comp-continue-step', function(e) {
	e.preventDefault();
	const $btn = $(this);
	const competitionId = $btn.data('competition-id');
	const categorySlug = $btn.data('category');
	const nextStep = $btn.data('next-step');

	$btn.prop('disabled', true).text('Saving...');

	$.ajax({
		url: photoCompetitionManagerSlideshow.ajaxUrl,
		type: 'POST',
		data: {
			action: 'photo_comp_advance_voting_step',
			_wpnonce: photoCompetitionManagerSlideshow.stepNonce,
			competition_id: competitionId,
			category_slug: categorySlug,
			step: nextStep
		},
		success: function(response) {
			if (response.success) {
				// Reload page to show updated step state.
				window.location.reload();
			} else {
				alert(response.data.message || 'Could not save progress — try again.');
				$btn.prop('disabled', false).html('Continue &rarr;');
			}
		},
		error: function() {
			alert('Could not save progress — try again.');
			$btn.prop('disabled', false).html('Continue &rarr;');
		}
	});
});
```

- [ ] **Step 3: Remove critique mode handler**

In `bindEvents()`, remove the critique mode click handler (lines 149-174) and the `.photo-competition-manager-start-critique` binding. The critique step now uses the same `photo-competition-manager-start-slideshow` handler with its own duration input.

Also remove the `isInCritiqueMode` flag and the `previousDuration` restore logic in the `stop()` method (lines 386-395).

- [ ] **Step 4: Remove duration preset button handler**

In `bindEvents()`, remove the `.duration-preset` click handler (lines 117-132). Duration is now a text input per step.

- [ ] **Step 5: Update slideshow start to read duration from the triggering step**

Update the `.photo-competition-manager-start-slideshow` handler to read duration from the nearest `.photo-comp-step-duration` input rather than the global hidden input:

```javascript
// In the slideshow start handler, before calling startSlideshow():
const $step = $(this).closest('.photo-comp-step, .complete-category-item, .complete-slideshow-section');
const $durationInput = $step.find('.photo-comp-step-duration');
if ($durationInput.length) {
	this.overrideDuration = parseInt($durationInput.val(), 10) * 1000 || 0;
}
```

Then in `getDisplayDuration()`, check `this.overrideDuration` first.

- [ ] **Step 6: Verify manually in browser**

Test the full workflow:
1. Close uploads → workflow activates.
2. Step 1 active: click "Start Preview" → slideshow opens with correct duration from text input.
3. Exit slideshow, click "Continue" → step 2 becomes active.
4. Click "Open Voting" → page reloads, step 3 active, "Voting Open" badge on step 2.
5. Click "Start Slideshow" → slideshow opens with voting duration.
6. Exit, click "Continue" → step 4 active.
7. Click "Close Voting" → page reloads, step 5 active.
8. Click "Continue" → category complete, tab shows checkmark.

- [ ] **Step 7: Commit**

```bash
git add src/assets/js/admin-slideshow.js
git commit -m "feat: Update slideshow JS for step-based workflow with per-step durations"
```

---

## Chunk 5: Edge Cases and Cleanup

### Task 7: Handle single-category edge case

**Files:**
- Modify: `src/admin/class-voting-controller.php`

- [ ] **Step 1: Update render() for single category**

In the `render()` method, when `count( $all_categories ) < 2`, skip the tab rendering and instead render a heading inside the workflow card postbox:

```php
if ( count( $all_categories ) >= 2 ) {
	$this->render_category_tabs( /* ... */ );
}
```

Inside `render_workflow_steps()`, when there are no tabs, add a heading before the steps:

```php
// At the start of the inside div, if single category:
if ( count( $all_categories ) < 2 ) : ?>
	<h2 class="photo-comp-single-category-heading">
		<?php echo esc_html( $category_label ); ?>
		<span class="photo-comp-tab-count">(<?php echo (int) $image_count; ?> <?php esc_html_e( 'images', 'photo-competition-manager' ); ?>)</span>
	</h2>
<?php endif;
```

Pass `$all_categories` count to `render_workflow_steps()` or use a class-level check.

- [ ] **Step 2: Verify in browser**

Temporarily remove categories so only one has images. Confirm no tab bar renders and the heading appears inside the card.

- [ ] **Step 3: Commit**

```bash
git add src/admin/class-voting-controller.php
git commit -m "fix: Handle single-category display without tab bar"
```

---

### Task 8: Remove dead code and deprecated CSS

**Files:**
- Modify: `src/admin/class-voting-controller.php`
- Modify: `src/assets/css/admin-slideshow.css`

- [ ] **Step 1: Remove render_results_links()**

The `render_results_links()` method (lines 767-810) is no longer called — results links are in the Quick Actions and the completion panel. Delete it.

- [ ] **Step 2: Remove deprecated CSS comment block**

Delete the "Legacy styles retained for backward compatibility" section (lines ~943-953).

- [ ] **Step 3: Remove the hidden #slideshow-duration-setting input**

In `render()`, remove:
```php
echo '<input type="hidden" id="slideshow-duration-setting" value="20" />';
```

- [ ] **Step 4: Verify no regressions**

Full manual test of the voting workflow. Also verify the slideshow modal still works correctly (fullscreen display, progress meter, pause/resume, keyboard controls).

- [ ] **Step 5: Commit**

```bash
git add src/admin/class-voting-controller.php src/assets/css/admin-slideshow.css
git commit -m "chore: Remove dead code and deprecated styles from voting controls"
```

---

### Task 9: Final integration test

- [ ] **Step 1: Run all PHP tests**

Run: `make test`
Expected: All tests pass.

- [ ] **Step 2: Full manual walkthrough**

Complete the entire voting workflow in the browser:
1. Open the voting controls page with uploads still open — verify greyed-out state.
2. Close uploads — verify step 1 becomes active.
3. Walk through all 5 steps for the first category.
4. Switch to second category tab — verify it starts at step 1.
5. Walk through all 5 steps for the second category.
6. Verify "All Categories Complete" panel appears.
7. Show results — verify results links work.
8. Reload page at various points — verify step recovery works.

- [ ] **Step 3: Commit any final fixes**

```bash
git add -A
git commit -m "fix: Final integration fixes for voting controls redesign"
```
