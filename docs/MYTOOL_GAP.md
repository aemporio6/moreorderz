# MY TOOL → MoreOrderz: functions to bring over (checked 21 Nov 2026)

MY TOOL = repo `aemporio6/nexus-sourcing` (mytool.<domain>), 67 modules, data in the owner's Google Drive (folder "MY TOOL – DATA").
Each line was checked against the MoreOrderz code (grep + reading), not from memory.

## A. Not in MoreOrderz – bring over and improve
| # | MY TOOL function | Module | Goes into (pillar) |
|---|---|---|---|
| 1 | Fine access per person: every area and every field Edit / View only / Hidden; one tick per right | access3, roles2 | Permission engine |
| 2 | "View as" another role (Owner never trapped) | view_as | Permission engine |
| 3 | Help mode: point at / tap anything → what it is, what to do, a suggestion (Easy English / Roman Urdu); Help guides | helpmode, help_view, help.json | Guidance |
| 4 | Next-step guidance on records; over-payment / over-shipment guard with ways out ("Set to what is owed", "Put the rest on…") | extra, guards | Guidance |
| 5 | Reference text for bank transfers (e.g. `VL PO-26-0067 DEP30 <PI no>`) from a pattern, Copy button; References register (any old ref, AWB, tracking, FBA id, EAN resolves) | core refText, refs | Search + Guidance |
| 6 | Did-you-mean / typo-tolerant search (Damerau distance), aliases ("word → meaning", SKU aliases) | core searchAll, didYouMean | Search |
| 7 | Smart import: any CSV / Excel / pasted table, finds the header row, knows what the file is, LEARNS (SKU / party / list aliases, ignored columns), row report download, Undo import | importer, importer_auto, imp_report | Import engine |
| 8 | Fixed-format readers: supplier PI, packing list, freight bill, Product information sheet | readers, prodsheet | Import engine |
| 9 | Inventory Cycle Calculator (planner): velocity, cover days, reorder date / qty, lead + transit + safety | icc | Buying |
| 10 | Packing per order line: cartons, CBM, weight from product sizes; forwarder rate cards (per kg / CBM, min kg), chargeable weight (volumetric divisors) | packing, core | Buying / Shipments |
| 11 | Landed cost: freight shared by Auto / CBM / kg / goods value / pack count (a Choice) | landed_final | Buying (Choice) |
| 12 | Mixed invoices / payments: a line or payment part that belongs to another order, company, brand, client or is personal; recharges report | core poSplit, recharges | Buying / Money |
| 13 | Pay several orders / shipments in one payment, amount per item pre-filled with what is owed | alloc_multi | Money |
| 14 | Supplier credit notes (open / applied / refunded) netted on the order and supplier ledger | credits | Buying / Money |
| 15 | Supplier bank details: change only with approval (fraud guard) | bank_safe | Money + Permission |
| 16 | Exchange rates fetched by themselves every day (ECB); a hand-typed rate never overwritten | rates_auto | Money |
| 17 | Payment Summary export in the owner's own sheet style (one tab per supplier, styled .xlsx, branding) | export_ps, brand | Reports |
| 18 | Calendar: due payments, ETA, ready dates, inspections, deliveries, reorders; day click → create with that date | views calendar | Home |
| 19 | Home as the work pipeline (order → deposit → production → inspection → balance → ship → receive → reorder), each step opens its list | home_flow | Home |
| 20 | Recycle Bin (30 days, restore) + guard: a record others depend on cannot be trashed, its dependants are listed | trash, guard_links | Foundations |
| 21 | Version history of every record with restore of an old version | history2 | Foundations |
| 22 | Calculation trace: when an AUTO value is changed by hand, keep the automatic value, who and when | calc_trace | Foundations |
| 23 | Two people editing the same record: conflict shown field by field, person chooses (never a silent winner) | sync_ui | Foundations |
| 24 | Custom field added once in Setup is used everywhere (form, record, search, export, data health) – no code | connect, forms | Registry |
| 25 | Smart new product: type the name → brand, category, pack, SKU fill from it and from the most similar product | product_add | Smart fields |
| 26 | Save & add another (same party / company / currency kept); bigger touch targets | daily | Smart fields |
| 27 | Data health page: whole data set checked against the rules, Open / Fix buttons, feeds alerts | health | Guidance |
| 28 | Barcode / QR scan with the phone camera (receive, count, move, product) | scan | Phone |
| 29 | App lock on the device: fingerprint / face / Windows Hello / PIN | applock | Security |
| 30 | Supplier upload link: supplier sends invoices / packing lists without a login, lands in the order | supplier_link | Buying |
| 31 | Drive: paste any link (folder / file / Sheet), Drive inbox that imports by itself, "Shared with me" by person, shared drives | drive_link, drive_people | Connect |
| 32 | Seller-Central style menu: every module opens a panel of its parts; real links (open in new tab); "All menus" | menu9, nav2 | Screens (Vue) |
| 33 | Form / record nav bar: Back · Forward · Undo · Redo · Reset · Recent; drafts kept while typing | form_nav, forms | Screens (Vue) |
| 34 | Tags on every record, filterable everywhere; bulk actions on lists (archive / close / tag / export) | core, views | Filters |

## B. Already in MoreOrderz (equal or stronger)
Approvals, QC checklist, samples, product ideas → GO (research), saved views + column picker, + Create hub, tasks + linked records, eBay transaction report / profit, goods in transit + supplier scorecard reports, two-step sign-in, vault, month close, ledger.

## C. MY TOOL's own data
MY TOOL's real records (POs, payments, shipments, receipts since 2022) live in the owner's Drive, not in either repo. The own version imports them once through the existing link package format (`mytool-link-1`, docs/moreorderz-link.md in MY TOOL), with the same rule: nothing moves without permission, bank details only when ticked.
