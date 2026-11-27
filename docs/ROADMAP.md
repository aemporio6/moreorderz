# MoreOrderz – plan and progress

The single list of everything: what is done, what is being built, what comes next, what was discussed, and what waits for the owner.
It lives in the code (docs/ROADMAP.md) and opens in the tool under Home › Plan & progress. Every batch updates it before it is deployed.
Nothing the owner asked for may be dropped from this list. A line only leaves "Next" or "Discussed" by moving to "Done", with its date.

Updated: 27 Nov 2026, 05:30 (Pakistan time)

## The owner's standing rules (every screen, every batch is checked against these)
0. **Better than the source (owner 27-11 02:42).** Whatever comes from the old tool or MY TOOL is not just copied: it becomes easier, dynamic, smart and improved – and it fits inside this system, connected to the rest, never something apart.
1. **Everything is connected.** Stock ↔ inventory ↔ partners ↔ buying ↔ sourcing ↔ selling ↔ money. Nothing is a screen on its own: every figure comes from the one ledger and setup behind it, and every action writes there. If something is not connected, it is not finished.
2. **Everything comes from the centre (setup).** Every list, value and default lives once in the central setup and reaches every place that needs it by itself. Nothing is added screen by screen.
3. **Every dropdown:** search, suggestions, used lately first, All, multiple, Other / + Add (saved to the central list), a default, and help.
4. **Automatic first, manual least.** The system fills what it knows, with suggestions. Where it can do a step, it asks "shall I do it?" – one press, and it is done (import, create, book, link).
5. **Never stuck.** No error stops the work. A step shows what is still needed and offers the fix right there.

