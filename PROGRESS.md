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

- 12 Nov 05:40 PKT – Web layer: login (hashed passwords, lock after 5 wrong tries, login log), company switch, boot API (menu in work order filtered by role, + Create catalogue from sys_forms), generic form / list / record / options / suggestions API. Screen shell uses the approved design's stylesheet and components: top bar, side menu, + Create with search, registry forms with Quick / Full, Back, Undo, Redo, Fill from last, suggestion chips, plain-word errors. Own codes (RES-, PO-, …) are numbered by the system. **27 tests pass** (incl. every one of the 302 data forms lists, every + Create entry opens). Browser test on desktop and phone: login → menu → list → Create → form → validation → save → list → edit: OK.

## Step 1 – still to do
- Global search (sys_search_index), record page with Linked / Tasks / Documents / History tabs, Tasks screen, role homes, 2FA (TOTP), users & rights screen, dropdown "+ Add" inside forms, saved views / column filters / export on lists.

## Notes for the next session
- Packages were installed from GitHub sources because packagist is blocked in the build workspace; `composer.json` / `composer.lock` are standard, the developer runs a normal `composer install`.
- 20 registry forms are tools (no table) – listed in `tests/Feature/EngineTest.php`.
- Local dev DB: database `moreorderz`, test database `moreorderz_test`.
