=== Photo Competition Manager ===
Contributors: donncha
Tags: competitions, photography, voting, shortcodes, member management
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete photography club competition platform. Handle submissions, member voting, public voting, email notifications, and beautiful results displays.

== Description ==

Photo Competition Manager provides everything photography clubs need to run professional competitions online:

**Core Features**

* **Member Management** – Maintain active rosters, assign grades, track member status, and bulk import/update via CSV
* **Competition Setup** – Create competitions with custom categories, submission quotas, and scoring matrices, with results grouped by the club's grades
* **Secure Submissions** – Members upload via magic-link authentication with automatic file validation, resizing, and quota enforcement
* **Flexible Voting** – Token-based member voting, password-protected public voting, and full-screen slideshow mode for in-person club nights
* **Results Display** – Full results tables with filtering, responsive top-3 podium displays, and customizable member name visibility
* **Email Notifications** – Automated emails for upload confirmations, voting invitations, results announcements, and custom templates with merge tags
* **Setup Wizard** – One-click page creation for upload, voting, results, and top-3 displays with pre-configured shortcodes

**Advanced Capabilities**

* **Voting Controls** – Open/close voting by category, manage voter tokens, track submission and voting status per competition
* **Results Analytics** – View score distributions, voting participation, and competition statistics from the admin dashboard
* **Export Tools** – Export competition results, voting data, and member lists to CSV for archiving or external reporting
* **Repository Pattern** – All data stored in dedicated database tables for performance, portability, and clean separation from WordPress content

**Five Shortcodes, Unlimited Possibilities**

* `[competition_upload]` – Member upload form with quota tracking
* `[competition_voting]` – Interactive voting interface with live validation
* `[competition_slideshow]` – Full-screen presentation mode for club meetings
* `[competition_results]` – Complete results table with grade and category filtering
* `[competition_top3]` – Responsive podium display showcasing winners

Perfect for photography clubs, camera clubs, photo societies, and any organization running regular image competitions.

== Installation ==

**Quick Start**

1. Install via the Plugins screen on your WordPress site.
2. Activate the plugin through **Plugins → Installed Plugins**
3. Navigate to **Competitions → Setup Wizard** to auto-create pages with shortcodes
4. Go to **Competitions → Settings** to configure default categories, grades, and scoring
5. Add your members via **Competitions → Members** (supports bulk CSV import)
6. Create your first competition and start accepting submissions!

**Manual Page Setup**

If you prefer manual control, create pages with these shortcodes:

* `[competition_upload]` – Member submission form
* `[competition_voting]` – Voting interface (token-based or password-protected)
* `[competition_slideshow]` – Full-screen slideshow for in-person voting
* `[competition_results]` – Complete results table
* `[competition_top3]` – Podium-style top 3 display

**Shortcode Attributes**

* `competition="slug"` – Target a specific competition (all shortcodes)
* `category="slug"` – Filter by category (voting and slideshow only)

**Building From Source**

This plugin includes compiled JavaScript and CSS assets. The complete source code is available on GitHub:

https://github.com/donnchawp/photo-competition-manager

Source files are located in the `assets/src/` directory. To build the assets:

1. Navigate to the assets directory: `cd assets`
2. Install dependencies: `npm install`
3. For development (watch mode): `npm run dev`
4. For production build: `npm run build`

The plugin uses `@wordpress/scripts` for building and bundling assets. All source code can be reviewed, modified, and rebuilt from the repository.

**Local Development**

Run `make up` to launch the WordPress environment with Mailpit email capture at <http://localhost:8026>.

== Frequently Asked Questions ==

= How do members submit their photos? =

Members receive a magic-link email with a unique upload URL. No passwords or login required. They simply click the link, select their category, and upload their images. The system automatically validates file types, dimensions, and enforces submission quotas.

= Can I customize the voting system? =

Yes! Choose between three voting modes:
1. **Token-based** – Each member gets a unique voting link via email
2. **Password-protected** – Share a single password with all voters
3. **Slideshow mode** – Full-screen presentation for in-person voting at club meetings

= How do email notifications work? =

The plugin sends automated emails for key events:
* Upload confirmations when members submit images
* Voting invitations with unique links or passwords
* Results announcements when competition closes
* Custom templates with merge tags (member names, competition titles, links, etc.)

Configure and customize all templates from **Competitions → Email Templates**.

