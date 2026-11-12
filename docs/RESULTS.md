# MoreOrderz – results (12 Nov 2026)

Automatic tests: **118 pass, 0 fail (1,051 checks)**. Two of them (the three TikTok owner files) run only where the files are present.

## Per step
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

## How much of the spec works – an honest estimate, not a measurement
- **The daily chain works end to end and is tested:** idea → purchase → shelf → order → courier → money → month close → partner share, on the owner's real data.
- **Every registry form with a table (302 of 322) has a list and a generic form.** Tested: all 302 lists load without error and every Create entry opens its form; saving through the generic form engine was tested on a sample of forms, not on all 302. 113 of 129 menu screens open real screens, 16 are placeholders.
- **Of the spec's rules (the engines), roughly half are built and tested.** Not built: all outside connections (marketplace, carrier, WhatsApp APIs), the generic Import Center, most of HR / VA pay, tax, documents, the accounts vault encryption, and the items listed in `PROGRESS.md` and `docs/HANDOVER.md` §6.
- **Not proven:** anything on the real printers, on the real server, with real users and real volumes over time; field-by-field equality of the imported rows.
