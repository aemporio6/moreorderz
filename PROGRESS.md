# Build progress

Owner said "final" on 12 Nov 2026. Decisions: Laravel + MariaDB · fresh build (no old code) · double-entry ledger: yes ·
public repo (code only, never data or secrets) · old data = export of 18 Sep 2026 (242 tables) for now · build in 7 steps, test each step.

| Step | What | Status |
|---|---|---|
| 1 | Foundation: install, registry, generic records, events, tasks, numbering, audit, double-entry ledger, login, shell | IN PROGRESS |
| 2 | Products and Stock | – |
| 3 | Buying (Research → PO → GRN) | – |
| 4 | Sales and Dispatch (orders, labels, printing) | – |
| 5 | Money (bank, settlements, TikTok, profit views) | – |
| 6 | Month close, Partners, Reports | – |
| 7 | Import old data + reconciliation report + full test | – |

## Log
- 12 Nov 04:45 PKT – Laravel 13.40 installed. `mo:install` builds the database in 3 s: 507 baseline tables + core tables, 4,084 registry fields, 146 menu screens with roles, 30 ledger accounts.
- 12 Nov 05:00 PKT – Services written: Registry, RecordService, Settings, Numbering, Audit, EventBus, TaskEngine, Ledger. **16 tests pass** (ledger balance / reversal / locked month / golden month 2,202.09 → 377.50; numbering; events + retry; rule tasks open once and close themselves; generic save with validation, auto-correct, history).

## Notes for the next session
- Packages were installed from GitHub sources because packagist is blocked in the build workspace; `composer.json` / `composer.lock` are standard, the developer runs a normal `composer install`.
- 20 registry forms are tools (no table) – listed in `tests/Feature/EngineTest.php`.
- Local dev DB: database `moreorderz`, test database `moreorderz_test`.
