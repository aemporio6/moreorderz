# MoreOrderz – hand-over notes for the developer

Branch `main`. Laravel 13 (PHP 8.3) + MariaDB 10.11. One code base, one database per install.
`PROGRESS.md` is the dated log of everything built and every honest limit; `docs/RESULTS.md` has the test results.
This file is the final hand-over (12 Nov 2026). Nothing in this repository is real owner data, a password or a key.

## 1. Install on a server
1. PHP 8.3 with pdo_mysql, mbstring, gd, intl, zip, bcmath, openssl. MariaDB 10.6+ (10.11 used). Composer. `poppler-utils` (pdftotext / pdfseparate) for carrier label PDFs.
2. `git clone`, `composer install --no-dev`, copy `.env.example` to `.env`, then set the lines in §2.
3. `php artisan key:generate` – **once**. Then `php artisan mo:install` – baseline schema (507 tables), migrations, field registry (4,084 fields, 322 forms), menu roles (146 screens), chart of accounts, print templates and routes. Safe to run again after every pull (it also runs new migrations).
4. First Owner: `php artisan mo:user owner@your-domain --role=OWNER --name="…"` (asks for a password, min 10 characters). The Owner then opens **Setup** (#/setup): company, banks, marketplaces, shops, carriers, printers, warehouse, people, opening balances, first files, go live.
5. Web root = `public/`. HTTPS only. `storage/` and `bootstrap/cache/` writable.
6. Cron, one line: `* * * * * cd /path && php artisan schedule:run >> /dev/null 2>&1`

## 2. The .env lines that matter
| Line | Value |
|---|---|
| `APP_ENV` / `APP_DEBUG` | `production` / `false` |
| `APP_KEY` | made by `key:generate` once. **The vault (secrets, connection keys) is encrypted with it: keep the same key on every server and in every backup restore. A new key makes every vault value unreadable** (there is no re-seal step for a key change). |
| `APP_URL` | the real https address, e.g. `https://app.example.com` – WhatsApp links and the webhook address use it |
| `DB_*` | an empty database for a new install |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | the company's mail server. With `MAIL_MAILER=log` nothing is sent: e-mails wait as HELD and leave as soon as a real mailer is set. |
| `QUEUE_CONNECTION` | `sync` is fine (the tool's own outboxes and events do the waiting) |

Never commit `.env`. The repository is public.

## 3. Scheduler (what the one cron line runs)
| Command | When | What |
|---|---|---|
| `mo:events` | every minute | waiting / failed events run again |
| `mo:send-messages` | every minute | WhatsApp and e-mail outboxes; HELD until a channel exists; 3 tries then task SYS_WA_FAILING |
| `mo:pull` | hourly at :17 | every marketplace API connection: orders 2 days back, money 14 days back; watched Drive folders |
| `mo:nightly` | 02:30 | stock = ledger self-check per company, low stock, payment / shipment / bank / money-file / month-close tasks, SKU home refill tasks, overdue subscriptions, morning task digests |

## 4. Other commands
| Command | Use |
|---|---|
| `mo:install [--fresh]` | set up / update the database (`--fresh` wipes it – never on the live one) |
| `mo:user <login> --role=… [--name=…]` | make a login or reset its password |
| `mo:import-old --from=<old db> [--dry] [--mask] [--only=table] [--transactions --opening-date=YYYY-MM-DD]` | import the old tool's data (§5) |
| `mo:import-compare --from=<old db> [--only=table]` | field-by-field check of the import (also on screen: Import history) |
| `mo:vault-seal [--only=table]` | put secret values already in the tables into the vault; card numbers keep 4 digits; CVV / PIN wiped. The live import does this by itself. |
| `mo:pull [--no-drive]` / `mo:send-messages` / `mo:nightly` / `mo:events` / `mo:reindex` | as in §3; `mo:reindex` rebuilds search after an import |

## 5. Import of the old tool's data
The import keeps the old ids, so it starts on a **fresh** install (§1 steps 1–3, before anything is typed in).
1. Load a **fresh full export** of the old tool into a separate database on the same server (e.g. `old_store`); the new tool's login needs `SELECT` on it. The export used in the build is from 18 Sep 2026 – take a new one at go-live.
2. `php artisan mo:import-old --from=old_store --dry` – counts only.
3. `php artisan mo:import-old --from=old_store` – master data. Then `… --transactions --opening-date=YYYY-MM-DD` – history, one sales list, opening stock.
4. `php artisan mo:import-compare --from=old_store` – every column OK / MASKED / DIFF; on the real copy 5 DIFF columns remain and all are explained (RESULTS.md).
5. `php artisan mo:reindex`. Create the Owner (§1.4). Imported users have **no password and no role**.
6. Reports: table `mo_import_report`, `storage/app/import/*.csv`, screen Import history.

Built in: password / PIN / CVV / token / secret / security-answer columns are never read; secrets that come over (memorable words, gateway codes, account numbers) are sealed into the vault; old rows whose id the new tool already uses for its own lists get a new id (recorded, links follow). `--mask` is for development copies only.
After the import the Owner must: give opening lots their cost (Stock › Lots › Set cost – 1,326 of 1,331 had none), enter open supplier balances by hand, check each shop's main location and default carrier, set printers and print the test page.

## 6. GO-LIVE CHECKLIST
**Server:** §1 and §2 done · cron line in · `APP_URL` https · backups of the database **and** `.env` (APP_KEY) · `php artisan test` green on a copy.
**Data:** fresh export imported (§5) · lot costs set · supplier balances · opening bank balances · Setup page all green.
**People:** each user has a role; Owner and Finance switch on two-step sign-in (Me › Two-step); allowed addresses (Sessions screen) if wanted.
**Printers:** both real printers (MUNBYN label, KYOCERA A4) – test page passed at 100 % / actual size, routes set.
**Outside connections** (System › API connections; keys are typed once and kept encrypted; press **Test** after typing; then one **Pull** and compare with the marketplace's own screen). Every adapter except "Files only" and "Tracking link" is complete code written to the provider's published contract and tested with answers shaped like that contract – **none has talked to the real service yet**:

| Connection | Keys to type | Settings | Check after Test |
|---|---|---|---|
| eBay Sell APIs | App ID, Cert ID, user refresh token (scopes sell.fulfillment, sell.finances) | marketplace id `EBAY_GB` | pull orders of 2 days = Seller Hub orders; pull money of a past week = Payouts page |
| Amazon SP-API | LWA client id, client secret, seller refresh token | endpoint EU, marketplace `A1F83G8C2ARO7P` | orders (no buyer address – restricted at Amazon); a closed settlement = Reports › Payments |
| TikTok Shop API | app key, app secret, access token, refresh token | shop cipher | orders and statements = Seller Center › Finance |
| OnBuy API | consumer key, secret key | site id 2000 | orders only (money stays by file) |
| Temu API | app key, app secret, access token | router address | orders only; Temu's partner documents are closed – field names may need a change on the first real call |
| Royal Mail Click & Drop | API key | service code, package format | buy one label for a test order; PDF lands in Carrier labels |
| Royal Mail Tracking | client id, client secret | – | track one real number |
| WhatsApp Cloud API | phone number id, permanent access token, app secret, verify token (a word you choose) | API version, language | see below |
| Google Drive | the service account key file (whole JSON) | main folder id | share the main folder and each watched folder with the service account's e-mail; "Make in Drive" once; "Check now" on a watched folder |

**WhatsApp:** in Meta Business Manager create a template for every code in Messages › Templates with the **same name in lower case** (statement, digest, task_new, approval, …) and the same placeholders in the same order; wait for Meta's approval. Set the webhook in the Meta app to `https://<APP_URL>/wa/inbound`, verify token = the one typed in the connection, subscribe to `messages`. A message to a template Meta has not approved fails with Meta's reason on the Messages screen.
**E-mail:** set the `MAIL_*` lines, send one partner statement with the Send button, check it arrives with the PDF.
**Files stay the fallback for everything:** order files, money files, bank files, label PDFs, tracking upload files work without any connection.

## 7. Roles
OWNER, MANAGER, PARTNER, FINANCE, ACCOUNTANT, SOURCING, WAREHOUSE, TL, VA. Rights come from the menu screens' forms plus a role list in each controller. Warehouse never sees money. A PARTNER login is linked to its partner in `fin_partner_users` and sees only its own account and statements. Approvals are decided by Owner or Manager, never by the person who asked. Secrets: only roles allowed by the choice VAULT_REVEAL may reveal; every reveal and refusal is in the audit log. Connections: Owner only.

## 8. Choices (Settings › Choices) – every option is built; the recommended one is the default
PROFIT_SHARE_AFTER_OVERHEADS · CLOSE_FIRST_FILE_PARTIAL · LATE_LINES_LOCKED_MONTH · WH_UNSHIPPED_AT_CONFIRM · PRINT_FAILOVER · DEFAULT_PROFIT_ROUTE · LOT_OWNER_SHARE · TEMU_SALE_VALUE · MENU_NAMES · VAULT_REVEAL · STATEMENT_SEND · TASK_DIGEST · VAT_REGISTERED (a fact, no default mark).

## 9. Where things are
- Engines: `app/Services/*` – Registry, RecordService, Ledger, StockService, SkuHomes, BuyingService, SourcingExtras, SalesService, DispatchService, OrderFiles, CarrierLabels, PrintService, MoneyService, MarketplaceFiles, SettlementService, CashForecast, Approvals, ProfitService, CloseService, PartnerService, WaterfallService, ReportService, Vault, Access, Merges, Subscriptions, OldImport, Choices, SetupWizard; outside connections in `app/Services/Connect/*` (one adapter per service, `Connections`, `Messenger`, `DriveFolders`, `Hubs`).
- API: `routes/web.php` (`/api/*`; the WhatsApp webhook `/wa/inbound` is the only route without sign-in), controllers in `app/Http/Controllers`.
- Front-end: `public/app/app.js` (one file, own router), `public/app/design.css`, `public/app/icons.js`.
- Data rules: `database/meta/field_registry.csv`, `database/meta/06C_task_codes.csv`; schema `database/schema/baseline.sql` + `database/migrations` (000001–000016).
- Samples for checking readers: `docs/sample_money_files/`, `docs/sample_order_files/` (made up, no real data).
- Invariants the tests hold everywhere: stock value (lots) = ledger account 1200; every journal balances; a locked month never changes.

## 10. Tests
`php artisan test` – **175 tests, 1,720 checks**. A second database `moreorderz_test` (in `phpunit.xml`); the import tests need `GRANT ALL ON mo_old_test.*` for the test login (skipped with that message otherwise). `MO_TEST_FRESH=1 php artisan test` rebuilds the test database (after a new migration). The owner's real TikTok / Amazon files are not in the repository: `MO_TT_FILES=/folder` runs those tests. Outside services are never called by the tests (Http::fake with contract-shaped answers).

## 11. Built, but with honest limits (details per part in PROGRESS.md)
- **Outside connections:** built to contract, not run against the real services (§6).
- **Printing:** the app makes the exact-size PDF and the user presses Print (adapter DRIVER_PDF). One-click printing adapters (print agent, raw ZPL / TSPL, IPP, cloud) are not built. Not yet tested on the real printers.
- **Carrier label PDF:** one label per page only.
- **VAT:** input VAT (fees, expenses, import VAT) only; output VAT and a VAT return are not built.
- **Profit waterfall:** VA salary comes from the split rule, not from the job history; supplier dues / "what is left per person" are not built; returns / landed-cost changes after a lock are not moved forward by themselves (the partner adjustment is); partners cannot Accept / Ask on a statement.
- **Money:** loans, budgets and capital-call engines are not built (their lists and forms work).
- **Vault:** no second two-step question before a reveal; no re-seal step for an APP_KEY change.
- **Sessions:** web sign-ins only; no phone app / device binding (there is no phone app – the web app works on phones).
- **Marketplace money by API:** OnBuy and Temu publish no money API – their money stays by file.
- **Not proven by anyone yet:** the real server, real printers, real users and volumes over months.

## 12. Decisions waiting for the owner
1. Design note D-1: one stock layer (lot × location) instead of the spec's two – confirm.
2. Under the "lot profit" route a lot owner gets the share set in LOT_OWNER_SHARE (default 100 %) – confirm the default.
3. If a VA's salary is also booked as an expense of the month, it is taken off twice (the screen warns) – choose one place.
4. Temu sale value BASE (default) or RETAIL – confirm with a real Temu statement.
5. A fresh full export before go-live.
