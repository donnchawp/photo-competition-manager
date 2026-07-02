# Template-Partial Pattern Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce a reusable template-partial rendering seam and convert `Voting_Controller` to it, byte-for-byte output-preserving, as the reference implementation for four later controller rollouts.

**Architecture:** Add `template_path()` and `render_template()` to the `Form_Rendering` trait. `render_template()` `ob_start()`+`include`s a partial (with a single `$data` array in scope) and returns the HTML string. Each `render_*` helper in `Voting_Controller` keeps its data-preparation logic, packs the computed locals into `$data`, and returns `render_template('admin/voting/<name>.php', $data)`. A golden-master snapshot test — written first, against the current code — proves the rendered markup is unchanged across the extraction.

**Tech Stack:** PHP 7.4+, WordPress plugin, PHPUnit + WP core test library, WordPress Coding Standards (phpcs/phpcbf).

## Global Constraints

- Plugin version floor: `0.3.0`. Do not add `@since` tags greater than `0.3.0`.
- WordPress Coding Standards: four-space (tab) indentation, snake_case functions, PascalCase classes in the `PhotoCompetitionManager` namespace. Run `./vendor/bin/phpcs --standard=WordPress --extensions=php <files>` and fix ERRORS (the `manage_photo_competitions` capability warning is pre-existing noise; test files are not held to a clean phpcs bar).
- All output escaping stays in the partials via WordPress helpers (`esc_html`, `esc_url`, `esc_attr`, `esc_html__`, `esc_html_e`, `esc_attr_e`, `printf( esc_html__(...) )`). Escapes move **verbatim** from the current inline echoes — no escaping added or removed.
- **No change to rendered output, markup structure, whitespace, or business logic.** The golden-master test (Task 2) is the invariant: it must be green before extraction begins and stay green after every extraction task.
- Partials receive exactly one variable, `$data` (array), read as `$data['key']`. **No `extract()`.**
- Templates live under `src/templates/`. `PHOTO_COMPETITION_MANAGER_DIR` resolves to `src/`, so the base for `template_path()` is `PHOTO_COMPETITION_MANAGER_DIR . '/templates/'`.
- Partial filenames are kebab-case `.php` files.
- Running PHPUnit — the tests-MySQL host port is dynamic:
  ```bash
  PORT=$(docker port photo-competition-manager-tests-mysql-1 3306/tcp | head -1 | sed 's/.*://')
  WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter <Filter>
  ```
  Ignore pre-existing `Duplicate key name 'member_competition_category'` dbDelta noise.

---

## File Structure

**Created:**
- `src/templates/admin/voting/slideshow-container.php` — static slideshow modal markup.
- `src/templates/admin/voting/competition-status-bar.php` — competition status bar.
- `src/templates/admin/voting/category-tabs.php` — category nav tabs.
- `src/templates/admin/voting/workflow-steps.php` — 5-step workflow card.
- `src/templates/admin/voting/quick-actions.php` — collapsible quick-actions bar.
- `src/templates/admin/voting/competition-complete.php` — "all categories complete" panel.
- `src/templates/admin/voting/notice-no-open-competitions.php` — notice: no open competitions.
- `src/templates/admin/voting/notice-members-without-grades.php` — notice: members missing grades.
- `src/templates/admin/voting/notice-missing-pages.php` — notice: voting/results page missing.
- `src/templates/admin/voting/notice-no-images.php` — notice: no images in any category.
- `tests/phpunit/Admin/class-voting-controller-render-test.php` — golden-master snapshot test.
- `tests/phpunit/Support/class-form-rendering-template-test.php` — unit test for the seam.
- `tests/phpunit/fixtures/voting-render/*.html` — captured snapshot fixtures.

**Modified:**
- `src/admin/Traits/trait-form-rendering.php` — add `template_path()` + `render_template()`.
- `src/admin/class-voting-controller.php` — convert `render()` and the six `render_*` helpers to use partials.
- `docs/plans/refactor.md` — document the pattern for the rollout issues.

---

## Extraction recipe (applies to Tasks 3–9)

Every extraction task performs the **same mechanical transform**. It is stated once here; each task supplies the exact method, partial path, and `$data` contract.