Emails going to many members (upload links, voting opened, results, and results links) are sent 5 at a time by the admin page you sent them from, so a big send can't time out a single request. The page shows progress while they go out, so keep it open until it says they've been sent. If you leave early, a notice on every admin page says where it stopped, with links to carry on without emailing anyone twice or to discard the rest. To change the batch size, define `CLUB_COMPETE_EMAIL_BATCH_SIZE` in `wp-config.php`.

= Can I customize categories and grades per competition? =

Categories, yes. Set default categories, quotas, and scoring matrices in **Competitions → Settings**, and each competition can override them on its Settings tab without affecting future competitions.

Grades are set once for the club in **Competitions → Settings**, and every competition uses them. Renaming a grade renames it in every competition's results, past ones included.

= What if someone loses their magic link or voting token? =

**For uploads:** Regenerate the member's upload link from **Competitions → Members** → Edit Member → "Send Upload Link"

**For voting:** From **Competitions → Voting Controls**, close and reopen the voting category to generate fresh token links for all members.

= Can I hide member names in the results? =

Yes. The "competition_results" shortcode has an optional "hide_names" parameter. Set that to 1 to hide the names.

= Does it work on mobile devices? =

Yes! All shortcodes are fully responsive and tested on mobile devices. The top-3 podium display, voting interface, and upload forms adapt beautifully to small screens.

= Where do uploaded images get stored? =

Images are stored in `/wp-content/uploads/competitions/{competition-slug}/{category-slug}/`. The plugin automatically creates thumbnails and validates uploads. All paths are secure and inaccessible without proper authentication.

= Can I export competition data? =

Yes. Visit **Competitions → Export** to download:
* Full competition results (CSV)
* Voting data and statistics (CSV)
* Member lists (CSV)
* All exports include timestamps and are formatted for Excel/Google Sheets

== Screenshots ==

1. Competition dashboard with status tracking, settings tabs, and quick actions
2. Member management interface with bulk CSV import and grade assignments
3. Voting controls for opening/closing categories and managing tokens
4. Setup wizard for one-click page creation with pre-configured shortcodes
5. Email template editor with merge tags and preview functionality
6. Responsive top-3 podium display showcasing winners with scores
7. Full results table with category filtering and member information
8. Mobile-optimized voting interface with image gallery
9. Submission tracking showing upload status and quota enforcement
10. Export screen for downloading competition data and statistics

== Changelog ==

= 0.4.0 (unreleased) =
* **Before you upgrade**
  * Back up the database first. The update runs eight database upgrades (versions 1 to 8) on the first request after it's installed.
  * Rolling back to 0.3.0 after upgrading loses the competition workflow state: whether uploads are closed or results published, and which voting stage each category has reached. 0.4.0 keeps it in its own database column, which 0.3.0 doesn't read.
  * On a site where members vote with emailed links, a member who asked for a link more than once has several. The upgrade keeps one per category, the one holding their ballot if they voted, and deletes the others along with any second ballot cast with them. It logs how many votes each competition lost.
  * On sites installed before November 2025 the upgrade rebuilds the upload links' database index so a member can have only one upload link per competition. A member who somehow has two keeps the earlier one, and the later emailed link stops working.
  * The upgrade records the results of every competition whose results are published, or that has closed or been archived, from the entries and votes as they are when it runs. Entries already deleted, and members deleted before the upgrade, stay missing from those results.

* **Competitions**
  * Only one competition can be open at a time, with a new Close Competition action
  * Competitions close at their close date, and overlapping dates are refused
  * Setting an open competition's close date to today in the edit form closes it when you save, and saving the form without changing the day keeps the time it closed
  * Competitions use the club's grade list, and the club's categories when they have none of their own
  * Voting Controls, Results and Submissions act on the current competition

