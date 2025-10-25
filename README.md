# Photo Competition Manager

WordPress plugin that helps photography clubs run recurring competitions, collect submissions, and manage voting.

## Quick Start
- Clone the repository, then install PHP dependencies via `composer install`.
- From `assets/`, run `npm install` to set up the JavaScript toolchain.
- Start the local WordPress environment with `npx @wordpress/env start` and activate the plugin from the WordPress dashboard.

## Shortcodes

The plugin provides several shortcodes for displaying competition functionality:

### Upload & Voting
- `[competition_upload]` - Member upload form for the active competition
- `[competition_voting competition="slug"]` - Public voting interface

### Results Display
- `[competition_results]` - Complete results table for most recent competition
- `[competition_results competition="slug"]` - Complete results table for specific competition
- `[competition_results hide_names="true"]` - Results table without member names
- `[competition_top3]` - Top 3 winners per grade for most recent competition
- `[competition_top3 competition="slug"]` - Top 3 winners per grade for specific competition

### Example Usage
```php
// Show upload form for a specific competition
[competition_upload]

// Display voting interface
[competition_voting competition="october-2024"]

// Show results for most recent competition
[competition_results]
[competition_top3]

// Show results for specific competition
[competition_results competition="october-2024"]
[competition_top3 competition="october-2024"]

// Show results without member names (anonymous display)
[competition_results hide_names="true"]
[competition_results competition="october-2024" hide_names="true"]
```

## Documentation
- **Shortcode Usage:** See `SHORTCODE_USAGE.md` for detailed examples and implementation guides
- **Product Requirements:** Review `photocomp_prd.md` for feature scope and business rules
- **Contributor Guide:** Follow `AGENTS.md` for coding standards and development workflow

## Development Workflow
- Use `npm run dev` inside `assets/` to watch and rebuild JS/CSS bundles; `npm run build` outputs minified assets.
- Run backend tests with `composer test` (PHPUnit) and lint PHP using `composer lint`.
- Execute JavaScript tests via `npm run test:js` inside `assets/`.
- Update `photocomp_prd.md` when product requirements shift to keep specs aligned with implementation.
- The WordPress dashboard exposes a Competitions overview (with quick create/edit/archive flows) and a Members list sourced from custom tables; verify these pages after activating the plugin.
- The admin Submissions view lets you filter uploads by competition and member to audit entries quickly.

## Contributor Resources
- Follow the detailed contributor guide in `AGENTS.md` for coding style, directory layout, and pull request expectations.
- Review the PRD in `photocomp_prd.md` for feature scope, data model, and business rules before building new functionality.

## Support
Open issues or feature requests using GitHub Issues, and include environment details plus reproduction steps. For security disclosures, reach maintainers through the private channels listed in the organization profile.