1. In the `render_*` method, keep all code **before** the `?>` (the data preparation: URL building, local computation) unchanged.
2. Replace the method's `?> … <?php` markup block with:
   ```php
   return $this->render_template(
       'admin/voting/<name>.php',
       array( /* exact keys listed per task */ )
   );
   ```
   and change the method's return type from `: void` to `: string`.
3. Create the partial file. Paste the markup block **verbatim** from the source, then rename each reference to a method local to `$data['<local>']` (e.g. `$competition->title` → `$data['competition']->title`; `$toggle_uploads_url` → `$data['toggle_uploads_url']`). Locals that are **declared inside** the markup block's own loops/conditionals (e.g. `$tab_key`, `$is_completed`, `$step_num`) stay as-is — they are presentation-local, not passed in.
4. The partial starts with `<?php defined( 'ABSPATH' ) || exit; ?>` then the markup.
5. In `render()`, change the call site from `$this->render_x( … )` (statement) to `echo $this->render_x( … )` so the returned string is emitted in the same position.
6. Verify: golden-master (Task 2) still green + phpcs clean on the two touched files.

Guard for `wp_kses`/`echo` of trusted admin HTML: partials contain the same pre-escaped markup as today; phpcs `WordPress.Security.EscapeOutput` should not newly fire because the echo happens inside the partial exactly as before. If phpcs flags the single `echo $this->render_x()` in `render()`, add `// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted pre-escaped partial HTML.` — the partial already escapes every dynamic value.

---

### Task 1: Add the `render_template` seam to `Form_Rendering`

**Files:**
- Modify: `src/admin/Traits/trait-form-rendering.php`
- Test: `tests/phpunit/Support/class-form-rendering-template-test.php`

**Interfaces:**
- Produces:
  - `protected function template_path( string $relative ): string`
  - `protected function render_template( string $relative, array $data = array() ): string`

- [ ] **Step 1: Write the failing test**

Create `tests/phpunit/Support/class-form-rendering-template-test.php`:

```php
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
			public function path( string $rel ): string {
				return $this->template_path( $rel );
			}
			public function render( string $rel, array $data ): string {
				return $this->render_template( $rel, $data );
			}
		};
	}

	public function test_template_path_roots_under_src_templates(): void {
		$this->assertSame(
			PHOTO_COMPETITION_MANAGER_DIR . '/templates/admin/voting/x.php',
			$this->host->path( 'admin/voting/x.php' )
		);
	}

	public function test_template_path_trims_leading_slash(): void {
		$this->assertSame(
			PHOTO_COMPETITION_MANAGER_DIR . '/templates/a/b.php',
			$this->host->path( '/a/b.php' )
		);
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
```

- [ ] **Step 2: Run test to verify it fails**

```bash
PORT=$(docker port photo-competition-manager-tests-mysql-1 3306/tcp | head -1 | sed 's/.*://')
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter Form_Rendering_Template_Test
```
Expected: FAIL — `render_template()`/`template_path()` do not exist (Error: call to undefined method).

- [ ] **Step 3: Add the two methods to the trait**

In `src/admin/Traits/trait-form-rendering.php`, inside the `Form_Rendering` trait (after `redirect_with_settings_errors()`), add:

```php
	/**
	 * Resolve a template partial path under src/templates/.
	 *
	 * @since 0.3.0
	 * @param string $relative Relative path, e.g. 'admin/voting/category-tabs.php'.
	 * @return string Absolute filesystem path.
	 */
	protected function template_path( string $relative ): string {
		return PHOTO_COMPETITION_MANAGER_DIR . '/templates/' . ltrim( $relative, '/' );
	}

	/**
	 * Render a template partial to a string.
	 *
	 * The partial receives a single variable, $data (array), in scope and is
	 * responsible for its own output escaping.
	 *
	 * @since 0.3.0
	 * @param string $relative Relative partial path under src/templates/.
	 * @param array  $data     View data available to the partial as $data.
	 * @return string Rendered HTML.
	 */
	protected function render_template( string $relative, array $data = array() ): string {
		ob_start();
		include $this->template_path( $relative );
		return (string) ob_get_clean();
	}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter Form_Rendering_Template_Test
```
Expected: PASS (3 tests).

- [ ] **Step 5: phpcs the trait**

```bash
./vendor/bin/phpcs --standard=WordPress --extensions=php src/admin/Traits/trait-form-rendering.php
```
Expected: no ERRORS.

- [ ] **Step 6: Commit**

