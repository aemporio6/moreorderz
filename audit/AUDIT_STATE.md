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
| 6 | Foundations (soft delete + restore, import undo, bulk actions, notifications) | NEXT | | |
| 7 | Areas weakest first: Sales (3 %), Tasks (3 %), Accounts (3 %), Reports (4 %), Migration (0 %), Sourcing, Core, Products, Finance, Security, Stock, Setup, Labels | todo | | |
| 8 | MY TOOL functions (docs/MYTOOL_GAP.md, 34) | todo | | |
| 9 | Vue screens, Intelligence, server go-live | todo | | |
How to work each step: build → test → change the verdict lines in `audit/verdicts/` → `python3 audit/verify.py` → `python3 audit/build_result.py <register.csv>` → commit + push → update this table and project docs `claude/AUDIT_STATE.md`, `claude/CODE_PROGRESS.md`.
