# Competition Types Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let one site run several kinds of competition. Each kind is a type with its own categories, default settings and grade display names. Members are tagged with the types they enter, and a competition is open either to all of its type's members or only to selected entrants.

**Architecture:**
- **Storage.** Types live in a new `photocomp_competition_types` table. Their default settings are JSON with the same shape as a competition's settings. Member tags go in `photocomp_member_types`, selected entrants in `photocomp_entrants`, and a competition gains `type_id` and `entry_mode` columns.
- **Upgrade.** The upgrade turns the club's current settings into one type, tags every member with it, and sets every competition to that type and open to all. Behaviour is unchanged until a second type exists.
- **Who may enter.** One service, `Competition_Entry`, answers that question. Every upload, link, reminder and voting-link path asks it.
- **Grade names and titles.** One service, `Competition_Labels`, answers "what does this grade and this title read as for this audience".

**Tech Stack:** PHP 7.4+/WordPress plugin, `$wpdb` repositories, PHPUnit in the wp-env tests container, pure-`$data` admin template partials with golden-master render tests.

**Spec:** `docs/superpowers/specs/2026-11-13-competition-types-design.md`

**Starting point:** start from `main` after PR #228 (external judging) has merged. That PR leaves `Activator::DB_VERSION = 9`, `voting.auth_mode` `password|token|judges`, and `Ballots::is_judged()`. This plan's migration is step 10.

## Global Constraints

- **Tests and lint.** Run tests with `make test ARGS=--filter=Name`, one filter per command with no quotes, spaces or `|`. Run lint with bare `./vendor/bin/phpcs`, which reads `phpcs.xml` and only scans `./src`. If `make test` hits port conflicts, use `docker exec photo-competition-manager-tests-cli-1 bash -c 'cd /var/www/html/wp-content/pcm && WP_CORE_DIR=/var/www/html WP_ENV_TEST_DB_HOST=tests-mysql ./vendor/bin/phpunit -c phpunit.xml.dist --filter=Name'`. One test failure predates this work: `Export_Screen_Test::test_an_original_that_cant_be_read_fails_the_zip`.
- **Naming.** Hooks the plugin fires start with `photo_competition_manager_`. Options use `photo_comp_`, and tables use `{$wpdb->prefix}photocomp_`.
- **Classes and tests.** New classes go in `PhotoCompetitionManager\<Layer>`, in a file named `class-<kebab-name>.php`. Each gets a matching test in `tests/phpunit/<Layer>/class-<kebab-name>-test.php`. `@since` tags use the next unreleased version.
- **Admin screens.** Admin screens render through pure-`$data` partials in `src/templates/admin/<controller>/`, with golden-master tests in `tests/phpunit/fixtures/<controller>-render/`. Regenerate a fixture only for an intended change, and name it in the commit.
- **Security.** Escape all output. Check nonces and the `manage_photo_competitions` capability on every admin action.
- **DDL in tests.** DDL in a test commits the transaction. Use the shadow-table pattern in `tests/phpunit/fixtures/class-legacy-tables.php`.
- **One type.** A site with one type must look and behave exactly as before. That means no type picker, no type filter and no tag inputs, unless a task says otherwise.
- **One open competition at a time** stays. `find_overlapping()`, `find_current_active()` and the one-category-voting rule are unchanged.
- **Commits.** Use Conventional Commits with no attribution lines.

## Review Focus

1. **A competition with no categories of its own before the upgrade.** It must keep showing exactly the club's categories afterwards. Test: Task 1, `test_upgrade_gives_competitions_without_categories_the_club_list`.
2. **A member added by CSV import on a one-type site.** They must get the type's tag, or they would silently never receive upload links. Test: Task 5, `test_csv_import_tags_new_members_with_the_only_type`.
3. **An entrant removed from a selected-entrants competition who still holds an upload link.** The link must be refused on the upload page and by the REST upload API alike. Test: Task 7, `test_removed_entrant_link_is_refused_on_page_and_api`.
4. **An untagged active member and an open-to-all competition.** The member gets no upload link, and the Members screen flags them. Tests: Task 5, `test_untagged_active_members_are_flagged`, and Task 7, `test_untagged_member_gets_no_link_for_open_competition`.
5. **A title containing `{grade}` sent to someone with no grade**, such as a judge or a member whose grade is no longer on the list. The title shows all the competition's grade names joined, never a literal `{grade}`. Test: Task 9, `test_title_for_recipient_without_grade_uses_everyone_form`.

