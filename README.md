# Club Competitions Plugin

WordPress plugin that helps photography clubs run recurring competitions, collect submissions, and manage voting.

## Quick Start
- Clone the repository, then install PHP dependencies via `composer install`.
- From `assets/`, run `npm install` to set up the JavaScript toolchain.
- Start the local WordPress environment with `npx @wordpress/env start` and activate the plugin from the WordPress dashboard.

## Development Workflow
- Use `npm run dev` inside `assets/` to watch and rebuild JS/CSS bundles; `npm run build` outputs minified assets.
- Run backend tests with `composer test` (PHPUnit) and lint PHP using `composer lint`.
- Execute JavaScript tests via `npm run test:js` inside `assets/`.
- Update `clubcompete_prd.md` when product requirements shift to keep specs aligned with implementation.
- The WordPress dashboard exposes a Competitions overview (with quick create/edit/archive flows) and a Members list sourced from custom tables; verify these pages after activating the plugin.
- The admin Submissions view lets you filter uploads by competition and member to audit entries quickly.

## Contributor Resources
- Follow the detailed contributor guide in `AGENTS.md` for coding style, directory layout, and pull request expectations.
- Review the PRD in `clubcompete_prd.md` for feature scope, data model, and business rules before building new functionality.

## Support
Open issues or feature requests using GitHub Issues, and include environment details plus reproduction steps. For security disclosures, reach maintainers through the private channels listed in the organization profile.