* **Uploads and entries**
  * The upload page says why an upload or delete was refused, instead of "Upload failed. Please try again."
  * A file over the server's upload limit is reported as too big, on the upload form and in batch upload
  * When the server's upload limit is lower than the competition's, the upload page shows the server's and refuses larger files with that figure
  * The upload limit takes the server's `post_max_size` into account too, and an upload over it is reported as too big instead of "Category assignments are required." or no message at all
  * Drag-and-drop upload sends one image at a time, so a large selection no longer fails as a whole
  * Drag-and-drop upload says an image is too big when the web server in front of WordPress refuses it, and stops after the first image if the upload link is refused
  * When some images in a drag-and-drop batch fail, the page no longer reloads and hides the failures. The list stays on screen with a "Show my entries" button, and the images that went in leave the selection
  * Moving entries between categories happens all at once or not at all
  * A category takes no new entries once voting has started in it, or once it has votes
  * Uploads can't reopen once any category's voting has started. Reset that category first
  * An image is never saved or moved onto another entry's file
  * Deleting a competition or a member deletes their entry files too
  * Originals are discarded one at a time and exported at full size
  * An entry whose file is missing shows "Image unavailable", and the slideshow skips it
  * Deleting a submission works in in-app browsers
  * The upload page's messages can be translated
  * Drag-and-drop upload's messages can be translated too, and say "1 image" or "2 images" instead of "image(s)"
  * The voting page's vote counter and missing-votes messages, and the upload page's category-change messages, can be translated too, and say "1 image" or "2 images" instead of "image(s)"
  * Drag-and-drop upload no longer says the quotas are full when images are added in a second go and there is room for them
  * A drag-and-drop batch's summary is green only when every image went in. A batch where nothing went in says "No images were uploaded." as an error, a mixed batch shows as a notice, and screen readers announce the outcome and any upload error
  * Success and error messages on the upload, voting and results pages use darker text, so they're easier to read

* **Voting**
  * A ballot counts only the category's images, and each voter gets one ballot
  * A ballot is stored whole or not at all: if it can't be saved, the voter is asked to try again instead of being thanked with votes missing
  * The voting link a member asks for has its own Voting Link email template, with every merge tag filled in
  * The "Check If Voting Is Open" button keeps the voting token
  * The page redirects after a ballot is cast, so reloading it doesn't send the ballot again, and a second ballot shows one notice instead of two
  * The voter's name and the voting password are remembered on classic themes too
  * An unanswered score is no longer counted as 0, and a score that isn't in the list is refused with a message
  * A ballot sent with an expired voting link says so, instead of being ignored
  * Asking for a voting link again sends a link that works. It replaces the earlier one, and a member who has already voted is told so

* **Results**
  * Tied entries share a position in the results email
  * The results CSV is ranked within each grade and category
  * The votes CSV gives each voter who voted with a link their own row, labelled by their link ("Token #5"). Before, they all shared one blank row and some of their votes were lost
  * The results and Top 3 pages show the latest published results, without vote counts
  * The results table stacks into cards on mobile
  * The detailed results email uses the email template system
  * Results are recorded when they're published, or the first time they're needed after the competition closes. Deleting a member, removing an entry or changing a member's grade afterwards moves nobody, and a deleted member's entries show as "Former member" in their place, without an image
  * Results can't be hidden once the competition has closed. To correct them, move the close date into the future, then hide and publish them again
  * Results hidden when the competition closes or is archived are recorded afresh the next time they're needed, so votes cast and fixes made while they were hidden count
  * Email Results and the results link to all members wait until results are shown or the competition has closed, so everyone is told the recorded positions. The committee's link can still go first
  * The Recalculate Scores button is gone: nothing used the score it saved

* **Members and email**
  * Upload and voting links are no longer sent to deactivated members, whose email addresses are now marked
  * Bulk member emails go out in batches, and stopped email jobs show on every admin page
  * Editors can use the Email Templates page
  * Every email sends the text the Email Templates page shows, even on a site that has never saved it
  * The Email Templates page stores only the templates you edit, so the rest pick up improved default text in later versions. Edited templates are marked "Edited", and Restore default fills in the default text for you to read and save. Templates saved before 0.4.0 are kept as they were: use Restore default on each one to pick up the new default text
  * Only the Voting Opened and Submission Confirmed notifications can be switched off. Upload links, voting links and results emails always send
  * Names and links are escaped in emails, and the detailed results table is no longer reformatted
  * A site name with an ampersand or quotes appears as typed in email subjects, not as `&amp;`
  * The submission confirmation counts the image just uploaded
  * Each sent email is logged under its kind of email and against its competition
  * Saving the club settings no longer reassigns member grades, and every way of saving a member requires a club grade
  * Every email, the Members and Submissions screens and the upload reminder link the voting, upload and results pages by one rule: the competition's page, then the club's, then a published page holding the plugin's shortcode. Upload links are no longer built on the home page or a guessed address when no upload page is found; sending one says why instead
  * Opening voting with the Voting Opened notification on but no voting page still opens voting, and tells the admin that members weren't emailed
  * The Voting Opened email links the competition's own voting page ahead of the one in Settings. Competitions keep a copy of the page links from when they were created, so to change an existing competition's link, edit the competition
  * Deleting a member also deletes their upload and voting links and every log entry about them. The votes they cast still count, without their name. Tools > Erase Personal Data deletes a member the same way, found by their email address
  * Tools > Export Personal Data includes what the plugin holds about a member: their record, entries, recorded results, the votes they cast and the emails sent to them. Settings > Privacy > Policy Guide suggests text saying what the club holds and why

