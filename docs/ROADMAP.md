# MoreOrderz – plan and progress

The single list of everything: what is done, what is being built, what comes next, what was discussed, and what waits for the owner.
It lives in the code (docs/ROADMAP.md) and opens in the tool under Home › Plan & progress. Every batch updates it before it is deployed.
Nothing the owner asked for may be dropped from this list. A line only leaves "Next" or "Discussed" by moving to "Done", with its date.

Updated: 27 Nov 2026, 04:15 (Pakistan time)

## The owner's standing rules (every screen, every batch is checked against these)
0. **Better than the source (owner 27-11 02:42).** Whatever comes from the old tool or MY TOOL is not just copied: it becomes easier, dynamic, smart and improved – and it fits inside this system, connected to the rest, never something apart.
1. **Everything is connected.** Stock ↔ inventory ↔ partners ↔ buying ↔ sourcing ↔ selling ↔ money. Nothing is a screen on its own: every figure comes from the one ledger and setup behind it, and every action writes there. If something is not connected, it is not finished.
2. **Everything comes from the centre (setup).** Every list, value and default lives once in the central setup and reaches every place that needs it by itself. Nothing is added screen by screen.
3. **Every dropdown:** search, suggestions, used lately first, All, multiple, Other / + Add (saved to the central list), a default, and help.
4. **Automatic first, manual least.** The system fills what it knows, with suggestions. Where it can do a step, it asks "shall I do it?" – one press, and it is done (import, create, book, link).
5. **Never stuck.** No error stops the work. A step shows what is still needed and offers the fix right there.

