# MoreOrderz

E-commerce operations tool: research → buying → warehouse → products → sales → dispatch → money → month close → partners.
Laravel 13 + MariaDB 10.6+. Built from the VCommerce Build Pack v1.7 (field registry, rules, system map).

**This repository holds code only.** No real business data, no passwords, no API keys. Secrets live in `.env` on the server, which is never committed.

## Set up

```sh
composer install
cp .env.example .env        # then fill DB_DATABASE, DB_USERNAME, DB_PASSWORD
php artisan key:generate
php artisan mo:install      # baseline schema, core tables, field registry (4,084 fields), menu, chart of accounts
php artisan mo:user owner@example.com --role=OWNER
php artisan serve
```

## Tests

```sh
php artisan test            # uses database moreorderz_test (see phpunit.xml)
```

## How it is built

- `database/schema/baseline.sql` – the full schema of the spec (507 tables) with its seed rows.
- `database/meta/field_registry.csv` – the one source of truth for every form, list and dropdown; loaded into `sys_registry`.
- `app/Services/Registry.php`, `RecordService.php` – generic list / form / save for every registry form (company filter, validation, auto-correct, defaults, history).
- `app/Services/EventBus.php`, `TaskEngine.php` – one event catalogue; rule tasks open once per problem and close themselves.
- `app/Services/Ledger.php` – double-entry ledger: every money movement is a balanced journal; reversals instead of edits; locked months.
- `PROGRESS.md` – what is built and tested so far, and what comes next.
