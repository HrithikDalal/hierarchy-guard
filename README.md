# Hierarchy Guard

Records every page URL change and redirects old subpage URLs to their new location. The admin views and warnings described in the design spec arrive in later releases.

## Development

Requires Docker, Node 22+, Composer and PHP 8.0+ on the host.

```bash
composer install
npm install
npm run env:start          # WordPress at http://localhost:8888 (admin / password)
npm run test:php           # PHPUnit inside the tests container
npm run test:php -- --filter PathsTest
composer lint              # PHPCS
```

The design spec lives in `docs/superpowers/specs/` and implementation plans in `docs/superpowers/plans/`.
