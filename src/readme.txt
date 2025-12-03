=== Photo Competition Manager ===
Contributors: donncha
Tags: competitions, photography, voting, shortcodes, member management
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete photography club competition platform. Handle submissions, member voting, public voting, email notifications, and beautiful results displays.

== Description ==

Photo Competition Manager provides everything photography clubs need to run professional competitions online:

**Core Features**

* **Member Management** – Maintain active rosters, assign grades, track member status, and bulk import/update via CSV
* **Competition Setup** – Create recurring competitions with custom categories, grade divisions, submission quotas, and scoring matrices
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

= Can I customize categories and grades per competition? =

Absolutely. Set default categories, grades, quotas, and scoring matrices in **Competitions → Settings**. Each competition can override these defaults via its Settings tab without affecting future competitions.

= What if someone loses their magic link or voting token? =

**For uploads:** Regenerate the member's upload link from **Competitions → Members** → Edit Member → "Send Upload Link"

**For voting:** From **Competitions → Voting Controls**, close and reopen the voting category to generate fresh token links for all members.

= Can I hide member names in the results? =

Yes. When configuring a competition's settings, enable "Hide member names in public results" to show only image numbers and scores. Perfect for anonymous judging or when members prefer privacy.

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

= 0.1.0 =
First public release. After activation, visit **Competitions → Setup Wizard** to create pages and **Competitions → Settings** to configure defaults before launching your first competition.
