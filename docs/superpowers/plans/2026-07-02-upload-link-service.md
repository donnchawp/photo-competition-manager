# Upload_Link_Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move email/orchestration logic out of `Upload_Token_Repository` and `Competitions_Repository` into a new `Upload_Link_Service`, leaving the repositories as pure persistence.

**Architecture:** A new service `PhotoCompetitionManager\Service\Upload_Link_Service` composes the token/competition/member repositories and `Email_Service` (constructor DI, nullable defaults — the `Upload_Handler` pattern). The three orchestration methods move verbatim into it; return shapes are preserved so callers are untouched apart from swapping the object they call. The repositories keep their persistence primitives and gain one new persistence method (`mark_sent`).

**Tech Stack:** PHP 7.4+, WordPress plugin, PHPUnit with the WP core test library, WordPress Coding Standards (phpcs/phpcbf).

## Global Constraints

- WordPress PHP coding standard: four-space indentation, snake_case functions, PascalCase classes in the `PhotoCompetitionManager` namespace. A PostToolUse hook auto-runs `phpcbf`; also run `./vendor/bin/phpcs --standard=WordPress --extensions=php <files>` and fix ERRORS. The `manage_photo_competitions` custom-capability warning is pre-existing noise; ignore it. Test files are not held to a clean phpcs bar.
- Prefix actions/filters/option keys with `photo_comp_` / `photo_competition_manager_`.
- Escape output with WP helpers; sanitize request input.
- Behavior-preserving refactor: keep the characterization suite green after each step. Return shapes and `WP_Error` codes must be preserved exactly.
- Branch: `refactor/8-upload-link-service` (already created). PR into `main`, body ends `Closes #8`, merge commit (not squash). The USER merges; do not merge yourself.
- Running tests (dynamic MySQL host port — do NOT hardcode):
  ```
  PORT=$(docker port photo-competition-manager-tests-mysql-1 3306/tcp | head -1 | sed 's/.*://')
  WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter <Filter>
  ```
  Pre-existing test noise to ignore: `Duplicate key name 'member_competition_category'` dbDelta lines.

---

### Task 1: Extract `mark_sent()` persistence method

Pull the inline `sent_at` write out of `send_upload_link_for_member` into its own repository method. This is the one non-pure-motion change and is isolated here so it can be reviewed and tested on its own. Behavior is unchanged: the existing method calls the new method.

**Files:**
- Modify: `src/includes/Repository/class-upload-token-repository.php` (add method; replace inline `$wpdb->update` at ~line 344-353 with a call)
- Test: `tests/phpunit/Repository/class-upload-token-repository-test.php`

**Interfaces:**
- Produces: `Upload_Token_Repository::mark_sent( int $token_id ): void` — sets `sent_at = utc_time()` for the given token row.

- [ ] **Step 1: Write the failing test**

Add to `tests/phpunit/Repository/class-upload-token-repository-test.php`:

```php
	/**
	 * Test mark_sent sets sent_at so has_recent_email_send becomes true.
	 */
	public function test_mark_sent_sets_sent_at() {
		$member_id      = 1;
		$competition_id = 2;

		$token_obj = $this->repo->find_or_create( $member_id, $competition_id );
		$this->assertIsObject( $token_obj );
		$this->assertFalse( $this->repo->has_recent_email_send( $member_id, $competition_id ) );

		$this->repo->mark_sent( (int) $token_obj->id );

		$this->assertTrue( $this->repo->has_recent_email_send( $member_id, $competition_id ) );
	}
```

- [ ] **Step 2: Run test to verify it fails**

```
PORT=$(docker port photo-competition-manager-tests-mysql-1 3306/tcp | head -1 | sed 's/.*://')
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter test_mark_sent_sets_sent_at
```
Expected: FAIL — `Call to undefined method ...::mark_sent()`.

- [ ] **Step 3: Add the `mark_sent` method**

In `src/includes/Repository/class-upload-token-repository.php`, add after `has_recent_email_send()` (the `utc_time()` function is already imported at the top of the file):

```php
	/**
	 * Record that an upload-link email was sent for a token.
	 *
	 * @since 1.2.0
	 * @param int $token_id Token row ID.
	 * @return void
	 */
	public function mark_sent( int $token_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table(),
			array( 'sent_at' => utc_time() ),
			array( 'id' => $token_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
```

- [ ] **Step 4: Replace the inline write in `send_upload_link_for_member`**

In the same file, replace the inline `sent_at` block (currently after the `if ( ! $sent )` guard):

```php
		// Update sent_at timestamp to track when email was sent.
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table(),
			array( 'sent_at' => utc_time() ),
			array( 'id' => $token_obj->id ),
			array( '%s' ),
			array( '%d' )
		);

		return true;
```

with:

```php
		// Record when the email was sent.
		$this->mark_sent( (int) $token_obj->id );

		return true;
```

- [ ] **Step 5: Run the repository test suite**

