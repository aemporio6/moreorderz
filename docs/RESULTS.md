# MoreOrderz – results (12 Nov 2026, final)

Automatic tests: **185 pass, 0 fail (1,809 checks)** on a freshly built test database. Tests that need the owner's real files run only where the files are present. Round 1 (the 7 steps) ended at 118 tests; round 2 closed the gaps (below).

## Round 1 – per step (limits as they were then; round 2 closed most of them)
| Step | What | Tests | Checked in the browser | Main limits |
|---|---|---|---|---|
| 1 | Foundation: install, registry forms and lists, events, tasks, numbering, audit, double-entry ledger, login + 2-step, rights, search | pass | login, menu, Create, forms on desktop + phone | saved list views, sessions / IP screen, setup wizard not built |
| 2 | Products and stock: lots, FIFO, transfers, counts, prices, bins | pass | stock screens, counts | SKU homes not built |
| 3 | Buying: research → PO → pay → shipment → receive → landed cost | pass | whole chain | samples / QC / customs engines, quick buy not built |
| 4 | Sales, dispatch, printing | pass | order → pick → pack → handover → confirm → return; printers, queue | carrier label PDF import and one-click printing not built; not tested on the real printers |
| 5 | Money: bank, matching, expenses, approvals, TikTok money file, profit views, dashboard, refunds | pass | bank CSV, real TikTok file upload, book, payout match | only the TikTok file; no VAT logic; partial refunds not automated |
| 6 | Month close, partners, reports | pass | checks, waive, agreements, capital, 10 reports | 3 of 17 close checks "does not apply"; most of the profit waterfall and report catalogue not built |
| 7 | Import of the old data + end-to-end | pass | app opened on the imported data; end-to-end below | compared by counts and totals, not field by field |

## Import of the real export (18 Sep 2026: 133 tables with data, 200,117 rows)
| What | Old rows | Outcome |
|---|---|---|
| Master data (87 tables) | 54,183 | 53,806 imported · 337 repeated lookup rows merged · 40 already seeded · 0 failed |
| History tables (31) | 134,185 | 134,185 copied · 0 failed |
| Six sales tables → one sales list | 57,589 | 36,574 history lines, 311,470.22, Feb 2022 – Mar 2026 · 18,776 repeats · 2,239 left out (shop gone, or no order number / date) |
| Opening stock | 1,331 products | 1,331 lots, 64,116 units = old stock; value 519.25 = ledger (1,326 products had no cost) |
| Old backups / old menu and rights / old stock movements | 11,749 | not carried over, by plan |

## End-to-end on the imported real data (masked copy)
Run through a logged-in browser session against the running app (the steps call the same API the screens use; pages were opened where a screen result is quoted).

| # | Step | Result |
|---|---|---|
| 1 | Real product with stock found (2,000 units) | pass |
| 2 | Its opening lot given a cost: stock value 519.25 → 3,519.25 | pass |
| 3 | Order entered in a real shop, stock reserved | pass |
| 4 | Pick list made and picked | pass |
| 5 | Packed into a parcel | pass |
| 6 | Pick sheet (A4) and packing slip (100 × 150 mm) made as real PDFs | pass |
| 7 | Courier handover: wrong scan refused, right scan accepted, closed | pass |
| 8 | Month confirmed: 2 units out of stock, sale 19.98 and cost 3.00 in the books | pass |
| 9 | Bank account, CSV loaded, line categorised: bank = books | pass |
| 10 | Expense entered | pass |
| 11 | Buying: supplier → PO → confirm → pay → shipment → receive → landed cost (lot at 2.60) | pass (first run failed on an error in the test script itself, not in the tool; passed after the script was corrected) |
| 12 | Trial balance balanced; stock value = ledger 1200; profit view = profit by the books | pass |
| 13 | Stock self-check on screen | pass |
| 14 | Month close checks for the shop: nothing red | pass |
| 15 | Phone: work, stock, orders, money, close, reports, lots open, no sideways overflow | pass |

Found and fixed during this run: there was no way to give an opening lot its cost (built: Stock › Lots › Set cost); the Stock page total counted only the listed products.