## Now being built
- ✅ 27-11 first part built: **Packaging / cartons, smart.** Compare MY TOOL and the old MoreOrderz, take the better one and go further: from the product's size and weight the system works out carton, CBM, volumetric weight, units per carton and the best box by itself; packaging data reaches product, listing, PO, shipment and labels by itself – changed once, changed everywhere.
  Built 27-11: one Shipping & packaging section on the product (item, retail pack, master carton, with units; a child takes its parent's values); worked out by itself: CBM, air / courier volumetric weight, net / gross per carton, CBM per unit, carton fill %, the best carrier box, full + tail cartons for any quantity; product page Packaging card; PO page Packing of every line. Still to do: shipment and FBA carton totals from it, listing shipping weight / size per marketplace, carton marks from it, reading the supplier's packing list into the product.
- ✅ 27-11 first part built: **Product asset vault ("Vector").** Every product has its files in order: product images (main, white background, lifestyle), listing images per marketplace, Amazon A+ / A++ content, labels (FNSKU, barcode, carton), packaging files (dieline, vector artwork .ai / .pdf, carton marks), certificates, manuals. Every file that comes in (Import anything, a supplier, Drive) is recognised and put in the right place of the right product by itself; one click on a product shows the whole vault.
  Built 27-11: the Vault on every product page (15 places); a file dropped there, filed from the product, or coming through Import anything with the SKU in its name goes to its place by itself (name, type and words decide – main, lifestyle, listing per marketplace, A+, FNSKU, dieline, vector artwork, carton marks, certificates, manuals); move a file, make it the first, take it out; the vault says what is missing (main image, listing images of each marketplace it is on, A+ and FNSKU for Amazon FBA, artwork for its own box). Still to do: Drive folder per product, images straight into the listing files, share the vault with a designer / supplier by link.
- **Inventory (owner approved the design 26-11 23:14).** Six parts, built in the steps below:
  - (a) ✅ Old shop lines moved into listings (3,310 listings; 3,304 products made, each private to its shop; batch 1 can be undone).
  - (b) ✅ Inventory screen (Products & Selling › Inventory). One line per product:
    - photo · SKU · ASIN · parent › children
    - available · FBA · on the way · inbound · reserved · damaged
    - owner, Pool / Private, listings and prices
    - sold in 30 days · cover · value · age
    - opening a product shows its whole story
  - (c) ✅ FBA is a ledger location per Amazon shop (= per country), made by itself. FBA units are sold only by that shop, never counted as merchant stock. The two-step transfer to an FC already existed (reserve → pick → ship into in transit → receive at the FC).
  - FBA check: Amazon's report on live uses ASINs, and 31 ASINs are not linked to any product yet. Link each one once in FBA check (search the product → it becomes the Amazon listing); after that, "Book" fills FBA in the ledger.
  - (d) ✅ FBA check (Inventory › FBA check): Amazon's last report against the ledger, SKU by SKU, with the reason. "Book" brings the ledger to Amazon's figure – as found / lost stock, or as units moved in from a warehouse. ✅ 27-11: **Shop quantity check** (Inventory › Shop quantity check): each shop's listings against what we can really send – "shows more than we have" (orders we cannot send) first, then "shows less" (lost sales). The quantity comes from the shop's active listings report (the Available quantity column, read by the listings import); the fix is the shop's quantity file, one press. Live has no listing report with quantities yet, so lines show "not reported" until each shop's active listings file is loaded once.
  - (e) ✅ From a product's story: Move units, Change owner, Give to a shop, Pool / Private. Everything goes through the ledger.
  - (f) ✅ Who sees what: a person limited to shops sees only those shops' products – on Inventory, the product story, the stock list, the stock ledger, lots and a product's stock page (Inventory sharing already did this). Still to do: a partner sees his own lots and shares, which comes with the partner work below.

## Next (in this order)
1. **Partner / finance for each partner** – ✅ first part built 27-11: Partners & Investors › **Each partner**. Per partner:
   - share (agreement %, or by product: 1 ÷ investors on the product – the old tool's rule)
   - his stock: on hand, FBA, on the way, coming, value at raw price and at cost (Owner only)
   - sold in 30 days, settled months (revenue, profit, his share), invoices and payments, partner ledger
   - "His stock in Inventory" opens Inventory filtered to his products
   - a partner login sees only his own account
   Live 27-11 00:35: 12 partners / investors show with their products, stock and shares from the old data.
   27-11 00:45: the old investor numbers were company numbers. 85 product–investor links are now mapped to their company (Partners › Investor links). Each partner shows as "Company – directors %". The owner confirmed investor 28 = VPOPY LIMITED (B-TEST 1 and 3). Earlier finding: Found 26-11 23:55: on live there are 0 partner agreements, 0 capital entries and 0 withdrawals, and every stock lot shows "Company". The old tool's product ↔ investor links (t_product_investors, 85 lines) were kept only as history. Its partner settings must be read from the old database and turned into agreements, lot owners and opening balances. For every partner:
   - share %
   - profit and loss
   - payments both ways (what he gave and what he took)
   - his inventory: on hand, his share, coming in, going out, and its value
   - a statement
2. **Task management** – ✅ checked 27-11: every old task came in (2 tasks, 11 categories, 3 templates, 20 shop reminders, 629 order reminders; 2,319 tasks made by rules). The screens were complete but hidden under Team & Work, so **Tasks** is now its own menu group: My tasks, Board, Weekly, Templates, Absence calendar. Still to do: MY TOOL's tasks come over once the MY TOOL link key is pasted.
3. **Documents: "File a document" from anywhere** – ✅ 27-11: a "File a document" button on every record, PO, cycle and product page (and in each record's Documents). Drop a file, screenshot or link: it is read, its kind is guessed and that record is offered first with the reason; one press puts it there. Filed documents now also show in the PO and cycle document lists (before, they showed only on the record).
4. **Access card by area** – ✅ 27-11: every person's card (Setup › Roles and rights › Access cards) has 7 areas – Buying, Inventory & warehouse, Products & selling, Money, Partners, Tasks & team, Setup – each Edit / View only / Hidden, with "changed from the role" shown. A person who cannot open an area sees it on Home with **Ask the Owner** (one press; the Owner gets the request and answers it under Requests). Owner-only rights never move.
5. **Guide me: steps for more screens** – ✅ 27-11 (first round): Inventory / FBA check (unlinked FBA SKUs, lines differing from Amazon), Shop quantity check (shows more / less, no quantities yet → load listings), Import anything and Home (files waiting), Each partner / Investor links (director shares not 100 %), Roles and rights (access requests waiting). Every line has its Fix button. More screens follow as they are built.
6. Register lines still PARTIAL (98) or FORM ONLY (39).
7. Reports: "every figure can be clicked down to its record, file and row" (MR-1285).
8. Label designer for our own labels: product, carton, FNSKU, pallet (MR-0134).
9. ICC: WhatsApp digest (MR-0060); an ICC step inside Create of a product (MR-1324).
10. Decision engine: tests for REBALANCE_FBA and CAPITAL_CALL with real-shaped data.

## Old tool – still open (owner 27-11 01:30: "look inside my old MoreOrderz, put what is left in the checklist, finish it")
The whole list is on one screen: **Home › Old tool check** – every old screen (138 controllers of the old code) with its register lines, and every old table with how much of its data came over. 27-11 recheck against the code: many lines marked open were in fact already built (tax worksheet, carton calculation, BUY file, Amazon Business orders, Etsy month, task team settings, marketplace fields) – they are now marked built with their evidence (audit/recheck_27nov.csv). **45 lines are really open**, built in this order:
1. ✅ Corporation-tax worksheet – already built (TaxService). ✅ Carton calculation – already built (CartonCalc).
2. ✅ 27-11: country lists show pinned countries first (MR-1125). ✅ carrier presets inside a group – pin, drag order, default, remove, clean-up, where offered (MR-0930, 0982); location can never sit under itself (MR-0887, 0978); product manager role → product manager (MR-0976).
3. Product page as in the old tool: Supplier / Logistics tab with purchases, Marketplace fields tab per marketplace, change history with names – MR-0220, 0882, 0897, 0940, 0942, 0962, 0963, 0989.
4. ✅ Supplier BUY file and Amazon Business order file – already built (BuyFile, SupplierOrders).
5. Masters still as forms only: investor, investment, forwarder, local vendor card, product type / trend ISO code, country pin order, company list with directors – MR-0301, 0302, 0295, 0227, 0981, 0935, 1125, 1122.
6. Live money formulas on every sourcing step form – MR-0807; shipments with forwarder as a party – MR-0314; supplier payment reversal – MR-0305.
7. One-master work (people, suppliers, lists, sales, imports) – MR-1404, 1406, 1407, 1413, 1414, 1419; module / parameter framework – MR-1129.
8. ✅ Tasks: workload vs limit, SLA per category, absence cover, delegated approval, recurring – already built (TaskTeam, MR-1240).
9. Data: every old table counted against what came over (shows on Old tool check after the count on the server). Owner 27-11 01:34: every setup list of the old tool – brands, banks, companies, countries, currencies, ports and all the others – must come over completely; whatever is missing is brought to its place here. **Counted on live 27-11:** 199 old tables have rows; 177 came over completely (brands, banks, companies and every other setup list among them), old copies and tool settings are not needed, the old stock moves are done the new way (opening lots). ✅ **Live 27-11: still to bring = 0.** The last 4 old tables now have their place (mo:old-extras: 1 provider, 8 IP servers, 3 warehouses, 23 list values): IP / VPN servers and their provider → the IP accounts list (label, place, plan, provider kept); warehouses with addresses → the warehouse list and the address on the stock location; old lookup lists → central lists "Old list: …".
10. Owner 27-11 01:34 / 01:36: **Create** – ✅ 27-11: Create is built from the root: every registry form, plus every menu screen that makes something (New …, Import …, Record …, Move …), appears by itself – a new module needs nothing added by hand. Inside Create: search, used lately, ← Back, ↶ Undo, ↷ Redo, ✕ Clear, ⟳ Refresh, Enter opens the first match; a form opened from Create has ← Back and "Switch to…" another form without leaving.
11. Owner 27-11 01:34: **Universal search** – ✅ 27-11 (first part): a record is found however it is typed – "PO-QX-7731", "#PO-QX-7731", "poqx7731", "PO QX 7731", "qx-7731", "#7731", "PO7731"; records made by imports (not through a form) are put in the search every night, and on live once now. On live 64,547 records that were never in the search (made by imports) were added 27-11 – SKUs, lots, orders and the rest are now found. Still to check: documents' text, settings and every screen's own words.
12. Owner 27-11 01:34: everything connected – nothing is made on its own, apart from the rest.
✅ 27-11: product certificates now get a task 60 days before they end (MR-0139, was form only).

## Waiting for the owner
- Labels: which eBay shop the 25 Nov orders belong to.
- Exchange rates for CNY and USD on live (Guide me › Add rate).
- MY TOOL link: paste the key in MY TOOL (Settings › Link with MoreOrderz). The key is like a password, so the owner pastes it himself.
- Passwords: each person changes their own (My account › Change password).

## Discussed – keep in mind
- Import anything should take everything and lose nothing; same importer everywhere; screenshots read (OCR); Drive folders; it asks about what it does not know (create / skip / keep).
- Every dropdown everywhere: search, used lately first, All, multiple, + Add, default, help. It comes from the central lists, never added screen by screen.
- Every screen: Back, + New, Still needed, Quick / Full, a heading that says what it is.
- Everything clickable is soft blue.
- Menu: open on click; while open it follows the mouse; it closes when the mouse leaves.
- Design: spacious, elegant, Inter + Plus Jakarta Sans; show options before applying.
- Never stop for the owner; less talk, more work. Reports in Roman Urdu: a short table plus "Abhi baqi hai".
- Security: no secrets, no real data in the public repo; nothing is typed into password boxes for the owner.

## Done (newest first)
- 27-11: Carrier presets inside a group; location loop check; product manager; old-tool lines rechecked (45 really open).
- 27-11: Create from the root + navigation; search however it is typed.
- 27-11: Old tool check (Home): old screens and old data against the new tool; certificate expiry tasks.
- 27-11: Guide me on the new screens. Access card: "Team tasks & people" never blocks a person's own tasks.
- 27-11: Access card by area (Edit / View only / Hidden) + "Ask the Owner" on Home.
- 27-11: Shop quantity check (inventory step d finished).
- 27-11: Tasks got its own menu group. "File a document" on every record page; filed documents show in the PO / cycle document lists too.
- 27-11: Menu – Inventory and Partners are now their own groups (owner 00:09). Inventory: Inventory · FBA check · Stock by partner · Receiving & Warehouse. Partners: Each partner + the partner screens. Each partner page built.
- 26-11: Inventory screen + product story; old shop lines → listings (3,310).
- 26-11: Empty central lists fill themselves (live had 127 empty lists, now 47 – the rest are lists of records not made yet). Every screen got Back / + New / Still needed / Quick-Full.
- 26-11: Import anything – one importer for files, folders, links, pasted tables and screenshots. PDFs and pictures are read in the browser (OCR in English and Chinese). It understands the kind, supplier, PO, products, amount and date. Fixed: uploads were being sent as empty data.
- 26-11: The one dropdown on every screen (PickMap / autoPick), with the person's default.
- 26-11: Decision engine ("What to do next", 9 rules with money and one click); ICC "Create PO for N".
- 26-11: Every Home button opens its own page; a heading on every screen.
- 26-11: Access cards (MY TOOL style); supplier upload link + "From suppliers" inbox with automatic filing.
- 26-11: Guide me on every screen; field coach; MY TOOL direct link; record inspector; names at the top; speed work (Home 2.9 s → 0.7 s).
- Earlier: register at 90 % BUILT (1,294 of 1,433 lines).
