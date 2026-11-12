# Build progress

Owner said "final" on 12 Nov 2026. Decisions: Laravel + MariaDB · fresh build (no old code) · double-entry ledger: yes ·
public repo (code only, never data or secrets) · old data = export of 18 Sep 2026 (242 tables) for now · build in 7 steps, test each step.

| Step | What | Status |
|---|---|---|
| 1 | Foundation: install, registry, generic records, events, tasks, numbering, audit, double-entry ledger, login, 2-step, rights, shell, search, record page, admin screens | DONE (12 Nov) |
| 2 | Products and Stock | DONE (12 Nov) |
| 3 | Buying (Research → PO → GRN) | – |
| 4 | Sales and Dispatch (orders, labels, printing) | – |
| 5 | Money (bank, settlements, TikTok, profit views) | – |
| 6 | Month close, Partners, Reports | – |
| 7 | Import old data + reconciliation report + full test | – |

## Log
- 12 Nov 04:45 PKT – Laravel 13.40 installed. `mo:install` builds the database in 3 s: 507 baseline tables + core tables, 4,084 registry fields, 146 menu screens with roles, 30 ledger accounts.
- 12 Nov 05:00 PKT – Services written: Registry, RecordService, Settings, Numbering, Audit, EventBus, TaskEngine, Ledger. **16 tests pass** (ledger balance / reversal / locked month / golden month 2,202.09 → 377.50; numbering; events + retry; rule tasks open once and close themselves; generic save with validation, auto-correct, history).

- 12 Nov 05:40 PKT – Web layer: login (hashed passwords, lock after 5 wrong tries, login log), company switch, boot API (menu in work order filtered by role, + Create catalogue from sys_forms), generic form / list / record / options / suggestions API. Screen shell uses the approved design's stylesheet and components: top bar, side menu, + Create with search, registry forms with Quick / Full, Back, Undo, Redo, Fill from last, suggestion chips, plain-word errors. Own codes (RES-, PO-, …) are numbered by the system. **27 tests pass** (incl. every one of the 302 data forms lists, every + Create entry opens). Browser test on desktop and phone: login → menu → list → Create → form → validation → save → list → edit: OK.

- 12 Nov 06:30 PKT – Added: search everything (index refreshed on every save, company-safe, `mo:reindex`), record page (Details · Linked records both ways · Tasks · History), Tasks screen (mine / everyone, late and urgent counts, rule tasks cannot be ticked by hand), list filters per column, CSV export (allowed roles only, logged, formula-safe), "+ Add" inside dropdowns (saves to the list and selects it). **31 tests pass.** Browser: all 140 menu routes open on desktop and phone with 0 errors; 108 show real lists / pages, 32 are special screens still to build in their steps.

- 12 Nov 07:10 PKT – **Step 1 done.** Added: rights per role (a role can only open, add and edit the forms of its own screens; export and delete kept to named roles), role home cards from live data (HomeCards: modules register cards), users and roles screen (Owner only; hashed passwords; cannot demote self), My account (change password, 2-step sign-in with authenticator app, secret stored encrypted), Settings (global / company value), Audit log, System health (failed events + retry), Ledger view (trial balance, profit and loss, journals). **37 tests pass** on a fresh database. Browser on desktop and phone: all 140 menu routes open, 0 errors; 110 real screens, 30 special screens still placeholders (built in steps 2–6).

- 12 Nov 08:20 PKT – **Step 2, part 1: stock engine + screens.** `StockService` is the only writer of stock: receive (lot per line, damaged kept apart + claim task), transfer (lot and cost travel), consumeForSale (FIFO by received date then lot id; private owner never takes pool and the reverse; refuses when short), returnIn (back into the same lot; damaged / missing to their own place), adjust (reason needed; loss or gain posted to the ledger), transferOwnership (lot split, FIFO date kept), balance / available (sellable places minus reserved) / value, selfCheck (product number = lots = ledger; stock value = ledger account 1200). Screens: Stock on hand (KPIs, low filter, Opening stock / Move / Adjust / Check), product stock page (where the units are, by lot), Stock ledger, Lots and owners (give units to another owner). Roles: Warehouse can move and adjust but sees no cost or value. Nightly job `mo:nightly` (self-check, STK_LOW tasks, event retry) + scheduler. Home cards: stock value, low stock. Schema fixes: zero-date defaults of old tables → NULL; lot source key no longer unique (owner split needs several lots per GRN line). **54 tests pass**, including a real race: 6 separate processes pick 3 units each from 10 – exactly 3 succeed, never negative. Browser (desktop + phone): opening stock → move (too many refused in plain words) → adjust → check passed → product page → ledger → lots → accounts balanced, stock value on Home = 35.75.

- 12 Nov 09:10 PKT – **Step 2 done.** Added: stock counts (open with frozen system numbers → blind count → submit → a second person approves → only differences posted as count_adjust; stock that moved after the snapshot is respected), Pool view (units and value per owner), Held stock (returns / quarantine / damaged with days held, Move or Write off), price periods (`PriceService`: a new price closes the one before it, a late price fits in the middle, a sale date finds its price; current price kept per marketplace), bin generator (zone × levels × positions from the company's location format; never a bin twice), product record gets Stock and Prices buttons. Cost of goods sold and returns are now posted **inside** the stock service, so the stock account 1200 always equals units × lot cost. Rule fix: a sale without a private owner takes pool lots and the company's own lots, never a partner's private lot. **60 tests pass.** Browser (desktop + phone): count opened → submitted → own approval blocked; all 140 menu routes open, 0 errors; 113 real screens, 27 placeholders left.

## Carried to later steps (not forgotten)
- SKU homes and the phone work screen (with Dispatch, step 4) · ICC reorder calculator (with Buying, step 3) · product family / variations import and bulk upload (with Import Center) · adjustments above ADJUST_APPROVAL_QTY need approval (with the approvals engine, step 5)
- Approvals queue engine and screen (with Money, step 5) · saved list views · sessions & IP allow-list screen · first-time setup wizard (after the modules exist) · role-specific KPI cards (each module registers its own in its step).

## Notes for the next session
- Packages were installed from GitHub sources because packagist is blocked in the build workspace; `composer.json` / `composer.lock` are standard, the developer runs a normal `composer install`.
- 20 registry forms are tools (no table) – listed in `tests/Feature/EngineTest.php`.
- Local dev DB: database `moreorderz`, test database `moreorderz_test`.
