# Contributing to TMW SEO Engine

Thank you for working on this plugin. This document covers the conventions and expectations for all contributors.

---

## Branching

- **`main`** — always deployable; represents the current production-ready state
- **Feature/fix branches** — branch from `main`, named descriptively:
  - `feature/keyword-graph-export`
  - `fix/csv-manager-orphan-count`
  - `chore/update-readme`
- Open a pull request against `main`; do not push directly to `main`

---

## Before Opening a PR

1. **Test on staging first.** The plugin writes to the database, registers cron jobs, and makes external API calls. Do not open a PR for code you have not run on a staging WordPress instance.

2. **Run the test suite locally:**
   ```bash
   composer install
   composer test
   ```
   All tests must pass. Do not open a PR with failing tests.

3. **Run a syntax check:**
   ```bash
   find . -name "*.php" -not -path "./vendor/*" | xargs php -l
   ```

4. **Version consistency.** If your change warrants a version bump, update all three places together:
   - The `Version:` field in `tmw-seo-engine.php`
   - The `TMWSEO_ENGINE_VERSION` constant in `tmw-seo-engine.php`
   - Add a `## X.Y.Z` entry to `CHANGELOG.md`
   - Update the version assertion in `tests/ActivationTest.php`
   - Update the `TMWSEO_ENGINE_VERSION` define in `tests/bootstrap/wordpress-stubs.php`

---

## Commit Messages

Use concise, present-tense messages that describe *what* the commit does, not *how*:

```
# Good
Fix CSV manager summary card count mismatch for imported seeds
Add __imported__ preset to build_seeds_where()
Update ActivationTest to assert version 4.6.3

# Avoid
Fixed stuff
WIP
Changes
```

For larger changes, use a short subject line (≤72 chars) followed by a blank line and a bullet-point body.

---

## Testing Expectations

- Every non-trivial logic change should have a corresponding test or an explicit comment explaining why one is not practical
- Tests that require a live WordPress database are kept outside the automated suite — note them in the PR description and describe how you validated them manually
- Do not modify `tests/bootstrap/wordpress-stubs.php` to suppress legitimate test failures; fix the underlying code

---

## Do Not Commit Secrets

- No API keys, credentials, OAuth tokens, or database passwords in any file
- The `tests/bootstrap/wordpress-stubs.php` stub values (e.g. `AUTH_KEY`) are fixed test stubs — they must never be reused in a real environment
- If you accidentally commit a credential, rotate it immediately and amend/squash the commit before the branch is merged

---

## Reporting Bugs

Open a GitHub issue with:
- Plugin version
- PHP and WordPress version
- Steps to reproduce
- Expected vs. actual behaviour
- Relevant log output (from **TMW SEO → Debug Dashboard** or **Logs**)

For security vulnerabilities, **do not use GitHub issues** — see [SECURITY.md](SECURITY.md).

---

## Code Style

- PHP 8.0+ syntax; `declare(strict_types=1)` on new files
- WordPress coding conventions for escaping and database calls (`$wpdb->prepare()`, `esc_html()`, etc.)
- No raw SQL outside `$wpdb->prepare()` unless using a documented safe pattern with an in-code explanation
- Destructive admin actions (DELETE, large UPDATE) must be POST-only with WordPress nonce verification
- New admin navigation links go through `TMWSEORoutes` (`includes/admin/class-tmwseo-routes.php`) — do not scatter raw `add_query_arg()` strings

---

## Changelog Entries

Every PR that changes user-visible behaviour or fixes a bug should include a `CHANGELOG.md` entry under a `## Unreleased` heading (or the target version heading if the release is imminent). Follow the existing format:

```markdown
## X.Y.Z — Short Title (YYYY-MM-DD)

### Category (e.g. Bug Fix / New Feature / Architecture)
- **`path/to/file.php`** — description of what changed and why
```