---

### Task 1: Schema and the one-type upgrade

**Files:**
- Modify: `src/includes/Install/class-activator.php` (`get_schema()`, `DB_VERSION` 9 → 10, `$installed < 10` step)
- Modify: `src/uninstall.php` (drop the new tables)
- Test: `tests/phpunit/Install/class-activator-test.php`, `tests/phpunit/class-plugin-test.php` (schema count)

**Interfaces:**
- **Produces these tables:**
  - `{prefix}photocomp_competition_types`: `id` BIGINT PK, `name` VARCHAR(191) NOT NULL, `settings` LONGTEXT NULL (competition-default JSON, see Task 2), `grade_names` LONGTEXT NULL (JSON, grade slug ⇒ display name), `created_at`, `updated_at` NULL.
  - `{prefix}photocomp_member_types`: `member_id` BIGINT NOT NULL, `type_id` BIGINT NOT NULL, PRIMARY KEY (`member_id`, `type_id`), KEY `type_id`.
  - `{prefix}photocomp_entrants`: `competition_id` BIGINT NOT NULL, `member_id` BIGINT NOT NULL, `created_at`, PRIMARY KEY (`competition_id`, `member_id`), KEY `member_id`.
  - `photocomp_competitions` gains `type_id` BIGINT UNSIGNED NULL, KEY `type_id`, and `entry_mode` VARCHAR(20) NOT NULL DEFAULT `'all'`.
- **Produces `Activator::create_default_type(): int|false`,** used only by the upgrade. Behaviour:
  - Inserts one type named `__( 'Club competition', 'photo-competition-manager' )` whose `settings` are the club option's `categories`, `upload`, `voting`, `slideshow` and `email_reminders`, and whose `grade_names` is `{}`.
  - Tags every member, active or not, with the new type.
  - Sets every competition, including soft-deleted ones, to `type_id` = that type and `entry_mode` = `'all'`.
  - Gives every competition whose settings have no `categories` the club's current category list.
  - It's idempotent. If a type already exists, it does nothing and returns that type's ID.

- [ ] **Step 1: Write the failing tests:**
  - `test_upgrade_to_10_adds_type_tables_and_columns`: use shadow tables for a v9 competitions table without the new columns.
  - `test_upgrade_creates_one_type_from_club_settings`: assert the type's `settings['categories']`, `settings['voting']['score_matrix']` and `settings['upload']` equal the club option's.
  - `test_upgrade_tags_every_member_and_sets_every_competition`: assert all members are tagged, and every competition has `type_id` set and `entry_mode` = `'all'`.
  - `test_upgrade_gives_competitions_without_categories_the_club_list`
  - `test_upgrade_to_10_is_idempotent`: run it twice and assert there's still one type and no duplicate tags.
- [ ] **Step 2: Run `make test ARGS=--filter=Activator_Test`.** Expected: the new tests FAIL.
- [ ] **Step 3: Implement.** Follow the step 9 pattern: guarded DDL, return false on failure so the step reruns, and bump the version only at the end.
- [ ] **Step 4: Run `make test ARGS=--filter=Activator_Test`, then `make test ARGS=--filter=Plugin_Test`.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: add competition types, member tags and entrants to the schema`

---

### Task 2: Competition types repository and type settings

**Files:**
- Create: `src/includes/Repository/class-competition-types-repository.php`
- Modify: `src/includes/Support/class-competition-settings.php`
- Test: `tests/phpunit/Repository/class-competition-types-repository-test.php`, `tests/phpunit/Support/class-competition-settings-test.php`

**Interfaces:**
- **Produces `Competition_Types_Repository`:**
  - `all(): object[]`, ordered by name.
  - `find( int $id ): ?object`. Fields: `id`, `name`, `settings` (decoded array), `grade_names` (decoded array).
  - `count(): int`
  - `only(): ?object`, which returns the type when exactly one exists and null otherwise.
  - `create( string $name, array $settings, array $grade_names = array() ): int|WP_Error`. Errors: `missing_name`, `invalid_settings` (from `Competition_Settings::validate_type_settings()`).
  - `update( int $id, string $name, array $settings, array $grade_names ): true|WP_Error`
  - `delete( int $id ): true|WP_Error`. Errors: `type_in_use` when any competition, soft-deleted included, has this `type_id`; `last_type` when it's the only type. On success it removes the type's member tags.
- **Produces these `Competition_Settings` methods:**
  - `TYPE_KEYS = array( 'categories', 'upload', 'voting', 'slideshow', 'email_reminders' )`, the settings a type holds.
  - `type_settings( object $type ): array`, which merges the type's stored settings over `defaults()` for exactly `TYPE_KEYS`.
  - `validate_type_settings( array $settings )`: true|WP_Error, reusing `validate( $settings, false )`.
  - `settings_for_new_competition( object $type ): array`, which is `type_settings( $type )` plus the club's `urls`. Competitions keep a copy of the page links, as today.

- [ ] **Step 1: Write the failing tests:**
  - `test_create_and_find_type_round_trips_settings_and_grade_names`
  - `test_delete_refuses_type_in_use`: covers a soft-deleted competition too.
  - `test_delete_refuses_last_type`
  - `test_only_returns_null_with_two_types`
  - `test_settings_for_new_competition_copies_type_and_club_urls`
  - `test_validate_type_settings_rejects_empty_score_matrix`
- [ ] **Step 2: Run `make test ARGS=--filter=Competition_Types_Repository` and `make test ARGS=--filter=Competition_Settings_Test`.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same two filters.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: store competition types and their default settings`

