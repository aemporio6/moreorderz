# MoreOrderz – plan and progress

The single list of everything: what is done, what is being built, what comes next, what was discussed, and what waits for the owner.
It lives in the code (docs/ROADMAP.md) and opens in the tool under Home › Plan & progress. Every batch updates it before it is deployed.
Nothing the owner asked for may be dropped from this list. A line only leaves "Next" or "Discussed" by moving to "Done", with its date.

Updated: 27 Nov 2026, 00:20 (Pakistan time)

## The owner's standing rules (every screen, every batch is checked against these)
1. **Everything is connected.** Stock ↔ inventory ↔ partners ↔ buying ↔ sourcing ↔ selling ↔ money. Nothing is a screen on its own: every figure comes from the one ledger and setup behind it, and every action writes there. If something is not connected, it is not finished.
2. **Everything comes from the centre (setup).** Every list, value and default lives once in the central setup and reaches every place that needs it by itself. Nothing is added screen by screen.
3. **Every dropdown:** search, suggestions, used lately first, All, multiple, Other / + Add (saved to the central list), a default, and help.
4. **Automatic first, manual least.** The system fills what it knows, with suggestions. Where it can do a step, it asks "shall I do it?" – one press, and it is done (import, create, book, link).
5. **Never stuck.** No error stops the work. A step shows what is still needed and offers the fix right there.

## Now being built
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
  - (d) ✅ FBA check (Inventory › FBA check): Amazon's last report against the ledger, SKU by SKU, with the reason. "Book" brings the ledger to Amazon's figure – as found / lost stock, or as units moved in from a warehouse. Still to do: each shop's quantity against what is available.
  - (e) ✅ From a product's story: Move units, Change owner, Give to a shop, Pool / Private. Everything goes through the ledger.
  - (f) ✅ Who sees what: a person limited to shops sees only those shops' products – on Inventory, the product story, the stock list, the stock ledger, lots and a product's stock page (Inventory sharing already did this). Still to do: a partner sees his own lots and shares, which comes with the partner work below.

## Next (in this order)
1. **Partner / finance for each partner** – ✅ first part built 27-11: Partners & Investors › **Each partner**. Per partner:
   - share (agreement %, or by product: 1 ÷ investors on the product – the old tool's rule)
   - his stock: on hand, FBA, on the way, coming, value at raw price and at cost (Owner only)
   - sold in 30 days, settled months (revenue, profit, his share), invoices and payments, partner ledger
   - "His stock in Inventory" opens Inventory filtered to his products
   - a partner login sees only his own account
   Still to do: bring the old investor links from the old tool into live where they are missing. The new screens then fill by themselves. Earlier finding: Found 26-11 23:55: on live there are 0 partner agreements, 0 capital entries and 0 withdrawals, and every stock lot shows "Company". The old tool's product ↔ investor links (t_product_investors, 85 lines) were kept only as history. Its partner settings must be read from the old database and turned into agreements, lot owners and opening balances. For every partner:
   - share %
   - profit and loss
   - payments both ways (what he gave and what he took)
   - his inventory: on hand, his share, coming in, going out, and its value
   - a statement
2. **Task management.** The old tasks did come in (2,321 on live, 3 templates). Still to check: the task screens are complete and as easy as in the old tool.
3. Documents: "File a document" from anywhere. Its kind and its record are guessed, with the reason shown (reuses Import anything).
4. Access card: Edit / View only / Hidden for each area; a blocked person gets an "Ask the Owner" button on Home.
5. Guide me: steps for more screens.
6. Register lines still PARTIAL (98) or FORM ONLY (39).
7. Reports: "every figure can be clicked down to its record, file and row" (MR-1285).
8. Label designer for our own labels: product, carton, FNSKU, pallet (MR-0134).
9. ICC: WhatsApp digest (MR-0060); an ICC step inside Create of a product (MR-1324).
10. Decision engine: tests for REBALANCE_FBA and CAPITAL_CALL with real-shaped data.

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
