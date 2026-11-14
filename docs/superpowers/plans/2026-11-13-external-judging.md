# External Judging Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a competition be scored by one or more external judges, each with a private link, instead of by members.

**Architecture:** "Scored by judges" is a third value of the existing `voting.auth_mode` setting (`password` | `token` | `judges`), so every existing branch on auth mode picks it up. Judges live in their own table, and each one assigned to a competition gets a row holding their link token. A new `Judge_Voter` joins `Link_Voter` and `Named_Voter` behind the `Voter` interface, and its votes go in `photocomp_votes` with a new `judge_id` column. The voting page and the slideshow serve entry images through an ID-based permalink, so the member's name in the file name never reaches the browser.

**Tech Stack:** PHP 7.4+/WordPress plugin, `$wpdb` repositories, PHPUnit with the WP core test library in the wp-env tests container, `@wordpress/scripts` for assets.

**Spec:** `docs/superpowers/specs/2026-11-13-external-judging-design.md`

## Global Constraints

- Run tests with `make test` and lint with `make lint`. To narrow a run, use `make test ARGS=--filter=Name`. `ARGS` is spliced into a single-quoted `bash -c`, so use one filter per command, with no quotes, spaces or `|`. The real phpcs gate is bare `./vendor/bin/phpcs`, which reads `phpcs.xml`.
- Prefix hooks the plugin fires with `photo_competition_manager_`. Option keys and cron events use `photo_comp_`. Table names use `{$wpdb->prefix}photocomp_`.
- New classes go in the `PhotoCompetitionManager\<Layer>` namespace, file `class-<kebab-name>.php`, mirrored in `tests/phpunit/<Layer>/class-<kebab-name>-test.php`.
- `@since` tags use the next unreleased version (0.4.0 while PR #153 is open, otherwise the version after it).
- Admin screens render through pure-`$data` template partials in `src/templates/admin/<controller>/`, with a golden-master render test per controller. Regenerate a fixture only for an intended change.
- Escape all output (`esc_html`, `esc_attr`, `esc_url`). Check nonces and the `manage_photo_competitions` capability on every admin action.
- Member-voted competitions (`password`, `token`) behave exactly as before. Existing render fixtures for them change only where a task says so.
- Judges never see an entrant's name or club. Public results show totals only, never a per-judge breakdown.
- Commit messages follow Conventional Commits with no attribution lines.

## Review Focus

1. **A judge opens their link when no category is open, or between categories.** They should see "Scoring isn't open yet" and a refresh button, not an error or an empty form. Test: Task 4, `test_judge_link_with_no_open_category_says_so`.
2. **A judge submits, and the admin closes the category before the POST arrives.** The judge should get "Voting is not open for this category" with their scores kept in the form. Test: Task 4, `test_judge_ballot_after_close_is_refused_and_kept`.
3. **The admin switches a competition from members to judges after members have voted.** The switch should be refused with a message, and the setting left unchanged. Test: Task 8, `test_scored_by_cannot_change_once_votes_exist`.
4. **The admin removes a judge mid-competition.** The judge's link should stop working at once, while their ballots already cast still count. Test: Task 2, `test_unassign_revokes_link_but_keeps_votes`.
5. **A member's old voting link or the voting password is used on a judged competition.** It should be refused, with a message that judges score this competition. Test: Task 3, `test_member_voters_refused_in_judged_competition`.

---

### Task 1: Schema for judges, assignments and judge votes

**Files:**
- Modify: `src/includes/Install/class-activator.php` (table SQL, `DB_VERSION` 8 → 9, a new `maybe_upgrade()` step)
- Modify: `src/includes/Install/class-uninstaller.php` (drop the two new tables)
- Test: `tests/phpunit/Install/class-activator-test.php`

**Interfaces:**
- Produces these tables:
  - `{prefix}photocomp_judges`: `id` BIGINT PK, `name` VARCHAR(191) NOT NULL, `email` VARCHAR(191) NOT NULL, `created_at`, `updated_at` NULL, KEY `email`.
  - `{prefix}photocomp_competition_judges`: `id` BIGINT PK, `competition_id` BIGINT NOT NULL, `judge_id` BIGINT NOT NULL, `token` VARCHAR(64) NOT NULL, `sent_at` DATETIME NULL, `created_at`, UNIQUE `competition_judge` (`competition_id`, `judge_id`), UNIQUE `token` (`token`).
  - `photocomp_votes` gains `judge_id` BIGINT UNSIGNED NULL, KEY `judge_id`, UNIQUE `image_judge` (`image_id`, `judge_id`).
- Produces `Activator::DB_VERSION = 9`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_upgrade_to_9_adds_judge_tables_and_votes_column() {
    update_option( Activator::DB_VERSION_OPTION, 8 );
    Activator::maybe_upgrade();
    global $wpdb;
    $this->assertSame( "{$wpdb->prefix}photocomp_judges", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$wpdb->prefix}photocomp_judges" ) ) );
    $this->assertSame( "{$wpdb->prefix}photocomp_competition_judges", $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', "{$wpdb->prefix}photocomp_competition_judges" ) ) );
    $this->assertNotEmpty( $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}photocomp_votes LIKE 'judge_id'" ) );
    $this->assertSame( 9, (int) get_option( Activator::DB_VERSION_OPTION ) );
}

public function test_existing_votes_keep_null_judge_id_after_upgrade() { /* insert a token vote at v8, upgrade, assert judge_id IS NULL and the vote count is unchanged */ }
```

DDL commits the test transaction. Follow `reference_test_db_ddl_leaks`: use the temp-shadow-table pattern in `tests/phpunit/fixtures/class-legacy-tables.php` for the v8 votes table, and clean up in `tearDown()` before `parent::tearDown()`.

- [ ] **Step 2: Run `make test ARGS=--filter=Activator_Test`.** Expected: the new tests FAIL.
- [ ] **Step 3: Implement.** Add both tables to the `dbDelta` SQL, and add `judge_id` with its keys to the votes `CREATE TABLE`. Add an `$installed < 9` step that runs `dbDelta` (or `ALTER TABLE … ADD COLUMN` guarded by `SHOW COLUMNS`) and returns early on failure, as the other steps do. The uninstaller drops both new tables.
- [ ] **Step 4: Run `make test ARGS=--filter=Activator_Test`, then `make test ARGS=--filter=Uninstaller_Test`.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: add tables for judges and judge votes`

---

### Task 2: Judges and competition-judge repositories

**Files:**
- Create: `src/includes/Repository/class-judges-repository.php`
- Create: `src/includes/Repository/class-competition-judges-repository.php`
- Test: `tests/phpunit/Repository/class-judges-repository-test.php`, `tests/phpunit/Repository/class-competition-judges-repository-test.php`

**Interfaces:**
- Consumes: Task 1 tables. Extend `Abstract_Repository` as the other repositories do.
- Produces `Judges_Repository`:
  - `create( string $name, string $email ): int|WP_Error`. Error codes `missing_name`, `invalid_email`.
  - `update( int $id, string $name, string $email ): true|WP_Error`
  - `find( int $id ): ?object`
  - `all(): object[]` (ordered by name)
  - `delete( int $id ): bool`. Also deletes the judge's `competition_judges` rows. Votes are left alone.
  - `find_by_email( string $email ): object[]`
- Produces `Competition_Judges_Repository`:
  - `assign( int $competition_id, int $judge_id ): string|WP_Error`. Returns the raw link token (`bin2hex( random_bytes( 32 ) )`). Assigning an already-assigned judge returns their existing token.
  - `unassign( int $competition_id, int $judge_id ): bool`
  - `judges_for( int $competition_id ): object[]` (judge rows joined with `token` and `sent_at`, ordered by name)
  - `find_by_token( string $token ): ?object` (`competition_id`, `judge_id`, `name`, `email`)
  - `mark_sent( int $competition_id, int $judge_id ): void`
  - `delete_by_competition( int $competition_id ): void` (called wherever a competition's voting tokens are deleted today)

- [ ] **Step 1: Write the failing tests.** Cover at least: `test_create_and_find_judge`, `test_create_rejects_bad_email`, `test_assign_returns_stable_token`, `test_find_by_token_returns_assignment`, `test_unassign_revokes_link_but_keeps_votes` (assign, insert a vote row with `judge_id`, unassign, then `assertNull( find_by_token( $token ) )` and assert the vote row still exists), and `test_delete_judge_removes_assignments_keeps_votes`.
- [ ] **Step 2: Run `make test ARGS=--filter=Judges_Repository`.** Expected: FAIL (class not found).
- [ ] **Step 3: Implement both repositories** with `$wpdb->prepare()` and the `%i` table placeholder, as `Upload_Token_Repository` does.
- [ ] **Step 4: Run the same filter.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: add judges and competition-judge repositories`

---

### Task 3: Judge voter and the judges auth mode in Ballots

**Files:**
- Create: `src/includes/Service/class-judge-voter.php`
- Modify: `src/includes/Repository/class-votes-repository.php`
- Modify: `src/includes/Service/class-ballots.php`
- Modify: `src/includes/Support/class-competition-settings.php` (`validate()` accepts `auth_mode` `judges`)
- Test: `tests/phpunit/Service/class-ballots-test.php`, `tests/phpunit/Support/class-competition-settings-test.php`

**Interfaces:**
- Consumes: `Competition_Judges_Repository::find_by_token()` (Task 2).
- Produces:
  - `Votes_Repository::create_judge_ballot( int $competition_id, string $category, int $judge_id, array $scores ): int|WP_Error`, through `insert_ballot()` with `$voter_column = 'judge_id'`.
  - `Votes_Repository::has_judge_voted( int $competition_id, string $category, int $judge_id ): bool`
  - `Votes_Repository::judges_with_ballot( int $competition_id, string $category ): int[]` (judge IDs)
  - `final class Judge_Voter implements Voter`, built with `__construct( object $assignment )`. It has `judge_id(): int` and `name(): string`. `may_vote_in()` always returns true, because the open-category check in `Ballots::cast()` is the gate. `record()` calls `create_judge_ballot()`, and `has_ballot()` calls `has_judge_voted()`.
  - `Ballots::judge_voter( object $competition, string $token ): Judge_Voter|WP_Error`. Error `invalid_link` when the token is unknown or belongs to another competition. Error `not_judged` when the competition's `auth_mode` isn't `judges`.
  - `Ballots::link_voter()` and `Ballots::named_voter()` return `WP_Error( 'judged_competition', __( 'Judges are scoring this competition, so members can\'t vote in it.', … ) )` when `auth_mode` is `judges`.
  - `Ballots::is_judged( object $competition ): bool` (public static), used by every later task.
  - `Ballots` takes `?Competition_Judges_Repository $competition_judges = null` as a sixth constructor argument.

- [ ] **Step 1: Write the failing tests** in `Ballots_Test`:

```php
public function test_judges_ballots_sum_into_totals() { /* judged competition, 2 judges, 2 entries; judge A scores 5,3, judge B scores 4,4; cast both; assert Results_Ranking totals 9 and 7 */ }
public function test_judge_link_for_other_competition_is_invalid() { /* assertSame( 'invalid_link', $ballots->judge_voter( $other, $token )->get_error_code() ) */ }
public function test_judge_cannot_cast_twice_in_category() { /* second cast returns already_cast */ }
public function test_judge_ballot_refused_when_category_not_open() { /* voting_closed */ }
public function test_member_voters_refused_in_judged_competition() {
    // assertSame( 'judged_competition', $ballots->link_voter( $judged, $member_token )->get_error_code() );
    // assertSame( 'judged_competition', $ballots->named_voter( $judged, 'Ann', 'pw' )->get_error_code() );
}
public function test_judge_voter_refused_in_member_competition() { /* not_judged */ }
```

Also add `test_validate_accepts_judges_auth_mode` and `test_validate_rejects_unknown_auth_mode` in `Competition_Settings_Test`.

- [ ] **Step 2: Run `make test ARGS=--filter=Ballots_Test` and `make test ARGS=--filter=Competition_Settings_Test`.** Expected: the new tests FAIL.
- [ ] **Step 3: Implement** the interfaces above. The admin forms are left until Task 8.
- [ ] **Step 4: Run the same filter.** Expected: PASS, including every existing Ballots test.
- [ ] **Step 5: Commit** `feat: let judges cast ballots in a judged competition`

---

### Task 4: Judge voting on the voting page

**Files:**
- Modify: `src/public/class-voting-shortcode.php`
- Modify: the voting partials under `src/templates/` that the page renders, if the judge view needs one
- Test: `tests/phpunit/Frontend/class-voting-shortcode-test.php` (new `judged` cases) and the voting render fixtures

**Interfaces:**
- Consumes: `Ballots::judge_voter()`, `Ballots::is_judged()` (Task 3), and `Competition_Workflow::categories_accepting_votes( object $competition )` (make it public if it's private).
- Produces: the judge link format `add_query_arg( 'judge', $token, Competition_Settings::page_url( 'voting_page', $competition ) )`. Task 8 builds links with this, and so does `Judge_Link::url( object $competition, string $token ): string` in `src/includes/Service/class-judge-link.php`, which this task creates.

Behaviour:
- `handle_ballot()`: when `is_judged()`, check the nonce `photo_competition_vote_as_judge`, build the voter with `judge_voter( $competition, $_GET['judge'] )`, and take the category from the POST, checked by `cast()`. Success redirects with `ballot=cast` and keeps `judge=` on the URL.
- `render()`: when `is_judged()`:
  - No valid judge link: show "Judges are scoring this competition." and nothing else. There's no link request form and no password form.
  - Valid link, no open category: show "Scoring isn't open yet." and a "Check again" button that keeps `judge=`.
  - Valid link and an open category: show the existing image grid and score inputs for that category, headed with the judge's name. The thank-you text says "Thank you, your scores have been recorded."
- The grid shows no entrant names. It shows image numbers only, as today.

- [ ] **Step 1: Write the failing tests:** `test_judge_sees_open_category_grid`, `test_judge_link_with_no_open_category_says_so`, `test_judged_competition_without_link_shows_notice_and_no_request_form` (assert no `photo_competition_request_voting_token` in the output), `test_judge_ballot_after_close_is_refused_and_kept` (POST after `Workflow_Fixtures::set_stage( …, 'done' )`, then assert the error message and that the posted scores are preselected), and `test_judge_ballot_redirect_keeps_judge_param`.
- [ ] **Step 2: Run `make test ARGS=--filter=Voting_Shortcode`.** Expected: FAIL.
- [ ] **Step 3: Implement.** Reuse `render_voting_interface()` by widening its `?Link_Voter $voter` parameter to `?Voter`, and pass the open category for a judge. Don't fork the grid markup.
- [ ] **Step 4: Run `make test ARGS=--filter=Voting_Shortcode` and `make test ARGS=--filter=Voting_Controller_Render`.** Expected: PASS, with member-voting fixtures unchanged.
- [ ] **Step 5: Commit** `feat: judges score from their link on the voting page`

---

### Task 5: Image permalink for the voting page and the slideshow

**Files:**
- Create: `src/public/class-image-permalink.php`
- Modify: `src/includes/Service/class-entries.php` (add `public_urls()`)
- Modify: `src/public/class-voting-shortcode.php` (both grids, around lines 649 and 907), `src/public/class-slideshow-shortcode.php:259`
- Modify: `src/includes/class-plugin.php` (register), `src/includes/Install/class-activator.php` (flush rewrite rules on activation and in the v9 step)
- Test: `tests/phpunit/Frontend/class-image-permalink-test.php`, plus the existing voting and slideshow tests

**Interfaces:**
- Produces `Entries::public_urls( object $entry ): array{full: string, thumb: string}`. It returns `home_url( "/photocomp-image/{$entry->id}/" )` and `…/{id}/thumb/`, each with `?v=<filemtime>`, or `''` when the file is missing. It has the same shape as `urls()`.
- Produces `Image_Permalink::register(): void`. It hooks `init` (the rewrite rule `^photocomp-image/([0-9]+)(?:/(thumb))?/?$` → `index.php?photocomp_image=$1&photocomp_image_size=$2`), `query_vars` and `template_redirect`.
- Produces `Image_Permalink::serve( int $entry_id, string $size ): void`. It streams the file with `Content-Type: image/jpeg`, `Cache-Control: public, max-age=31536000` and `X-Content-Type-Options: nosniff`, then exits. It sends a 404 when the entry, its competition (soft-deleted counts as missing) or the file doesn't exist. The file path comes only from `Entries` (category directory plus `filename`), never from the request.

- [ ] **Step 1: Write the failing tests:** `test_public_urls_use_entry_id_not_filename` (assert the URL contains `/photocomp-image/<id>/` and not the member's slug), `test_serve_404s_unknown_entry`, `test_serve_404s_deleted_competition`, `test_voting_grid_has_no_member_slug_in_markup` (render the grid for a member named "Jane Murphy" and `assertStringNotContainsString( 'jane-murphy', $html )`), and `test_slideshow_payload_has_no_member_slug`. For `serve()`, test a `resolve( int $entry_id, string $size ): ?string` helper that returns the path, so the test needn't exit. `serve()` stays a thin wrapper around it.
- [ ] **Step 2: Run `make test ARGS=--filter=Image_Permalink` and `make test ARGS=--filter=Voting_Shortcode` and `make test ARGS=--filter=Slideshow`.** Expected: FAIL.
- [ ] **Step 3: Implement.** Switch the voting page and the slideshow from `urls()` to `public_urls()` for every competition. Results, Top 3, emails, exports and admin screens keep `urls()`.
- [ ] **Step 4: Run the same filter, then `make test`.** Expected: PASS. Regenerate the voting and slideshow render fixtures. Their only diff should be the image URLs.
- [ ] **Step 5: Commit** `feat: serve voting and slideshow images by entry ID so file names stay hidden`

---

### Task 6: Strip author metadata from resized entry images

**Files:**
- Modify: `src/includes/Support/class-image-processor.php`
- Create: `tests/phpunit/fixtures/images/with-author.jpg` (a small JPEG with EXIF `Artist` and `Copyright`, an IPTC byline (2#080) and XMP `dc:creator` set to "Jane Murphy", made once with `exiftool`)
- Test: `tests/phpunit/Support/class-image-processor-test.php`

**Interfaces:**
- Produces `Image_Processor::strip_author_metadata( string $path ): void`. With Imagick available, it removes the `exif`, `iptc` and `xmp` profiles and keeps `icc`. With GD, the re-save already drops them, so it does nothing. `process()` calls it on the resized entry image and on the thumbnail. The media-library original keeps its metadata.

- [ ] **Step 1: Write the failing tests:** `test_processed_entry_has_no_author_metadata` (process the fixture, then assert `exif_read_data()` has no `Artist` or `Copyright`, `iptcparse()` of the `APP13` block has no `2#080`, and the file bytes don't contain "Jane Murphy") and `test_original_keeps_author_metadata`.
- [ ] **Step 2: Run `make test ARGS=--filter=Image_Processor`.** Expected: FAIL under Imagick.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same filter.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: strip author metadata from resized entry images`

---

### Task 7: Judges admin screen and privacy eraser

**Files:**
- Create: `src/admin/class-judges-controller.php`, `src/templates/admin/judges/judges-screen.php` (list plus add/edit form)
- Modify: `src/admin/class-admin-screen.php` (submenu "Judges", slug `photo-competition-manager-judges`, after Members), `src/includes/class-plugin.php` (eraser)
- Create: `src/includes/Service/class-judge-deletion.php`
- Test: `tests/phpunit/Admin/class-judges-controller-test.php`, `tests/phpunit/Admin/class-judges-controller-render-test.php` (golden master in `tests/phpunit/fixtures/judges-render/`), `tests/phpunit/Service/class-judge-deletion-test.php`

**Interfaces:**
- Consumes: `Judges_Repository` (Task 2).
- Produces `Judge_Deletion::delete( int $judge_id ): true|WP_Error` and `Judge_Deletion::erase( string $email, int $page = 1 ): array`, registered on `wp_privacy_personal_data_erasers` as `photo-competition-manager-judges`.
- The actions are add, edit and delete, using the same action-dispatch, nonce and redirect pattern as `Members_Controller`. Delete asks for confirmation, with the text "Their scores stay in every competition they judged."

- [ ] **Step 1: Write the failing tests:** `test_add_judge`, `test_add_judge_rejects_invalid_email` (assert the error notice and that nothing is saved), `test_delete_judge_keeps_totals` (a judged competition with a cast ballot: delete the judge, then assert `Results_Ranking` totals are unchanged), `test_eraser_removes_judge_by_email`, `test_judges_never_appear_on_members_screen`, and the render snapshot.
- [ ] **Step 2: Run `make test ARGS=--filter=Judges_Controller` and `make test ARGS=--filter=Judge_Deletion`.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same filter.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: add a Judges screen and erase judges on privacy requests`

---

### Task 8: Scored-by setting, judge picker and judge link email

**Files:**
- Modify: `src/admin/class-competitions-controller.php` (around lines 495–570 and 881), `src/admin/class-settings-controller.php` (around lines 134 and 406)
- Modify: `src/templates/admin/competitions/settings-form.php`, `src/templates/admin/settings/` voting partial
- Create: `src/templates/admin/competitions/judges-panel.php`
- Modify: `src/includes/Service/class-email-kinds.php` (new kind `judge_link`)
- Test: `tests/phpunit/Admin/class-competitions-controller-test.php`, `class-competitions-controller-render-test.php`, `class-settings-controller-test.php`, `tests/phpunit/Service/class-email-kinds-test.php`

**Interfaces:**
- Consumes: Task 2 repositories, `Judge_Link::url()` (Task 4) and `Email_Service::send()`. A judge row has `name` and `email`, which is all `send()` reads from `$member`.
- Produces the email kind `judge_link`: `notification => false`, label "Judge link", tags `{judge_link}` (link) and `{judge_name}` (text). Default subject: "Your judging link for {competition_title}". Default body: a greeting, "You've been asked to judge {competition_title}. Use this link to score the images when the organiser opens each category.", a button to `{judge_link}`, and "Please don't share this link."
- The "Who scores" select on the competition and club settings forms has the options "Members, with the voting password" (`password`), "Members, with emailed links" (`token`) and "Judges" (`judges`).
- The judges panel shows on the competition edit screen when `auth_mode` is `judges`. It has checkboxes for every judge in `Judges_Repository::all()`, saved with assign/unassign. For each assigned judge it shows a copyable link field, a "Send link" action and the time it was last sent, and the panel has a "Send link to all judges" action. Sending calls `mark_sent()` and logs through `Event_Logger` as other requested emails do.

- [ ] **Step 1: Write the failing tests:**
  - `test_scored_by_cannot_change_once_votes_exist`: a member-voted competition with one vote. POST `voting_auth_mode=judges`, then assert the settings error and that `auth_mode` is still `password`. Switching between `password` and `token` stays allowed.
  - `test_assign_and_unassign_judges`
  - `test_send_judge_link_emails_judge`: assert one mail to the judge's address, with the token URL in the body.
  - `test_judge_link_kind_declares_tags`
  - The render snapshot for a judged competition's edit screen.
- [ ] **Step 2: Run `make test ARGS=--filter=Competitions_Controller` and `make test ARGS=--filter=Settings_Controller` and `make test ARGS=--filter=Email_Kinds`.** Expected: FAIL.
- [ ] **Step 3: Implement.** Use `Votes_Repository::has_votes( $competition_id )` for the lock.
- [ ] **Step 4: Run the same filter.** Expected: PASS. Existing render fixtures change only by the new select option.
- [ ] **Step 5: Commit** `feat: choose judges for a competition and email them their links`

---

### Task 9: Members aren't invited to vote, and results hide per-judge votes

**Files:**
- Modify: `src/admin/class-voting-controller.php:594` (`queue_voting_opened_notifications()`)
- Modify: `src/includes/Service/class-email-kinds.php` (`upload_reminder`), `src/includes/Service/class-upload-link-service.php:173`
- Modify: `src/includes/Service/class-email-job-manager.php` (around lines 553–570), `src/templates/email/results-table.php`
- Test: `tests/phpunit/Admin/class-voting-controller-test.php`, `tests/phpunit/Service/class-upload-link-service-test.php`, `tests/phpunit/Service/class-email-job-manager-test.php`, `tests/phpunit/Service/class-results-table-render-test.php`, `tests/phpunit/Admin/class-email-templates-controller-render-test.php`

**Interfaces:**
- Consumes: `Ballots::is_judged()`.
- In a judged competition, `queue_voting_opened_notifications()` returns `null`, so no job is queued.
- `upload_reminder` gains a text tag `{voting_info}`. It renders as "Once voting opens, you can vote at <voting page URL>" for a member-voted competition, and as `''` for a judged one. The default body uses `{voting_info}` instead of the line with `{voting_page}`. `{voting_page}` stays declared, so admins' edited templates keep working unchanged.
- The `results-table.php` data gains `judged: bool`. When it's true, `Email_Job_Manager` passes `statistics => null` and `votes => array()`, and the template leaves out the vote-count row.

- [ ] **Step 1: Write the failing tests:** `test_open_voting_in_judged_competition_queues_no_member_email`, `test_upload_link_email_in_judged_competition_has_no_voting_line`, `test_upload_link_email_in_member_competition_keeps_voting_line`, `test_detailed_results_in_judged_competition_show_total_only` (assert the output contains the total and contains neither "Vote #" nor "Median"), and an unchanged-output check for member-voted results.
- [ ] **Step 2: Run `make test ARGS=--filter=Voting_Controller_Test` and `make test ARGS=--filter=Upload_Link_Service` and `make test ARGS=--filter=Email_Job_Manager` and `make test ARGS=--filter=Results_Table_Render` and `make test ARGS=--filter=Email_Templates_Controller_Render`.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same filter.** Expected: PASS. The email-templates render fixture changes only in the `upload_reminder` default body and tag list.
- [ ] **Step 5: Commit** `feat: keep members out of judged voting and hide per-judge votes in results`

---

### Task 10: Judges' progress on the Voting Controls screen

**Files:**
- Modify: `src/admin/class-voting-controller.php` (render data)
- Create: `src/templates/admin/voting/judges-progress.php`
- Test: `tests/phpunit/Admin/class-voting-controller-render-test.php` (new fixture in `tests/phpunit/fixtures/voting-render/`)

**Interfaces:**
- Consumes: `Competition_Judges_Repository::judges_for()` and `Votes_Repository::judges_with_ballot()`.
- The partial `$data['judges']` is `array<int, array{name: string, submitted: bool}>` for the category whose voting stage is Voting or Slideshow shown. It's empty, and the partial isn't rendered, when the competition isn't judged.
- It shows each judge's name with "Scored" or "Waiting", and no scores.

- [ ] **Step 1: Write the failing render test** for a judged competition with two judges, one of whom has submitted.
- [ ] **Step 2: Run `make test ARGS=--filter=Voting_Controller_Render`.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same filter.** Expected: PASS, with the existing voting fixtures unchanged.
- [ ] **Step 5: Commit** `feat: show which judges have scored the open category`

---

### Task 11: Docs and release notes

**Files:**
- Modify: `CONTEXT.md`: add **Judge** and **Judged competition**. Update **Voter** (judges are a third kind), **Ballot**, **Self-vote** (not for judges) and the opening line ("members or judges score them").
- Modify: `clubcompete_prd.md`: add external judging.
- Modify: the release notes on PR #153's branch (per `project_release_notes_pr`): one user-facing entry for judging, and one for hiding entrant names from voting image URLs.

- [ ] **Step 1: Make the edits.**
- [ ] **Step 2: Run `make check`.** Expected: lint, PHPUnit and JS tests all PASS.
- [ ] **Step 3: Commit** `docs: describe judges and judged competitions`
