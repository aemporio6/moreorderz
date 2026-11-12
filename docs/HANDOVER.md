# MoreOrderz – hand-over notes for the developer

Branch `main`. Laravel 13 (PHP 8.3) + MariaDB 10.11. One code base, one database per install.
Read `PROGRESS.md` for the dated log of what was built and every honest limit; `docs/RESULTS.md` for the test results.

## 1. Install on a server
1. PHP 8.3 with the extensions pdo_mysql, mbstring, gd, intl, zip, bcmath. MariaDB 10.6+ (10.11 used). Composer.
2. `git clone`, `composer install --no-dev`, copy `.env.example` to `.env`, set `APP_KEY` (`php artisan key:generate`), `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, and the `DB_*` values of an **empty** database.
3. `php artisan mo:install` – loads the baseline schema (507 tables), runs the migrations, loads the field registry (4,084 fields, 322 forms), menu roles, chart of accounts, print templates and routes. Safe to run again after every pull.
4. First Owner login: `php artisan mo:user owner@your-domain --role=OWNER --name="…"` (asks for a password, min 10 characters). Two-step login can be switched on per user under "Me".
5. Web root = `public/`. HTTPS only. `storage/` and `bootstrap/cache/` writable.
6. Cron, one line: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1` – runs `mo:events` every minute (event retries) and `mo:nightly` at 02:30 (stock self-check, low stock, payment due, shipment, bank / money-file / month-close tasks).

## 2. Import of the old tool's data
The import keeps the old ids, so it must start on a **fresh** install (step 1–3 above, no users or companies yet except by the import).
1. Load the old export into a **separate** database on the same server (for example `old_store`). The login of the new tool needs `SELECT` on it.
2. `php artisan mo:import-old --from=old_store --dry` – counts only. Read the table.
3. `php artisan mo:import-old --from=old_store` – master data (87 tables).
4. `php artisan mo:import-old --from=old_store --transactions --opening-date=YYYY-MM-DD` – history tables, one sales list, opening stock.
5. Create the Owner login (1.4). Every imported user has **no password and no role**: the Owner sets both.
6. Reports of each run: table `mo_import_report` and `storage/app/import/*.csv` (names, counts, totals – no data rows).

Rules built in: columns named like password / pass / PIN / CVV / token / secret / answer are never read. `--mask` hides phones, e-mails, addresses, ID and bank numbers – use it for development copies only, never for the live import. Running a step again adds nothing twice.

After the import the Owner must: give the opening lots their cost (Stock › Lots › "Show only these" → Set cost) – 1,326 of 1,331 had none in the old data; enter open supplier balances by hand; check shops' main location and default carrier; set printers (Setup › Printers: test page on both real printers at "Actual size / 100 %").

## 3. Tests
`php artisan test` – 118 tests. They need a second database (`moreorderz_test`, set in `phpunit.xml`); the import tests also need `GRANT ALL ON mo_old_test.*` for the test login (skipped with that message otherwise). `MO_TEST_FRESH=1 php artisan test` rebuilds the test database (needed after a new migration). The three TikTok owner files are not in the repository: set `MO_TT_FILES=/folder` to run those two tests, otherwise they are skipped.

## 4. Roles
OWNER, MANAGER, PARTNER, FINANCE, ACCOUNTANT, SOURCING, WAREHOUSE, TL, VA. Rights come from the menu screens' forms plus a role list in each controller. Warehouse never sees money. A PARTNER login must be linked to its partner in `fin_partner_users`. Approvals (expense, supplier payment, stock adjustment, withdrawal) are decided by Owner or Manager, never by the person who asked.

## 5. Where things are
- Engines: `app/Services/*` (Registry, RecordService, Ledger, StockService, BuyingService, SalesService, DispatchService, PrintService, MoneyService, SettlementService, Approvals, ProfitService, CloseService, PartnerService, ReportService, OldImport …).
- API: `routes/web.php` (`/api/*`), controllers in `app/Http/Controllers`.
- Front-end: `public/app/app.js` (one file, own router), `public/app/design.css`, `public/app/icons.js`.
- Data rules: `database/meta/field_registry.csv`, `database/meta/06C_task_codes.csv`; schema `database/schema/baseline.sql` + `database/migrations`.
- Invariants the tests hold everywhere: stock value (lots) = ledger account 1200; every journal balances; a locked month never changes.

## 6. Not built (see PROGRESS.md for the full lists)
Marketplace / carrier / WhatsApp APIs and the generic Import Center (only the TikTok money file loader exists) · carrier label PDF import · one-click printing adapters · VA share / salary, handler share, investor-by-lot earnings, loss carry-forward, investor invoicing route · partner statement PDF · bank line split, VAT return, cash forecast, loans / budgets / capital calls engines · shared-expense rules · encrypted vault for bank / card numbers · saved list views, sessions / IP screen, setup wizard · most of the report catalogue · 16 menu screens are placeholders. Every form of the registry that has a table has a list and a generic form (all lists are tested; saving is tested on a sample), but only the flows named in PROGRESS.md have their own rules.

## 7. Decisions waiting for the owner
1. Share the shop's profit with partners before or after overheads (now: before).
2. Close check FILES_PRESENT when the first money file starts mid-month.
3. Investor "no stacking" rule (marked "owner to confirm" in the spec).
4. Design note D-1: one stock layer (lot × location) instead of the spec's two.
5. Late lines of a confirmed month are confirmed in a second run; of a locked month they are booked forward – confirm this is wanted.
6. Menu rename proposal.
7. A fresh full export before go-live (this one is from 18 Sep 2026).
