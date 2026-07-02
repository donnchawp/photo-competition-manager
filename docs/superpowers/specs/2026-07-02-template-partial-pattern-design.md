# Template-partial pattern — design spec

**Issue:** #10 — Establish template-partial pattern; apply to `Voting_Controller`
**Status:** approved (design)
**Blocked by:** #9 (closed)

## Problem

Admin controllers' `render_*` methods echo raw HTML inline, mixing markup with
business rules. `Voting_Controller` is the worst offender: ~1000 of its 1394
lines are markup emission spread across `render()` and six `render_*` helpers,
plus five inline notice blocks echoed directly inside `render()`.

This issue introduces a template-partial layer under `src/templates/` and applies
it to `Voting_Controller` as the **reference implementation**. The other four
admin controllers follow in separate rollout issues that copy this pattern.

### Premise correction (verified against code)

The issue's acceptance criterion "Rendered markup unchanged versus the
characterization tests" rests on a safety net that **does not exist**. The
existing `tests/phpunit/Admin/class-voting-controller-test.php` (13 tests) covers
only `handle_actions()` — the command side (open/close voting, resets, step
advancement, nonce/capability guards). It never invokes `render()` or any
`render_*` method and asserts on zero bytes of HTML.

Therefore the markup emitters this issue refactors currently have **no test
coverage**. A "markup unchanged" refactor requires a golden-master of the current
output to be written **first**. This is reflected in the plan sequencing below.

## Goals

- A reusable partial-loading seam that render methods use instead of echoing HTML.
- `Voting_Controller` fully converted to it, byte-for-byte output-preserving.
- All output escaping preserved (moved verbatim into the partials).
- The pattern documented well enough for the four rollout issues to copy mechanically.

## Non-goals

- The other admin controllers (the four rollout targets tracked separately) —
  separate rollout issues.
- Public shortcodes (`class-voting-shortcode.php` et al.) — same anti-pattern,
  out of scope here.
- Any change to rendered output, markup structure, or business logic.

## Design

### 1. The seam — `Form_Rendering` trait

Two new methods on `src/admin/Traits/trait-form-rendering.php`:

```php
/**
 * Resolve a template partial path under src/templates/.
 *
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

**Contract:**

- Partials receive exactly one variable: `$data` (array). They read `$data['key']`.
  **No `extract()`** — variable origins stay explicit and phpcs-clean.
- **All escaping lives in the partial** (`esc_html`, `esc_url`, `esc_attr`,
  `esc_html__`, `wp_kses_post`, etc.), moved verbatim from the current inline
  echoes. This satisfies AC "output escaping is preserved."
- `render_template()` **returns a string**. Callers `echo` it. Return-by-string
  is deliberate: it lets the golden-master test assert on the returned HTML
  directly, with no output buffering in the test, and allows fragment composition.
- `PHOTO_COMPETITION_MANAGER_DIR` resolves to `src/` (defined in
  `src/includes/bootstrap.php`), so the base is `src/templates/`.

### 2. Partials — `src/templates/admin/voting/`

Kebab-case filenames (consistent with the repo's kebab-case asset convention),
one partial per current markup unit:

| Partial | Replaces |
| --- | --- |
| `competition-status-bar.php` | `render_competition_status_bar()` |
| `category-tabs.php` | `render_category_tabs()` |
| `workflow-steps.php` | `render_workflow_steps()` |
| `quick-actions.php` | `render_quick_actions()` |
| `competition-complete.php` | `render_competition_complete()` |
| `slideshow-container.php` | `render_slideshow_container()` |
| `notice-no-open-competitions.php` | inline notice in `render()` (no open competitions) |
| `notice-members-without-grades.php` | inline notice in `render()` (members missing grades) |
| `notice-missing-pages.php` | inline notice in `render()` (voting/results page missing) |
| `notice-no-images.php` | inline notice in `render()` (no images in any category) |

### 3. Controller changes — `class-voting-controller.php`

- Each `render_*( ...args )` method becomes a thin adapter: build a `$data`
  array from its existing parameters, then `return $this->render_template(
  'admin/voting/<name>.php', $data )`. Signatures keep their prepared-view-data
  parameters (the seam already exists at the parameter level today).
- `render()` keeps all data-preparation logic (repository queries, view-state
  computation, page-load step recovery) **unchanged**. Every point where it
  currently `echo`es markup — the wrapper `<div class="wrap ...">`, the `<h1>`,
  the hidden meter input, and each inline notice block — instead `echo`es a
  `render_template(...)` call (notices) or the returned fragment from a helper.
- Small standalone markup (the `wrap` open/close, `<h1>`, hidden meter `<input>`)
  may stay as direct `echo` OR move into a `page-shell` partial. Decision: keep
  the one-line `echo`s inline (they are trivial and structural); only multi-line
  markup blocks move to partials. This keeps the diff honest and the pattern
  about *blocks*, not every stray tag.

The methods change from `void` (echo) to `string` (return); `render()` echoes
their results in the same order, preserving output sequence exactly.

### 4. Safety net — golden-master, written FIRST

New test `tests/phpunit/Admin/class-voting-controller-render-test.php`:

1. Build fixtures via WP factories driving `render()` through its branches:
   - **Happy path** — one open competition, categories with images, a workflow
     card rendered.
   - **Notice branches** — no open competitions; members without grades;
     missing voting/results pages; no images in any category.
2. Capture current `render()` output (buffer `render()` once per scenario) into
   snapshot fixtures under `tests/phpunit/fixtures/voting-render/`.
3. Assert the live output byte-equals the stored snapshot.

**Sequencing (critical):** write this test against the **current** code, confirm
green (snapshots capture today's output), *then* extract partials, then confirm
the same test is still green. That is the proof of AC "rendered markup unchanged."

Determinism note: `render()` output must be stabilized for snapshotting — nonce
fields and any timestamp/ID-dependent markup are normalized (regex-replaced to a
placeholder) before comparison, so snapshots don't churn on per-run nonces. The
normalization is applied identically to both the stored snapshot and live output.

### 5. Documentation

A short "Template partials" section added to the refactor docs
(`docs/plans/refactor.md`) or a sibling doc, describing: the `render_template` /
`template_path` seam, the `src/templates/<area>/<controller>/` location, the
`$data`-array contract, and the "escaping lives in the partial" rule — enough for
the four rollout issues to copy without re-deriving the pattern.

## Acceptance criteria (from #10, mapped)

- [x] A template-partial rendering approach exists → §1 `render_template`.
- [x] `Voting_Controller` render methods use partials → §2–§3.
- [x] Output escaping preserved → §1 contract; escapes move verbatim into partials.
- [x] Rendered markup unchanged vs. characterization tests → §4 golden-master
      (written first, must stay green).
- [x] Pattern documented for rollout → §5.

## Risks

- **Snapshot brittleness / nondeterminism.** Nonces and dynamic IDs in output.
  Mitigated by normalization in §4.
- **Fixture setup cost.** `render()` needs open competitions, categories, images,
  members. Mitigated by reusing the factory helpers already in the existing
  voting test.
- **Whitespace drift during extraction.** Moving echo strings into `.php`
  partials can introduce stray newlines. The byte-equality golden-master catches
  this immediately — that is its job.
