# MoreOrderz – plan and progress

The single list of everything: what is done, what is being built, what comes next, what was discussed, and what waits for the owner.
It lives in the code (docs/ROADMAP.md) and opens in the tool under Home › Plan & progress. Every batch updates it before it is deployed.
Nothing the owner asked for may be dropped from this list. A line only leaves "Next" or "Discussed" by moving to "Done", with its date.

Updated: 26 Nov 2026, 23:30 (Pakistan time)

## Now being built
- **Inventory (owner approved the design 26-11 23:14).** Six parts, built in the steps below:
  - (a) ✅ Old shop lines moved into listings (3,310 listings; 3,304 products made, each private to its shop; batch 1 can be undone).
  - (b) ✅ Inventory screen (Products & Selling › Inventory). One line per product:
    - photo · SKU · ASIN · parent › children
    - available · FBA · on the way · inbound · reserved · damaged
    - owner, Pool / Private, listings and prices
    - sold in 30 days · cover · value · age
    - opening a product shows its whole story
  - (c) FBA as one location per country (UK, France, Canada, USA). "In transit" as a location. Transfer orders: pick → ship → in transit → receive.
  - (d) Daily reconciliation: the FBA report against the ledger, and each shop's quantity against what is available. Every difference has a reason and a fix.
  - (e) Changes from the screen (always through the ledger): transfer, pool ↔ private, give to a shop or take back, change the owner.
  - (f) Who sees what: the owner sees everything; a partner sees his own lots and shops; a VA sees only his own shop(s). The inventory list and the story are already limited this way; it still has to be checked on every other stock screen.

## Next (in this order)
1. **Partner / finance for each partner**, as in the old tool. For every partner:
   - share %
   - profit and loss
   - payments both ways (what he gave and what he took)
   - his inventory: on hand, his share, coming in, going out, and its value
   - a statement
2. **Task management.** Check that the old tool's tasks came in, and that the task screens are complete and easy to use (as in the old tool).
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
- 26-11: Inventory screen + product story; old shop lines → listings (3,310).
- 26-11: Empty central lists fill themselves (live had 127 empty lists, now 47 – the rest are lists of records not made yet). Every screen got Back / + New / Still needed / Quick-Full.
- 26-11: Import anything – one importer for files, folders, links, pasted tables and screenshots. PDFs and pictures are read in the browser (OCR in English and Chinese). It understands the kind, supplier, PO, products, amount and date. Fixed: uploads were being sent as empty data.
- 26-11: The one dropdown on every screen (PickMap / autoPick), with the person's default.
- 26-11: Decision engine ("What to do next", 9 rules with money and one click); ICC "Create PO for N".
- 26-11: Every Home button opens its own page; a heading on every screen.
- 26-11: Access cards (MY TOOL style); supplier upload link + "From suppliers" inbox with automatic filing.
- 26-11: Guide me on every screen; field coach; MY TOOL direct link; record inspector; names at the top; speed work (Home 2.9 s → 0.7 s).
- Earlier: register at 90 % BUILT (1,294 of 1,433 lines).
