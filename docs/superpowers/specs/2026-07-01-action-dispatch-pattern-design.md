# Action-dispatch pattern (issue #9)

Establish a reusable action-dispatch abstraction and apply it to `Voting_Controller`.
Behavior-preserving refactor of `handle_actions()`; the 13 characterization tests in
`tests/phpunit/Admin/class-voting-controller-test.php` are the contract and must stay green.

## Problem

`Voting_Controller::handle_actions()` is a ~365-line chain of five `if ( 'x' === $action )`
branches. Every branch repeats the same skeleton:

1. Read `competition` (+ sometimes `category`, `clear_votes`) from `$_GET`.
2. `check_admin_referer( <nonce string built from those params> )`.
3. **find-or-404** — `find()`; if missing, `add_settings_error( 'competition_not_found' )`
   and redirect to the plain voting page. 14 near-identical lines, ×5.
4. Mutate `$settings`.
5. **update-tail** — `update()`; on `WP_Error` add an error, else add a success
   settings-error (+ optional side-effect); redirect with the `focus` param + `#focus-panel`.

Four other admin controllers share this shape and get the same treatment in later rollout
issues (#16–#19 characterize them first). #9 changes **only** `Voting_Controller` but leaves
behind a shared seam those issues reuse.

## Per-action variance the abstraction must accommodate

- **Nonce scope differs.** `open`/`close`/`reset` are category-scoped
  (`..._{id}_{slug}`); `show_results`/`hide_results` are competition-scoped (`..._{id}`).
- **`open` has a pre-check.** A global "no other active competition may have a category open"
  constraint runs *before* find-or-404 and can early-redirect. Order is preserved exactly
  (no test pins constraint-before-find, but it is a real behavior difference in the
  missing-competition + other-open edge case).
- **Success side-effects differ.** `open` sends voting-opened notifications on success.
  `reset` conditionally deletes votes/tokens on success *and* chooses one of two success
  messages from `clear_votes` — both only in the non-error branch.

## Chosen shape — shared dispatcher trait + per-controller domain helpers

Reusable pieces go in a new trait `Admin_Action_Dispatcher`
(`src/admin/Traits/trait-admin-action-dispatcher.php`); the voting-specific error group and
redirect URL stay as private helpers on the controller.

### The trait (reused by all five controllers)

```php
trait Admin_Action_Dispatcher {
    /**
     * Route the request's `action` through a declarative map.
     * Each entry: [ 'nonce' => callable():string, 'handle' => callable():void ].
     * The dispatcher verifies the nonce centrally before invoking the handler,
     * so every routed action is guaranteed to be nonce-checked.
     */
    protected function dispatch_action( array $actions ): void {
        $action = isset( $_GET['action'] )
            ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        if ( '' === $action || ! isset( $actions[ $action ] ) ) {
            return;
        }
        $spec = $actions[ $action ];
        check_admin_referer( ( $spec['nonce'] )() );
        ( $spec['handle'] )();
    }

    /** Read a non-negative int from the query string (pre-nonce param for routing). */
    protected function query_int( string $key ): int { /* absint( wp_unslash ) or 0 */ }

    /** Read a sanitized text field from the query string. */
    protected function query_text( string $key ): string { /* sanitize_text_field or '' */ }
}
```

`query_int`/`query_text` carry the `phpcs:ignore WordPress.Security.NonceVerification.Recommended`
they need — params are read to *build* the nonce before it is verified, exactly as the
current code does.

### The controller

`handle_actions()` becomes the capability guard, the `focus` read, and the map:

```php
public function handle_actions(): void {
    if ( ! current_user_can( 'manage_photo_competitions' ) ) {
        return;
    }
    $focus = $this->query_text( 'focus' );

    $this->dispatch_action( array(
        'open_category_voting'  => array(
            'nonce'  => fn() => 'photo_competition_open_voting_'
                . $this->query_int( 'competition' ) . '_' . $this->query_text( 'category' ),
            'handle' => fn() => $this->handle_open_category_voting( $focus ),
        ),
        'close_category_voting' => array( /* category-scoped nonce */ ),
        'reset_category'        => array( /* category-scoped nonce */ ),
        'show_results'          => array(
            'nonce'  => fn() => 'photo_competition_show_results_' . $this->query_int( 'competition' ),
            'handle' => fn() => $this->handle_show_results( $focus ),
        ),
        'hide_results'          => array( /* competition-scoped nonce */ ),
    ) );
}
```

Five small `handle_*` methods do domain work, using three private helpers that hold the
voting error group and URLs:

```php
/** find() or add competition_not_found + redirect to the plain voting page. */
private function load_competition_or_fail( int $competition_id ): object;

/** Add an error settings-error and redirect to the plain voting page (no focus/anchor). */
private function fail_voting( string $code, string $message ): void;

/** update(); on WP_Error add error, else add success + run optional side-effect;
    then redirect with focus + #focus-panel. */
private function finish_voting_update(
    int $competition_id, array $settings,
    string $success_code, string $success_message,
    string $focus, ?callable $on_success = null
): void;
```

Example handler (the awkward one, `reset`, showing conditional message + side-effect both
gated on the update succeeding):

```php
private function handle_reset_category( string $focus ): void {
    $competition_id = $this->query_int( 'competition' );
    $category_slug  = $this->query_text( 'category' );
    $clear          = 1 === $this->query_int( 'clear_votes' );

    $competition = $this->load_competition_or_fail( $competition_id );
    $settings    = /* close-if-open, reset step to 1, drop from voted_categories */;

    $this->finish_voting_update(
        $competition_id, $settings, 'category_reset',
        $clear
            ? __( 'Category reset to step 1 and all votes cleared.', 'photo-competition-manager' )
            : __( 'Category reset to step 1. Existing votes were kept.', 'photo-competition-manager' ),
        $focus,
        $clear ? function () use ( $competition_id, $category_slug ) {
            ( new Votes_Repository() )->delete_by_competition_and_category( $competition_id, $category_slug );
            ( new Voting_Token_Repository() )->delete_by_competition_and_category( $competition_id, $category_slug );
        } : null
    );
}
```

`open`'s handler runs the global constraint loop (calling `fail_voting( 'voting_already_open', … )`
on violation) **before** `load_competition_or_fail()`, preserving current ordering.