* **Errors and logging**
  * Category change refusals return 400, 403 or 404 instead of 500
  * Database errors and server paths are no longer shown to members; category change failures are logged as `category_change_failed`
  * Originals that couldn't be deleted are logged as `original_not_deleted`
  * Clubs can choose how long logs are kept, in a new Logs section of Settings, and older log entries are deleted once a day. By default logs are kept forever, as before, so upgrading deletes nothing. The Logs screen says how long they're kept
  * Uninstalling removes email jobs, transients and cron events

* **Compatibility**
  * Requires WordPress 6.5
  * Tested up to WordPress 7.1

= 0.3.0 =
* Fix fatal error on activation due to missing Admin_Dependencies class in release package
* **Results Sharing** — Share competition results via a secret link before making them public
  * New "Generate Results Link" action on the Competitions page
  * "Send to Committee" and "Send to All Members" buttons on the Results Dashboard
  * Share link bypasses results visibility and resolves to the correct competition
* **Committee Members** — Mark members as committee via admin or CSV import
* Confirmation dialogs on hash regeneration and email sending to prevent accidental actions

= 0.2.0 =
* **Voting Controls Redesign**
  * Streamlined voting controls page with improved layout
  * Added focus panel for managing individual categories
  * Clear "Voting is Open/Closed" status headings
  * Extended slideshow duration options (5s to 30s) with 20s default

* **Member Management**
  * New toggle button on Members page to enable/disable uploads

* **Results & Scoring**
  * Proper tie handling in admin results and scoring calculations
  * Scores now stored as totals per category for accuracy
  * Grades displayed in results emails and thumbnails

* **Exports**
  * Improved vote and uploader exports with category separation
  * Aligned export columns across categories for better spreadsheet compatibility

* **Email**
  * Site name now prefixed to all email subjects for clarity

* **Bug Fixes**
  * Delete associated votes when deleting an image
  * Normalized all times to UTC
  * Proper cleanup of physical files and attachment posts on deletion

* **Compatibility**
  * Tested up to WordPress 6.9

= 0.1.0 =
* **Core Features**
  * Member management with CSV bulk import/export
  * Competition creation with custom categories, grades, and scoring
  * Magic-link authentication for secure member uploads
  * Three voting modes: token-based, password-protected, and slideshow
  * Full results tables and responsive top-3 podium displays

* **Admin Interface**
  * Setup wizard for automatic page creation
  * Voting controls dashboard with category management
  * Submission tracking and quota enforcement
  * Results analytics and statistics
  * CSV export for all competition data

* **Email System**
  * Automated upload confirmations
  * Voting invitation emails with tokens
  * Results announcement notifications
  * Customizable templates with merge tags
  * Template enable/disable controls

* **Frontend Shortcodes**
  * `[competition_upload]` – Member submission form
  * `[competition_voting]` – Interactive voting interface
  * `[competition_slideshow]` – Full-screen presentation mode
  * `[competition_results]` – Complete results table
  * `[competition_top3]` – Responsive podium display

* **Technical**
  * Repository pattern with dedicated database tables
  * Automatic image resizing and thumbnail generation
  * Mobile-responsive frontend styles
  * WordPress coding standards compliance
  * PHPUnit test coverage for core functionality

== Upgrade Notice ==

= 0.4.0 =
Back up your database first. This update upgrades the database on the first request, and rolling back to 0.3.0 afterwards loses the competition workflow state.

= 0.3.0 =
Fixes a fatal error on plugin activation. Share competition results with committee members or all members via a secret link before making results public.

= 0.2.0 =
Redesigned voting controls, improved tie handling in scoring, better export formatting, and various bug fixes. All times are now normalized to UTC.

= 0.1.0 =
First public release. After activation, visit **Competitions → Setup Wizard** to create pages and **Competitions → Settings** to configure defaults before launching your first competition.