```bash
git add src/admin/Traits/trait-form-rendering.php tests/phpunit/Support/class-form-rendering-template-test.php
git commit -m "feat: add render_template seam to Form_Rendering trait (#10)"
```

---

### Task 2: Golden-master snapshot test for `Voting_Controller::render()`

Written against the **current, unmodified** controller. This is the safety net; it must be green before any extraction.

**Files:**
- Create: `tests/phpunit/Admin/class-voting-controller-render-test.php`
- Create (generated on first run): `tests/phpunit/fixtures/voting-render/*.html`

**Interfaces:**
- Consumes: `create_open_competition()` pattern from `tests/phpunit/Admin/class-voting-controller-test.php`; `Admin_Controller_Test_Case` base (provides `$this->admin_id`, capability setup).
- Produces: `capture( string $scenario ): string` snapshot helper (self-writing on first run).

**Scenarios to cover (drive `render()` through each branch):**
1. `no-open-competitions` — a controller with zero open competitions → `notice-no-open-competitions` branch.
2. `no-images` — one open competition with categories in settings but no image rows → `notice-no-images` branch.
3. `happy-path` — one open competition, two categories each with an image and a graded member → status bar + category tabs + workflow steps + quick actions render.

- [ ] **Step 1: Write the snapshot test**

Create `tests/phpunit/Admin/class-voting-controller-render-test.php`:

```php
<?php
/**
 * Golden-master snapshot tests for Voting_Controller::render().
 *
 * Pins the exact rendered HTML ahead of the template-partial extraction (#10).
 * Nonces are normalized so snapshots do not churn per run.
 *
 * @package PhotoCompetitionManager\Tests\Admin
 */

namespace PhotoCompetitionManager\Tests\Admin;

require_once __DIR__ . '/class-admin-controller-test-case.php';

use PhotoCompetitionManager\Admin\Voting_Controller;
use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Images_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;

/**
 * @covers \PhotoCompetitionManager\Admin\Voting_Controller
 */
class Voting_Controller_Render_Test extends Admin_Controller_Test_Case {

	/** @var Competitions_Repository */
	private $competitions;

	/** @var Images_Repository */
	private $images;

	/** @var Members_Repository */
	private $members;

	/** @var Voting_Controller */
	private $controller;

	public function set_up(): void {
		parent::set_up();
		$this->competitions = new Competitions_Repository();
		$this->images       = new Images_Repository();
		$this->members      = new Members_Repository();
		$this->controller   = new Voting_Controller( $this->competitions, $this->images, $this->members );
	}

	/**
	 * Render the page and return normalized HTML.
	 */
	private function render_normalized(): string {
		ob_start();
		$this->controller->render();
		$html = (string) ob_get_clean();
		// Normalize per-run nonces: _wpnonce=<10 hex> and nonce field values.
		$html = preg_replace( '/(_wpnonce=)[a-f0-9]{10}/', '$1NONCE', $html );
		$html = preg_replace( '/(name="_wpnonce" value=")[a-f0-9]{10}/', '$1NONCE', $html );
		return $html;
	}

	/**
	 * Assert live output equals the stored snapshot; write it on first run.
	 */
	private function assert_matches_snapshot( string $scenario ): void {
		$dir  = __DIR__ . '/../fixtures/voting-render';
		$file = $dir . '/' . $scenario . '.html';
		$html = $this->render_normalized();

		if ( ! file_exists( $file ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $file, $html );
			$this->markTestSkipped( "Snapshot written for {$scenario}; re-run to assert." );
			return;
		}

		$this->assertSame( file_get_contents( $file ), $html, "Rendered markup drifted for scenario {$scenario}." );
	}

	/**
	 * Seed a competition with the given categories and return its ID.
	 *
	 * @param array<int,array{slug:string,label:string}> $categories Category defs.
	 */
	private function seed_competition( array $categories ): int {
		return $this->competitions->create(
			array(
				'title'      => 'Spring Show',
				'slug'       => 'spring-show',
				'open_date'  => null,
				'close_date' => null,
				'settings'   => array( 'categories' => $categories ),
			)
		);
	}

	public function test_render_no_open_competitions(): void {
		// No competitions created at all.
		$this->assert_matches_snapshot( 'no-open-competitions' );
	}

	public function test_render_no_images(): void {
		$this->seed_competition( array( array( 'slug' => 'colour', 'label' => 'Colour' ) ) );
		$this->assert_matches_snapshot( 'no-images' );
	}

	public function test_render_happy_path(): void {
		$comp_id = $this->seed_competition(
			array(
				array( 'slug' => 'colour', 'label' => 'Colour' ),
				array( 'slug' => 'mono', 'label' => 'Mono' ),
			)
		);
		$member_id = $this->members->create(
			array( 'name' => 'Ada', 'email' => 'ada@example.com', 'grade' => 'A' )
		);
		foreach ( array( 'colour', 'mono' ) as $cat ) {
			$this->images->create(
				array(
					'competition_id' => $comp_id,
					'member_id'      => $member_id,
					'category'       => $cat,
					'filename'       => $cat . '.jpg',
					'random_number'  => 100,
				)
			);
		}
		$this->assert_matches_snapshot( 'happy-path' );
	}
}
```

