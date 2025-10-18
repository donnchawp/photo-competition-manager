=== Club Competitions ===
Contributors: donncha
Tags: competitions, photography, voting, shortcodes, member management
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run photography club competitions without spreadsheets. Collect submissions, manage members, and publish results directly from WordPress.

== Description ==

Club Competitions gives camera clubs a complete workflow for running recurring competitions:

* **Member management** – maintain the active roster, grade members, and import or update records in bulk.
* **Submission handling** – accept uploads per category with automatic quota checks, secure magic-link authentication, and server-side validation.
* **Voting experiences** – switch between token-based or password-protected public voting using drop-in shortcodes.
* **Results publishing** – display full tables, hide member names when needed, and surface top-three highlights for newsletters or club sites.
* **Repository-backed data** – competitions, votes, and members live in dedicated tables to keep content organised and portable.

The plugin bundles frontend shortcodes for uploads, voting, full results, and top-three highlights. Admin pages cover competitions, members, submissions, and default settings so committees can adjust quotas, grades, or scoring without touching code.

== Installation ==

1. Upload `club-competitions/` to your WordPress `wp-content/plugins/` directory or install via the Plugins screen.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Navigate to **Club Competitions** in the admin menu to create your first competition, configure categories/grades, and add members.
4. Drop the provided shortcodes on public pages:
   * `[competition_upload]` – member uploads
   * `[competition_voting]` – audience or member voting
   * `[competition_results]` – full competition tables
   * `[competition_top3]` – concise winners block

For local development, run `make up` to boot the disposable WordPress environment and start the bundled Mailpit container for email previews.

== Frequently Asked Questions ==

= Where do magic-link emails go in development? =

When working locally, `make up` (or `make mailpit-start`) launches Mailpit on <http://localhost:8025> with SMTP exposed at `localhost:1025`. All plugin emails are routed there automatically.

= Can I customise categories and grades per competition? =

Yes. Use **Club Competitions → Settings** to define default categories, grade labels, quotas, and score matrices. Each competition can override those defaults on its Settings tab without affecting future events.

= How do I reset a voter token if someone loses their link? =

From **Club Competitions → Voting Controls**, close the active category for that competition. Reopen voting to generate new token links for members in that category.

== Screenshots ==

1. Competition dashboard showing upcoming events, archive filters, and quick actions.
2. Member management table with grade, status, and edit options.
3. Voting controls page to open/close categories globally.
4. Frontend results shortcode highlighting winners with thumbnails and scores.

== Changelog ==

= 0.1.0 =
* Initial release with member management, competition dashboards, secure upload shortcode, voting workflows, and public results displays.

== Upgrade Notice ==

= 0.1.0 =
First public release. Review the default settings before launching live competitions and confirm shortcodes render on your theme.
