# 📸 PRD: WordPress Plugin for Photography Club Competitions

**Product Name (Working Title):**
**PhotographyClubCompetitions** – A WordPress plugin for managing photography club competitions, submissions, and voting.

**Version:** 1.0 (Initial Release)
**Platform:** Self-hosted WordPress (WordPress.org)
**Primary Audience:** Photography club administrators and members
**Prepared by:** [Your Name]
**Date:** [Insert date]

---

## 1. Product Summary

**Goal:**
To provide photography clubs with an easy-to-use system for running periodic photo competitions, managing image submissions by members, facilitating fair voting, and generating results automatically.

**Objectives:**
- Simplify image submission and validation for members.
- Automate competition setup, reminders, and result collation.
- Provide a fair, anonymized voting experience for members.
- Display results cleanly, both in the meeting and online.

---

## 2. Key Features Overview

| Feature | Description |
|----------|-------------|
| **Competition Management** | Create and manage competitions (e.g., “October 2024”). Define open/close dates, categories, and grade groupings. |
| **Member Management** | Maintain a separate list of members (not WordPress users). Admins can add/edit members and upload on their behalf. |
| **Image Upload** | Members can upload a set number of images in defined categories (e.g. Colour, Black & White). Images validated, resized, renamed automatically. |
| **Voting System** | Voting opens manually or at a defined time. Members can vote via a mobile-friendly page or QR code link. |
| **Slideshow Display** | Admin projects images in a timed slideshow view for group voting. |
| **Email Reminders** | Automated reminders and notifications (1 week before, and configurable follow-ups). |
| **Results & Archiving** | Automatically sort and display results by grade. Provide CSV export and HTML results page with top 3 thumbnails per grade. |
| **Security Options** | Public voting link by default; optional per-user or per-competition unique links and upload/voting passwords for tighter access control. |

---

## 3. User Roles & Permissions

| Role | Permissions |
|------|--------------|
| **Admin** | Create/edit competitions, manage members, upload images, configure categories and grades, open/close voting, view/export results, manage email settings. |
| **Member** | Upload images when competition is open, view competition details, vote when voting opens. |
| **Public Viewer** | View published results after competition closes. |

---

## 4. Functional Requirements

### 4.1 Competition Setup
- Admin can create competitions with:
  - Name (e.g., *2024-10*)
  - Categories (e.g., Colour, Black & White)
  - Grades (e.g., Beginner, Intermediate, Advanced)
  - Upload start/end dates
  - Voting start/end dates (optional; manual override allowed)
- Only one competition can be active at a time.
- Past competitions automatically archived.

### 4.2 Member & Image Upload
- Members are managed internally (custom table).
  - Fields: Name, Email, Grade, Active status.
- Each member receives a unique upload link via email.
- Upload rules per competition:
  - Max number of images per category defined by admin.
  - Only JPEG (.jpg/.jpeg) accepted.
  - Admin defines maximum file size (e.g. 5MB) and pixel dimensions.
  - Optional upload password per competition; when set, members must enter it before accessing the upload form.
  - Plugin automatically resizes oversized images.
  - Filenames automatically reformatted to:
    **`username-categoryslug-[counter].jpg`**
  - Images stored in Media Library, tagged with:
    - Competition tag (e.g. `2024-10`)
    - Category tag (e.g. `colour`)
- If user uploads more images than allowed, excess images are numbered sequentially.

**Admin upload:**
Admins can upload or replace images for any member directly from the dashboard.

### 4.3 Image Processing & Storage
- Uploaded images stored in:
  ```
  /wp-content/uploads/competitions/<competition-name>/<category>/
  ```
- Thumbnails generated automatically via WordPress media functions.
- Each image assigned a random number (1–N) within its category for anonymized presentation.

### 4.4 Voting System
- Voting opened manually by admin or automatically by schedule.
- Optional voting password per competition; when set, voters must provide it before submitting ballots.
- Admin may restrict to one vote per device/session.
- Default scoring matrix: 9, 8, 7, 6, 5 (configurable).
- Voters prompted for **Name** before voting; recorded with results.
- Voting page:
  - Accessible via shortcode (e.g. `[competition_voting]`).
  - Mobile-optimized grid of image thumbnails with image numbers.
  - Option to switch to full-screen view for individual images.
