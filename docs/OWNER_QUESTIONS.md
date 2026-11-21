# Owner questions (MoreOrderz)

Updated 21 Nov 2026 (batch 7e-101). How to answer: write **OK** next to a line to keep the suggested default, or write your own answer. Nothing here stops the building – every line already runs with the suggested default – except part A.

## Answered by the Owner (21 Nov 2026)

| # | Answer | Done in the system |
|---|---|---|
| B1 | SOURCING **sees the money** – goods value, what came / went, landed cost, invoice and payment amounts – but **not our bank accounts** | see_money is on for SOURCING by default; the bank account list is sent only to the roles that pay (Owner, Finance) |
| B3 | Directors only from Owner / Director / Partner – yes | kept |
| B4 | Every product seen by Owner, Manager (and ADMN-SUPER) – yes | kept (Choices › Who sees every product?) |
| A3 | Demo products: set as suggested | the 3 demo products are Pool on their marketplace |
| B5 | Hidden margin only for the Owner – yes | kept |
| B6 | Custom period From after To → swapped – yes | kept |
| A1 | Google Drive: a Drive link that is there counts as correct | no Drive API check; only the http(s) form is checked |
| A2 | Logo: the approved MoreOrderz seal | on the header of every A4 PDF, a very light watermark (seal + VARTLEAVES LIMITED) on every screen and every PDF page (labels stay clean), and the logo on every Excel export (top right + print header). CSV files cannot hold a picture. |

## A. Needs you before it can be finished (outside things)

| # | What | Why it is needed | Suggested |
|---|---|---|---|
| A1 ✔ | Google Drive connection on the server | Document links are checked only for the http(s) form now; a check that the Drive file exists and is shared (R-B_SOURCING-49) and Drive uploads need the company Drive connected on the server (the developer sets it up; no password in chat). | Yes, connect the company Drive (owner: "yes" again 22 Nov 03:42 – the developer connects it on the server; evidence files can then move to Drive) |
| A2 ✔ | Company logo file | PDFs (PO, GRN, statements) print without a logo until one is uploaded in Settings. | Send the logo once (PNG or SVG) (owner: "yes" 22 Nov 03:43 – upload it in Settings › Company, or attach it in the chat) |
| A3 ✔ | Product visibility of the real products | A non-super user (VA, seller …) only sees products that are Pool, or Private in his shops (R-D-21). Products without visibility are hidden from them. | Set Pool / Private per marketplace on every product (bulk edit can do it) |

## B. Choices made in the last batches (please confirm)