## Now being built
- ✅ 27-11 first part built: **Packaging / cartons, smart.** Compare MY TOOL and the old MoreOrderz, take the better one and go further: from the product's size and weight the system works out carton, CBM, volumetric weight, units per carton and the best box by itself; packaging data reaches product, listing, PO, shipment and labels by itself – changed once, changed everywhere.
  Built 27-11: one Shipping & packaging section on the product (item, retail pack, master carton, with units; a child takes its parent's values); worked out by itself: CBM, air / courier volumetric weight, net / gross per carton, CBM per unit, carton fill %, the best carrier box, full + tail cartons for any quantity; product page Packaging card; PO page Packing of every line. ✅ 27-11 part 2: a supplier's packing list (PDF / Word / Excel / pasted, table or text) is read – per SKU: units per carton, carton size (mm read as cm), gross weight – and Import anything offers "Put the carton figures of N product(s) in" with each change shown; one press, every change in the product's history. A sourcing shipment of a PO gets its planned cartons, CBM and gross weight from the PO's packing when they are empty. An FBA carton without weight or size takes them from the product (a full carton = its weighed gross; a part carton = units × pack weight + the empty carton). Still to do: listing shipping weight / size per marketplace, carton marks from it.
- ✅ 27-11 first part built: **Product asset vault ("Vector").** Every product has its files in order: product images (main, white background, lifestyle), listing images per marketplace, Amazon A+ / A++ content, labels (FNSKU, barcode, carton), packaging files (dieline, vector artwork .ai / .pdf, carton marks), certificates, manuals. Every file that comes in (Import anything, a supplier, Drive) is recognised and put in the right place of the right product by itself; one click on a product shows the whole vault.
  Built 27-11: the Vault on every product page (15 places); a file dropped there, filed from the product, or coming through Import anything with the SKU in its name goes to its place by itself (name, type and words decide – main, lifestyle, listing per marketplace, A+, FNSKU, dieline, vector artwork, carton marks, certificates, manuals); move a file, make it the first, take it out; the vault says what is missing (main image, listing images of each marketplace it is on, A+ and FNSKU for Amazon FBA, artwork for its own box). ✅ 27-11: every vault picture has a public signed link (🔗 copies it – paste it in a marketplace listing; pictures only, never labels or artwork; taking it out of the vault ends the link); a PO's supplier link now also offers the products' vault artwork, dielines, carton marks and FNSKU / barcode labels. Still to do: Drive folder per product.
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
7. ✅ 27-11 Profit views: every figure clicks down to its records, with the file and row each came from (MR-1285). Found and fixed on live: the finance hourly job failed every hour (an alert key that had to be unique over every rule) – fixed; the ICC had never run on live – run once (18 products: 8 order now, cash needed 6,110 GBP), the nightly job runs it from now on.
8. ✅ 27-11 Label designer (Setup › Label designer): product, carton, FNSKU, pallet, goods-in labels – fields from the record, barcode of any field, size presets, live preview, printed through the print routes; Print label on every product (MR-0134).
9. ✅ 27-11 ICC: the daily digest has STOCK TO ORDER (order now / this week, qty, order-by, run-out, cash needed, link) (MR-0060); a new product gets its ICC the moment it is saved (MR-1324) – and the ICC of a product without sales no longer fails.
10. ✅ 27-11 Decision engine tested on real-shaped data: REBALANCE_FBA (snapshot + 30-day sales + warehouse lots → send 45, 11 days lost × margin) and CAPITAL_CALL (13-week cash low point vs ICC orders due in 90 days).

## Communication hub (owner 27-11 06:43) – one strong, linked communication system
Owner: the old tool sent supplier messages straight to WhatsApp. Now, at a high level: (1) share anything from WhatsApp / phone / laptop straight into the tool and choose where it goes, with history of what came, from whom, where it was used; (2) chat with every person (supplier, forwarder, investor, VA…) from their record – WhatsApp, SMS, WeChat, e-mail – with a timeline per person; (3) chat parts / screenshots pinned as evidence on sourcing records (PO, cycle, claim); (4) automatic WhatsApp to VA / investor on their own number for low stock, tasks, price changes; (5) every tracking number a blue link to the carrier's own tracking page – sea, road, courier companies listed, "+ Add" where one is missing.
1. ✅ 27-11 Back always stays in the app (owner 06:54). Red '<n> to place' on the top bar + TO PUT IN PLACE in the morning digest for shared things not yet placed (owner 06:58).
1b. ✅ 27-11 Share to MoreOrderz: the phone / laptop app is a share target – Share from WhatsApp, the gallery or e-mail → MoreOrderz → Import anything takes the files and the text (source SHARE) and offers each its place (supplier, PO, cycle, product, payment…); kept with who and when.
2. ✅ 27-11 way 1 (owner 07:56): Contacts & chat – contact list, Message opens your own WhatsApp / SMS / mail / WeChat id copy, conversation per contact, replies as text / screenshot / file / voice (played in the tool), evidence pinned on records, Messages & evidence card on every record.
2a. Owner 07:28: a contact list for WhatsApp / WeChat (numbers added per person), chat built inside the tool both ways, and voice messages – kept, played in the tool.
2. Per-person conversation timeline (all channels) + "Message" on every person, supplier, forwarder, investor record.
3. Evidence: pin a message / screenshot to a PO, cycle, claim or payment; shown there as proof.
4. Automatic WhatsApp to VA / investor numbers for low stock, tasks, price changes (on the existing alert rules).
5b. Forward to the business WhatsApp number → comes into Import anything (when the WhatsApp connection is on).
6. ✅ 27-11 Personal screens (owner 06:54): ⚙ Arrange on every screen (parts, tiles, columns; fold / hide / order; Reset) and Arrange my menu (groups, screens inside their own section only, pin, hide; Reset) – view only, per person. ✅ 27-11 also: drag a part with the mouse / finger (⠿), number tiles Big / Small.
7. ✅ 27-11 Top company list also shows the 32 companies + Add a company; Refresh reads fresh numbers; WhatsApp set-up in steps (owner 07:09 / 07:24).
8. ✅ 27-11 (Setup › Workspaces & bank links) Owner 07:24 – options in the owner's hands, no questions: a workspace (e.g. 'test enterprise') can be kept / switched off / deleted by the owner; every bank account of a person / company can be linked by the owner to a company and to a Money bank account, or kept apart.
5. ✅ 27-11 Tracking links on every screen, 22 carriers + '+ carrier' in place. (was: Tracking: carrier list with tracking-link patterns (sea, road, air, courier), every tracking number a link, "+ Add carrier" in place.

## NEXT – owner 27-11 09:26 (saved here so nothing is lost when the session / limit ends)
**A. Connectors hub (one place, fully dynamic)** – Setup › Connections becomes a catalogue of connectors, each with logo, what it does in this tool, status (connected / not / error), Connect · Test · Disconnect, and who connected it when.
1. Google: Drive (watched folders already exist – move under the hub), Sheets (read/write a sheet as an import source or export target), Gmail (inbound attachments → Import anything / "to place"), Calendar (due dates, task reminders), Docs.
2. Microsoft: OneDrive / Excel / Word / Outlook (same jobs as Google).
3. Marketplaces: eBay, Amazon (SP-API), TikTok Shop, Etsy, OnBuy, Temu, Vinted, Shopify (adapters for eBay/Amazon/TikTok/OnBuy/Temu already written – MR-0074 – wait for keys).
4. Money: Wise, banks (statement files today), PayPal, Stripe. Messaging: WhatsApp (way 2), WeChat, email/SMTP. Printers. Carriers (tracking).
5. Connect with permission: OAuth "Sign in with …" where the service offers it (owner presses Allow – no key pasted in chat); key/secret forms only where no OAuth, owner types it himself, stored sealed (never shown).
6. Built from one table (cat_connectors: code, group, name, domain, auth kind, scopes, jobs, help link) so a new connector is one row, not new screens; each connector says which modules use it.
**B. Logos everywhere** – every marketplace, bank, carrier, connector, messaging app, printer, payment service shows its small current logo beside its name (dropdowns, lists, records, shop chips, Connections).
- Way: one logo helper keyed by the service's own web domain (cat_* rows get a `domain` column: amazon.co.uk, ebay.co.uk, tiktok.com, etsy.com, onbuy.com, vinted.co.uk, temu.com, wise.com, hsbc.co.uk …); the picture is the service's own current site icon fetched at run time and cached, so it stays the latest; a coloured initial badge when none. Owner can upload his own picture for any row. Not drawn by hand.
**C. Still-to-do from this ROADMAP** – finish what can be built without the owner (each line above marked open); the rest stays under "Waiting for the owner".
**D. My brands (owner 27-11 09:30)** – a "My brands" button/page: one button per own brand (BYMO, LAMNOON, WE ART LEAVES); each brand page shows its logo, its products, shops/listings, sales and stock. The brand logo shows wherever the brand is used (product, listing, label, PO, reports) – clean version, no border/background, letters only (cut out to transparent PNG, light + dark versions).
- Owner's logo files are saved in his Google Drive folder "MoreOrderz – My brand logos" (https://drive.google.com/drive/folders/1LweZct0XsbRc4-hlzaaglRgUSLrcr2z5): bymo-light/dark, lamnoon-light/dark, weartleaves-light, weartleaves-gold-dark, weartleaves-red-dark. Not in the public repo – uploaded into the tool's own files (brand record › Logo) on the server.
**E. Deep audit after A–D (owner 27-11 09:31)** – a high-level audit of the whole system, module by module: every screen, form, field, dropdown, button, link, number, rule, job and connection checked against the register and the owner's rules; mistakes found are fixed and tested on the spot, then the next module. Ends with a clear list: built / fixed / waiting for owner.
**F. Live exchange rates (owner 27-11 09:33)** – ✅ 27-11: rates come in by themselves from the European Central Bank (authoritative, no key, ~30 currencies incl. CNY, USD, EUR, MYR) at 06:20 / 15:20 / 17:20 on working days (mo:fx-live); a rate typed by hand for a day is never overwritten; a thin strip on the top bar shows today's 1 GBP = … CNY · USD · EUR with the date (opens Money › FX rates). Each stock lot, payment, freight and investment keeps (locks) the rate of its own day; the next one takes the next rate – never mixed.
- Still to do: Wise / WorldFirst real deal rates through their connection when the owner links them (connectors hub) – the actual rate of each transfer goes on the payment (typed rate already wins over the table); a "rate moved since the deal" line on lots, POs and investments (deal rate vs today, gain / loss), and in the deep audit check every module uses its own day's rate.
Order when work resumes (guide pictures re-made 27-11, 168 of 168):  B logos (quick, visible) → D my brands → A connectors hub (catalogue + Google Drive/Sheets/Gmail OAuth first, then marketplaces as keys come) → C → E deep audit.

## User guide – a teacher inside the tool (owner 27-11 08:00, must)
Visual, with links, for every module, screen, form, field, dropdown, button and figure; how the company runs and how each module works; who is where, what is where.
1. Built by itself from the registry + menu + rules (a new module / field appears in the guide by itself), one page per screen with its real screenshot (made by the browser check) and the buttons marked.
2. "?" beside every field, dropdown, button and figure → opens the guide at exactly that part (not the whole guide).
3. "Show me" tours: step-by-step highlight on the live screen (press this → choose this → save) until the job is right; a mistake says why and how to fix it.
4. The company map: the whole flow (buy → receive → stock → sell → money → partners), roles and where each thing lives, with links.
✅ 27-11 part 1 live: User guide page (map, every screen step by step, every form and box, search), '? Guide' on every screen, '?' on every form box → the guide at that box. ✅ part 2: pictures with numbered buttons (168 screens), ▶ Show me tours (screen, Guide me, every form), '?' on every number tile. Pictures are re-made with tools/dev/guide-pictures.cjs when screens change.
All four points done (27-11).

### Remember – owner 27-11 07:56: WhatsApp "way 2" (remind the owner and do it together)
The owner chose way 1 now (Message button opens each person's own WhatsApp; history in the tool). **Way 2 – the tool's own business WhatsApp number (automatic alerts out, replies in) – is to be done later together with the owner, when he has a new number (not his personal WhatsApp).** Remind him at the start of the next session / when limits reset. Steps: he signs in at https://developers.facebook.com/apps in the shared Chrome → the developer makes the app, adds WhatsApp, webhook (address from https://app.moreorderz.com/#/connections?setup=whatsapp), subscribes "messages" → the owner only enters the SMS code and copy-pastes the permanent token (https://business.facebook.com/settings/system-users) and the app secret into the tool's form → Test → on. One setup for everybody; each person only has his own number on his record.

## Old tool – still open (owner 27-11 01:30: "look inside my old MoreOrderz, put what is left in the checklist, finish it")
The whole list is on one screen: **Home › Old tool check** – every old screen (138 controllers of the old code) with its register lines, and every old table with how much of its data came over. 27-11 recheck against the code: many lines marked open were in fact already built (tax worksheet, carton calculation, BUY file, Amazon Business orders, Etsy month, task team settings, marketplace fields) – they are now marked built with their evidence (audit/recheck_27nov.csv). **1 line is open (27-11): MR-0074 marketplace APIs – eBay / Amazon / TikTok / OnBuy / Temu adapters are written and wait for the owner's keys; Etsy / Shopify / Wise come in by file today**, built in this order:
1. ✅ Corporation-tax worksheet – already built (TaxService). ✅ Carton calculation – already built (CartonCalc).
2. ✅ 27-11: product type / trend codes – required, upper case, unique in the company (MR-0935); company list shows its directors (share %) and a Tax link to that company's tax dashboard (MR-1122). ✅ country lists show pinned countries first (MR-1125). ✅ carrier presets inside a group – pin, drag order, default, remove, clean-up, where offered (MR-0930, 0982); location can never sit under itself (MR-0887, 0978); product manager role → product manager (MR-0976).
3. ✅ 27-11 Product page as in the old tool, connected (Inventory › a product): one bar to every part – Overview, Details (every field by section, Edit, Duplicate), Purchases (every PO line and lot that came in, + Record a purchase), Stock, Selling, Variations, Packaging, Vault, History in words (names, not ids) + Prices, Marketplace fields, Variations editor, Investors; deep link ?tab=. The product record has a "Product page" button (MR-0220, 0882, 0897, 0963, 0989).
4. ✅ Supplier BUY file and Amazon Business order file – already built (BuyFile, SupplierOrders).
5. ✅ 27-11: investor + investment – a sourcing investor is its partner account, every sourcing investment is the partner's capital (Each partner shows it). Forwarder list (one source for shipments, freight, cycles, ICC) was already built. local vendor card, product type / trend ISO code, country pin order, company list with directors – MR-0301, 0302, 0295, 0227, 0981, 0935, 1125, 1122.
6. ✅ Live money formulas (StepFormulas) and supplier payment approval / reversal were already built. Shipments carry the forwarder from that list (MR-0314).
7. One-master work (people, suppliers, lists, sales, imports) – MR-1404, 1406, 1407, 1413, 1414, 1419; module / parameter framework – MR-1129.
8. ✅ 27-11: short link of any record – /r/<n> (🔗 Short link on every record, product, PO and cycle page; opens after sign-in) (MR-0089). Sourcing catalogs read their options from the central lists (MR-1130).
9. ✅ 27-11: Settings is the module / parameter map – every setting of every module by itself, levels company / all / shop, in force now, last change, Change → the module's checked page (MR-1129). Drop shipping upload learns columns matched by hand (MR-0987). Rechecked as built: cycle workspace with 24 places (MR-0291), old invoicing – settle, invoices, payments, credit notes, hidden margin (MR-0339).
10. ✅ 27-11: one product master – sourcing products link to their product by SKU and share HS code, material, weights, carton, brand, origin both ways (only empty fields) (MR-0287, 1408); one landing-price rule everywhere: own → latest lot → parent → raw price, with where it came from (MR-0940). Rechecked as built: supplier list (MR-0315), stock transfers that really move stock (MR-0316), old per-shop product rows → listings (MR-0222), one party list with suppliers as parties and merge with undo (MR-1404, 1406, 1407), one master for lists (MR-1419).
11. ✅ 27-11: one import book on Import history – every file of every importer (money, bank, sales, drop shipping, Drive folders, connections) with the breakdown by module, marketplace · shop, source and kind, and where each kind goes (MR-1414, 1258). Rechecked: one sales list with charges + old profit compare (MR-1413, 0068); old 'main product' rows were never used (10 on live, none linked) – kept as history (MR-0223). Left: marketplace APIs with real keys (MR-0074), every module on two marketplace profiles (MR-0350), the sales & marketplaces line list (MR-0123).
12. ✅ 27-11: sales Saved exports (fields, order, name, share, daily / weekly) on the sales screen (MR-0123); unknown sale SKUs found by themselves – shop prefix, ASIN, item id, listing SKU; bundles A+B never guessed (found on live: 57 problem rows = 51 'SKU not found' of 27 marketplace SKUs + 6 empty SKU, all from old-tool files); two profiles eBay + TikTok tested (MR-0350).
13. ✅ Tasks: workload vs limit, SLA per category, absence cover, delegated approval, recurring – already built (TaskTeam, MR-1240).
14. Data: every old table counted against what came over (shows on Old tool check after the count on the server). Owner 27-11 01:34: every setup list of the old tool – brands, banks, companies, countries, currencies, ports and all the others – must come over completely; whatever is missing is brought to its place here. **Counted on live 27-11:** 199 old tables have rows; 177 came over completely (brands, banks, companies and every other setup list among them), old copies and tool settings are not needed, the old stock moves are done the new way (opening lots). ✅ **Live 27-11: still to bring = 0.** The last 4 old tables now have their place (mo:old-extras: 1 provider, 8 IP servers, 3 warehouses, 23 list values): IP / VPN servers and their provider → the IP accounts list (label, place, plan, provider kept); warehouses with addresses → the warehouse list and the address on the stock location; old lookup lists → central lists "Old list: …".
10. Owner 27-11 01:34 / 01:36: **Create** – ✅ 27-11: Create is built from the root: every registry form, plus every menu screen that makes something (New …, Import …, Record …, Move …), appears by itself – a new module needs nothing added by hand. Inside Create: search, used lately, ← Back, ↶ Undo, ↷ Redo, ✕ Clear, ⟳ Refresh, Enter opens the first match; a form opened from Create has ← Back and "Switch to…" another form without leaving.
11. Owner 27-11 01:34: **Universal search** – ✅ 27-11 (first part): a record is found however it is typed – "PO-QX-7731", "#PO-QX-7731", "poqx7731", "PO QX 7731", "qx-7731", "#7731", "PO7731"; records made by imports (not through a form) are put in the search every night, and on live once now. ✅ 27-11: a word inside a filed document (read from its PDF / picture) finds it. On live 64,547 records that were never in the search (made by imports) were added 27-11 – SKUs, lots, orders and the rest are now found. Still to check: documents' text, settings and every screen's own words.
12. Owner 27-11 01:34: everything connected – nothing is made on its own, apart from the rest.
✅ 27-11: product certificates now get a task 60 days before they end (MR-0139, was form only).

## Waiting for the owner
- Labels: which eBay shop the 25 Nov orders belong to.
- ~~Exchange rates for CNY and USD~~ ✅ 27-11 come in by themselves (ECB, first run on live: 29 rates of 2026-11-26; 1 GBP = 8.7274 CNY · 1.3104 USD · 1.194 EUR).
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
- 27-11: Inventory › Inventory setup (locations tree with units, location types, adjustment and price-change reasons on one page); the main company is mirrored into the tenant row.
- 27-11: Security sweep – old secrets kept in columns no form showed (vendor portal password and bank details, an old account password, bank question answers) are sealed in the vault; local vendor card complete (MR-0981, 0227). Packing list into products; FBA / shipment cartons from the packaging.
- 27-11: Packaging engine + PO packing; product vault; old tool product images shown again (39 of the first 50 products have their photo back).
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
