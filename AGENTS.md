# CCM development

- Target PHP 8.4 / Laravel 13, React 19 / TypeScript and MariaDB. PHP tooling may run inside the project Docker runtime if unavailable on the host.
- Read docs/technicka-koncepce.md and docs/program/spusteni.md before architectural changes.
- Never commit credentials, environment files, private CSV uploads or generated dependencies.
- All vehicle data access must be scoped to the authenticated owner. Preserve decimal precision, transaction boundaries, audit records and revision checks.
- Validate with npm run build, vendor/bin/pint --test and MariaDB-backed PHPUnit tests. Test databases are disposable; never run RefreshDatabase against production.
