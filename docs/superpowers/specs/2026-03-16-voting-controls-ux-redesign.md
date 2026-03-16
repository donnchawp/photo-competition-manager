# Voting Controls UX Redesign

## Problem

The voting controls page (`admin.php?page=photo-competition-manager-voting`) has several UX issues:

- Custom gradient/border styling looks nothing like a WordPress admin page.
- Category tabs float disconnected from the main control panel.
- All controls are visible and appear interactive even when prerequisites are not met (uploads open, results visible), creating confusion about what to do first.
- No clear workflow sequence — the admin must know the correct order of operations from memory.
- Duplicate warnings appear in both the status bar and the control panel.
- Slideshow duration presets (7 buttons) take up significant space for a set-once value.

## Design

### Page Structure

Three sections, top to bottom, all using standard WordPress admin postbox styling (`background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04)`).

#### 1. Competition Status Bar

A WP postbox containing:

- Competition title (left-aligned).
- Uploads status: pill badge ("Open" warning / "Closed" success) + action button ("Close Uploads" primary when open, "Reopen" secondary when closed).
- Results status: pill badge ("Hidden" success / "Visible" warning) + action button ("Show" secondary when hidden, "Hide" primary when visible).

No warning text inside this box. The pill colours and action buttons communicate state clearly.

#### 2. Workflow Card

A WP postbox with WordPress `nav-tab-wrapper` style category tabs attached to the top of the card. Tabs show category label, image count, a green dot when voting is live, and a checkmark when the category is complete.

Inside the card: a 5-step workflow for the selected category.

**Step states:**

| State | Visual treatment |
|-------|-----------------|
| Completed | Green circle with checkmark. Label struck through. Inline status badges where relevant (e.g., "Voting Open" on step 2). |
| Active | Blue left border (`4px solid #2271b1`). Light blue background (`#f0f6fc`). Expanded to show description, action button(s), and duration input where applicable. Number circle filled blue. |
| Upcoming | Grey circle with number. Grey label with brief description. No controls. |

**Prerequisites not met (uploads open or results visible):** The workflow card is visible but its content is rendered at reduced opacity (~45%). A WordPress-style `border-left` notice appears at the bottom of the card: "Close uploads before starting the voting workflow." or "Hide results before starting the voting workflow." as appropriate.

**Another category has voting open:** When switching to a category that is not the one with active voting, the workflow card renders normally (not greyed out) but step 2 (Open Voting) shows as non-actionable with the message "Close voting in [other category] first". The admin can still view completed steps and upcoming steps for context.

**Single category:** When only one category has images, skip the `nav-tab-wrapper` entirely. Render the category label and image count as a heading inside the postbox instead.

#### 3. Quick Actions

A collapsible WP postbox labelled "Voting Links & QR Code". Contains:

- QR code for voting page URL.
- Voting password display (if configured as plaintext).
- Copy URL button.
- Links to results pages (full results, top 3) if configured.

### Step Definitions

Each category follows this 5-step workflow:

| # | Step | Type | Controls when active | Advances when |
|---|------|------|---------------------|---------------|
| 1 | Preview Slideshow | Optional | "Start Preview" button, duration text input, "Continue" button | User clicks "Continue" |
| 2 | Open Voting | Required | "Open Voting" button | Immediately on action |
| 3 | Show Slideshow | Optional | "Start Slideshow" button, duration text input, "Continue" button | User clicks "Continue" |
| 4 | Close Voting | Required | "Close Voting" button | Immediately on action, auto-advances to step 5 |
| 5 | Critique | Optional | "Start Critique" button, duration text input, "Continue" button | User clicks "Continue" (completes category) |
| 6 | Complete (sentinel) | — | No controls | Written when step 5 Continue is clicked; used by "All Categories Complete" check |

**"Continue" button behaviour:** Present on slideshow steps (1, 3, 5). Serves dual purpose — skip the step if the slideshow was never started, or confirm completion after running the slideshow. The slideshow can be re-run as many times as needed before clicking Continue. Label is always "Continue", never changes.

**Duration inputs:** A simple text input with a numeric value in seconds. Each slideshow step has its own duration field. Defaults are loaded from global competition settings and can be overridden on the page for the current session. A value of `0` means manual advance (Space or arrow keys).

