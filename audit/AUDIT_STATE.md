# Register audit – state and how to resume (updated after every area)

Goal (owner, 21 Nov 2026 02:13): every one of the 1,547 lines of `claude/v1.7/MASTER_REGISTER_v1.7.csv` (project doc) gets a status with proof. Then build everything that is not BUILT.

## Method
1. Rule level first: one verdict file per rules document in `audit/verdicts/<AREA>.txt`, one line per rule:
   `rule no | status | evidence | note`
   status: B = BUILT (core of the rule works, code + test) · P = PARTIAL · F = FORM_ONLY (registry form + list exist, rule logic not built) · M = MISSING · N = not applicable (retired / old-tool note)
   evidence: a regex that must be found in the code (app, routes, public/app/app.js, tests, migrations), or `form:<form_key>` (must be in database/meta/field_registry.csv). `audit/verify.py` checks every B / P / F line by machine.
2. Then the 453 register lines that name no rule (checklist M-items, owner requests, architect suggestions, carry-forward) – one verdict each in `audit/verdicts/NO_RULE.txt` (`MR-id | status | evidence | note`).
3. `audit/build_result.py` joins register + verdicts → `audit/REGISTER_AUDIT.csv` + `docs/AUDIT_RESULT.md` (percent per area, list of what is missing).
The register CSV itself is not in the repo (it is the project doc); to run the scripts, save it as `audit/register.csv` (project_read → copy the local file).

## Progress
| Area (rules doc) | Rules | Verdict file | Done |
|---|---|---|---|
| A_SALES | 92 | A_SALES.txt | yes |
| B_SOURCING | 70 | B_SOURCING.txt | yes |
| C_ACCOUNTS | 50 | C_ACCOUNTS.txt | yes |
| D_PRODUCTS | 86 | D_PRODUCTS.txt | yes |
| E_FINANCE (R-FIN, R-INV, R-ACC, R-TAX) | 109 | E_FINANCE.txt | yes |
| F_SETUP | 52 | F_SETUP.txt | yes |
| G_CORE | 121 | G_CORE.txt | yes |
| H_WAREHOUSE | 60 | H_WAREHOUSE.txt | yes |
| Lines without a rule | 453 | NO_RULE.txt | yes |
| verify.py + build_result.py + result doc | – | docs/AUDIT_RESULT.md, audit/REGISTER_AUDIT.csv | yes |
| Re-check of BUILT lines | – | 133 BUILT lines with code evidence: 120 have their key named in a test, 13 are tested through their screen route (samples, customs, statements, agreements, withdrawals, audit); full suite 186 tests green | yes |

## Rule for whoever continues (any chat, any container)
- `git clone https://github.com/aemporio6/moreorderz` → read `docs/START_HERE_MOREORDERZ.md`, then this file, then `docs/OWN_VERSION_PLAN.md` and `docs/MYTOOL_GAP.md`.
- Continue with the first "no" row above. After every area: update this table, commit, push, and write this file to project doc `claude/AUDIT_STATE.md`.
- Never mark BUILT without evidence that verify.py accepts.

## Result (21 Nov 2026)
1,434 lines that count: BUILT 209 (15 %) · PARTIAL 612 · FORM ONLY 261 · MISSING 352 · not applicable 113. Per area: docs/AUDIT_RESULT.md.
Rules level: 631 rules – BUILT 78, PARTIAL 235, FORM ONLY 112, MISSING 206.

## Next (build phase) – order agreed with the owner in docs/OWN_VERSION_PLAN.md
Parity gate -> Permission engine -> Search -> Filters -> Smart fields -> Guidance -> Foundations -> then area by area, weakest first (Sales, Tasks, Accounts, Reports), each MISSING / PARTIAL / FORM ONLY line turned BUILT with a test; the verdict line is changed in the same commit.