```
PORT=$(docker port photo-competition-manager-tests-mysql-1 3306/tcp | head -1 | sed 's/.*://')
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter Upload_Token_Repository_Test
```
Expected: PASS (`OK`), including the existing rate-limit tests and the new `test_mark_sent_sets_sent_at`.

- [ ] **Step 6: phpcs the source file**

```
./vendor/bin/phpcs --standard=WordPress --extensions=php src/includes/Repository/class-upload-token-repository.php
```
Expected: no ERRORS (warnings for the pre-existing custom capability are noise).

- [ ] **Step 7: Commit**

```bash
git add src/includes/Repository/class-upload-token-repository.php tests/phpunit/Repository/class-upload-token-repository-test.php
git commit -m "refactor: extract Upload_Token_Repository::mark_sent (#8)"
```

---

### Task 2: Characterization safety net for the orchestration methods

Pin the current behavior of the three methods being moved, BEFORE moving them. Tests target the current repository methods and observe email via WordPress's `wp_mail`/`pre_wp_mail` filters (no source changes needed). These tests are the safety net; Task 3 re-points them at the service with identical assertions.

**Files:**
- Test (add): `tests/phpunit/Repository/class-upload-token-repository-test.php` — single-send + enumeration-safe pins
- Test (create): `tests/phpunit/Repository/class-competitions-repository-reminders-test.php` — bulk reminder pins

**Interfaces:**
- Consumes (current, to be removed in Task 3): `Upload_Token_Repository::send_upload_link_for_member( int, int, string, bool )`, `Upload_Token_Repository::send_upload_link_by_email( int, string, string ): bool`, `Competitions_Repository::send_submission_reminder_emails( int )`.

- [ ] **Step 1: Add single-send + enumeration pins to the token-repo test**

Add these test methods to `tests/phpunit/Repository/class-upload-token-repository-test.php`. Add `use PhotoCompetitionManager\Repository\Competitions_Repository;` and `use PhotoCompetitionManager\Repository\Members_Repository;` to the file's imports if not present.

```php
	/**
	 * Create an open competition and return its id.
	 */
	private function make_open_competition(): int {
		$comps = new Competitions_Repository();
		return (int) $comps->create(
			array(
				'title'     => 'Reminder Comp',
				'slug'      => 'reminder-comp',
				'open_date' => '2020-01-01 00:00:00',
			)
		);
	}

	/**
	 * Create a member with an email and return its id.
	 */
	private function make_member( string $email = 'alice@example.com' ): int {
		$members = new Members_Repository();
		return (int) $members->create(
			array(
				'name'   => 'Alice',
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => 1,
			)
		);
	}

	public function test_send_upload_link_for_member_missing_competition() {
		$member_id = $this->make_member();
		$result    = $this->repo->send_upload_link_for_member( 9999, $member_id, 'https://example.com/upload/' );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_competition', $result->get_error_code() );
	}

	public function test_send_upload_link_for_member_missing_member() {
		$competition_id = $this->make_open_competition();
		$result         = $this->repo->send_upload_link_for_member( $competition_id, 9999, 'https://example.com/upload/' );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_member', $result->get_error_code() );
	}

	public function test_send_upload_link_for_member_missing_email() {
		global $wpdb;
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member();
		$members        = new Members_Repository();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $members->table(), array( 'email' => '' ), array( 'id' => $member_id ), array( '%s' ), array( '%d' ) );

		$result = $this->repo->send_upload_link_for_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_email', $result->get_error_code() );
	}

	public function test_send_upload_link_for_member_success_and_rate_limit() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member();

		$mail_count = 0;
		add_filter(
			'wp_mail',
			function ( $atts ) use ( &$mail_count ) {
				++$mail_count;
				return $atts;
			}
		);

		$first = $this->repo->send_upload_link_for_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertTrue( $first );
		$this->assertSame( 1, $mail_count );

		// Second call is rate-limited: returns true, no additional mail.
		$second = $this->repo->send_upload_link_for_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertTrue( $second );
		$this->assertSame( 1, $mail_count );
	}

	public function test_send_upload_link_for_member_send_failed() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member();

		add_filter( 'pre_wp_mail', '__return_false' );

		$result = $this->repo->send_upload_link_for_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertWPError( $result );
		$this->assertSame( 'send_failed', $result->get_error_code() );
	}

	public function test_send_upload_link_by_email_unknown_email_is_success() {
		$competition_id = $this->make_open_competition();
		$result         = $this->repo->send_upload_link_by_email( $competition_id, 'nobody@example.com', 'https://example.com/upload/' );
		$this->assertTrue( $result );
	}

	public function test_send_upload_link_by_email_send_failure_returns_false() {
		$competition_id = $this->make_open_competition();
		$this->make_member( 'bob@example.com' );
		add_filter( 'pre_wp_mail', '__return_false' );

		$result = $this->repo->send_upload_link_by_email( $competition_id, 'bob@example.com', 'https://example.com/upload/' );
		$this->assertFalse( $result );
	}

	public function test_send_upload_link_by_email_non_send_error_is_success() {
		// Known member but bad competition → for_member returns missing_competition;
		// wrapper hides it and returns true (privacy).
		$this->make_member( 'carol@example.com' );
		$result = $this->repo->send_upload_link_by_email( 9999, 'carol@example.com', 'https://example.com/upload/' );
		$this->assertTrue( $result );
	}
```

