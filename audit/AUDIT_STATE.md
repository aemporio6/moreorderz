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