- [ ] **Step 2: Run once to generate snapshots, then again to assert**

```bash
PORT=$(docker port photo-competition-manager-tests-mysql-1 3306/tcp | head -1 | sed 's/.*://')
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter Voting_Controller_Render_Test
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter Voting_Controller_Render_Test
```
Expected: first run — 3 skipped (snapshots written); second run — 3 passing.

- [ ] **Step 3: Sanity-check the captured happy-path snapshot**

Confirm `tests/phpunit/fixtures/voting-render/happy-path.html` actually contains the workflow markup (proves the fixture drove render() down the real path, not an early notice return):

```bash
grep -c "photo-comp-workflow-card\|nav-tab-wrapper\|quick-actions-bar" tests/phpunit/fixtures/voting-render/happy-path.html
```
Expected: non-zero (all three landmarks present). If zero, the fixture setup is not reaching the happy path — fix the seeding (grades/categories/images) before proceeding. Do not continue to extraction until this holds.

- [ ] **Step 4: Commit**

```bash
git add tests/phpunit/Admin/class-voting-controller-render-test.php tests/phpunit/fixtures/voting-render
git commit -m "test: golden-master snapshot for Voting_Controller::render() (#10)"
```

---

### Task 3: Extract `slideshow-container` partial

Simplest extraction (no dynamic data) — validates the seam end-to-end first.

**Files:**
- Create: `src/templates/admin/voting/slideshow-container.php`
- Modify: `src/admin/class-voting-controller.php` — `render_slideshow_container()` (lines ~641–669) and its call site in `render()` (~631).

Apply the **Extraction recipe**. `$data` is empty (`array()`); the markup has no method locals. `render_slideshow_container()` becomes `: string` returning `render_template( 'admin/voting/slideshow-container.php' )`; call site becomes `echo $this->render_slideshow_container();`.

- [ ] **Step 1:** Create the partial: `<?php defined( 'ABSPATH' ) || exit; ?>` followed by the verbatim markup from the current method body (the `<div id="photo-comp-slideshow-modal" …>` … `</div>` block, including its `esc_attr_e`/`esc_html_e` calls).
- [ ] **Step 2:** Convert the method per the recipe (return type `: string`, `return $this->render_template( … )`).
- [ ] **Step 3:** Change the call site in `render()` to `echo $this->render_slideshow_container();`.
- [ ] **Step 4: Run golden-master + full voting suite**

```bash
PORT=$(docker port photo-competition-manager-tests-mysql-1 3306/tcp | head -1 | sed 's/.*://')
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter 'Voting_Controller'
```
Expected: PASS (existing behavior test + render snapshot test all green — markup unchanged).

- [ ] **Step 5: phpcs**

```bash
./vendor/bin/phpcs --standard=WordPress --extensions=php src/admin/class-voting-controller.php src/templates/admin/voting/slideshow-container.php
```
Expected: no ERRORS.

- [ ] **Step 6: Commit**

```bash
git add src/templates/admin/voting/slideshow-container.php src/admin/class-voting-controller.php
git commit -m "refactor: extract slideshow-container partial (#10)"
```

---

### Task 4: Extract `competition-status-bar` partial

**Files:**
- Create: `src/templates/admin/voting/competition-status-bar.php`
- Modify: `src/admin/class-voting-controller.php` — `render_competition_status_bar()` (lines ~729–801) and call site (~597).

Apply the recipe. Keep the pre-`?>` URL building. Exact `$data`:

```php
array(
	'competition'        => $competition,
	'uploads_closed'     => $uploads_closed,
	'results_visible'    => $results_visible,
	'toggle_uploads_url' => $toggle_uploads_url,
	'show_results_url'   => $show_results_url,   // present today though only hide/show used in markup; keep to preserve intent
	'hide_results_url'   => $hide_results_url,
)
```
In the partial, `$competition->title` → `$data['competition']->title`; each `$*_url` → `$data['*_url']`; `$uploads_closed`/`$results_visible` → `$data[...]`. Call site: `echo $this->render_competition_status_bar( $active_competition, $active_settings );`.

> Note: `$show_results_url` is computed in the current method but not referenced in its markup. Keep computing and passing it (behavior-preserving); do not "clean up" unused locals in this task — that would be an out-of-scope change and risks a diff the golden-master can't see.

- [ ] **Step 1:** Create the partial (verbatim markup, locals → `$data[...]`).
- [ ] **Step 2:** Convert the method to return the string.
- [ ] **Step 3:** Update the call site to `echo`.
- [ ] **Step 4: Golden-master + suite**

```bash
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter 'Voting_Controller'
```
Expected: PASS.

- [ ] **Step 5: phpcs** the controller + new partial. Expected: no ERRORS.
- [ ] **Step 6: Commit** `refactor: extract competition-status-bar partial (#10)`

---

### Task 5: Extract `category-tabs` partial

**Files:**
- Create: `src/templates/admin/voting/category-tabs.php`
- Modify: `src/admin/class-voting-controller.php` — `render_category_tabs()` (lines ~816–851) and call site (~605).

Apply the recipe. The early `if ( count( $all_categories ) < 2 ) { return; }` guard stays in the **method** (return `''` in that case, so nothing is echoed):

```php
private function render_category_tabs( array $all_categories, string $current_key, bool $voting_open_globally, ?int $open_competition_id, ?string $open_category_slug, array $voted_categories = array() ): string {
	if ( count( $all_categories ) < 2 ) {
		return ''; // Single category: no tabs.
	}
	return $this->render_template(
		'admin/voting/category-tabs.php',
		array(
			'all_categories'       => $all_categories,
			'current_key'          => $current_key,
			'voting_open_globally' => $voting_open_globally,
			'open_competition_id'  => $open_competition_id,
			'open_category_slug'   => $open_category_slug,
			'voted_categories'     => $voted_categories,
		)
	);
}
```
In the partial, the `foreach` and all `$tab_*` locals are declared **inside** the loop → keep as-is; only the loop source `$all_categories` and the flags become `$data[...]`. Call site: `echo $this->render_category_tabs( … );`.

- [ ] **Step 1–3:** Create partial, convert method, `echo` at call site.
- [ ] **Step 4: Golden-master + suite** — Expected: PASS. (Note: the `happy-path` fixture has 2 categories, so this branch is exercised.)
- [ ] **Step 5: phpcs** — Expected: no ERRORS.
- [ ] **Step 6: Commit** `refactor: extract category-tabs partial (#10)`

---

### Task 6: Extract `workflow-steps` partial

Largest partial. Data prep (URLs, `$steps` array, flags) stays in the method; the entire `?>…<?php` block (with its inner `foreach ( $steps … )` and `$is_completed`/`$is_active`/`$is_upcoming` locals) moves to the partial.

**Files:**
- Create: `src/templates/admin/voting/workflow-steps.php`
- Modify: `src/admin/class-voting-controller.php` — `render_workflow_steps()` (lines ~867–1123) and call site (~620).

Exact `$data`:

```php
array(
	'competition'        => $competition,
	'category_slug'      => $category_slug,
	'category_label'     => $category_label,
	'image_count'        => $image_count,
	'current_step'       => $current_step,
	'settings'           => $settings,
	'comp_id'            => $comp_id,
	'is_ready'           => $is_ready,
	'total_categories'   => $total_categories,
	'another_cat_voting' => $another_cat_voting,
	'voting_open_here'   => $voting_open_here,
	'open_voting_url'    => $open_voting_url,
	'close_voting_url'   => $close_voting_url,
	'reset_url'          => $reset_url,
	'steps'              => $steps,
)
```
In the partial: the loop `foreach ( $data['steps'] as $step_num => $step )` — `$step_num`/`$step`/`$is_completed`/`$is_active`/`$is_upcoming` are loop-locals, keep as-is. The `$uploads_closed`/`$results_visible` computed inside the `if ( ! $is_ready )` notice block are also declared inside the markup → keep as-is (they read `$data['settings']` — rename their source: `$settings['upload']…` → `$data['settings']['upload']…`). Every other pre-loop local → `$data[...]`. Call site: `echo $this->render_workflow_steps( … );`.

