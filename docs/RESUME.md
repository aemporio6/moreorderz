# How to resume MoreOrderz – exactly as it runs now

Nothing important lives only in the work container. If the container is gone (limit, new session, days later), everything comes back from two places.

## Where everything is kept

| What | Where | Safe? |
|---|---|---|
| All code, migrations, tests, the audit verdicts, these notes | GitHub **aemporio6/moreorderz** (branch main) – pushed after every batch | Yes |
| State of the work, the NEXT batch, the owner's standing rules | Project doc **claude/AUDIT_STATE.md** (= audit/AUDIT_STATE.md in the repo) | Yes |
| One line per batch | Project doc **claude/CODE_PROGRESS.md** (= PROGRESS.md) | Yes |
| Register result (BUILT / PARTIAL / MISSING per area) | Project doc **claude/AUDIT_RESULT.md** (= docs/AUDIT_RESULT.md) | Yes |
| Owner decisions record | Project doc **claude/OWNER_QUESTIONS.md** (= docs/OWNER_QUESTIONS.md) | Yes |
| The spec (rules, register, system map) | Project docs **claude/v1.7/** (RULES_*.md, MASTER_REGISTER_v1.7.csv …) | Yes |
| Helper scripts (tests, rule lookup, browser check, clean-up) | repo **tools/dev/** | Yes |
| The dev database with demo data | only in the container – rebuilt by `php artisan migrate` + the demo seed | Rebuilt, not needed |
| Secrets (DB password, API keys, WhatsApp token) | never in chat, never in the repo – the developer keeps them on the server | – |

## Owner's standing rules (also at the top of AUDIT_STATE.md)

1. Replies to the owner in Roman Urdu; developer notes in simple English; say "developer".
2. Honest tables and real numbers; PARTIAL when something is open.
3. Save after every step: GitHub push + project docs.
4. 6 rules per batch is a maximum, not a target; group related rules.
5. The whole system is ONE – every part linked (events, one message door, one owner function, one registry, one task engine); each status says what the work links to.
6. No choice questions any more: my suggestion is the default, built as a Settings › Choices setting (dropdown, + Add, All, multiple, recommended help) – never locked.
7. Security: never open, quote or copy config/database.php, passwords, keys, tokens; the repo is public – no real data, no dumps.

## Resume in a new session – paste this to Claude

> Resume the MoreOrderz build exactly where it stopped. Clone GitHub aemporio6/moreorderz to /home/claude/moreorderz, install (composer install, MariaDB database moreorderz + moreorderz_test, php artisan migrate --seed), copy tools/dev/* to the scratchpad. Read the project docs claude/AUDIT_STATE.md (owner rules at the top, last row = NEXT batch), claude/CODE_PROGRESS.md and docs/RESUME.md, and the spec in claude/v1.7/. Copy claude/v1.7/MASTER_REGISTER_v1.7.csv to /home/claude/audit/register.csv and the RULES_*.md files to /home/claude/rebuild/projdocs/. Run the full test suite once to see it green, then continue with the NEXT batch and the same full cycle (code + migration, spec-example tests, full suite, browser desktop + phone with checksum, verdicts, verify, build_result, commit + push, AUDIT_STATE row + NEXT, PROGRESS line, project docs, Roman-Urdu status), then send_later 1 minute for the next batch.

## Check after resuming

- `git log --oneline -1` equals the last commit named in AUDIT_STATE.md.
- `php artisan test` → all green (count in the last PROGRESS line).
- `python3 audit/verify.py` → 0 problems; `python3 audit/build_result.py /home/claude/audit/register.csv` → the same BUILT count as the last AUDIT_STATE row.