- [ ] **Step 2: Create the bulk-reminder pin test**

Create `tests/phpunit/Repository/class-competitions-repository-reminders-test.php`:

```php
<?php
/**
 * Characterization tests for Competitions_Repository::send_submission_reminder_emails.
 *
 * @package PhotoCompetitionManager\Tests
 */

namespace PhotoCompetitionManager\Tests\Repository;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use WP_UnitTestCase;

class Competitions_Repository_Reminders_Test extends WP_UnitTestCase {

	/**
	 * @var Competitions_Repository
	 */
	private $repo;

	/**
	 * @var Members_Repository
	 */
	private $members;

	public function setUp(): void {
		parent::setUp();
		$this->repo    = new Competitions_Repository();
		$this->members = new Members_Repository();
	}

	private function make_open_competition(): int {
		return (int) $this->repo->create(
			array(
				'title'     => 'Open Comp',
				'slug'      => 'open-comp',
				'open_date' => '2020-01-01 00:00:00',
			)
		);
	}

	private function make_closed_competition(): int {
		return (int) $this->repo->create(
			array(
				'title'      => 'Closed Comp',
				'slug'       => 'closed-comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-01-02 00:00:00',
			)
		);
	}

	private function make_member( string $name, string $email ): void {
		$this->members->create(
			array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => 1,
			)
		);
	}

	public function test_invalid_competition_id() {
		$result = $this->repo->send_submission_reminder_emails( 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
	}

	public function test_missing_competition() {
		$result = $this->repo->send_submission_reminder_emails( 9999 );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_competition', $result->get_error_code() );
	}

	public function test_competition_not_open() {
		$competition_id = $this->make_closed_competition();
		$result         = $this->repo->send_submission_reminder_emails( $competition_id );
		$this->assertWPError( $result );
		$this->assertSame( 'competition_not_open', $result->get_error_code() );
	}

	public function test_no_members() {
		$competition_id = $this->make_open_competition();
		$result         = $this->repo->send_submission_reminder_emails( $competition_id );
		$this->assertWPError( $result );
		$this->assertSame( 'no_members', $result->get_error_code() );
	}

	public function test_sends_then_skips_on_rate_limit() {
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Alice', 'alice@example.com' );
		$this->make_member( 'Bob', 'bob@example.com' );

		$first = $this->repo->send_submission_reminder_emails( $competition_id );
		$this->assertIsArray( $first );
		$this->assertTrue( $first['success'] );
		$this->assertSame( 2, $first['sent_count'] );
		$this->assertSame( 0, $first['skipped_count'] );
		$this->assertSame( 2, $first['total_count'] );

		// Second run: both are rate-limited.
		$second = $this->repo->send_submission_reminder_emails( $competition_id );
		$this->assertIsArray( $second );
		$this->assertSame( 0, $second['sent_count'] );
		$this->assertSame( 2, $second['skipped_count'] );
	}
}
```

- [ ] **Step 3: Run both pinned suites and confirm green**

```
PORT=$(docker port photo-competition-manager-tests-mysql-1 3306/tcp | head -1 | sed 's/.*://')
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter Upload_Token_Repository_Test
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter Competitions_Repository_Reminders_Test
```
Expected: both `OK`. These pins now describe current behavior. If any pin fails, the assumption about current behavior is wrong — STOP and reconcile with the actual code before proceeding.

- [ ] **Step 4: Commit**

```bash
git add tests/phpunit/Repository/class-upload-token-repository-test.php tests/phpunit/Repository/class-competitions-repository-reminders-test.php
git commit -m "test: characterize upload-link + reminder orchestration before move (#8)"
```

---

### Task 3: Create `Upload_Link_Service`, move the three methods, update callers, remove repo methods

This is the atomic move. Splitting it would leave an intermediate state where a repository calls a service (the reminder loop depends on the single-send). Create the service, re-point the callers, delete the repo orchestration methods, and relocate the Task 2 pins onto the service with identical assertions.