- [ ] **Step 1:** Create the partial (verbatim markup; systematic local → `$data[...]` rename per the notes above).
- [ ] **Step 2:** Convert the method to build `$data` and return `render_template(...)`.
- [ ] **Step 3:** Update the call site to `echo`.
- [ ] **Step 4: Golden-master + suite** — Expected: PASS. This is the most error-prone rename; if the snapshot drifts, diff live vs. `tests/phpunit/fixtures/voting-render/happy-path.html` to find the missed local.
- [ ] **Step 5: phpcs** — Expected: no ERRORS.
- [ ] **Step 6: Commit** `refactor: extract workflow-steps partial (#10)`

---

### Task 7: Extract `quick-actions` partial

**Files:**
- Create: `src/templates/admin/voting/quick-actions.php`
- Modify: `src/admin/class-voting-controller.php` — `render_quick_actions()` (lines ~1133–1195) and call site (~624).

Exact `$data`:

```php
array(
	'voting_page_url' => $voting_page_url,
	'results_url'     => $results_url,
	'top3_url'        => $top3_url,
	'voting_password' => $voting_password,
)
```
Keep the plaintext-password derivation (the `preg_match` block) in the method. Call site: `echo $this->render_quick_actions( $voting_page_url, $global_settings, $active_settings );`.

- [ ] **Step 1–3:** Create partial, convert method, `echo` at call site.
- [ ] **Step 4: Golden-master + suite** — Expected: PASS.
- [ ] **Step 5: phpcs** — Expected: no ERRORS.
- [ ] **Step 6: Commit** `refactor: extract quick-actions partial (#10)`

---

### Task 8: Extract `competition-complete` partial

**Files:**
- Create: `src/templates/admin/voting/competition-complete.php`
- Modify: `src/admin/class-voting-controller.php` — `render_competition_complete()` (lines ~1209–1307) and call site (~618).

Exact `$data`:

```php
array(
	'competition'               => $competition,
	'all_categories'            => $all_categories,
	'results_visible'           => $results_visible,
	'results_url'               => $results_url,
	'top3_url'                  => $top3_url,
	'slideshow_replay_duration' => $slideshow_replay_duration,
	'critique_replay_duration'  => $critique_replay_duration,
	'show_results_url'          => $show_results_url,
)
```
`$voted_categories` is a method param but unused in the markup — keep the param on the method signature (behavior-preserving); do not add it to `$data`. The `foreach ( $all_categories as $cat_data )` loop-local stays as-is. Call site: `echo $this->render_competition_complete( $active_competition, $all_categories, $voted_categories, $active_settings, $global_settings );`.

- [ ] **Step 1–3:** Create partial, convert method, `echo` at call site.
- [ ] **Step 4: Golden-master + suite** — Expected: PASS. Note: the `happy-path` fixture does **not** reach step 6 completion, so this branch may be uncovered by the snapshot. Add a fourth scenario to the render test if you want coverage here — otherwise verify manually (see Task 10 note). At minimum, confirm no PHP error is thrown by running the full suite.
- [ ] **Step 5: phpcs** — Expected: no ERRORS.
- [ ] **Step 6: Commit** `refactor: extract competition-complete partial (#10)`

---

### Task 9: Extract the four inline notice partials from `render()`

The four notice blocks are echoed directly inside `render()`. Extract each to a partial and `echo $this->render_template(...)` in place. The surrounding data-prep and early `return`s stay exactly where they are.

**Files:**
- Create: `src/templates/admin/voting/notice-no-open-competitions.php`
- Create: `src/templates/admin/voting/notice-members-without-grades.php`
- Create: `src/templates/admin/voting/notice-missing-pages.php`
- Create: `src/templates/admin/voting/notice-no-images.php`
- Modify: `src/admin/class-voting-controller.php` — `render()` (blocks at ~415–421, ~425–447, ~465–476, ~518–524).

Per block:

