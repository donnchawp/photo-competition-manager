# Repository Guidelines

## Project Structure & Module Organization
- `photocomp_prd.md` captures the product definition; keep it current when scope or terminology shifts.
- Bootstrap the WordPress plugin from `src/photo-competition-manager.php`, loading discrete modules from `src/includes/`.
- Group admin interfaces under `src/admin/`, public features under `src/public/`, shared utilities in `src/includes/Support/`, templates in `src/templates/`, and assets in `assets/`.
- Mirror the source layout in `tests/phpunit` and `tests/js` so failures map directly back to modules.

## Build, Test, and Development Commands
- `npx @wordpress/env start` spins up a disposable WordPress install with the plugin symlinked from `src/`.
- `npm run dev` (run inside `assets/`) watches JS/CSS via `@wordpress/scripts`.
- `npm run build` produces minified bundles for release packaging.
- `composer install` in the repository root provisions PHP dependencies and the WordPress core test scaffold.

## Coding Style & Naming Conventions
- Follow WordPress PHP coding standards: four-space indentation, snake_case functions, PascalCase classes inside the `PhotoCompetitionManager` namespace.
- Prefix actions, filters, and option keys with `photo_comp_` to avoid collisions.
- Use kebab-case for asset filenames and camelCase for JavaScript variables; keep React components in PascalCase.
- Update inline documentation blocks (`@since`, `@param`, `@return`) whenever signatures change.

## Testing Guidelines
- Use PHPUnit with the WordPress core test library; structure files as `tests/phpunit/<Module>Test.php` alongside factories under `tests/phpunit/fixtures/`.
- Use WP factories to avoid touching real database tables inside automated tests.
- For JavaScript behavior, add Jest suites under `tests/js` and execute them with `npm run test:js`.
- Ship at least one happy-path and one validation test per new feature or bug fix before requesting review.

## Commit & Pull Request Guidelines
- The repository currently lacks Git history; follow Conventional Commits (`feat:`, `fix:`, `docs:`) to establish consistency from the outset.
- Keep pull requests focused on a single logical change; include a summary, testing notes, and screenshots or screencasts for UI-impacting work.
- When archiving competitions, pair repository updates with UI affordances (archive/restore links) and tests covering repository-state transitions.
- Update supporting docs (`photocomp_prd.md`, schema diagrams, configuration samples) alongside code changes.
- Ensure local tests pass and planned CI pipelines succeed before merging.

## Security & Configuration Tips
- Validate uploads against MIME type, extension, and size limits before writing to `/wp-content/uploads/competitions/`.
- Store secrets (SMTP credentials, API tokens) in environment variables or `wp-config.php`; never commit them to the repository.
- Escape all output using WordPress helpers (`esc_html`, `wp_kses_post`, `esc_url_raw`) and sanitize request input before processing.