**Files:**
- Create: `src/includes/Service/class-upload-link-service.php`
- Modify: `src/includes/Repository/class-upload-token-repository.php` (remove `send_upload_link_for_member`, `send_upload_link_by_email`)
- Modify: `src/includes/Repository/class-competitions-repository.php` (remove `send_submission_reminder_emails`)
- Modify: `src/admin/class-members-controller.php` (~line 180)
- Modify: `src/admin/class-competitions-controller.php` (~line 331)
- Modify: `src/public/class-upload-shortcode.php` (constructor + ~line 291)
- Test (create): `tests/phpunit/Service/class-upload-link-service-test.php` (relocated pins)
- Test (modify): `tests/phpunit/Repository/class-upload-token-repository-test.php` (remove the moved single-send/enumeration pins)
- Test (delete): `tests/phpunit/Repository/class-competitions-repository-reminders-test.php` (relocated into the service test)

**Interfaces:**
- Produces:
  - `Upload_Link_Service::__construct( ?Upload_Token_Repository, ?Competitions_Repository, ?Members_Repository, ?Email_Service )`
  - `Upload_Link_Service::send_to_member( int $competition_id, int $member_id, string $upload_page_url, $force_send = false )` → `true|WP_Error` (codes `missing_competition`, `missing_member`, `missing_email`, `send_failed`; `true` on rate-limit skip)
  - `Upload_Link_Service::send_by_email( int $competition_id, string $member_email, string $upload_page_url ): bool`
  - `Upload_Link_Service::send_reminders( int $competition_id )` → `array{success,sent_count,skipped_count,failed_count,total_count,errors,message}|WP_Error` (codes `invalid_competition`, `missing_competition`, `competition_not_open`, `no_members`)
- Consumes: `Upload_Token_Repository::{find_or_create, has_recent_email_send, generate_upload_url, mark_sent}`, `Competitions_Repository::{find, is_open}`, `Members_Repository::{find, find_by_email, all}`, `Email_Service::send_upload_link`.

- [ ] **Step 1: Create the service class**

Create `src/includes/Service/class-upload-link-service.php`:

```php
<?php
/**
 * Send upload-link emails to members (magic links and bulk reminders).
 *
 * @package PhotoCompetitionManager\Service
 */

namespace PhotoCompetitionManager\Service;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly.

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Repository\Upload_Token_Repository;
use PhotoCompetitionManager\Support\Competition_Settings;
use WP_Error;

/**
 * Upload Link Service.
 *
 * @since 1.2.0
 */
class Upload_Link_Service {

	/**
	 * Upload token repository.
	 *
	 * @var Upload_Token_Repository
	 */
	private $token_repo;

	/**
	 * Competitions repository.
	 *
	 * @var Competitions_Repository
	 */
	private $competitions_repo;

	/**
	 * Members repository.
	 *
	 * @var Members_Repository
	 */
	private $members_repo;

	/**
	 * Email service.
	 *
	 * @var Email_Service
	 */
	private $email_service;

	/**
	 * Constructor.
	 *
	 * @param Upload_Token_Repository|null $token_repo        Token repository.
	 * @param Competitions_Repository|null $competitions_repo Competitions repository.
	 * @param Members_Repository|null      $members_repo      Members repository.
	 * @param Email_Service|null           $email_service     Email service.
	 */
	public function __construct(
		?Upload_Token_Repository $token_repo = null,
		?Competitions_Repository $competitions_repo = null,
		?Members_Repository $members_repo = null,
		?Email_Service $email_service = null
	) {
		$this->token_repo        = $token_repo ?? new Upload_Token_Repository();
		$this->competitions_repo = $competitions_repo ?? new Competitions_Repository();
		$this->members_repo      = $members_repo ?? new Members_Repository();
		$this->email_service     = $email_service ?? new Email_Service();
	}

	/**
	 * Create a fresh upload token and email a magic link to a member.
	 *
	 * Treats recent token as success (rate-limited) to avoid spamming members.
	 *
	 * @since 1.2.0
	 * @param int    $competition_id  Competition ID.
	 * @param int    $member_id       Member ID.
	 * @param string $upload_page_url Base URL of the upload page.
	 * @param bool   $force_send      Force sending even if a recent token exists.
	 * @return bool|WP_Error True on success, WP_Error on hard failure.
	 */
	public function send_to_member( int $competition_id, int $member_id, string $upload_page_url, $force_send = false ) {
		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new WP_Error( 'missing_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$member = $this->members_repo->find( $member_id );
		if ( ! $member ) {
			return new WP_Error( 'missing_member', __( 'Member not found.', 'photo-competition-manager' ) );
		}

		if ( empty( $member->email ) ) {
			return new WP_Error( 'missing_email', __( 'Member does not have an email address.', 'photo-competition-manager' ) );
		}

		// Rate-limit: if an email was sent recently, skip unless forced.
		if ( $this->token_repo->has_recent_email_send( $member_id, $competition_id ) && ! $force_send ) {
			return true;
		}

		$token_obj = $this->token_repo->find_or_create( $member_id, $competition_id );
		if ( is_wp_error( $token_obj ) ) {
			return $token_obj;
		}

		$upload_url = $this->token_repo->generate_upload_url( $competition_id, $member_id, $upload_page_url );
		if ( is_wp_error( $upload_url ) ) {
			return $upload_url;
		}

		$settings        = Competition_Settings::parse( $competition->settings );
		$voting_page_url = $settings['urls']['voting_page'] ?? null;

		$sent = $this->email_service->send_upload_link(
			$member->email,
			$member->name ?? $member->email,
			$competition->title,
			$upload_url,
			$competition_id,
			$voting_page_url
		);

		if ( ! $sent ) {
			return new WP_Error( 'send_failed', __( 'Failed to send email.', 'photo-competition-manager' ) );
		}

		$this->token_repo->mark_sent( (int) $token_obj->id );

		return true;
	}

	/**
	 * Email an upload link by member email without leaking existence (no enumeration).
	 *
	 * @since 1.2.0
	 * @param int    $competition_id  Competition ID.
	 * @param string $member_email    Member email (unsanitized).
	 * @param string $upload_page_url Base URL for the upload page.
	 * @return bool True if sent or intentionally suppressed; false only on hard send failure.
	 */
	public function send_by_email( int $competition_id, string $member_email, string $upload_page_url ): bool {
		$member_email = sanitize_email( $member_email );
		if ( empty( $member_email ) ) {
			return false;
		}

		$member = $this->members_repo->find_by_email( $member_email );

		// If member doesn't exist, pretend success to avoid enumeration.
		if ( ! $member ) {
			return true;
		}

		$result = $this->send_to_member( $competition_id, (int) $member->id, $upload_page_url );

		// Treat most errors as success to preserve privacy; only fail on hard send errors.
		if ( is_wp_error( $result ) ) {
			return 'send_failed' === $result->get_error_code() ? false : true;
		}

		return (bool) $result;
	}

	/**
	 * Send submission reminder emails to all members for a competition.
	 *
	 * @since 1.2.0
	 * @param int $competition_id Competition ID.
	 * @return array{success: bool, sent_count: int, skipped_count: int, failed_count: int, total_count: int, errors: array, message: string}|WP_Error
	 */
	public function send_reminders( $competition_id ) {
		if ( $competition_id <= 0 ) {
			return new WP_Error( 'invalid_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		$competition = $this->competitions_repo->find( $competition_id );
		if ( ! $competition ) {
			return new WP_Error( 'missing_competition', __( 'Competition not found.', 'photo-competition-manager' ) );
		}

		if ( ! $this->competitions_repo->is_open( $competition ) ) {
			return new WP_Error( 'competition_not_open', __( 'Competition must be open to send reminder emails.', 'photo-competition-manager' ) );
		}

		$members = $this->members_repo->all( 10000, false );
		if ( empty( $members ) ) {
			return new WP_Error( 'no_members', __( 'No active members found.', 'photo-competition-manager' ) );
		}

		// Determine upload page URL; fall back to home URL.
		$upload_page_url = Competition_Settings::find_page_url_with_shortcode( 'competition_upload' );
		if ( empty( $upload_page_url ) ) {
			$upload_page_url = home_url( '/' );
		}
		$upload_page_url = apply_filters( 'photo_competition_manager_upload_page_url', $upload_page_url, $competition );

		$sent_count    = 0;
		$skipped_count = 0;
		$failed_count  = 0;
		$total_count   = count( $members );
		$errors        = array();

		foreach ( $members as $member ) {
			if ( empty( $member->email ) ) {
				continue;
			}

			$has_recent = $this->token_repo->has_recent_email_send( (int) $member->id, (int) $competition_id );

			$result = $this->send_to_member( (int) $competition_id, (int) $member->id, $upload_page_url );

			if ( is_wp_error( $result ) ) {
				++$failed_count;
				$errors[] = sprintf(
					'%s: %s',
					$member->name ?? $member->email,
					$result->get_error_message()
				);
			} elseif ( true === $result ) {
				if ( $has_recent ) {
					++$skipped_count;
				} else {
					++$sent_count;
				}
			}
		}

		return array(
			'success'       => true,
			'sent_count'    => $sent_count,
			'skipped_count' => $skipped_count,
			'failed_count'  => $failed_count,
			'total_count'   => $total_count,
			'errors'        => $errors,
			'message'       => sprintf(
				/* translators: 1: Number of emails sent, 2: Total number of members */
				__( 'Sent %1$d of %2$d submission reminder emails.', 'photo-competition-manager' ),
				$sent_count,
				$total_count
			),
		);
	}
}
```

- [ ] **Step 2: Confirm the service file autoloads**

The plugin loads `src/includes/` modules. Verify the new file is picked up by the same mechanism as `class-upload-handler.php` (same directory, `class-` prefix). Confirm with:

```
grep -rn "class-upload-handler.php\|Service/class-\|glob\|require" src/photo-competition-manager.php src/includes/*.php | head
```
If services are explicitly required (not glob-autoloaded), add a matching `require`/registration line for `class-upload-link-service.php` next to the `Upload_Handler` one. If they are glob-loaded from the directory, no change is needed.