1. **no-open-competitions** (~415–421): markup is the `notice notice-warning inline` + the closing `</div>` for the wrap. `$data = array()`. Replace the echoed block with `echo $this->render_template( 'admin/voting/notice-no-open-competitions.php' );` **keeping** the `return;` that follows. Preserve the wrap-closing `</div>` inside the partial exactly as today.
2. **members-without-grades** (~425–447): `$data = array( 'members_without_grades' => $members_without_grades )`. The `foreach` + `$image_count`/`$image_text` locals are declared in the markup → keep as-is. Include the wrap-closing `</div>` and keep the trailing `return;`.
3. **missing-pages** (~465–476): `$data = array( 'missing' => $missing )`. No `return` follows (render continues) — just replace the echo with `echo $this->render_template(...)`.
4. **no-images** (~518–524): `$data = array()`. Includes wrap-closing `</div>`; keep the trailing `return;`.

Each partial begins with `<?php defined( 'ABSPATH' ) || exit; ?>`.

- [ ] **Step 1:** Create the four partials (verbatim markup; locals → `$data[...]` where passed).
- [ ] **Step 2:** Replace each echoed block in `render()` with the corresponding `echo $this->render_template(...)`, preserving every surrounding `return;`.
- [ ] **Step 3: Golden-master + suite**

```bash
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter 'Voting_Controller'
```
Expected: PASS. The `no-open-competitions` and `no-images` scenarios directly exercise two of these; `members-without-grades` and `missing-pages` are not in the fixtures — verify those two by eye against the diff (markup moved verbatim) or add scenarios.

- [ ] **Step 4: phpcs** the controller + four partials. Expected: no ERRORS.
- [ ] **Step 5: Commit** `refactor: extract voting render() notice partials (#10)`

---

### Task 10: Document the pattern for the rollout issues

**Files:**
- Modify: `docs/plans/refactor.md`

- [ ] **Step 1: Add a "Template partials" section**

Append a section documenting, for the four rollout controllers to copy:

- The seam: `render_template( string $relative, array $data ): string` and `template_path()` on `Form_Rendering`.
- Location convention: `src/templates/<area>/<controller>/<partial>.php`, kebab-case.
- Contract: partials receive one `$data` array (read `$data['key']`, no `extract()`); **all escaping lives in the partial**; every partial starts with `<?php defined( 'ABSPATH' ) || exit; ?>`.
- Method conversion: keep data-prep in the method, change `: void` → `: string`, `return $this->render_template(...)`; change call sites to `echo`.
- Verification: golden-master snapshot the controller's `render()` **before** extracting, keep it green after each partial.
- Reference implementation: `Voting_Controller` + `src/templates/admin/voting/` + `tests/phpunit/Admin/class-voting-controller-render-test.php`.

- [ ] **Step 2: Manual verification in the real admin**

Because two workflow branches (all-categories-complete, members-without-grades, missing-pages) may be outside the snapshot fixtures, verify the live page renders unchanged. Per the environment notes, exercise `render()` in the runtime as admin (browser at the voting page, or `wp eval` capturing `render()` output) and confirm no notices/warnings and the page looks identical to `main`.

- [ ] **Step 3: Run the full suite once**

```bash
PORT=$(docker port photo-competition-manager-tests-mysql-1 3306/tcp | head -1 | sed 's/.*://')
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit
```
Expected: green (matches the ~380-test baseline plus the new tests).

- [ ] **Step 4: Commit** `docs: document template-partial pattern for rollout (#10)`

---

## Final verification (before PR)

- [ ] Full suite green (Task 10 Step 3).
- [ ] `git grep -n "render_.*(): void" src/admin/class-voting-controller.php` returns no `render_*` markup helpers still `void` (all converted). The non-markup helpers `send_voting_opened_notifications()` and `check_members_without_grades()` legitimately stay `void`.
- [ ] `git grep -n 'echo .<' src/admin/class-voting-controller.php` — only the trivial structural echoes remain in `render()` (wrap `<div>`, `<h1>`, hidden meter `<input>`); no multi-line markup blocks.
- [ ] phpcs clean across `src/admin/class-voting-controller.php`, `src/admin/Traits/trait-form-rendering.php`, and `src/templates/admin/voting/*.php`.
- [ ] PR body ends `Closes #10`; merge commit (not squash); the user merges.
