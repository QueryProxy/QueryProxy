# Contributing to QueryProxy

Thanks for considering a contribution!

## Getting started

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
npm run build
php artisan serve
```

## Ground rules

- **Tests are required.** Every behavior change comes with a Pest test
  (`php artisan test`). The SQL guard (`SqlInspector`), masking (`Masker`) and
  webhook verification paths are security-sensitive — treat their suites as a contract.
- **Style:** run `./vendor/bin/pint` before committing.
- **Conventions:** class-based Livewire components (`app/Livewire`), domain logic in
  `app/Services/<Area>/`, enums in `app/Enums`, one migration per feature.
- **Security-sensitive changes** (guards, policies, HMAC verification, masking,
  credential storage) need an explicit note in the PR description explaining the
  threat model impact.
- Discuss bigger features in an issue first — the product scope is defined by the
  PRD in the SSOT repo.

## Pull requests

1. Fork, branch from `main` (`feat/...`, `fix/...`).
2. Add tests, keep the whole suite green.
3. Describe *why*, not only *what*.

By contributing you agree that your contributions are licensed under AGPLv3.