- [ ] **Step 3: Update the members controller caller**

In `src/admin/class-members-controller.php`, add the import after line 16:

```php
use PhotoCompetitionManager\Service\Upload_Link_Service;
```

Replace (around line 180):

```php
			$token_repo = new Upload_Token_Repository();
			$result     = $token_repo->send_upload_link_for_member(
				(int) $competition_id,
				(int) $member_id,
				$upload_page_url,
				true // Send email immediately.
			);
```

with:

```php
			$upload_link_service = new Upload_Link_Service();
			$result              = $upload_link_service->send_to_member(
				(int) $competition_id,
				(int) $member_id,
				$upload_page_url,
				true // Send email immediately.
			);
```

(Leave the `Upload_Token_Repository` import and its other use at ~line 1028 — `generate_upload_url` — untouched.)

- [ ] **Step 4: Update the competitions controller caller**

In `src/admin/class-competitions-controller.php`, add the import after line 15:

```php
use PhotoCompetitionManager\Service\Upload_Link_Service;
```

Replace (around line 331):

```php
				$result = $this->competitions->send_submission_reminder_emails( $competition_id );
```

with:

```php
				$upload_link_service = new Upload_Link_Service();
				$result              = $upload_link_service->send_reminders( $competition_id );
```

- [ ] **Step 5: Update the upload shortcode caller (add DI + swap call)**

In `src/public/class-upload-shortcode.php`, add the import after line 16:

```php
use PhotoCompetitionManager\Service\Upload_Link_Service;
```

Add a property after the `Email_Service` property block (near line 72+):

```php
	/**
	 * Upload link service.
	 *
	 * @var Upload_Link_Service
	 */
	private $upload_link_service;
```

Extend the constructor signature and body. Change the signature to add a final parameter:

```php
	public function __construct(
		?Upload_Handler $upload_handler = null,
		?Competitions_Repository $competitions_repo = null,
		?Members_Repository $members_repo = null,
		?Upload_Token_Repository $token_repo = null,
		?Email_Service $email_service = null,
		?Upload_Link_Service $upload_link_service = null
	) {
		$this->upload_handler    = $upload_handler ?? new Upload_Handler();
		$this->competitions_repo = $competitions_repo ?? new Competitions_Repository();
		$this->members_repo      = $members_repo ?? new Members_Repository();
		$this->token_repo        = $token_repo ?? new Upload_Token_Repository();
		$this->email_service     = $email_service ?? new Email_Service();
		$this->upload_link_service = $upload_link_service ?? new Upload_Link_Service();
	}
```

Replace (around line 291):

```php
		$ok = $this->token_repo->send_upload_link_by_email(
			(int) $competition->id,
			$member_email,
			get_permalink()
		);
```

with:

```php
		$ok = $this->upload_link_service->send_by_email(
			(int) $competition->id,
			$member_email,
			get_permalink()
		);
```

- [ ] **Step 6: Remove the moved methods from the repositories**

In `src/includes/Repository/class-upload-token-repository.php`, delete the entire `send_upload_link_for_member()` method (and its docblock) and the entire `send_upload_link_by_email()` method (and its docblock). Keep `find_or_create`, `has_recent_email_send`, `generate_upload_url`, `mark_sent`, `get_tracking_by_competition`.

In `src/includes/Repository/class-competitions-repository.php`, delete the entire `send_submission_reminder_emails()` method (and its docblock). Keep `is_open`, `find`, etc.

- [ ] **Step 7: Verify no remaining references to the removed methods**

```
grep -rn "send_upload_link_for_member\|send_upload_link_by_email\|send_submission_reminder_emails" src/
```
Expected: no output. If anything remains, update it to the service equivalent.

- [ ] **Step 8: Relocate the pins onto the service**

Create `tests/phpunit/Service/class-upload-link-service-test.php` by moving the pinned assertions from Task 2, changing only the subject under test from the repositories to a `new Upload_Link_Service()`. Assertions are identical to Task 2 — this is what proves behavior is preserved.