---

### Task 3: Types admin screen and a smaller Settings screen

**Files:**
- Create: `src/admin/class-types-controller.php`, `src/templates/admin/types/types-screen.php` (list), `src/templates/admin/types/type-form.php` (add/edit)
- Modify: `src/admin/class-admin-screen.php` (submenu "Competition Types", slug `photo-competition-manager-types`, after Settings), `src/admin/class-settings-controller.php`, `src/templates/admin/settings/*`
- Test: `tests/phpunit/Admin/class-types-controller-test.php`, `tests/phpunit/Admin/class-types-controller-render-test.php` (fixtures in `tests/phpunit/fixtures/types-render/`), `tests/phpunit/Admin/class-settings-controller-test.php`, `class-settings-controller-render-test.php`

**Interfaces:**
- **Consumes:** Task 2.
- **The type form** has a name field and the existing section partials, reused rather than copied: categories (`render_categories_section`'s partial), upload limits, voting (who scores, score list, password, click to zoom), slideshow and email reminders. Below them is a "Grade names" table with one row per club grade: the site-wide label, and a text input for this type's name, where blank means use the site-wide label. Actions: add, update and delete (with a confirmation page). `type_in_use` and `last_type` errors show as notices.
- **The Settings screen** keeps grades, email, page URLs and log retention. It no longer shows or saves categories, upload, voting, slideshow or email reminders. Where they were, it shows "Categories, upload limits, voting and slideshow settings are now set for each competition type." with a link to the Types screen. The club option keeps its old keys untouched; nothing reads them after Task 1.
- **The voting UI type option** (`photo_comp_voting_ui_type`) stays on Settings. It is site-wide.

- [ ] **Step 1: Write the failing tests:**
  - `test_add_type_saves_name_categories_and_settings`
  - `test_add_type_rejects_missing_name`
  - `test_edit_type_saves_grade_names_and_blank_means_default`
  - `test_delete_type_in_use_shows_error`
  - `test_settings_save_no_longer_touches_competition_defaults`: post the old voting fields to Settings and assert the type's voting settings are unchanged.
  - Render snapshots for the list, the add form, the edit form, and the edit form with an error.
- [ ] **Step 2: Run `make test ARGS=--filter=Types_Controller` and `make test ARGS=--filter=Settings_Controller`.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same filters.** Expected: PASS. The settings-render fixtures change by the removed sections and the new link. Regenerate them and name them in the commit.
- [ ] **Step 5: Commit** `feat: edit competition types on their own screen`

---

### Task 4: Competitions belong to a type

**Files:**
- Modify: `src/includes/Repository/class-competitions-repository.php` (`create()`/`update()` accept `type_id` and `entry_mode`)
- Modify: `src/admin/class-competitions-controller.php` (create branch ~:193-207, update branch), `src/templates/admin/competitions/create-form.php`, `general-form.php`
- Test: `tests/phpunit/Admin/class-competitions-controller-test.php`, `class-competitions-controller-render-test.php`, `tests/phpunit/Repository/class-competitions-repository-test.php`

**Interfaces:**
- **Consumes:** `Competition_Types_Repository::all()`, `only()` and `find()`, and `Competition_Settings::settings_for_new_competition()`.
- **`Competitions_Repository::create( array $data )`** accepts `type_id` (required, error `invalid_type` if no such type) and `entry_mode` (`all`|`selected`, default `all`).
- **Creating a competition:**
  - With one type, the form has no type field, and the competition gets `only()`'s ID.
  - With several types, a required `competition_type` select lists `all()`.
  - Settings come from `settings_for_new_competition( $type )`, replacing the `global_settings()` copy.
- **Editing a competition:** with several types, the General tab shows the type. It's a select until the competition has any entries, then read-only text with "A competition's type can't change once it has entries." A POST that tries to change it after that is refused with error `type_locked`. Changing type doesn't re-copy settings; the competition keeps its own copy.

- [ ] **Step 1: Write the failing tests:**
  - `test_create_with_one_type_needs_no_type_field`
  - `test_create_copies_type_categories_and_settings`
  - `test_create_with_two_types_requires_a_type`
  - `test_editing_type_after_editing_competition_doesnt_change_it`
  - `test_type_cannot_change_once_competition_has_entries`
  - Render snapshots for the create form and general tab, with one type (unchanged) and with two types.
- [ ] **Step 2: Run `make test ARGS=--filter=Competitions_Controller` and `make test ARGS=--filter=Competitions_Repository`.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same filters.** Expected: PASS. The one-type fixtures stay byte-identical.
- [ ] **Step 5: Commit** `feat: create each competition from a type`

---

### Task 5: Member type tags

**Files:**
- Modify: `src/includes/Repository/class-members-repository.php`, `src/admin/class-members-controller.php`, `src/templates/admin/members/{filters,members-table,create-form,edit-form,import-form}.php`, `src/includes/Service/class-member-csv-importer.php`, `src/includes/Service/class-member-deletion.php` (delete the member's tags)
- Test: `tests/phpunit/Repository/class-members-repository-test.php`, `tests/phpunit/Admin/class-members-controller-test.php`, `class-members-controller-render-test.php`, `tests/phpunit/Service/class-member-csv-importer-test.php`, `class-member-deletion-test.php`

**Interfaces:**
- **Produces these `Members_Repository` methods:**
  - `type_ids( int $member_id ): int[]`
  - `set_types( int $member_id, array $type_ids ): void`, which replaces the member's tags.
  - `find_by_type( int $type_id, bool $only_active = true ): object[]`
  - `find_untagged( bool $only_active = true ): object[]`
- **Automatic tagging:** `create()` tags a new member with `Competition_Types_Repository::only()`'s type when exactly one type exists, unless `$data['type_ids']` is given. This covers every path that creates a member: the screen, CSV import and Task 6's create-and-add.
- **Members screen, several types only:**
  - a `type` filter
  - a "Types" column
  - checkbox tags on the create and edit forms
  - bulk "Add to type" and "Remove from type" actions
  - a notice, "N active members have no type, so they get no links for competitions open to all. [Show them]", which links to `?type=none`
- **CSV import** gets an optional `types` column of type names separated by `;`, matched case-insensitively. An unknown name makes that row an error, `Row %d: "%s" isn't a competition type.`, and the row is skipped. With several types, a missing column leaves new members untagged and existing members' tags unchanged.

- [ ] **Step 1: Write the failing tests:**
  - `test_new_member_gets_the_only_type`
  - `test_new_member_untagged_with_two_types`
  - `test_set_types_replaces_tags`
  - `test_csv_import_tags_new_members_with_the_only_type`
  - `test_csv_import_types_column_tags_members`
  - `test_csv_import_unknown_type_is_a_row_error`
  - `test_untagged_active_members_are_flagged`
  - `test_filter_by_type`
  - `test_deleting_member_deletes_tags`
  - Render snapshots: one type (unchanged) and two types.
- [ ] **Step 2: Run `make test ARGS=--filter=Members_Repository`, `make test ARGS=--filter=Members_Controller`, `make test ARGS=--filter=Member_Csv_Importer` and `make test ARGS=--filter=Member_Deletion`.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same filters.** Expected: PASS, with one-type members-render fixtures byte-identical.
- [ ] **Step 5: Commit** `feat: tag members with the competition types they enter`

---

### Task 6: Entry setting and entrants on a competition

**Files:**
- Create: `src/includes/Repository/class-entrants-repository.php`, `src/includes/Service/class-competition-entry.php`, `src/templates/admin/competitions/entrants-panel.php`
- Modify: `src/admin/class-competitions-controller.php` (an Entrants tab or panel, plus actions)
- Modify: `src/includes/Repository/class-competitions-repository.php` (delete a competition's entrants on delete)
- Modify: `src/includes/Service/class-member-deletion.php` (delete the member's entrant rows)
- Test: `tests/phpunit/Repository/class-entrants-repository-test.php`, `tests/phpunit/Service/class-competition-entry-test.php`, `tests/phpunit/Admin/class-competitions-controller-test.php`, `class-competitions-controller-render-test.php`

**Interfaces:**
- **Produces `Entrants_Repository`:**
  - `add( int $competition_id, int $member_id ): void`, which is idempotent.
  - `remove( int $competition_id, int $member_id ): void`
  - `member_ids( int $competition_id ): int[]`
  - `has( int $competition_id, int $member_id ): bool`
  - `delete_by_competition( int )`
  - `delete_by_member( int )`
- **Produces `Competition_Entry`**, the single answer to "who may enter":
  - `may_enter( object $competition, object $member ): bool`
    - The member must be active.
    - With `entry_mode` `all`, the member must carry the competition's type.
    - With `selected`, the member must be in `Entrants_Repository`.
  - `eligible_members( object $competition ): object[]`: active members who may enter and have an email.
  - `add_entrant( object $competition, int $member_id ): true|WP_Error`
    - Error `entry_closed` once any category's voting has started (`Competition_Workflow` stage past Previewed).
    - Error `not_selected_mode` when `entry_mode` is `all`.
    - When the competition's uploads are open, it sends the member's upload link with `Upload_Link_Service::send_to_member( $competition_id, $member_id, true )`.
  - `remove_entrant( object $competition, int $member_id ): true|WP_Error`
    - Error `entrant_has_entries` while they have entries.
    - Error `entry_closed` once voting has started.
    - Otherwise it removes the entrant and deletes their upload token for this competition.
  - `set_mode( object $competition, string $mode ): true|WP_Error`
    - Error `entry_closed` once voting has started.
    - Switching to `selected` adds every member who has entries as an entrant.
- **Entrants panel**, on the competition edit screen:
  - The entry setting: "Open to everyone in <type>" or "Selected entrants only". With one type, the first option reads "Open to all members".
  - When the setting is `selected`: the entrant list with Remove and "Send upload link" (`Upload_Link_Service::send_to_member( …, true )`, shown while uploads are open), an "Add entrants" picker listing eligible active members with the type's tag first, then other active members, and an "Add a new member and enter them" mini-form (name, email, grade). That form creates the member through `Members_Repository::create()`, which tags them by Task 5's rule, then calls `add_entrant()`.
  - Errors show as notices.

- [ ] **Step 1: Write the failing tests:**
  - `test_may_enter_open_to_all_requires_type_tag`
  - `test_may_enter_selected_requires_entrant`
  - `test_inactive_member_may_not_enter`
  - `test_add_entrant_while_uploads_open_sends_upload_link`: assert the mail is sent to the member.
  - `test_add_entrant_after_voting_started_is_refused`
  - `test_remove_entrant_with_entries_is_refused`
  - `test_remove_entrant_revokes_upload_token`
  - `test_switching_to_selected_keeps_members_with_entries`
  - `test_create_member_and_enter_them`
  - `test_resend_upload_link_to_entrant`
  - Render snapshots of the panel in both modes.
- [ ] **Step 2: Run `make test ARGS=--filter=Entrants_Repository`, `make test ARGS=--filter=Competition_Entry` and `make test ARGS=--filter=Competitions_Controller`.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same filters.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: open a competition to all of its type or to selected entrants`

---

### Task 7: Only those who may enter get links and can upload

**Files:**
- Modify:
  - `src/includes/Service/class-upload-link-service.php` (`send_link()`, `send_by_email()`, `queue_reminders()`)
  - `src/public/class-upload-shortcode.php` (~:220-229, after `find_valid_token`)
  - `src/includes/API/class-upload-api.php` (`validate_token_permission()`)
  - `src/admin/class-voting-controller.php` (`queue_voting_opened_notifications()` ~:657)
  - `src/public/class-voting-shortcode.php` (`handle_token_request()` ~:490)
  - `src/admin/class-members-controller.php` (send one upload link, ~:170-200)
- Test: `tests/phpunit/Service/class-upload-link-service-test.php`, `tests/phpunit/Frontend/class-upload-shortcode-test.php`, `tests/phpunit/API/class-upload-api-test.php`, `tests/phpunit/Admin/class-voting-controller-test.php`, `tests/phpunit/Frontend/class-voting-shortcode-test.php`

**Interfaces:**
- **Consumes:** `Competition_Entry::may_enter()` and `eligible_members()` (Task 6).
- **`send_link()`** returns `WP_Error( 'not_an_entrant', … )` when `! may_enter()`. `send_by_email()` stays silent in that case, keeping the existing generic message so it doesn't reveal who has entered. `queue_reminders()` queues `eligible_members()` instead of every active member.
- **The upload page and the REST API** treat a valid token whose member `! may_enter()` as invalid. The upload page shows "This upload link is no longer valid for this competition." and the API returns `invalid_token`, status 403.
- **The voting opened email** goes to active members carrying the competition's type, not every active member. Judged competitions still send none (#228).
- **Voting links:** with `entry_mode` `selected`, a member can request a voting link only if they're an entrant. With `all`, only if they carry the type. Otherwise they get the existing generic message and no email. The voting password (honour system) is unchanged.
- **Members screen:** "Send upload link" for one member shows `not_an_entrant` as an error notice.

- [ ] **Step 1: Write the failing tests:**
  - `test_untagged_member_gets_no_link_for_open_competition`
  - `test_reminders_go_only_to_entrants_when_selected`
  - `test_removed_entrant_link_is_refused_on_page_and_api`: a single test that checks both, or a pair sharing setup.
  - `test_request_link_by_email_for_non_entrant_sends_nothing`
  - `test_voting_opened_goes_to_type_members_only`
  - `test_non_entrant_cannot_request_voting_link_in_selected_competition`
  - `test_one_type_site_sends_links_to_all_active_members_as_before`
- [ ] **Step 2: Run each of `Upload_Link_Service`, `Upload_Shortcode`, `Upload_Api`, `Voting_Controller_Test` and `Voting_Shortcode` with `make test ARGS=--filter=<Name>`.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same filters, then the full suite once.** Expected: PASS.
- [ ] **Step 5: Commit** `feat: send links to and accept uploads from only those who may enter`

---

### Task 8: Per-type grade display names

**Files:**
- Create: `src/includes/Service/class-competition-labels.php`
- Modify:
  - `src/includes/Service/class-results-ranking.php` (`empty_groups()` :318-322, the raw-slug fallback in `rank_recorded`)
  - `src/includes/Service/class-member-export.php` (`grade_label()` :308)
  - `src/includes/Service/class-entries.php:246` (`{member_grade}` in the submission confirmation)
  - `src/admin/class-results-controller.php` (~:644-681, :739)
  - `src/admin/class-members-controller.php` (nothing to change: the Members screen shows site-wide labels)
- Test: `tests/phpunit/Service/class-competition-labels-test.php`, `class-results-ranking-test.php`, `tests/phpunit/Frontend/class-results-shortcode-test.php`, `class-top3-shortcode-test.php`, `tests/phpunit/Service/class-results-table-render-test.php`, `tests/phpunit/Admin/class-results-controller-render-test.php`

**Interfaces:**
- **Produces `Competition_Labels`:**
  - `grade_label( object $competition, string $grade_slug ): string`: the type's `grade_names[ slug ]` if set, otherwise the site-wide label, otherwise the slug.
  - `grade_labels( object $competition ): array<string,string>`
  - `admin_grade_label( object $competition, string $grade_slug ): string`: "Cup (Advanced)" when the type renames the grade, otherwise the site-wide label.
- **Results_Ranking group labels** come from `grade_labels( $competition )`, so the results shortcode, Top 3 and the results email change without edits.
  - Admin results tables use `admin_grade_label()`.
  - The member export uses `grade_label()` per competition row.
  - `{member_grade}` in the submission confirmation uses `grade_label()`, and now shows a label where it used to show the raw slug.

- [ ] **Step 1: Write the failing tests:**
  - `test_grade_label_uses_type_name_then_site_label`
  - `test_admin_grade_label_shows_both_when_renamed`
  - `test_results_page_shows_type_grade_names`
  - `test_results_email_shows_type_grade_names`
  - `test_member_grade_tag_shows_label_not_slug`
  - `test_unrenamed_type_output_is_unchanged`: the existing results fixtures stay byte-identical.
- [ ] **Step 2: Run `make test ARGS=--filter=Competition_Labels`, then `Results_Ranking`, `Results_Shortcode` and `Results_Controller` the same way.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same filters.** Expected: PASS. Only the fixtures for the submission-confirmed `{member_grade}` change. Name them.
- [ ] **Step 5: Commit** `feat: let a competition type rename grades for its competitions`

---

### Task 9: `{grade}` in competition titles

**Files:**
- Modify:
  - `src/includes/Service/class-competition-labels.php` (add the title methods)
  - `src/includes/Service/class-email-service.php:72`
  - `src/public/class-upload-shortcode.php:503`
  - `src/public/class-voting-shortcode.php` (~:573, :877)
  - `src/public/class-slideshow-shortcode.php:159`
  - `src/public/class-results-shortcode.php:181`
  - `src/public/class-top3-shortcode.php:183`
  - admin title displays (competitions table, edit screen heading, Voting Controls, Results, Submissions, `class-export-screen.php:113/144/175`)
  - `src/includes/Service/class-member-export.php:348`
  - the competition create/general form help text
- Test: `tests/phpunit/Service/class-competition-labels-test.php`, `tests/phpunit/Service/class-email-service-test.php`, `tests/phpunit/Frontend/class-upload-shortcode-test.php`, `class-results-shortcode-test.php`

**Interfaces:**
- **Produces these `Competition_Labels` methods:**
  - `title_for( object $competition, ?object $recipient = null ): string`. With a `$recipient` that has a `grade` the competition uses, `{grade}` becomes `grade_label()` for that grade. With no recipient, or one without such a grade, `{grade}` becomes every grade label that holds entries in the competition, joined with " / ". If no grade holds entries yet, it uses every site-wide grade label joined. A title without `{grade}` is returned unchanged.
  - `title_for_everyone( object $competition ): string`, which is `title_for( $competition, null )`.
- **`Email_Service::send()`** fills `{competition_title}` with `title_for( $competition, $member )`. Judges have no `grade`, so they get the "everyone" form.
- **The upload page** uses `title_for( $competition, $member )` when a valid token identifies the member, and `title_for_everyone()` otherwise.
- **Every other display listed** uses `title_for_everyone()`.
- **The create and general forms** add help text: "Add {grade} to show each entrant their own grade's name, for example "{grade} 2026"."

- [ ] **Step 1: Write the failing tests:**
  - `test_title_without_placeholder_unchanged`
  - `test_title_for_member_uses_their_grade_name`
  - `test_title_for_everyone_joins_grade_names_with_entries`
  - `test_title_for_recipient_without_grade_uses_everyone_form`
  - `test_email_subject_uses_recipients_grade_title`: send `upload_reminder` to a member of grade `advanced` whose type renames it "Cup", with the title `{grade} 2026`, and assert the subject contains "Cup 2026".
  - `test_upload_page_shows_entrants_grade_title`
- [ ] **Step 2: Run `make test ARGS=--filter=Competition_Labels`, then `Email_Service` and `Upload_Shortcode` the same way.** Expected: FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the same filters, then the full suite once.** Expected: PASS, with existing fixtures unchanged, since none of their titles contain `{grade}`.
- [ ] **Step 5: Commit** `feat: show each entrant their own grade's name in a competition's title`

---

### Task 10: Docs

**Files:**
- Modify:
  - `CONTEXT.md`: add **Competition type** and **Entrant**. Update **Category** (a new competition copies its type's list), **Grade** (site-wide across types, with per-type display names) and the opening line. Write in the file's glossary voice, with no class or table names.
  - `clubcompete_prd.md`: add the feature and the data model changes.
  - `src/readme.txt`: on PR #153's branch if 0.4.0 is still unreleased, otherwise under the next version. Add user-facing entries, including the change to "Before you upgrade": "ten database upgrades", the new type, and Settings losing its competition-default sections.

- [ ] **Step 1: Make the edits.**
- [ ] **Step 2: Run the full suite and bare `./vendor/bin/phpcs`.** Expected: PASS, apart from the known failure.
- [ ] **Step 3: Commit** `docs: describe competition types and entrants`