- Votes stored in custom database table.

### 4.5 Slideshow Display
- Separate shortcode (e.g. `[competition_slideshow]`) for admin use.
- Displays images full screen with:
  - Image number overlay.
  - Auto-advance timer (default 10 seconds; configurable).
  - Manual controls: pause, next, previous, repeat.
- Designed for projection during in-person meetings.

### 4.6 Email Notifications
- Automated reminders via `wp_mail`:
  - 1 week before competition opens.
  - Configurable follow-ups.
  - Upload link included.
- Admin can:
  - Edit templates (subject, content, timing).
  - Send manual reminders.
- Optional QR code in email linking to voting page when open.

### 4.7 Results & Archiving
- Admin dashboard displays live results (averages or totals).
- Sorting by:
  - Category
  - Grade
  - Score
- Results exportable as CSV (final results only).
- **Results Display Shortcodes:**
  - `[competition_results]` - Complete results table grouped by grade, sorted by score
  - `[competition_top3]` - Top 3 winners per grade with podium-style display
- HTML results page automatically generated per competition.
  - Shows top 3 winners per grade with thumbnails and scores.
- Archive page lists all past competitions with links to their results.

---

## 5. Non-Functional Requirements

| Category | Requirement |
|-----------|--------------|
| **Performance** | Must handle 100+ members and 500+ images without timeout. |
| **Security** | Validate uploads; prevent malicious file types; optional token-based voting links. |
| **Compatibility** | WordPress 6.0+, PHP 8.0+, MySQL 5.7+. |
| **Responsiveness** | Upload and voting pages optimized for mobile. |
| **Accessibility** | Compliant with WCAG 2.1 AA where feasible. |
| **Localization** | Strings translatable via `.pot` file. |
| **Data Privacy** | Store only name and email for members; no external data sharing. |

---

## 6. Data Model (simplified)

### Tables
- `wp_clubcompete_members`
  - id, name, email, grade, active, created_at
- `wp_clubcompete_competitions`
  - id, title, slug, open_date, close_date, voting_open, created_at
- `wp_clubcompete_images`
  - id, member_id, competition_id, category, filename, random_number, score, created_at
- `wp_clubcompete_votes`
  - id, competition_id, category, voter_name, image_id, score, created_at

---

## 7. Shortcodes

| Shortcode | Description |
|------------|-------------|
| `[competition_upload competition="slug"]` | Member upload form. |
| `[competition_voting competition="slug"]` | Public/mobile voting interface. |
| `[competition_slideshow competition="slug"]` | Admin slideshow view. |
| `[competition_results]` | Complete results table for most recent competition. |
| `[competition_results competition="slug"]` | Complete results table for specific competition. |
| `[competition_top3]` | Top 3 winners per grade for most recent competition. |
| `[competition_top3 competition="slug"]` | Top 3 winners per grade for specific competition. |

### Results Display Features

**`[competition_results]`** - Complete Results Table
- Groups results by member grade (Beginner, Intermediate, Advanced)
- Sorts by total score (highest to lowest)
- Shows position, image thumbnail, member name, category, score, and vote count
- Responsive table design with mobile-friendly layout
- Clickable thumbnails link to full-size images

**`[competition_top3]`** - Top 3 Podium Display
- Shows top 3 winners for each grade
- Podium-style layout with 1st, 2nd, and 3rd place styling
- Visual distinction with gold, silver, and bronze colors
- Displays image thumbnails, member names, categories, and scores
- Responsive design that stacks vertically on mobile devices

---

## 8. Admin Dashboard

Sections:
1. **Dashboard** – Overview of current competition and recent activity.
2. **Competitions** – Create/edit competitions, set parameters.
3. **Members** – Add/edit/import members, upload for members.
4. **Images** – View all images uploaded per category/member.
5. **Voting** – Start/stop voting, monitor live results.
6. **Emails** – Configure and send reminders.
7. **Results** – View/export results, publish top 3 by grade.
8. **Archive** – Browse previous competitions and results.

---

## 9. Future Enhancements (Post v1.0)
- Support for multiple simultaneous competitions.
- Optional anonymous voting (no name required).
- Integration with Google Drive or Dropbox for image backup.
- REST API endpoints for external integration.
- Automatic watermarking or EXIF stripping.