```php
<?php
/**
 * Tests for Upload_Link_Service.
 *
 * @package PhotoCompetitionManager\Tests\Service
 */

namespace PhotoCompetitionManager\Tests\Service;

use PhotoCompetitionManager\Repository\Competitions_Repository;
use PhotoCompetitionManager\Repository\Members_Repository;
use PhotoCompetitionManager\Service\Upload_Link_Service;
use WP_UnitTestCase;

class Upload_Link_Service_Test extends WP_UnitTestCase {

	/**
	 * @var Upload_Link_Service
	 */
	private $service;

	/**
	 * @var Competitions_Repository
	 */
	private $comps;

	/**
	 * @var Members_Repository
	 */
	private $members;

	public function setUp(): void {
		parent::setUp();
		$this->service = new Upload_Link_Service();
		$this->comps   = new Competitions_Repository();
		$this->members = new Members_Repository();
	}

	private function make_open_competition(): int {
		return (int) $this->comps->create(
			array(
				'title'     => 'Open Comp',
				'slug'      => 'open-comp',
				'open_date' => '2020-01-01 00:00:00',
			)
		);
	}

	private function make_closed_competition(): int {
		return (int) $this->comps->create(
			array(
				'title'      => 'Closed Comp',
				'slug'       => 'closed-comp',
				'open_date'  => '2020-01-01 00:00:00',
				'close_date' => '2020-01-02 00:00:00',
			)
		);
	}

	private function make_member( string $name, string $email ): int {
		return (int) $this->members->create(
			array(
				'name'   => $name,
				'email'  => $email,
				'grade'  => 'beginner',
				'active' => 1,
			)
		);
	}

	// --- send_to_member ---

	public function test_send_to_member_missing_competition() {
		$member_id = $this->make_member( 'Alice', 'alice@example.com' );
		$result    = $this->service->send_to_member( 9999, $member_id, 'https://example.com/upload/' );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_competition', $result->get_error_code() );
	}

	public function test_send_to_member_missing_member() {
		$competition_id = $this->make_open_competition();
		$result         = $this->service->send_to_member( $competition_id, 9999, 'https://example.com/upload/' );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_member', $result->get_error_code() );
	}

	public function test_send_to_member_missing_email() {
		global $wpdb;
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->members->table(), array( 'email' => '' ), array( 'id' => $member_id ), array( '%s' ), array( '%d' ) );

		$result = $this->service->send_to_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_email', $result->get_error_code() );
	}

	public function test_send_to_member_success_and_rate_limit() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );

		$mail_count = 0;
		add_filter(
			'wp_mail',
			function ( $atts ) use ( &$mail_count ) {
				++$mail_count;
				return $atts;
			}
		);

		$first = $this->service->send_to_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertTrue( $first );
		$this->assertSame( 1, $mail_count );

		$second = $this->service->send_to_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertTrue( $second );
		$this->assertSame( 1, $mail_count );
	}

	public function test_send_to_member_send_failed() {
		$competition_id = $this->make_open_competition();
		$member_id      = $this->make_member( 'Alice', 'alice@example.com' );
		add_filter( 'pre_wp_mail', '__return_false' );

		$result = $this->service->send_to_member( $competition_id, $member_id, 'https://example.com/upload/' );
		$this->assertWPError( $result );
		$this->assertSame( 'send_failed', $result->get_error_code() );
	}

	// --- send_by_email ---

	public function test_send_by_email_unknown_email_is_success() {
		$competition_id = $this->make_open_competition();
		$result         = $this->service->send_by_email( $competition_id, 'nobody@example.com', 'https://example.com/upload/' );
		$this->assertTrue( $result );
	}

	public function test_send_by_email_send_failure_returns_false() {
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Bob', 'bob@example.com' );
		add_filter( 'pre_wp_mail', '__return_false' );

		$result = $this->service->send_by_email( $competition_id, 'bob@example.com', 'https://example.com/upload/' );
		$this->assertFalse( $result );
	}

	public function test_send_by_email_non_send_error_is_success() {
		$this->make_member( 'Carol', 'carol@example.com' );
		$result = $this->service->send_by_email( 9999, 'carol@example.com', 'https://example.com/upload/' );
		$this->assertTrue( $result );
	}

	// --- send_reminders ---

	public function test_reminders_invalid_competition_id() {
		$result = $this->service->send_reminders( 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_competition', $result->get_error_code() );
	}

	public function test_reminders_missing_competition() {
		$result = $this->service->send_reminders( 9999 );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_competition', $result->get_error_code() );
	}

	public function test_reminders_competition_not_open() {
		$competition_id = $this->make_closed_competition();
		$result         = $this->service->send_reminders( $competition_id );
		$this->assertWPError( $result );
		$this->assertSame( 'competition_not_open', $result->get_error_code() );
	}

	public function test_reminders_no_members() {
		$competition_id = $this->make_open_competition();
		$result         = $this->service->send_reminders( $competition_id );
		$this->assertWPError( $result );
		$this->assertSame( 'no_members', $result->get_error_code() );
	}

	public function test_reminders_sends_then_skips_on_rate_limit() {
		$competition_id = $this->make_open_competition();
		$this->make_member( 'Alice', 'alice@example.com' );
		$this->make_member( 'Bob', 'bob@example.com' );

		$first = $this->service->send_reminders( $competition_id );
		$this->assertIsArray( $first );
		$this->assertTrue( $first['success'] );
		$this->assertSame( 2, $first['sent_count'] );
		$this->assertSame( 0, $first['skipped_count'] );
		$this->assertSame( 2, $first['total_count'] );

		$second = $this->service->send_reminders( $competition_id );
		$this->assertIsArray( $second );
		$this->assertSame( 0, $second['sent_count'] );
		$this->assertSame( 2, $second['skipped_count'] );
	}
}
```