## Why this shape (vs. the alternatives)

- **vs. fully-declarative action map** (one rich array per action carrying loader, mutator,
  success spec, side-effect, and the dispatcher running the whole lifecycle): `open`'s
  pre-check ordering and `reset`'s conditional message/side-effect force escape-hatch fields
  that erode the declarativeness. Keeping the lifecycle in short imperative handlers is
  clearer and keeps behavior-ordering explicit and reviewable.
- **vs. per-action command objects** (a class per action): heavyweight for five short
  actions in a WP plugin — new files, a context object, registry wiring. Over-engineered for
  this surface.

## Scope boundaries

- **Only `Voting_Controller`.** No other controller changes in #9.
- **`handle_advance_step()` (AJAX) is untouched** — it is not part of the GET-action router.
- The find-or-404 and update-tail helpers stay **on the controller** (not the trait) because
  they bake in the `photo_competition_voting` error group and the voting page URL, which
  differ per controller. The rollout issues may hoist a parameterized version into the trait
  if the shape holds across controllers; that generalization is deliberately deferred (YAGNI).

## Behavior-preservation checklist (mapped to the characterization suite)

- Capability guard: `handle_actions()` returns early — `test_handle_actions_noop_without_capability`.
- Bad/missing nonce → `wp_die()`: dispatcher's `check_admin_referer` runs before the handler —
  `test_open_category_voting_bad_nonce_dies`.
- open success sets `open_categories=[slug]`, step 3, message `voting_opened` — `test_open_category_voting_success`.
- open blocked by another-open constraint, no mutation — `test_open_category_voting_blocked_when_another_open`.
- open missing competition → `competition_not_found` — `test_open_category_voting_competition_not_found`.
- close clears open, step 5, records voted — `test_close_category_voting_success`.
- reset keeps/clears votes with the matching message — `test_reset_category_keeps_votes`, `test_reset_category_clears_votes`.
- show/hide toggle `results_visible` with `results_shown`/`results_hidden` — `test_show_results_makes_visible`, `test_hide_results_makes_hidden`.
- AJAX step machine unchanged — `test_advance_step_*` (handler untouched).

## Verification

Run the Voting suite, then the full suite, against the live DB port; paste the `OK` line.
Full-suite baseline after #21 is 283 tests.