## Build phase log (newest last) – continue from the last line
Owner instruction (21 Nov 03:07): work without asking, resume by yourself with a timer, take every register line to BUILT (100 %), keep the numbers honest.
| # | Step | State | Commit | BUILT after |
|---|---|---|---|---|
| 1 | Permission engine (pillar 1): rights as data, made roles, personal rights, field rules, access requests, view-as, shop scope (registry lists + any request naming a shop), screen "Roles and rights" | done, 195 tests | c804890 + next | 210 (15 %) |
| 1b | Shop scope on the summary screens: `Perm::mine()` on order board, tracking, returns, label pages, profit, sales reports, settlements, refunds, hubs, month close, every shop picker; shop in a route path is checked too. MR-0036, MR-1286, R-A_SALES-47 → BUILT | done, 196 tests | this commit | 212 (15 %) |
| 2 | Search everything: `Finder` (Ctrl+K / "/"): screens, actions, create, reports, settings, rights, fields, records; stems, synonyms, sound, typing mistakes, amount / phone / e-mail, scope chips, scan, recent + pinned, did-you-mean; same word search in every list. Open: help topics, two record buttons, 200-query acceptance test | done, 202 tests | this commit | see Result line |
| 3 | List Standard: `ListFilter` (rule tree, operators per type, AND / OR groups, presets, "(empty)"), per-column filter with value picker, totals row, 25–250 rows, column order + widths + density per user, Copy / CSV / Excel / PDF / Print, saved exports (shared, scheduled by e-mail). Open: export to Drive, old t_export_options migration, "More…" tabs | done, 209 tests | this commit | 222 |
| 4 | Smart fields: `FormAssist` – searchable dropdowns (server search, last used first, multi-select All / None, Add what you typed), + Add by form or in place, cascades, ≤ 2 chips with reasons on every field, - / + steppers, auto-correct with Undo, look-alike warning, "Why can't I save?", default tags, copy, Help mode, Ctrl+S, "What happens when I save?". Open: 51 dropdowns without Add, hand-made dialogs | done, 214 tests | this commit | see Result |
| 5 | Guidance: `Guide` – strip above every list, next step / "Why can't I continue?" card on every record, empty-list actions, Help mode, "?" shortcuts + g-jumps, Back / Forward, recent pages, favourites, step playbooks with coverage screen (#/playbooks), "I am stuck" on tasks + "Stuck on me". Open: cover person, digest, filters in the address | done, 219 tests | this commit | 229 (16 %) |
| 6 | Foundations: `BulkJobs` (tick rows → change a field / delete / restore, preview, bulk_jobs, Undo, screen #/bulk-jobs), delete refuses what is used and has Undo, inline edit by double-click, `Notify` + bell (grouped, mark read, 60 s). Not done here: import undo (goes with Sales, R-D-78), bulk role change on users | done, 224 tests | this commit | see Result |
| 7 | NEXT → Areas weakest first: Sales (3 %), Tasks (3 %), Accounts (3 %), Reports (4 %), Migration (0 %), Sourcing, Core, Products, Finance, Security, Stock, Setup, Labels | todo | | |
| 8 | MY TOOL functions (docs/MYTOOL_GAP.md, 34) | todo | | |
| 9 | Vue screens, Intelligence, server go-live | todo | | |
How to work each step: build → test → change the verdict lines in `audit/verdicts/` → `python3 audit/verify.py` → `python3 audit/build_result.py <register.csv>` → commit + push → update this table and project docs `claude/AUDIT_STATE.md`, `claude/CODE_PROGRESS.md`.
| 7a | SALES batch 1 – sales list and line actions (rules 27, 28, 33, 36–43, 48, 49): `SaleLines`, screen #/sales, recycle bin | done, 231 tests | this commit | 251 (18 %), Sales 20 / 155 |
| 7b | SALES batch 2 – monthly sales import (rules 1–18, 66): `SalesImport` + `Parse`, screen #/sales-import (Check → Import, replace question with diff, fix-up grid with SKU aliases, Sale Columns popup, Pending imports + shop × month grid, delete import with Undo), migration 000023 | done, 247 tests | this commit | 267 (19 %), Sales 25 / 155 |
| 7c | SALES batch 3 – sales list as a view (rules 34, 35, 42, 44, 45, 46, part of 31): `SaleView` (columns per person, extra marketplace columns, strip with fee / VA share / net, PDF / Word / Excel / CSV export), every filter has a control, one-batch chip with Confirm / Errors / Delete, batch status Confirmed at Month Confirm, Owner unlock (`SalesService::unlockMonth`), VA % frozen on the batch | done, 255 tests | this commit | 272 (19 %), Sales 28 / 155 |
| 7d | SALES batch 4 – drop-shipping match (rules 19–26; 12, 14, 41 now BUILT): `DsMatch` (5 fields, own phones, assignment, lock, money, reasons + CSV, Re-match also by event `DS_BATCH_APPLIED`, explanation with masking, link / unlink by hand), DS cells of the list, migration 000024 | done, 264 tests | this commit | 281 (20 %), Sales 36 / 155 |
| 7e-1 | SALES batch 5, part 1 – shop label component (81), Annual sale report (50), Detailed sale report (51), saved VA share + Refresh VA % (31), drop-shipping minimum margin (91): `ShopLabel`, `SalesReports`, screens #/sales-annual and #/sales-detailed, migration 000025 | done, 269 tests | this commit | 288 (20 %), Sales 39 / 155 |
| 7e-2 | SALES batch 5, part 2 – VA statement (65): `VaStatements`, screen #/va-statements (menu 10.6), migration 000026; shop monthly reminders with templates (72): `ShopReminders`, screen #/shop-reminders (menu 6.8), migration 000027 | done, 274 tests | this commit | see Result |
| 7e-3 | SALES batch 5, part 3 – carrier cost model (69) and preset matrix (70): `Carriers` (rates with a start date, model checks, matrix with gaps, cheapest service, services offered per marketplace), screen #/carriers (menu 17.4), migration 000028; sale-line cost reads the rate of the order date | done, 277 tests | this commit | see Result |
| 7e-4 | SALES batch 5, part 4 – small parts that complete rules 8, 13, 17, 21, 25, 26: schema self-check names the migration files + System › Health list (`SchemaCheck`), date format read / remembered, daily totals apart (unique key of a batch now includes the kind), equal-match pick list, daily not-matched digest, masking on the drop-shipping list / record / export; migration 000029 | done, 283 tests | this commit | 301 (21 %), Sales 45 / 155 |
| 7e-5 | SALES batch 5, part 5 – rules 11, 34, 65 completed: out-of-period question at Month Close (keep / move with a reason), VA share as its own Month Close line, refund after Confirm as an adjustment line of the next open month (locked line never edited), returns and landed-cost corrections dated in the next open month (`Ledger::openDate`) | done, 287 tests | this commit | 309 (22 %), Sales 49 / 155 |
| 7e-6 | SALES batch 5, part 6 – rule 2 completed (fee from the money report, typed fee replaced when it arrives, fix link on every refusal); marketplace master + questions + shop checklist (68): `Marketplaces`, screen #/marketplaces (button on Import sales, also in search), migration 000030 | done, 290 tests | this commit | 315 (22 %), Sales 50 / 155 |
| 7e-7 | SALES batch 5, part 7 – night tasks SAL_NO_COST / LST_STATUS / IMP_WAITING (`SalesWatch`), large exports as a background job with bell + e-mail (46 completed, migration 000031), 11 stale verdicts of lines without a rule brought up to date with evidence | done, 294 tests | this commit | 325 (23 %), Sales 60 / 155 (39 %) |
| 7e-8 | SALES batch 5, part 8 – bulk delete of Pending sales by shop + dates with preview, Owner-only right and second approval above 500 lines (53); drop-shipping supplier-orders upload (R-D-73, 76, 77, 86 BUILT; 74, 75, 78 PARTIAL): `DsUpload` (held file under a token, forgiving reader, guess + saved mapping + marketplace DS Columns, row checks, repeated / joined / ignore / replace, bookkeeping, history, undo with restore, + New platform, event `DS_BATCH_APPLIED`), screen #/ds-upload (button on Import sales, in search), rights orders.dsUpload / dsUndo / dsPlatformAdd; clicked through in a browser | done, 304 tests | this commit | 333 (23 %), Sales 68 / 155 (44 %), Products 16 / 101 |
| 7e-9 | Drop-shipping orders list + item page (R-D-85 BUILT), buyer privacy and rights (R-D-79 BUILT), "Show rows" per upload (R-D-78 BUILT): `DsOrders` (filters, sorts, totals bar per currency, item page with linked sale lines and profit, Match manually, Re-match, delete to the recycle bin, masked CSV), screens #/ds-orders and #/ds-orders/<id> (menu 6.7, in search), original file row hidden on generic screens too; clicked through in a browser | done, 308 tests | this commit | 340 (24 %), Sales 75 / 155 (48 %) |
| 7e-10 | NEXT → by register weight (count open Sales lines by via_rules in audit/REGISTER_AUDIT.csv first): Sales 31 leftovers; 73–74 shop health; 71 customer cases; then 87, 67, audits 57–64, 77–80, 84–90, 52–56; Products R-D-94 inventory sharing (closes MR-0128), R-D-74 shared reader, R-D-75 aliases vs Field Registry | todo | | |