| # | Rule | Choice now in the system | Can be changed in |
|---|---|---|---|
| B1 ✔ | R-F_SETUP-16 | SOURCING does not see money (PO value, cost); Owner, Manager, Finance, Accountant, Partner do | Rights › role SOURCING › see_money |
| B2 | R-F_SETUP-16 | Accounts listed in the old DASH_PARTNER setting got the role PARTNER | People › roles |
| B3 ✔ | R-F_SETUP-38 | Directors 1–3 are picked only from people with role Owner, Director or Partner (owner: "yes" again 22 Nov 03:38) | — (fixed rule; no setting) |
| B4 ✔ | R-D-21 / R-D-17 | Every product is seen by Owner, Manager and the ADMN-SUPER role; everybody else is limited (owner: "yes" again 22 Nov 03:39) | Choices › Who sees every product? |
| B5 ✔ | R-F_SETUP-29 | The row Hidden margin is shown to the Owner only (partners do not see it) | — |
| B6 ✔ | R-F_SETUP-27 | A custom period with From after To is swapped | — |
| B7 | R-B_SOURCING-18 | Parent fields carried by Save & Next are locked; the unlock icon changes them | — |
| B8 | R-B_SOURCING-4 | A repeated cycle takes the last agreed price (latest PO line, else the supplier's RFQ final price, else the old target) | — |
| B9 | R-F_SETUP-28 | A product counts as Private when it is private on any marketplace × shop | — |
| B10 | Form standard | Every dropdown has + Add, except 67 fixed lists whose values the program uses (statuses, modes like FIFO / FBA) and 6 lists only the system makes (sales orders, bank statements, printer drivers, device slots) – these show the reason instead (docs/FORM_STANDARD_AUDIT.md) | the developer adds a value to a fixed list |
| B11 | R-FIN-66 | Finance alerts go to the **Owner** only at first. FA3 (no settlement), FA4 (no bank lines) and FA9 (supplier payment due) send WhatsApp only, because their tasks already come from the existing rules; FA7 (slow / dead stock) makes a task only (one per SKU, no WhatsApp flood). You change roles and channels on the Finance alerts screen | — |
| B12 | R-FIN-66 FA1 | Free cash = bank − supplier payments still open − approved withdrawals. The unpaid tax reserve is not taken off yet because the system does not keep it as its own figure | — |
| B13 | R-FIN-3 | Partners who already had a login keep working: the system ticked all six onboarding items for them once (new partners must complete all six) | — |
| B14 | R-FIN-54 | "Stock days" = stock on hand ÷ units sold per day in the chosen dates. The list uses order dates (the same figures as Profit by SKU), not settlement end dates | — |
| B15 | R-FIN-51 | A location counts as "at FBA" when its kind is FBA / FC / AMAZON / MARKETPLACE / 3PL, or when an inbound shipment was sent to it. Give your fulfilment-centre locations one of these kinds | — |
| B16 | R-FIN-4 | Your company has no finance settings row yet, so the tax reserve before partner shares is 0 % VAT + 0 % corporation tax. The form shows the spec defaults (0 % and 19 %); saving it once starts the reserve at what you enter. Please open Setup › Company & finance settings and save the rates you want | — |
| B17 | R-FIN-6 | Payouts are now matched to the bank within ± 3 days (the spec default; the old code used 5 when the setting was empty). Change "Match payouts within" if your marketplaces pay later | — |
| B18 | R-B_SOURCING-46 / 55 | "Make FINAL" for landed cost and approving a landed-cost override are for Owner, Manager and Partner (new right buying.LANDED_FINAL – change it in Rights) | — |
| B19 | R-B_SOURCING-46 | After new freight, customs or GRN rows the landed cost is worked out again as PROVISIONAL by itself, 5 minutes after the last change (never once it is FINAL) | — |
| B20 | R-G_CORE-15 / 13 | A cancelled task can be undone for 10 minutes (back to its last status). A new due date may not be in the past. The owner (VA) can ask for a new date; the person who assigned it approves (counts as a move) or rejects with a reason | ✔ Owner confirmed 22 Nov 01:45 ("ok theek ha") |
| B21 | R-G_CORE-97 / 33 | A rule task a Partner closes with a reason stays quiet 7 days (setting rule_snooze_days in Alerts › Thresholds); snoozes are listed under Tasks › Everyone with End snooze. Bulk on tasks is for Owner / Partner / Manager, at most 200 at once, with Undo (not for reminders) | — |
| B22 | R-G_CORE-48 / 45 | A person may change his own quiet hours, time zone, digest time and WhatsApp / e-mail switches (Tasks › My alerts); a Partner or Manager changes anyone's; only a Partner switches a channel off for someone else; the weekly task limit only a Partner or Manager. An empty digest ("Nothing due. Well done.") is sent because digest_send_empty is 1 today – set it to 0 in Alerts › Thresholds to stop it | — |
| B23 | R-G_CORE-53 / 46 | Outside the 24-hour WhatsApp window only an APPROVED template is sent. All templates (statements, capital calls, task alerts …) start as PENDING: when Meta / 360dialog approves one, open Settings › WhatsApp templates and set it to Approved – until then those WhatsApp messages show FAILED "template needed" (e-mail and in-app still go) | ✔ Owner confirmed 22 Nov 02:18 ("ok") |
| B24 | R-G_CORE-6 / 34 | A new task now needs proof by default (the spec's rule); only a Partner or Manager can switch proof off, with a reason. A task CSV import tells nobody unless "Tell each owner on WhatsApp now" is ticked (the tasks still show in the digest) | — |
| B25 | R-G_CORE-16 / 17 / 23 | Partners now see Team & settings (absence, delegation, SLA categories). Only a Partner sets delegations and changes categories; a Manager sets absences. A delegation with "ALL" also lets the person decide money approvals | — |

## C. Suggested defaults written in the specification (220 lines)

Each line already works with the default shown. Answer only where you want something else.


### Stock/Warehouse (33)

| Line | Rule | Default (question) |
|---|---|---|
| MR-0525 | RULES_H_WAREHOUSE R-H-1, R-H-11 | Shelf stock = SKU + bin + qty, no owner; ownership stays in the lot ledger; Σ bins = Σ lots per SKU; owner chosen at Month Confirm; optional "segregated owner" list |
| MR-0526 | RULES_H_WAREHOUSE R-H-16, R-H-25 | Reserve at SKU (+ shop owner rule) level at intake; bin chosen when the pick list is built |
| MR-0527 | RULES_H_WAREHOUSE R-H-7 | Simple and Full presets on Settings (zones, putaway step, batch picking, replenishment are switches) |
| MR-0528 | RULES_H_WAREHOUSE R-H-51 | One phone Work screen with tabs and one scan box |
| MR-0529 | RULES_H_WAREHOUSE R-H-24 | Dispatch-by from the marketplace profile's handling days + a working calendar per country |
| MR-0530 | RULES_H_WAREHOUSE R-H-33 | Tracking back by the profile's API when present, else its CSV upload |
| MR-0531 | RULES_H_WAREHOUSE R-H-29, R-H-30 | Pack step takes weight, suggests box from the product pack and service from carrier rules; packing materials as stock optional (default off) |
| MR-0532 | RULES_H_WAREHOUSE R-H-32, R-H-55 | Void / cancel a printed label (postage refund where the carrier allows), reprint with reason, alert "printed but not handed over" |
| MR-0533 | RULES_H_WAREHOUSE R-H-34 … R-H-36 | Handover supports collection and drop-off, partial handover, end-of-day manifest per carrier profile, lost-parcel case and claim |
| MR-0534 | RULES_H_WAREHOUSE R-H-47 | Automatic counts on short pick, bin reaching zero, negative attempt, no room; plus ABC schedule |
| MR-0535 | RULES_H_WAREHOUSE R-H-8 | One warehouse may hold stock of several companies and shops; data scope everywhere |
| MR-0536 | RULES_H_WAREHOUSE R-H-39 … R-H-41 | Returns graded → home / RETURNS / QUARANTINE by setting; supplier-fault → supplier claim on the GRN; refund link |
| MR-0537 | RULES_H_WAREHOUSE R-H-13 | Negative stock always blocked (no setting); "Found stock" adjustment instead; no warehouse action moves ownership |
| MR-0538 | RULES_H_WAREHOUSE R-H-3, R-H-14 | Is stock in UNASSIGNED sellable after go-live? → Yes (WH_UNASSIGNED_SELLABLE = Y) |
| MR-0539 | RULES_H_WAREHOUSE R-H-3 | Is stock in RECEIVING sellable? → No (WH_RECEIVING_SELLABLE = N) |
| MR-0540 | RULES_H_WAREHOUSE R-H-33 | When is tracking sent to the marketplace? → At handover (tracking_upload_when = ON_HANDOVER per profile) |
| MR-0541 | RULES_H_WAREHOUSE R-H-37 | What happens to own-stock lines with no ship record at Confirm? → ASK with one-click "Book as shipped now" (WH_UNSHIPPED_AT_CONFIRM) |
| MR-0542 | RULES_H_WAREHOUSE R-H-39 | Where does a new, unopened return go? → Home bin (WH_RETURN_NEW_TO = HOME), as R-D-88 |
| MR-0543 | RULES_H_WAREHOUSE R-H-39 | Where does an opened, resellable return go? → RETURNS for a check (WH_RETURN_OPENED_TO) |
| MR-0544 | RULES_H_WAREHOUSE R-H-46 | Which lots take a written-off loss? → FIFO (WH_LOSS_LOT_RULE = FIFO, as R-D-47); PRO_RATA by owner available |
| MR-0545 | RULES_H_WAREHOUSE R-H-46 | Count variance needing approval → value ≥ 100 in base currency (WH_COUNT_APPROVAL_VALUE) or ≥ ADJUST_APPROVAL_QTY units |
| MR-0546 | RULES_H_WAREHOUSE R-H-46 | Recount threshold → 2 units or 5 % (WH_RECOUNT_THRESHOLD_UNITS / _PCT) |
| MR-0547 | RULES_H_WAREHOUSE R-H-46 | Days before MISSING units become a write-off task → 30 (WH_MISSING_WRITE_OFF_DAYS) |
| MR-0548 | RULES_H_WAREHOUSE R-H-16 | Reservation trigger and expiry → at intake, no expiry (WH_RESERVE_ON = INTAKE, WH_RESERVE_EXPIRY_HOURS = 0) |
| MR-0549 | RULES_H_WAREHOUSE R-H-25 | Pick strategy → HOME_FIRST (WH_PICK_STRATEGY) |
| MR-1370 | 20_Warehouse/BRIEF_REVIEW.md §1 | kitting is left for later (bundle map covers selling sets) |
| MR-1451 | PHASE_03 §3.1 | FIFO tie-break (F-18): same received date → lower lot id first; lots of one GRN line created largest share first (equal shares: lower agreement id). |
| MR-1460 | R-H-29 | Box sizes (F-34): 5 template boxes (2 mailers, 3 boxes) with inner sizes, max weight and tare – replace with the real boxes. |
| MR-1465 | SCENARIO_13 §7b | Count step values (F-20): Scenario 13 §7b uses the seeded thresholds (recount above 2 units; approval above 100 base currency or 50 units). |
| MR-1468 | 19_Old_Tool_Audit/COVERAGE_ROUND_v1.7.md §3 | agree ""Cover pool shortages" switch (company)" is not stored |
| MR-1471 | 19_Old_Tool_Audit/COVERAGE_ROUND_v1.7.md §3 | agree "Typed stock per location / per variation (v1.1 product form)" is not stored |
| MR-1480 | R-F_SETUP-51 | The location manager gets that location's stock tasks only when no other owner rule applies (R-F_SETUP-51). |
| MR-1511 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task STK_FC_DIFF raised when: Fulfilment centre / 3PL received qty ≠ shipped carton qty, or FC stock report ≠ ledger at that location |

### Setup (28)

| Line | Rule | Default (question) |
|---|---|---|
| MR-0471 | R-F_SETUP-4 | Accountancy: "Due in 7 days or less" shows amber (7-day window)? |
| MR-0474 | R-F_SETUP-15 | A failing dashboard card raises one admin alert per card per day? |
| MR-0475 | R-F_SETUP-16 | Some dashboard cards are off by default for the VA, SOURCING, CS and STOCK roles? |
| MR-0476 | R-F_SETUP-21 | Sourcing dashboard "Due in 7 days" uses a 7-day window? |
| MR-0477 | R-F_SETUP-27 | Report filter: a From date after the To date is swapped automatically? |
| MR-0478 | R-F_SETUP-29 | The report row "Hidden margin" is shown to the Owner only (old: every partner saw it)? |
| MR-0480 | R-F_SETUP-35 | Company country is required? |
| MR-0481 | R-F_SETUP-35 | Director shares may not add up to more than 100 %? |
| MR-0482 | R-F_SETUP-35 | UTR = 10 digits (spaces removed)? |
| MR-0483 | R-F_SETUP-35 | Companies House authentication code = 6 letters / digits, upper case? |
| MR-0484 | R-F_SETUP-35 | HMRC activation code = 12 letters / digits, upper case? |
| MR-0485 | R-F_SETUP-35 | Company VAT number (optional): format check GB + 9 or 12 digits? |
| MR-0486 | R-F_SETUP-35 | Company EORI number (optional): format check GB + 12 digits? |
| MR-0487 | R-F_SETUP-35 | Company ISO code (our short code) is required and unique? |
| MR-0488 | R-F_SETUP-35 | Company registration number is required, 8 characters? |
| MR-0489 | R-F_SETUP-35 | Company telephone 1 is required? |
| MR-0490 | R-F_SETUP-35 | Company e-mail 1 is required? |
| MR-0491 | R-F_SETUP-35 | Company address is required? |
| MR-0492 | R-F_SETUP-38 | Directors 1–3 and the PSC list are picked from parties with role OWNER, DIRECTOR or PARTNER? |
| MR-0493 | R-F_SETUP-39 | All other required / unique / format checks listed in R-F_SETUP-39? |
| MR-1459 | 009 §S9 | Example profiles (F-25 / F-11): company-0 templates (codes EX_…, names "(example)"), run with 009 on every database instead of a separate test-only file; bank examples named by layout, not by bank. |
| MR-1462 | 009 §8, §9 | Currency / time-zone columns (F-22 / F-23): currency columns become NULL-able with no default (service fills BASE_CURRENCY); person time zone empty = COMPANY_TIMEZONE. |
| MR-1467 | 19_Old_Tool_Audit/COVERAGE_ROUND_v1.7.md §3 | agree "Persons with significant control (company)" is not a separate list |
| MR-1483 | COVERAGE_ROUND_v1.7.md §4 | Seeded list wording: SUPPLIER_RATING A–D, CASE_CHANNEL (7 values), PAY_CYCLE (4 values), SUPPLIER_CERT (4 generic values), BRAND_REG_STATUS (5 values) – all editable lists. |
| MR-1524 | 06C_Task_Codes/06C_TASK_CODES.md §7 | HUB_TOLERANCE = 1.00 (hub totals vs file) |
| MR-1525 | 06C_Task_Codes/06C_TASK_CODES.md §7 | IMP_WAITING_HOURS = 24 |
| MR-1526 | 06C_Task_Codes/06C_TASK_CODES.md §7 | WA_FAIL_LIMIT = 5 WhatsApp failures before SYS_WA_FAILING |
| MR-1527 | 06C_Task_Codes/06C_TASK_CODES.md §7 | BANK_UNMATCHED_DAYS = 7 |

### Sourcing (27)

| Line | Rule | Default (question) |
|---|---|---|
| MR-0062 | R-B_SOURCING-64 | Supplier share link expiry 14 days |
| MR-0424 | R-B_SOURCING-3 | PO payment due dates come from the payment-terms template; without one, deposit due = PO date and balance due = expected ready date? |
| MR-0425 | R-B_SOURCING-5 | Owner entity is required on a PO (old tool allowed empty)? |
| MR-0426 | R-B_SOURCING-8 | Local purchase payment due date = order date + 30 days (editable)? |
| MR-0427 | R-B_SOURCING-13 | Old-entry import reads up to 5 paid-amount and 5 freight-cost column sets per entry, once? |
| MR-0428 | R-B_SOURCING-26 | Task SRC_SHIP_ARRIVING when the ETA is within 3 days (SRC_SHIP_ARRIVING_DAYS = 3), priority NORMAL? |
| MR-0429 | R-B_SOURCING-26 task table + 06C_task_codes.csv | Task SRC_OVER_ALLOC (units unallocated or over-allocated) with priority NORMAL? |
| MR-0430 | R-B_SOURCING-26 task table + 06C_task_codes.csv | Task SRC_STEP_MISSING (a cycle stage before the current one has no record) with priority NORMAL, to the cycle owner? |
| MR-0431 | R-B_SOURCING-26 task table + 06C_task_codes.csv | Task SRC_DOC_MISSING (required document not linked, or Drive link broken) with priority NORMAL? |
| MR-0432 | R-B_SOURCING-26 task table + 06C_task_codes.csv | Task STK_NO_LOCATION (stock but no location holds it) with priority NORMAL, plus a Month Close check? |
| MR-0433 | R-B_SOURCING-26 task table + 06C_task_codes.csv | Task STK_NO_COST (stock without a cost) with priority NORMAL to the Partner, plus a Month Close check? |
| MR-0434 | R-B_SOURCING-26 task table + 06C_task_codes.csv | Task STK_LEDGER_MISMATCH (product stock differs from the sum of locations) with priority HIGH, plus a Month Close check? |
| MR-0435 | R-B_SOURCING-27 | Supplier payment reminder 3 days before the due date (SRC_PAY_REMIND_DAYS = 3), task on the due date? |
| MR-0436 | R-B_SOURCING-44 | Landed cost: insurance, origin, destination, last-mile, FEE, COST, agent fee and other costs are split by value? |
| MR-0437 | R-B_SOURCING-45 | A manual landed-cost override more than 10 % away from the computed cost needs Owner approval (LC_OVERRIDE_APPROVAL_PCT = 10)? |
| MR-0438 | R-B_SOURCING-46 | Landed cost re-runs by itself 5 minutes after a new freight / customs / GRN row (unless it is FINAL)? |
| MR-0439 | R-B_SOURCING-51 | Sourcing setting defaults: LC_OVERRIDE_APPROVAL_PCT = 10 and SRC_PAY_REMIND_DAYS = 3 (money tolerance 0.009 and QC green 95 keep the old values)? |
| MR-0440 | R-B_SOURCING-54 | An old entry needs supplier, order date, order reference, at least one line and an owner? |
| MR-0472 | R-F_SETUP-10 | Customs stage needs the C88 / customs entry document? |
| MR-0473 | R-F_SETUP-14 | If the importing company is not VAT-registered, import VAT is added to landed cost? |
| MR-0479 | R-F_SETUP-34 | Sourcing rule tasks run every 15 minutes and on save of the related record? |
| MR-0514 | R-B_SOURCING-58 (proposed – owner to confirm) | How is sample cost shared between shops? → proposed: By 90-day sales share of the product per shop (booked once as shop expense) |
| MR-0518 | R-B_SOURCING-63 | What happens when a supplier claim is settled (credit / refund)? → proposed: Negative cost on the product + new landed-cost version |
| MR-1457 | 009 §S2 | Approval rule roles (F-07): SRC_OLD_ENTRY → PARTNER, SRC_OVERPAY and SRC_LC_OVERRIDE → OWNER; risk MEDIUM; no phone check. |
| MR-1464 | 07_ICC §3 (F-03) | ICC stages (F-03): ordered = PO qty − produced; production = produced − shipped; transit = shipped − received. |
| MR-1478 | R-B_SOURCING-66 | Supplier rated D shows a red chip on PO / RFQ pickers but does not block (R-B_SOURCING-66). |
| MR-1515 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task SRC_OLD_ENTRY_REJECTED raised when: Old entry (historical purchase) approval rejected – needs correction |

### Core/Platform (24)

| Line | Rule | Default (question) |
|---|---|---|
| MR-0023 | R-G_CORE-99 | Search synonyms seed (13 words incl. three Roman-Urdu words) and amount match tolerance ± 0.5 % |
| MR-0377 | registry t_enterprise | t_enterprise form is a quick view of the company master; the tenant row mirrors the company marked f_tenant_main |
| MR-0495 | R-G_CORE-11 | Task parent / child loop check up to 10 levels? |
| MR-0496 | R-G_CORE-15 | Undo of a task cancel within 10 minutes? |
| MR-0498 | R-G_CORE-31 | Search starts at 2 characters (old: 1)? |
| MR-0500 | R-G_CORE-56 | Search: recently opened pages ranked higher, records shown after pages? |
| MR-0501 | R-G_CORE-59 | Opening a secret asks for 2FA once per session and again after 15 minutes idle; every open is logged? |
| MR-0503 | R-G_CORE-66 | List page sizes 25 / 50 / 100 / 250? |
| MR-0504 | R-G_CORE-75 | Clean-up job nightly at 02:30 company time; import uploads kept 1 day, generated files the same day only? |
| MR-0505 | R-G_CORE-83 | Shop health thresholds per marketplace (example eBay-type: defect rate ≤ 0.5 % green, ≤ 2 % amber; late shipment ≤ 3 % / ≤ 7 %)? |
| MR-0506 | R-G_CORE-91 | HS code is typed: digits only, 6–10 digits? |
| MR-0507 | R-G_CORE-91 | ISP code is typed: letters / digits, up to 20? |
| MR-0552 | RULES_H_WAREHOUSE R-H-20, R-H-31, R-H-33 | API delivery order of marketplaces / carriers → Owner setting api_priority on each profile – no fixed order in the spec |
| MR-1293 | 00_START_HERE.md working rule 1 | write the word "final" for this spec – no program code before it |
| MR-1303 | 02_Decisions.md D3 | confirm in writing before Phase 2: The 20–25 everyday things (list in Phase 8); big forms open in full with the context carried over |
| MR-1304 | 02_Decisions.md D4 | confirm in writing before Phase 2: Always require quantity, unit cost, owner, date, company (+ product) |
| MR-1315 | 19_Old_Tool_Audit/CODE_ROUND_KICKOFF.md "Scope of this round" | name the modules of Round 1 |
| MR-1329 | 19_Old_Tool_Audit/CODE_ROUND_KICKOFF.md P0 | the six measures F A C E S K (Function, Automation, Connection, Ease, Suggestions, Correctness) and the 0–10 scale; scoring is done in the test round |
| MR-1477 | 19_Old_Tool_Audit/COVERAGE_ROUND_v1.7.md §3 | agree "Reports_model (37 functions), T_account::runDbChanges, T_carriers::add1, T_product_vendor::inventory_data_v1, T_sale_order::removev1, 50 functions of copy controllers, 30 copy / backup views"… |
| MR-1505 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task SYS_API_SYNC raised when: API sync run failed or connection expired |
| MR-1506 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task DQ_ITEM_NOTE raised when: Sale line with an item note, not local and no vendor |
| MR-1507 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task DQ_PARENT_INACTIVE raised when: Active child variant whose parent is inactive |
| MR-1508 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task SYS_ERROR raised when: Nightly step, worker listener, dashboard card or registry load failed; unknown event refused; no task owner found |
| MR-1514 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task SYS_WA_FAILING raised when: WhatsApp sends failing WA_FAIL_LIMIT (5) times in a row for a provider |

### Products (21)

| Line | Rule | Default (question) |
|---|---|---|
| MR-0047 | R-D-90 | Product Pack page shows t_packings / t_carrier_packages read-only – no merge of the rate tables into packaging (M47 asked to merge) |
| MR-0056 | R-G_CORE-108 | DQ_TASK_BELOW_PCT = 60; required fields count double in the score |
| MR-0136 | R-D-89 | Packaging box types RETAIL / MAILER / INNER / PALLET (outer carton stays on the product) |
| MR-0139 | R-D-91 | CERT_REMIND_DAYS = 60; PRD_CERT_EXPIRY is a new code in category COMPLIANCE, priority NORMAL, owner product owner → MANAGER |
| MR-0456 | R-D-1 | Product form: opening stock and min level must be 0 or more, landing price 2 decimals and 0 or more? |
| MR-0457 | R-D-2 | SKU look-alike warning ignores case, treats spaces / underscores as "-" and ignores leading zeros? |
| MR-0458 | R-D-5 | Colour items get a hex value for the swatch (you give the hex values)? |
| MR-0459 | R-D-22 | Price guard: target margin 0 by default (PRICE_TARGET_MARGIN); a price below the floor gives a warning and task PRICE_BELOW_FLOOR? |
| MR-0460 | R-D-23 | New products take packing and storage fees from a company 3PL tariff, so they are never £0? |
| MR-0461 | R-D-25 | Stock ownership chip words: JOINT (2 or more investors), HOLDING (1), CENTRAL (pool), PRIVATE? |
| MR-0462 | R-D-30 | A price-change reason is required when an existing price period is closed? |
| MR-0463 | R-D-41 | Stock adjustments above how many units need approval (ADJUST_APPROVAL_QTY)? Give the number. |
| MR-0464 | R-D-46 | Stock alerts page: a reason is required on every stock update (old tool: optional)? |
| MR-0465 | R-D-59 | Listing import: "Variation details" is optional? |
| MR-0466 | R-D-80 | Investor ids that match no company go to the review list "Unmatched investor ids" (never guessed as a person)? |
| MR-0467 | R-D-82 | Invoice VA lookup uses the job-history row valid on the month end? |
| MR-0468 | R-D-84 | Invoice reopen / cancel also covers documents where the party is issuer or recipient? |
| MR-0967 | RULES_D R-D-13 | Tags: one multi-select (any number); old Tag 1–3 become read-only copies of the first three; colour picker on hex fields, no new field type |
| MR-1469 | 19_Old_Tool_Audit/COVERAGE_ROUND_v1.7.md §3 | agree "Brand tagline, colours, brand categories" is not stored |
| MR-1473 | 19_Old_Tool_Audit/COVERAGE_ROUND_v1.7.md §3 | agree "t_product_vendors.fk_id_role2/3, fk_id_role_account2/3" is retired |
| MR-1474 | 19_Old_Tool_Audit/COVERAGE_ROUND_v1.7.md §3 | agree "t_product_vendors.carrier (free text)" is retired |

### Finance (21)

| Line | Rule | Default (question) |
|---|---|---|
| MR-0338 | R-D-95 | T_inv_settings kept while the INVESTOR_INVOICING route exists; retired into Finance agreements only on the owner's word |
| MR-0469 | R-FIN-14 | Capital-call bank matching uses a date window of ± match_payout_days? |
| MR-0470 | R-FIN-25 | "Upload needed" warning: settlement file older than 17 days, bank file older than 8 days – same rule for other report types? |
| MR-0510 | R-A_SALES-31/65 | Are shop expenses taken before or after the VA share? → proposed: After the VA share (setting VA_EXPENSES_BEFORE_SHARE = N) |
| MR-0515 | R-B_SOURCING-63 | Where do import freight and duty sit before goods sell? → proposed: Balance sheet (FIN_CATEGORY IMPORT_FREIGHT / IMPORT_DUTY), reach profit through COGS |
| MR-0517 | 006 TIKTOK_SETTLEMENT report type | Which TikTok file is the money (settlement) file? → proposed: Use the TikTok settlement / statement export per payout as report type TIKTOK_SETTLEMENT; fill mappings once a real file is supplied |
| MR-0520 | 02B_Owner_Decisions_ADD_v1.7.md open items | One real TikTok settlement / statement export (no customer data needed – remove buyer columns) to build the mapping and fee buckets |
| MR-1301 | 02_Decisions.md D1 | confirm in writing before Phase 2: Investors own lots (specific units). Profit follows the units sold from their lots |
| MR-1306 | 02_Decisions.md D6 | confirm in writing before Phase 2: FIFO everywhere (Finance already uses FIFO). The WAC screen stays as a report from the same lots |
| MR-1326 | 10_Tests_and_Acceptance.md §D4 GT-1 | default chosen in the fix round: GT-1…GT-6 golden-truth tests on copies of the team-account data (inputs, pass rule); exact figures taken from the source at test time + which folder copy and which month each GT test uses; exact figures read from the source at test time |
| MR-1333 | R-A_SALES-83 | the 0.50 claim-row fee credit assumption (old 39.91 = 39.41 + 0.50) – confirm when GT-2 runs on the real August transaction report |
| MR-1453 | R-A_SALES-83 | Claim fee credits outside selling costs (F-27): bucket CLAIM_FEE_CREDIT, not in selling costs nor in marketplace profit; verify on Jan–Jul (GT-1 … GT-4). |
| MR-1454 | R-FIN-40 | Month Close sales source (F-29): profiles with a month_total_rule use the orders-file KPI; their refunds are inside the hub figures (no separate refund line). |
| MR-1461 | 009 §S1 | Finance setting values (F-32): the R-FIN-6 defaults (free_cash_alert_limit 10000, settlement_overdue_days 17, bank_overdue_days 8, large_fee_gbp 250, slow_days 90, dead_days 180, agreement_ending_day… |
| MR-1463 | R-ACC-5 | Confirmation statement carry-over (F-13): an old company-form date is copied once into an empty Hub date; afterwards only the Hub is edited. |
| MR-1481 | R-F_SETUP-51 | Repaired option lists use the old database defaults: VAT returns quarterly (Q), stagger 1, payroll monthly (M), split modes SHARE, tax treatment NA (R-F_SETUP-51). |
| MR-1482 | R-FIN-73 | Profit roles of the 9 seeded finance categories: FREIGHT and PLATFORM_FEE variable; SUBSCRIPTION and OFFICE fixed; PPC, SUPPLIER_REFUND, TAX_RESERVE none; INTERCOMPANY transfer (R-FIN-73). |
| MR-1504 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task FIN_APPROVE raised when: Maker-checker request (payment, withdrawal, stock adjustment, expense, refund outside, check waiver, unlock, merge) |
| MR-1512 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task FIN_CASH_LOW raised when: FA1 free cash < company free_cash_alert_limit |
| MR-1516 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task INV_PARTY_INCOMPLETE raised when: Generate refused or warned: invoice party missing print details (R-INV-14) |
| MR-1531 | 06B IC-7 | which 5 close checks may be waived |

### Sales (20)

| Line | Rule | Default (question) |
|---|---|---|
| MR-0072 | R-A_SALES-89 | CUSTOMER_DATA_RETENTION_MONTHS = 24 (buyer name blanked after) |
| MR-0412 | R-A_SALES-1 | When a sales batch is replaced, show a diff of old against new lines and keep the old batch restorable from the recycle bin for 30 days? |
| MR-0413 | R-A_SALES-18 | Raise task SAL_MONTH_MISSING when an active shop has no sales batch by day 5 of the next month (now read from the marketplace profile overdue_days, #110)? |
| MR-0414 | R-A_SALES-29 | May the Owner switch the VA share basis to "actual" from the next month on? |
| MR-0415 | R-A_SALES-40 | Deleted sales batches and lines go to the recycle bin for 30 days? |
| MR-0416 | R-A_SALES-46 | Sales report export to Excel / CSV up to 20,000 rows (old tool had only PDF and Word)? |
| MR-0417 | R-A_SALES-51 | Send the annual sales report automatically on day 6 of each month by e-mail / WhatsApp? |
| MR-0418 | R-A_SALES-53 | Bulk delete of sales lines: Owner only, and a second approval when more than 500 lines? |
| MR-0419 | R-A_SALES-63 | Price tolerance in the sales / purchase audit = £0.01? |
| MR-0420 | R-A_SALES-64 | A dropship hold order not used after 14 days raises task DS_HOLD_UNUSED (DS_HOLD_DAYS = 14)? |
| MR-0421 | R-A_SALES-68 | Shop codes are upper case with 2–10 letters / digits? |
| MR-0422 | R-A_SALES-71 | VA reminder thread: reply-by = 1 day after the reminder? |
| MR-0516 | mp_profiles.overdue_days (005) | When is a missing month file "overdue"? → proposed: Per marketplace profile (overdue_days, proposed 5 days after month end; money files also wait money_lag_days) |
| MR-1455 | R-A_SALES-86 | Refund kind "To classify" (F-17) when neither the line nor the money report has a sale amount. |
| MR-1458 | 06B IC-0.2 | Payout words (F-24): PAYOUT_RULE = PER_PAYOUT_LINE / NET_OF_RESERVE / DAILY_SWEEP; old cycle words moved to payout_cycle; example profiles: eBay-type money ROLLING, TikTok-type PER_PAYOUT and hub tot… |
| MR-1470 | 19_Old_Tool_Audit/COVERAGE_ROUND_v1.7.md §3 | agree "Listing content: bullets, search terms, marketplace category id, free shipping, business price, promoted %" is not stored |
| MR-1503 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task SAL_NO_COST raised when: Sale line of the month with cost_source = missing |
| MR-1509 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task LST_STATUS raised when: Listing status disagrees with stock (ACTIVE while available = 0, or ENDED by import while available > 0) |
| MR-1517 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task IMP_WAITING raised when: Import batch stuck before Apply (detected / mapped / checked) longer than IMP_WAITING_HOURS (24) |
| MR-1532 | 06B IC-0.2 (money_lag_days) | TikTok money lag 14 days; eBay lag 3 days and overdue 5 days |

### Accounts/People (19)

| Line | Rule | Default (question) |
|---|---|---|
| MR-0441 | R-C-4 | Person numbers: the managed series PERSON uses the same next number without a suffix? |
| MR-0442 | R-C-26 | Mobile / SIM required fields = the stand-alone set without device and address, plus capacity? |
| MR-0443 | R-C-30 | A marketplace account needs company, owner, payout bank + account, payment bank + account and payout mode? |
| MR-0444 | R-C-39 | Job history: salary currency defaults to the currency of the current country (GBP last)? |
| MR-0445 | R-C-39 | Job history share %: a number from 0 to 100 in steps of 0.5? |
| MR-0446 | R-C-39 | Share % and £ rate become numbers at migration; text that is not a number becomes empty and is listed on a review report? |
| MR-0447 | R-C-40 | Job history: contract renewal shows amber when it is 30 days or less away? |
| MR-0448 | R-C-41 | Shop assignment: "from" date defaults to today? |
| MR-0449 | R-C-42 | Shop pickers show every shop (inactive ones greyed), without needing an assignment? |
| MR-0450 | R-C-46 | Bank card expiry reminder 30 days before (to the person, copy to Finance)? |
| MR-0451 | R-C-46 | Credit card bill reminder 5 days before (to Finance)? |
| MR-0452 | R-C-46 | SIM recharge reminder 3 days before? |
| MR-0453 | R-C-46 | Contract renewal reminder 30 days before (to the Owner)? |
| MR-0454 | R-C-46 | Salary date reminder 3 days before (to Finance)? |
| MR-0455 | R-C-46 | Drop the unused old link columns after migration? |
| MR-1302 | 02_Decisions.md D2 | confirm in writing before Phase 2: One party list (t_account) for people and companies; supplier = role 24 + a supplier profile |
| MR-1466 | 19_Old_Tool_Audit/COVERAGE_ROUND_v1.7.md §3 | agree "Gender on the person" is not stored |
| MR-1479 | R-C-52 | Probation end raises a due item 14 days before (R-C-52); one active employment row per person. |
| MR-1521 | 06C_Task_Codes/06C_TASK_CODES.md §2 | SUPERVISOR becomes TL |

### Tasks (12)

| Line | Rule | Default (question) |
|---|---|---|
| MR-0494 | R-G_CORE-2 | New task form: offer "give it to the person with the fewest open tasks" as the owner? |
| MR-0497 | R-G_CORE-28 | Task comments up to 2,000 characters? |
| MR-0499 | R-G_CORE-45 | New task form: Amount £ and Confidential for the Partner, "Tell on WhatsApp now" switched on? |
| MR-0502 | R-G_CORE-65 | Password reset link valid for 30 minutes? |
| MR-0511 | R-G_CORE-94 | Above what amount is a money task URGENT? → proposed: £1,000 in base currency (setting TASK_URGENT_MONEY) for 6 codes |
| MR-0512 | R-G_CORE-97 | How long does "Close with reason" snooze a rule task? → proposed: 7 days (setting rule_snooze_days) |
| MR-0551 | 06C §9 | WH_PARCEL_LOST as a money code → Yes: claim amount > TASK_URGENT_MONEY → URGENT (7th money code) |
| MR-1513 | 06C_Task_Codes/06C_TASK_CODES.md §5 | new task ACC_DUE_ITEM raised when: Due item from sys_due_sources reaches remind days (SIM recharge, card expiry, card bill, contract renewal, salary date, subscription, IP renewal) |
| MR-1518 | 06C_Task_Codes/06C_TASK_CODES.md §4–§5 | accept the 36 task code names written during the rebuild |
| MR-1519 | 06C_Task_Codes/06C_TASK_CODES.md §4 | the three renamed task codes |
| MR-1520 | 06C_Task_Codes/06C_TASK_CODES.md §3 | the SLA values of the 16 categories (e.g. LABELS due 0 days, ack 4 h) |
| MR-1530 | 06_ENGINES §D, §D-merge, §D-add, §D-fix | event renames are naming choices only; STOCK_CONSUMED stays a current event |

### Labels/Printing (6)

| Line | Rule | Default (question) |
|---|---|---|
| MR-0050 | R-D-92 | Country of origin default = the supplier's country |
| MR-0132 | 18_Labels v1.7 §12 | Label sheet presets (margins / gaps of the 4-, 8- and 21-up A4 sheets) |
| MR-0550 | 18_Labels §11 | Packing slip shows the buyer address? → No (WH_PACKSLIP_SHOW_ADDRESS = N) |
| MR-1393 | 18_Labels v1.7 §13.4 | printer model from the sticker and the roll size by one test print (96 × 136 or 100 × 150) |
| MR-1394 | 18_Labels v1.7 §13.4 | suggested print routes for the A4 printer |
| MR-1456 | 18_Labels §4 | Label status map (F-16): TO_SHIP → NONE, LABEL_READY → READY, SHIPPED → UPLOADED, CANCELLED → VOID; reprint DAMAGED_LABEL → LABEL_DAMAGED, LOST → LOST_BEFORE_DISPATCH. |

### Security (5)

| Line | Rule | Default (question) |
|---|---|---|
| MR-0041 | R-C-49 | IP_ALLOWLIST_MODE = OFF; SESSION_IDLE_MINUTES = 120 |
| MR-0513 | R-G_CORE-98 | How long is a 2FA check valid for sensitive actions? → proposed: 60 minutes, then asked again |
| MR-1305 | 02_Decisions.md D5 | confirm in writing before Phase 2: Kept in a password manager (e.g. Bitwarden). The tool keeps only a vault_ref link |
| MR-1472 | 19_Old_Tool_Audit/COVERAGE_ROUND_v1.7.md §3 | agree "Supplier portal login / password, shop password-manager link" is vault only |
| MR-1475 | 19_Old_Tool_Audit/COVERAGE_ROUND_v1.7.md §3 | agree "CVV / PIN columns (9)" is emptied in Phase 0, dropped |

### Migration (4)

| Line | Rule | Default (question) |
|---|---|---|
| MR-1316 | 19_Old_Tool_Audit/CODE_ROUND_KICKOFF.md "Only things the owner still provides" | say where the core/ and assets/ folders are, or that they are in Drive |
| MR-1452 | R-H-14 | Migration partial placement (F-15): old shelf rows are placed only through a migration-map row; no map → all to UNASSIGNED. |
| MR-1476 | 19_Old_Tool_Audit/COVERAGE_ROUND_v1.7.md §3 | agree "t_product_vendors_temp, t_import_options, csv_generator_options, t_products_listed" is retired |
| MR-1484 | R-C-51 | Old ID columns: when no photo-ID type of that kind exists for the company, the migration lists the person for the Owner – no ID type is seeded (R-C-51). |
