# Contributing to QueryProxy

Thank you for your interest in contributing. This guide explains how to propose changes.

## Before You Start

- Small fixes (typos, broken links, obvious bugs) can be sent directly as a pull request.
- For a new feature or a change in behavior, open an issue first so the approach can be discussed before you write code. Bigger features are weighed against the project's scope.
- Opening a pull request does not guarantee that it will be accepted.
- Do not report security vulnerabilities in issues or pull requests; follow the [security policy](SECURITY.md).
- Security-sensitive changes (guards, policies, HMAC verification, masking, credential storage) need an explicit note in the pull request description about the threat model impact. The suites for the SQL guard (`SqlInspector`), masking (`Masker`) and webhook verification are a contract: treat them as such.

## Development Setup

Requirements:

- PHP 8.3 or newer, with `pdo_sqlite`, `intl`, `zip` and `bcmath` (`pdo_mysql` and `pdo_pgsql` for those drivers)
- Composer
- Node.js 22 and npm

Clone the repository and install dependencies:

```bash
git clone https://github.com/QueryProxy/QueryProxy.git
cd QueryProxy
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
npm run build
php artisan serve
```

Use `npm run dev` to start the Vite dev server while you work on the frontend.

Code conventions: class-based Livewire components (`app/Livewire`), domain logic in `app/Services/<Area>/`, enums in `app/Enums`, one migration per feature.

## Running Tests

These are the same commands that CI runs. Run them before opening a pull request.

```bash
./vendor/bin/pint --test
composer audit
npm audit --omit=dev --audit-level=high
```

```bash
./vendor/bin/pest --ci
```

Every behavior change comes with a Pest test. Run `./vendor/bin/pint` to fix code style issues.

The `live-pgsql` test group runs the SQL guard against a real PostgreSQL server and is skipped unless `QUERYPROXY_LIVE_PGSQL_HOST` is set. [`docker-compose.test.yml`](docker-compose.test.yml) starts a throwaway server for it on port `55432`; the variables to set are listed at the top of that file. Then run:

```bash
./vendor/bin/pest --group=live-pgsql
```

## Commit Messages

This project follows [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/):

```text
<type>(<scope>)!: <description>

<body>
```

- **Types:** `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore`, `revert`.
- **Scope** is optional, lowercase kebab-case, and names a module or area: `feat(auth): ...`.
- **Description** is in English, imperative mood, starts with a lowercase letter, has no trailing period and is at most 72 characters.
- **Body** is optional and explains why the change was made. Wrap lines at 72 characters.
- Mark a breaking change only with `!` after the type or scope, and describe it in the body.
- Do not add footers or trailers (`BREAKING CHANGE:`, `Refs:`, `Closes #12`, `Co-Authored-By:`, `Signed-off-by:` or any tool attribution). Link issues in the pull request description instead.
- Keep one logical change per commit.

Example:

```text
fix(api): return 404 for unknown project slug
```

## Pull Requests

1. Fork the repository and create a branch from `main` named `<type>/<short-kebab-description>`, for example `feat/token-refresh` or `fix/123-null-slug-404`.
2. Write the pull request title in Conventional Commits format. Pull requests are squash merged, so the title becomes the commit message on `main`.
3. If the change affects users, add an entry under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md).
4. Fill in the pull request template, describe why the change is needed (not only what it does) and link the related issue with `Closes #<number>`.
5. Make sure CI passes. A maintainer reviews the pull request and may ask for changes before merging.

By contributing you agree that your contributions are licensed under AGPLv3.

## Code of Conduct

This project is governed by the [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you agree to uphold it.
