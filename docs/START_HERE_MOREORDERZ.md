# MoreOrderz – START HERE (for any new chat or session)

Last updated: 12 Nov 2026, 15:15 PKT. If a chat is lost, the owner says "my MoreOrderz project" – read this file first, then the files it names. Nothing else is needed to continue.

## 1. What it is
Muhammad Aashiq's business tool "V-Commerce / MoreOrderz", rebuilt from scratch. The old tool was PHP CodeIgniter 3 + MariaDB (database towards3_store); it is no longer used as code – only its data is imported.
New tool: **Laravel 13 (PHP 8.3) + MariaDB 10.11**, double-entry ledger, registry-driven forms (4,084 fields, 322 forms), 146 menu screens, all real screens.
It covers the whole chain: research → buying (PO, payments, shipments, customs, QC, samples, landed cost) → stock (lots, bins, SKU homes, counts) → orders (files and APIs) → dispatch (pick, pack, handover, labels, printing) → money (bank, marketplace money files and APIs, refunds, VAT, forecast, loans, budgets, subscriptions) → month close (17 checks, lock) → partners (agreements, profit waterfall, statements, capital calls) → reports (25) → import of the old data.

## 2. Where everything is
| What | Where |
|---|---|
| Code (whole tool, old + new together) | GitHub **https://github.com/aemporio6/moreorderz** (public, branch `main`), last commit **b02efeb**, 45 commits |
| Final hand-over ZIP | `moreorderz_FINAL.zip` (273 files, sha256 3bb0322e…68da4) – sent in the chat, and in Google Drive folder "MoreOrderz – project backup" |
| Dated log of everything built + honest limits | `PROGRESS.md` in the repo = project doc `claude/CODE_PROGRESS.md` |
| Install, .env, cron, commands, go-live checklist, choices | `docs/HANDOVER.md` = project doc `claude/CODE_HANDOVER.md` |
| Test results and honest table | `docs/RESULTS.md` = project doc `claude/CODE_RESULTS.md` |
| Specification pack v1.7 (the rules) | project docs `claude/v1.7/*` (RULES_A–H, 06B integration contracts, 06C task codes, field registry, SQL) |
| Made-up sample files for the readers | `docs/sample_money_files/`, `docs/sample_order_files/` |
| Print helper for the packing PC | `tools/print-agent/` |

## 3. State (final)
- **185 automated tests pass (1,809 checks)**. All 146 menu screens open on a fresh install, desktop and phone, 0 errors, 0 placeholders.
- Built in round 1 (7 steps) and round 2 (parts 1a–8): see PROGRESS.md. Round 2 part 8 (last): choices with company default + per-shop values, VA salary source, two-layer stock option, VAT return, loans, budgets, capital calls, partner Accept/Ask on statements, one-click printing (IPP, raw TSPL/ZPL/EPL, print helper, PrintNode), installable phone app.
- Outside connections built to the providers' published contracts and tested with simulated answers – **not yet run with real keys**: eBay, Amazon SP-API, TikTok Shop, OnBuy, Temu, Royal Mail Click & Drop + Tracking, WhatsApp Cloud API, Google Drive, PrintNode. Real printers not yet tested.
- Real owner files checked: 3 TikTok money workbooks, the Amazon date range report (Jul–Sep 2026), the eBay report layout. These real files are NOT in the repo.
- Import of the real old export (18 Sep 2026): 200,117 rows accounted for; field-by-field check 1,105 columns, 5 explained differences. A fresh export is needed before go-live.
- The hand-over to the developer is done (`moreorderz_FINAL.zip`).

## 4. What was planned next (owner, 12 Nov 14:43)
The owner wants **his own version** of this same tool (not from zero), built with Claude in the cloud, in parallel to the developer's copy. Advice given and agreed in principle:
- Keep **Laravel 13 backend + MariaDB** (all rules and tests stay).
- Move the screens from the one big `public/app/app.js` to **Vue 3 + Inertia**, screen by screen, tests running all the time.
- Add **Redis queue + Horizon** for background work (messages, pulls).
- Add **AI features through the Claude API** (e.g. "why did profit drop", research summary, PO from a supplier message).
- Server: a VPS (Hetzner / DigitalOcean) with Laravel Forge, or Laravel Cloud.
- Needed from the owner before starting: (1) a new **private** GitHub repo (e.g. `moreorderz-own`), (2) server decision, (3) what first – Vue screens or AI features.
- Only one version can be live on the real data in the end – the owner chooses later.
Not started yet: waiting for the owner's go.

## 5. How to continue in a new cloud session
```
git clone https://github.com/aemporio6/moreorderz.git && cd moreorderz
composer install --prefer-source      # packagist may be blocked in the workspace
service mariadb start                 # or: (mysqld_safe --user=mysql &)
# databases: moreorderz (dev), moreorderz_test (tests), user with rights on both; mo_old_test for import tests
cp .env.example .env && php artisan key:generate
php artisan mo:install && php artisan mo:user owner@demo.test --role=OWNER --name="Owner"
MO_TEST_FRESH=1 php artisan test      # expect 185 pass
php artisan serve --port=8123
```
Old data: the owner's upload `towards3_store.sql` (18 Sep 2026) → load into a separate database → `php artisan mo:import-old --from=<db> --mask` (always `--mask` in a workspace) → `--transactions` → `mo:import-compare`.

## 6. Rules the owner set (always)
- Reply to the owner in **Roman Urdu** (Latin letters – never Urdu / Hindi script). Developer notes in simple English inside code blocks.
- Never mention the developer by name – say "developer".
- Honest senior-architect advice: never overclaim; honest tables; say plainly what is built-to-contract-not-tested.
- No childish analogies; explain in terms of his own tool.
- Save after every step: GitHub push + project doc `claude/CODE_PROGRESS.md`. Keep building without stopping; hand over ZIPs (git archive of the whole repo); the last one is named FINAL.
- Every business decision with more than one way becomes a Choice (dropdown) with all options built; the recommended one is the default; per shop where it makes sense.
- Security: never open, quote or copy config/database.php or any password, API key, token, secret, SMTP password or WhatsApp token found anywhere. The owner's e-mail is private. Never store CVV / PIN. Anonymise customer data in test copies (`--mask`). The repo is public: never commit real data, the DB dump, `.env`, passwords or keys; the old plain `pass` column is never used. Real owner files (Amazon CSV, eBay / Temu / TikTok files) never go into the repo or a ZIP.

## 7. Open decisions (all are now Choices – the owner only picks)
STOCK_COST_LAYER · LOT_OWNER_SHARE · VA_SALARY_SOURCE · TEMU_SALE_VALUE · OUTPUT_VAT_MODE (and the others in HANDOVER §8). Outside the tool: a fresh full export of the old tool before go-live; lot costs for the 1,326 products that had none in the old data.