## Round 2 – closing the gaps
| Part | What was built | Tests after | Honest state |
|---|---|---|---|
| 1a | Choices: every business decision with more than one way is a setting, all options built, recommended one default | 121 | – |
| 1b | VAT on fees / expenses (input VAT), VAT report; part refunds, refunds of unconfirmed lines | 123 | output VAT / VAT return not built |
| 1c | Money files of Amazon (flat file V2 + date range report), eBay transaction report, any table by column names | 128 | Amazon date range report checked on the owner's real file (4,632 lines, 21 settlements, all add up); eBay on its real layout; OnBuy / Temu by column names – need one real file each |
| 2 | Profit waterfall (VA / handler / owner modes, loss carried forward, both routes, stacking rules), statement PDFs with versions, adjustment to a locked month | 131 | limits in HANDOVER §11 |
| 3a / 3b | Saved list views + column picker, sessions & allowed addresses, SKU homes + putaway + refill, samples, QC, customs, quick buy, setup wizard | 144 | – |
| 4 | Order files (eBay, Temu, any table), bank Excel, bank line split, cash forecast | 150 | – |
| 5a | Carrier label PDF import, 14 more reports (25 in all) | 152 | one label per page |
| 5b | Vault (secrets encrypted, cards 4 digits, CVV / PIN never), import id-clash fix, field-by-field import check, Accounts dashboard, 6 screens | 158 | real copy: 1,105 columns – OK 1,044, masked 55, DIFF 5 all explained |
| 6 | Outside connections (eBay, Amazon, TikTok, OnBuy, Temu, Royal Mail ×2, WhatsApp, Google Drive), outboxes, webhook, hubs, Drive folders, subscriptions | 171 | **built to contract, not run against the real services** |
| 7 | Final retest; first sign-in on an install without a company (crashed – fixed); tracking-upload layouts per marketplace profile (data, editable); merging look-alike people / suppliers / products by hand with Undo | 175 | – |
| 8 | Every open decision as a choice with a company default **and per-shop values**; VA salary from the split rule / job history / expenses; two-layer stock option; VAT return (9 boxes, book, pay); loans with payments and due tasks; budgets against actual; capital calls (WhatsApp / e-mail, money in = partner capital); partner Accept / Ask on statements with Finance answers; one-click printing (IPP, raw TSPL / ZPL / EPL, print helper, PrintNode); installable phone app | 185 | one-click printing and the APIs: not yet on the real printers / services; VAT return is not filed to HMRC by the tool |

## Final retest (part 7)
| Check | Result |
|---|---|
| All automatic tests on a fresh test database | 175 pass, 0 fail |
| `mo:install --fresh` on an empty database, new Owner, first sign-in | pass (after the fix above) |
| Every one of the 146 menu screens opened as Owner, desktop 1440 px and phone 390 px | 146 / 146 open, 0 script errors, 0 placeholders, 0 sideways scroll, none slower than 3 s |
| End-to-end on the imported real data (masked copy), 15 steps | 13 pass in the rerun; step 2 "set lot cost" refused because the first run had already set that cost (correct refusal), step 11 failed on the old script's text quantity – the corrected script passes the whole buying chain (PO received, lot 20 units, stock value = ledger 1200, trial balance balanced) |
| `mo:nightly`, `mo:pull`, `mo:send-messages` on the dev, imported and fresh databases | run clean; stock = ledger for every company |

## How much of the spec works – an honest estimate, not a measurement
- **The daily chain works end to end and is tested:** idea → purchase → shelf → order → courier → money → month close → partner share and statement, on the owner's real data (masked copy).
- **All 146 menu screens are real screens** (no placeholders left). Every registry form with a table has a list and a generic form.
- **The engines of the spec are built and tested**, including the items that were still open at part 7 (one-click printing, VAT return, loans / budgets / capital calls, VA salary from the job history, partner answers on statements, phone app). What remains is listed honestly in HANDOVER §11.
- **Not proven:** the outside services with real keys, the real printers, the real server, real users and volumes over months.