**Critique mode (step 5):** Uses the same slideshow launcher as steps 1 and 3. The duration input defaults to `0` (manual advance) from global settings but can be overridden to a timed value if the admin wants a timed critique run. The existing `photo-competition-manager-start-critique` JS class is retired; all three slideshow steps use the same `photo-competition-manager-start-slideshow` handler, passing the duration from the text input.

### Duration Defaults in Global Settings

Add three new settings under the competition settings (global defaults):

| Setting | Key | Default value |
|---------|-----|---------------|
| Preview slideshow duration | `slideshow.preview_duration` | `10` |
| Voting slideshow duration | `slideshow.voting_duration` | `15` |
| Critique slideshow duration | `slideshow.critique_duration` | `0` (manual) |

These appear in the existing global settings page. Per-competition overrides are not needed — the on-page text input handles per-session adjustment.

### Step Persistence

Step progress per category must persist across page reloads (the page uses full page reloads for actions like Open Voting / Close Voting). Store the current step for each category in the competition settings under `voting.category_steps`, keyed by category slug:

```
voting.category_steps.colour = 3
voting.category_steps.bw = 1
```

**Server-side step updates:** The existing action handlers must also write `voting.category_steps` when they execute:

| Server action | Writes step value |
|--------------|-------------------|
| `open_category_voting` | `3` (step 2 done, step 3 active) |
| `close_category_voting` | `5` (step 4 done, auto-advance to step 5) |

**AJAX endpoint for "Continue" button:** Register a `wp_ajax_photo_comp_advance_voting_step` action.

- **Request parameters:** `competition_id` (int), `category_slug` (string), `step` (int — the step to advance to), `_wpnonce` (string).
- **Authorization:** Verify `current_user_can( 'manage_photo_competitions' )` before processing. Return `{ "success": false, "message": "Insufficient permissions." }` (HTTP 403) if the check fails.
- **Nonce:** Generated per-page via `wp_create_nonce( 'photo_comp_voting_step' )` and passed as a JS variable.
- **Success response:** `{ "success": true, "step": <new step value> }`.
- **Error response:** `{ "success": false, "message": "..." }` — displayed as a WP admin notice.
- **On AJAX failure:** Stay on the current step. Show a brief error notice ("Could not save progress — try again"). Do not silently advance.

**Page load recovery:** On page load, if the live voting state disagrees with the stored step, live state wins. Specifically:

- If voting is currently open for this category and stored step < 3, set step to 3.
- If voting was closed (the key `{competition_id}_{category_slug}` appears in `voted_categories`) and stored step < 5, set step to 5.

This handles the case where an admin reloads on a different device or after a browser crash.

**Relationship to `voted_categories`:** The existing `voted_categories` array is retained for backward compatibility. Step 5 completion (Continue clicked after Critique) writes the category key to `voted_categories` in addition to setting `category_steps` to 6 (complete). The "All Categories Complete" check uses `category_steps >= 6` as the primary signal, falling back to `voted_categories` for competitions that started under the old system.

### Completed Category State

When step 5 is completed (Continue clicked after Critique, or Continue clicked to skip), the category tab shows a checkmark. The workflow area for that category shows all 5 steps with green checkmarks.

### All Categories Complete

When all categories are complete, the workflow card transforms to show:

- "All Categories Complete" header with a green checkmark icon.
- List of completed categories with per-category slideshow replay buttons (Slideshow / Critique) for post-voting presentations.
- Two duration text inputs: one for slideshow replay (defaults to voting duration), one for critique replay (defaults to critique duration).
- "Show Results" primary action button if results are still hidden.
- Links to results pages if configured.

### CSS Changes

Remove all custom styling that deviates from WordPress admin conventions:

- Remove gradient backgrounds (`linear-gradient`).
- Remove custom border colours on the control panel (`border: 2px solid #2271b1`).
- Remove custom pill/badge styling; use WordPress notice colours.
- Remove oversized hero button styling (`.voting-button.button-hero`).
- Remove the floating category tab bar; replace with `nav-tab-wrapper` attached to the postbox.
- Retain slideshow modal styling (fullscreen overlay) — this is intentionally custom.
- Retain QR code display styling.

### What Does Not Change

- The slideshow modal (fullscreen image display with controls) — works well as-is.
- QR code generation and display.
- The voting notification email sent when voting opens.
- The member grade validation check (blocking error when members lack grades).
- All server-side action handling (open/close voting, open/close uploads, show/hide results).