- [ ] **Step 9: Remove the relocated pins from the repository tests**

From `tests/phpunit/Repository/class-upload-token-repository-test.php`, delete the eight `test_send_upload_link_for_member_*` / `test_send_upload_link_by_email_*` methods added in Task 2 and their `make_open_competition` / `make_member` helpers (now redundant). Keep `test_mark_sent_sets_sent_at` and all pre-existing tests. Remove the `Competitions_Repository` / `Members_Repository` imports if no longer used in that file.

Delete `tests/phpunit/Repository/class-competitions-repository-reminders-test.php` entirely (relocated into the service test):

```bash
git rm tests/phpunit/Repository/class-competitions-repository-reminders-test.php
```

- [ ] **Step 10: Run the affected suites and confirm green**

```
PORT=$(docker port photo-competition-manager-tests-mysql-1 3306/tcp | head -1 | sed 's/.*://')
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter Upload_Link_Service_Test
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit --filter Upload_Token_Repository_Test
```
Expected: both `OK`. The service pins pass with assertions identical to the (now-removed) repo pins — behavior preserved.

- [ ] **Step 11: phpcs the changed source files**

```
./vendor/bin/phpcs --standard=WordPress --extensions=php \
  src/includes/Service/class-upload-link-service.php \
  src/includes/Repository/class-upload-token-repository.php \
  src/includes/Repository/class-competitions-repository.php \
  src/admin/class-members-controller.php \
  src/admin/class-competitions-controller.php \
  src/public/class-upload-shortcode.php
```
Expected: no ERRORS. Fix any that appear (the `manage_photo_competitions` capability warning is pre-existing noise).

- [ ] **Step 12: Commit**

```bash
git add src/ tests/
git commit -m "refactor: move upload-link email orchestration into Upload_Link_Service (#8)"
```

---

### Task 4: Full-suite verification, manual flow check, and PR

**Files:** none (verification + PR).

- [ ] **Step 1: Run the full PHPUnit suite**

```
PORT=$(docker port photo-competition-manager-tests-mysql-1 3306/tcp | head -1 | sed 's/.*://')
WP_ENV_TEST_DB_HOST="127.0.0.1:$PORT" vendor/bin/phpunit
```
Expected: `OK` (ignore the pre-existing `Duplicate key name 'member_competition_category'` dbDelta noise). Confirm the final summary line shows no failures or errors.

- [ ] **Step 2: Manual wp-env verification of both flows**

With `npx @wordpress/env start` running:
1. Magic-link (admin): from the Members admin screen, trigger "send upload email" for a member in an open competition; confirm the success notice and that a token row's `sent_at` is set.
2. Magic-link (public): submit the `[competition_upload]` email form with a known member email and confirm the generic success message; with an unknown email confirm the same generic message (no enumeration).
3. Reminders (admin): from the competition dashboard, trigger "send emails"; confirm the "Sent X of Y" notice, then trigger again and confirm the counts shift to skipped (rate-limited).

Use the `logs` skill / debug log if a send appears to fail. Record the observed results in the PR body.

- [ ] **Step 3: Push and open the PR**

```bash
git push -u origin refactor/8-upload-link-service
gh pr create --base main --title "refactor: move email orchestration into Upload_Link_Service" \
  --body "Moves upload-link magic-link + bulk reminder orchestration out of the repositories into a new Upload_Link_Service, leaving repositories as pure persistence. Adds characterization coverage for the moved behavior (previously untested). Manual verification of magic-link and reminder flows recorded below.

Closes #8"
```

Do NOT merge — the user merges PRs. Offer `gh pr merge` only if asked.

---

## Self-Review

**Spec coverage:**
- "Upload magic-link send + rate-limit logic lives in a service" → Task 3 (`send_to_member`, `send_by_email`).
- "Bulk submission-reminder orchestration lives in a service" → Task 3 (`send_reminders`).
- "Repositories no longer instantiate services or send email" → Task 3 Step 6 (methods removed); Step 7 greps to confirm no references.
- "Magic-link and reminder flows verified unchanged" → Task 4 Step 2 (manual) + service pins (Tasks 2→3).
- "Characterization/regression tests green" → the AC-premise gap is closed by Task 2 (pin before move) and Task 3 (re-point, same assertions); Task 4 Step 1 runs the full suite.
- Design's `mark_sent` extraction → Task 1.

**Placeholder scan:** No TBD/TODO; every code step shows full code; every test step shows assertions and expected pass/fail. Task 3 Step 2 is a conditional (autoload check) with an explicit grep and the fix to apply if needed — not a placeholder.

**Type consistency:** Method names (`send_to_member`, `send_by_email`, `send_reminders`, `mark_sent`), the constructor DI order, and error codes match between the Interfaces blocks, the service body, the callers, and the tests. Return shapes match the pins in Task 2 and Task 3.
