<?php

namespace App\Services;

/**
 * Choices: business decisions where more than one way is built into the tool. Every option stays available;
 * the recommended one is the default. The Owner picks per company on Settings › Choices. Each choice is one setting.
 */
class Choices
{
    /** key → [question, why it matters, default, [value => [label, what it does]]] */
    public const LIST = [
        'PROFIT_SHARE_AFTER_OVERHEADS' => ['Partners share the profit of a shop …', 'Overheads are expenses that belong to no shop. They are split between the shops by their confirmed sales of the month.', '1', [
            '1' => ['after its share of the overheads', 'A partner earns on what is really left. This is what the profit rules of the specification say.'],
            '0' => ['before overheads', 'A partner earns on the shop\'s own profit; the company carries all overheads alone.']]],
        'WH_LOSS_LOT_RULE' => ['Writing off missing units: which lots lose them?', 'Units left in Missing after the write-off days are written off as stock loss.', 'FIFO', [
            'FIFO' => ['The oldest lots first (FIFO)', 'Suggested, as every other stock loss.'], 'PRO_RATA' => ['Each owner in proportion', 'Every lot owner loses in proportion to his units in Missing.']]],
        'TYPED_CODE_CHECKS' => ['Typed outside codes (HS code, ISP code): how strict?', 'They are typed, never numbered by the system.', 'STRICT', [
            'STRICT' => ['Checked', 'Suggested: HS code 6–10 digits (dots and spaces removed), ISP code letters / digits up to 20.'], 'OLD' => ['Free text, as the old tool', 'Only cleaned (spaces / dots out, upper case).']]],
        'LC_FREIGHT_METHOD' => ['Landed cost: how is freight spread over the products of a cycle?', 'As the old tool: by quantity. Can be changed for one run.', 'QUANTITY', [
            'QUANTITY' => ['By quantity', 'Suggested (old tool): each unit carries the same freight.'], 'VALUE' => ['By value', 'Dearer products carry more.'], 'WEIGHT' => ['By weight', 'From the carton rows (gross weight); a product without carton data falls back to quantity.'], 'CBM' => ['By volume (CBM)', 'From the carton rows; a product without carton data falls back to quantity.']]],
        'LC_DUTY_METHOD' => ['Landed cost: how is customs duty spread?', 'Duty is charged on value, so by value is the usual way.', 'VALUE', [
            'VALUE' => ['By value', 'Suggested (old tool).'], 'QUANTITY' => ['By quantity', ''], 'WEIGHT' => ['By weight', 'From the carton rows.'], 'CBM' => ['By volume (CBM)', 'From the carton rows.']]],
        'LC_LOSS_UNITS' => ['Landed cost: damaged and missing units …', 'The accepted units can carry their cost, or the cost can be written off as a loss and claimed from the supplier.', 'ABSORB', [
            'ABSORB' => ['are carried by the accepted units', 'Suggested (old tool): the unit cost rises, no loss line.'], 'WRITE_OFF' => ['are written off as a loss', 'Unit cost over all units; the damaged / missing part goes to stock loss (5500) and onto the supplier claim of the GRN.']]],
        'PRODUCT_SCOPE' => ['Who sees every product?', 'Everybody else sees the products that are Pool on a marketplace, and the Private products of the shops he owns, sells in or is assigned to. A product without visibility is hidden from them.', 'SUPER', [
            'SUPER' => ['Owner, Manager and the super role (ADMN-SUPER)', 'Suggested (the specification): every other role – VA, seller, sourcing, stock, finance … – is limited.'],
            'SHOP_ROLES' => ['Everybody except VA / seller logins', 'Only the shop roles are limited; office roles see every product.'], 'OFF' => ['Everybody', 'No product is hidden from anyone.']]],
        'COMPANY_FORM_CHECKS' => ['Company form: which checks run on save?', 'The old tool required only the company name; the specification proposes typed code formats, required registrar details, unique ISO code and director shares up to 100 %.', 'STRICT', [
            'STRICT' => ['All checks', 'Suggested: country, ISO code (unique), registration no (8 characters), telephone 1, e-mail 1 and address required; UTR 10 digits, auth code 6, HMRC activation code 12, VAT GB + 9 / 12 digits, EORI GB + 12 digits; shares 0–100 and together at most 100 %.'],
            'OLD' => ['As the old tool', 'Only the company name is required; codes are only cleaned (spaces out, upper case); each share still 0–100.']]],
        'CLOSE_FIRST_FILE_PARTIAL' => ['When a shop\'s very first money file starts in the middle of a month …', 'Month close wants the marketplace\'s money file for the whole month.', '1', [
            '1' => ['that first month counts from the file\'s first day', 'The first month can be locked. Later months must be covered completely.'],
            '0' => ['the whole month must be covered', 'Strict: the first month stays open until a file covering it from day 1 is loaded.']]],
        'LATE_LINES_LOCKED_MONTH' => ['A sale line dated in a month that is already locked …', 'A locked month never changes.', 'FORWARD', [
            'FORWARD' => ['is confirmed and booked today, in the open month', 'Nothing waits; the locked month\'s figures stay as they were.'],
            'REFUSE' => ['is refused until someone decides', 'The line stays pending; the Owner sees a task and decides what to do with it.']]],
        'WH_UNSHIPPED_AT_CONFIRM' => ['At Month confirm, own-stock lines that were not shipped through the tool …', 'Confirm takes the units out of stock.', 'ASK', [
            'ASK' => ['stop and ask', 'The person confirming sees the lines and chooses "book as shipped now".'],
            'AUTO' => ['are booked as shipped by themselves', 'Faster; use it when dispatch is not done in the tool.']]],
        'PRINT_FAILOVER' => ['When the printer of a job is not ready …', 'Every job kind can have a backup printer.', 'ASK', [
            'ASK' => ['ask before using the backup printer', 'The person printing decides.'],
            'AUTO' => ['use the backup printer by itself', 'No stop at the packing desk.'],
            'OFF' => ['fail the job', 'Nothing prints anywhere else; a task is opened.']]],
        'DEFAULT_PROFIT_ROUTE' => ['How is the profit of a shop paid out when the shop has no route of its own?', 'Every shop × month has exactly one route, so nobody is paid twice for the same units. A shop can get its own route from a chosen month (Partners › Profit routes).', 'LOT_PROFIT', [
            'LOT_PROFIT' => ['Lot profit', 'Lot owners earn the profit of the units sold from their own lots, then the agreements share the rest.'],
            'INVESTOR_INVOICING' => ['Investor invoicing', 'The four-way split rule: investor % to the lot owners by the cost of their units sold, plus owner, VA and handler %.']]],
        'LOT_OWNER_SHARE' => ['Route "lot profit": how much of the profit of his own lots\' units does a lot owner get?', 'The rest of that profit goes on down the waterfall (agreements, then the company). Applies to months locked from now on.', '100', [
            '100' => ['100 %', 'All the profit of the units sold from his own lots (after tax reserve and VA / handler shares).'], 'AGREEMENT' => ['The % of his own agreement', 'His PO agreement % for lots of that PO, else his pool / shop agreement %, else 100 %.'],
            '90' => ['90 %', ''], '80' => ['80 %', ''], '75' => ['75 %', ''], '70' => ['70 %', ''], '60' => ['60 %', ''], '50' => ['50 %', ''], '40' => ['40 %', ''], '30' => ['30 %', ''], '25' => ['25 %', ''], '20' => ['20 %', ''], '10' => ['10 %', '']]],
        'DS_MIN_MARGIN' => ['Drop shipping: the smallest margin a line should make', 'A drop shipping line below this margin opens a task for the shop (it can be accepted with a note). A line below 0 % is always flagged. A shop can have its own value.', '10', [
            '0' => ['0 %', ''], '5' => ['5 %', ''], '10' => ['10 %', 'Suggested.'], '12' => ['12 %', ''], '15' => ['15 %', ''], '20' => ['20 %', ''], '25' => ['25 %', ''], '30' => ['30 %', '']]],
        'FULFIL_PHONE_FORMAT' => ['Carrier file: how are phone numbers written?', 'National: the country code becomes 0 and only digits stay (+44 (7700) 900123 → 07700900123). Old: only +44 → 0 and brackets removed, spaces stay (0 7700 900123).', 'NATIONAL', [
            'NATIONAL' => ['National, digits only', 'Suggested: carriers accept it without changes.'], 'OLD' => ['As the old tool wrote it', 'Spaces stay in the number.']]],
        'REFUND_ON_CONFIRMED' => ['Money report: a refund of a line that is already confirmed – what happens?', 'The confirmed line is never changed. Adjust: the refund is booked at once as an adjustment line in the next open month. Ask first: it waits as a proposal a person accepts or rejects.', 'ADJUST', [
            'ADJUST' => ['Book the adjustment in the next open month', 'Suggested (the rule): nothing waits, the locked month stays as it is.'], 'PROPOSE' => ['Ask a person first', 'The refund waits in Money › Refunds until accepted.']]],
        'SUP_BANK_NO_DETAILS' => ['Supplier payment: the supplier has no bank details recorded – pay anyway?', 'Bank details of a supplier are approved after a phone check by a second person. This choice is only for a supplier with no bank details at all.', 'ALLOW', [
            'ALLOW' => ['Yes – the payment is made', 'Suggested until the bank details of all suppliers are entered.'], 'BLOCK' => ['No – add the bank details first', 'Strict: no payment without approved bank details.']]],
        'CARRIER_FILE_DEFAULT' => ['Carrier file: which layout is offered first?', 'The file for the carrier from any order selection (sales list, fulfilment queue). Each layout has the carrier\'s own bulk-upload columns; parcel data comes from the line, else from the product pack.', 'TIKTOK', [
            'TIKTOK' => ['TikTok / standard (25 columns)', 'Suggested: the old file, read by most label tools.'], 'CLICK_DROP' => ['Royal Mail Click & Drop', 'Order import of Click & Drop, with the package size worked out.'], 'EVRI' => ['Evri', 'Evri bulk upload with weight and size.'], 'DPD' => ['DPD', 'DPD bulk shipments.']]],
        'VA_EXPENSES_BEFORE_SHARE' => ['VA statement: are the shop expenses taken off before or after the VA share?', 'After: VA share = VA % × after-fee profit, then the expenses come off the store part. Before: the share is worked out on after-fee profit less expenses.', '0', [
            '0' => ['After the VA share', 'The VA share does not carry the shop expenses.'], '1' => ['Before the VA share', 'The VA shares the shop expenses.']]],
        'TEMU_SALE_VALUE' => ['Temu order list: which price is the sale value of a line?', 'Temu pays the seller its base price; the buyer pays the retail price (with Temu\'s own margin and VAT). The money file later settles what was really paid.', 'BASE', [
            'BASE' => ['Temu\'s base price (what the seller is paid)', 'Uses "activity goods base price", else "goods base price", × quantity.'], 'RETAIL' => ['Retail price the buyer paid', 'Uses "retail price total" of the line.']]],
        'ICC_FORECAST' => ['ICC: where do the season and trend of a product come from?', 'Recommended: from the product\'s own sales (same month last year + trend of the last 90 days) – better reorder quantities; a product with less than 300 days of sales uses the calendar.', 'SALES', [
            'SALES' => ['From the product\'s own sales (recommended)', 'Season = this month last year against the year; trend = last 90 days against the 90 before.'], 'CALENDAR' => ['Calendar only', 'Oct–Jan ×1.5, Jun–Aug ×0.9, else ×1.0; no trend.']]],
        'MENU_NAMES' => ['Which names does the menu show?', 'Only the words in the menu change; every screen stays where it is.', 'SIMPLE', [
            'SIMPLE' => ['Short names', 'For example "Owner Home" – the part in brackets is left out.'], 'ORIGINAL' => ['Full names of the specification', 'For example "Owner Home ("Command Center")".']]],
        'VAULT_REVEAL' => ['Who may reveal a secret (passwords, memorable answers, full account numbers)?', 'Every reveal and every refusal is written to the audit log with the user, the record and the field – never the value.', 'OWNER', [
            'OWNER' => ['Owner only', 'Everybody else sees ••••; the Owner opens a value when it is needed.'],
            'OWNER_MANAGER' => ['Owner and Manager', 'A Manager who may open the record may also reveal its secrets.']]],
        'STATEMENT_SEND' => ['How do partners get their statement when a month is locked?', 'WhatsApp and / or e-mail to the partner\'s own number and address in People & companies. Nothing goes out until a WhatsApp connection or an e-mail server is set up – it waits and leaves then.', 'AUTO', [
            'AUTO' => ['Sent by themselves at the lock', 'Every partner gets the new statement as soon as the month is locked (and every new version).'],
            'BUTTON' => ['Only when someone presses Send', 'The statement waits on the partner page until the Owner or Finance sends it.']]],
        'TASK_DIGEST' => ['Morning WhatsApp of late and urgent tasks?', 'One message a day per person who has late or urgent tasks, to the WhatsApp number of the person.', 'WHATSAPP', [
            'WHATSAPP' => ['Yes, by WhatsApp', 'Each morning, only to people with something late or urgent.'],
            'OFF' => ['No', 'People see their tasks only in the tool.']]],
        'VA_SALARY_SOURCE' => ['Where does the VA\'s salary in the profit waterfall come from?', 'The salary is taken off the shop\'s profit before shares. Take it from one place only, so it is never taken twice.', 'SPLIT_RULE', [
            'SPLIT_RULE' => ['The split rule of the shop', 'The salary typed on the shop\'s split rule (Partners › Split rules).'],
            'JOB_HISTORY' => ['The VA\'s job history', 'The salary on the VA\'s job history row for this shop that is valid in the month (People › job history), turned into the base currency at its rate.'],
            'EXPENSES' => ['Booked expenses only', 'The waterfall takes no salary: the salary is booked as an expense of the shop (category Salaries) and is inside the shop\'s profit already.']]],
        'OUTPUT_VAT_MODE' => ['Who pays the VAT on this shop\'s sales?', 'Only when the company is VAT-registered. UK marketplaces collect the VAT themselves for sellers based outside the UK; a UK-based seller usually charges and pays it.', 'SELLER', [
            'SELLER' => ['The company (VAT is inside the sale price)', 'VAT return box 1 gets the VAT inside the confirmed sales of the shop (sales × rate ÷ (100 + rate)).'],
            'MARKETPLACE' => ['The marketplace collects it', 'The shop\'s sales carry no output VAT for the company (deemed supplier rule); box 6 still shows the sales.']]],
        'STOCK_COST_LAYER' => ['Which lot\'s cost does a sold unit carry?', 'Physical stock is always counted per product and place. This decides which lot (owner and cost) a sold unit is booked from at Month confirm.', 'PICKED_LOT', [
            'PICKED_LOT' => ['The lot that was picked', 'The unit carries the cost and owner of the lot the picker took it from (one layer: lot × place).'],
            'FIFO_AT_CONFIRM' => ['Oldest lot at Month confirm (two layers)', 'At Month confirm the sold units are booked from the oldest lots of the product by the shop\'s owner rule, whichever lot was picked; the lots swap their places so every place still holds the same number of units (spec R-H-1).']]],
        'VAT_REGISTERED' => ['Is the company registered for VAT?', 'This is a fact about the company, not a preference. It decides where the VAT inside fees and expenses goes.', '0', [
            '0' => ['No', 'VAT paid on fees and expenses is part of the cost.'],
            '1' => ['Yes', 'VAT on marketplace fees and on expenses is kept apart as VAT to claim back (account 1400) and shown in the VAT report.']], true],
        // owner rule 22 Nov 03:47 / 03:48: my suggestions are the defaults, never locked – a list of roles is a multiple choice with All and + Add
        'DIRECTOR_ROLES' => ['Company form: Directors 1–3 are picked from people with the role …', 'Recommended: Owner, Director and Partner – the people who can sign for a company. "+ Add" on a director makes a new person with role Director.', 'OWNER,DIRECTOR,PARTNER', []],
        'FBA_LOCATION_KINDS' => ['Stock reports: a location counts as "at Amazon / the marketplace" when its kind is …', 'Recommended: Marketplace fulfilment centre, Third-party warehouse and FBA – stock there is ours but not on our shelves. A location an inbound shipment was sent to always counts.', 'MARKETPLACE_FULFILMENT,THREE_PL,FBA,3PL', []],
        'CYCLE_REPEAT_PRICE' => ['Repeat a sourcing cycle: the target price of each product is …', 'Recommended: the last agreed price – what we really paid last time is the best start for the new order.', 'LAST_AGREED', [
            'LAST_AGREED' => ['the last agreed price', 'Suggested: the latest PO line, else the supplier\'s RFQ final price of the old cycle, else the old target.'],
            'RFQ_FIRST' => ['the RFQ final price first', 'The supplier\'s RFQ final price of the old cycle, else the latest PO line, else the old target.'],
            'TARGET' => ['the old cycle\'s target', 'Keeps the target typed on the old cycle; prices are agreed again.']]],
        'LC_AUTO_RERUN_MINUTES' => ['Landed cost: after new freight, customs or GRN rows the PROVISIONAL landed cost is worked out again …', 'Recommended: 5 minutes after the last change – several rows typed one after another give one new version. A FINAL landed cost never changes by itself.', '5', [
            '0' => ['at the next worker run', 'As soon as the worker sees the change.'], '5' => ['5 minutes after the last change', 'Suggested.'], '15' => ['15 minutes after the last change', ''],
            '60' => ['1 hour after the last change', ''], 'OFF' => ['never by itself', 'Only when someone presses Calculate.']]],
        'TASK_PROOF_DEFAULT' => ['New task: is proof (a screenshot, photo, file, link or reference) needed before Done?', 'Recommended: Yes – the specification\'s rule; work without proof cannot be checked. The person creating the task can still switch it off (with a reason) when his role may.', '1', [
            '1' => ['Yes, proof is needed by default', 'Suggested.'], '0' => ['No, proof is off by default', 'Proof is asked only when the person ticks it.']]],
        'TASK_PROOF_OFF_ROLES' => ['Tasks: who may switch "proof required" off (with a reason)?', 'Recommended: Owner, Partner and Manager – the people who approve the work.', 'OWNER,PARTNER,MANAGER', []],
        'TASK_IMPORT_TELL' => ['Task CSV import: "Tell each owner on WhatsApp now" is …', 'Recommended: not ticked – a big import should not send many messages at once; the tasks still show in each person\'s morning digest.', '0', [
            '0' => ['not ticked', 'Suggested.'], '1' => ['ticked', 'Every imported task sends its "new task" message.']]],
        'CHANNELS_SELF_EDIT' => ['My alerts: may a person change his own quiet hours, time zone, digest time and WhatsApp / e-mail switches?', 'Recommended: Yes – each person knows when he sleeps and where he is; the weekly task limit stays with Partners and Managers.', '1', [
            '1' => ['Yes', 'Suggested.'], '0' => ['No, only Partners and Managers', 'A person sees his own settings but cannot change them.']]],
        'CHANNEL_SWITCH_ROLES' => ['Who may switch WhatsApp or e-mail alerts off for somebody else?', 'Recommended: Owner and Partner – a switched-off person misses task alerts, so it is a Partner\'s decision.', 'OWNER,PARTNER', []],
        'SAVE_NEXT_LOCK' => ['Sourcing cycle "Save & Next": the parent fields carried to the next step (cycle, PO, shipment, GRN) are …', 'Recommended: locked – the next record cannot be saved under the wrong PO by mistake; the lock icon still opens one field when it is really needed.', '1', [
            '1' => ['locked (the lock icon opens one)', 'Suggested.'], '0' => ['filled but free to change', 'Faster when people often change the parent.']]],
        'PRIVATE_RULE' => ['Stock health: a product counts as Private when it is private …', 'Recommended: on any marketplace × shop – one private shop already means the units are kept for someone, so they must not be sold from the pool.', 'ANY', [
            'ANY' => ['on any marketplace × shop', 'Suggested (K5).'], 'ALL' => ['on every marketplace × shop it is listed on', 'Private only when it is never pool anywhere.']]],
        'FREE_CASH_COMMITTED' => ['Free cash (alert FA1): which supplier payments are taken off the bank?', 'Free cash = bank − supplier payments still open − approved withdrawals. Recommended: every open payment – money promised to suppliers is not free.', 'ALL_OPEN', [
            'ALL_OPEN' => ['every open supplier payment', 'Suggested.'], 'DUE_30' => ['only payments due in the next 30 days', 'Later payments are left out (more free cash shown).'], 'DUE_7' => ['only payments due in the next 7 days', '']]],
        'PERIOD_FROM_AFTER_TO' => ['Period filter: a custom range whose From is after its To …', 'Recommended: swap the two dates – the person clearly meant that range, nothing is lost.', 'SWAP', [
            'SWAP' => ['is swapped', 'Suggested.'], 'REFUSE' => ['is refused with a message', 'The card says "From is after To" and shows nothing until it is fixed.']]],
        'ABSENCE_ROLES' => ['Tasks › Team & settings: who may set an absence (and its cover)?', 'Recommended: Owner, Partner and Manager – the people who plan the work.', 'OWNER,PARTNER,MANAGER', []],
        'DELEGATION_ROLES' => ['Tasks › Team & settings: who may set a delegation (someone approves for someone else)?', 'Recommended: Owner and Partner – a delegation hands over approval rights.', 'OWNER,PARTNER', []],
        'CATEGORY_ROLES' => ['Tasks › Team & settings: who may change task categories (SLA, default owner, escalation)?', 'Recommended: Owner and Partner – categories decide who is chased and when.', 'OWNER,PARTNER', []],
        'DELEGATION_ALL_MONEY' => ['A delegation with rights "ALL" also lets the person decide money approvals?', 'Recommended: Yes – "ALL" means everything the absent person may do; use rights "APPROVE" for tasks only.', '1', [
            '1' => ['Yes', 'Suggested.'], '0' => ['No, money approvals stay with their own approvers', '']]],
        'SHARE_LINK_DAYS' => ['Supplier link without login: how many days is a link valid?', 'Recommended: 14 days – long enough for a supplier to answer, short enough that an old link does not stay open.', '14', [
            '3' => ['3 days', ''], '7' => ['7 days', ''], '14' => ['14 days', 'Suggested.'], '30' => ['30 days', ''], '60' => ['60 days', '']]],
        'TASK_UNDO_MINUTES' => ['Tasks: a cancelled task can be brought back (Undo) for …', 'Recommended: 10 minutes – long enough to fix a wrong click, short enough that nobody builds on a cancel.', '10', [
            '5' => ['5 minutes', ''], '10' => ['10 minutes', 'Suggested.'], '30' => ['30 minutes', ''], '60' => ['1 hour', ''], '1440' => ['1 day', '']]],
        'TASK_COMMENT_MAX' => ['Tasks: a comment can be at most …', 'Recommended: 2,000 characters – a long text belongs in a file attached to the comment.', '2000', [
            '1000' => ['1,000 characters', ''], '2000' => ['2,000 characters', 'Suggested.'], '5000' => ['5,000 characters', ''], '10000' => ['10,000 characters', '']]],
        'SOURCING_DUE_DAYS' => ['Sourcing dashboard › Supplier payments: "Due soon" means due within …', 'Recommended: 7 days – one week to arrange the money.', '7', [
            '3' => ['3 days', ''], '7' => ['7 days', 'Suggested.'], '14' => ['14 days', ''], '30' => ['30 days', '']]],
        'COUNT_TRIGGERS' => ['Warehouse: which events open an automatic count of the bin?', 'Recommended: all four – each one means the shelf and the books may disagree. (A bin emptied by a pick is asked separately: Warehouse setting WH_COUNT_ON_ZERO.)', 'SHORT_PICK,NEGATIVE,PUTAWAY_NO_ROOM,EMPTY_NO', [
            'SHORT_PICK' => ['A short pick (fewer units found than expected)', ''], 'NEGATIVE' => ['Taking more than the bin holds', ''], 'PUTAWAY_NO_ROOM' => ['A putaway refused for no room', ''], 'EMPTY_NO' => ['The picker says an emptied bin is not empty', '']]],
        'CLOSE_WAIVABLE' => ['Month close: which checks may be waived (with a reason) instead of fixed?', 'Recommended: the five checks the specification allows – files that can disagree for a known reason; all others must be fixed because the figures would be wrong.', 'ORDERS_VS_TXN,HUB_TOTALS,PAYOUT_VS_BANK,PURCHASE_VS_SUPPLIER,ORDERS_FILE_VS_SALES,EXPENSES', []],
        'STOCK_UPDATE_REASON' => ['Stock alerts page: is a reason needed for every stock update?', 'Recommended: Yes – a stock change without a reason cannot be checked later (the old tool had it optional).', '1', [
            '1' => ['Yes, choose a reason', 'Suggested.'], '0' => ['No, the reason is optional', 'The history shows "no reason given".']]],
        'ACCOUNT_DUE_DAYS' => ['Accounts & subscriptions: "Due soon" (amber) means due within …', 'Recommended: 7 days – one week to pay or renew.', '7', [
            '3' => ['3 days', ''], '7' => ['7 days', 'Suggested.'], '14' => ['14 days', ''], '30' => ['30 days', '']]],
        'SALE_RECYCLE_DAYS' => ['Sales: deleted or replaced sale lines stay in the recycle bin (Undo) for …', 'Recommended: 30 days – long enough to notice a wrong delete at month close.', '30', [
            '7' => ['7 days', ''], '30' => ['30 days', 'Suggested.'], '60' => ['60 days', ''], '90' => ['90 days', '']]],
        'SALE_BULK_APPROVAL_LINES' => ['Sales: a bulk delete needs a second person above …', 'Recommended: 500 lines – a normal month of one shop goes through, a bigger delete is checked by a second person.', '500', [
            '100' => ['100 lines', ''], '500' => ['500 lines', 'Suggested.'], '1000' => ['1,000 lines', ''], '5000' => ['5,000 lines', '']]],
        'SALE_EXPORT_MAX_ROWS' => ['Sales report export: Excel / CSV up to …', 'Recommended: 20,000 rows – a year of a busy shop; a bigger file is slow to open. PDF and Word keep their own smaller limits.', '20000', [
            '5000' => ['5,000 rows', ''], '20000' => ['20,000 rows', 'Suggested.'], '50000' => ['50,000 rows', ''], '100000' => ['100,000 rows', '']]],
        'DASH_ERROR_ALERT' => ['A dashboard card that cannot load …', 'Recommended: one admin alert per card per day – enough to notice it, not one alert for every page open.', 'PER_DAY', [
            'PER_DAY' => ['raises one admin alert per card per day', 'Suggested.'], 'OFF' => ['is only written to the error log', 'No task; System › Health still shows the error.']]],
        'SEARCH_MIN_CHARS' => ['Search: a full search starts at …', 'Recommended: 2 characters – one letter only offers names that start with it; a full search on one letter finds almost everything.', '2', [
            '2' => ['2 characters', 'Suggested.'], '3' => ['3 characters', 'Fewer, more exact results on a big database.']]],
        'SEARCH_AMOUNT_TOLERANCE' => ['Search by an amount ("1,250") also finds amounts within …', 'Recommended: ± 0.5 % – rounding and small fees still match, a different payment does not.', '0.5', [
            '0' => ['exactly that amount (± 0.01)', ''], '0.5' => ['± 0.5 %', 'Suggested.'], '1' => ['± 1 %', ''], '2' => ['± 2 %', '']]],
        'CONTRACT_RENEWAL_DAYS' => ['Job history: a contract renewal date shows amber when it is within …', 'Recommended: 30 days – a month to talk about the new contract.', '30', [
            '14' => ['14 days', ''], '30' => ['30 days', 'Suggested.'], '60' => ['60 days', ''], '90' => ['90 days', '']]],
        'SECURE_2FA' => ['Opening a secret (Reveal, secure pages) needs two-step sign-in …', 'Recommended: from everyone – a password alone is not enough for bank and card secrets; a login without the phone app is asked to set it up first.', 'REQUIRE', [
            'REQUIRE' => ['from everyone (set it up first)', 'Suggested (specification).'], 'IF_SET_UP' => ['only from logins that have it set up', 'Others reveal with their password sign-in only (every reveal is still logged).']]],
        'SECURE_REASK_MINUTES' => ['Secrets: the code of the phone app is asked again after … without activity', 'Recommended: 60 minutes (owner decision #107) – a normal working hour goes on, a forgotten open screen closes.', '60', [
            '15' => ['15 minutes', 'The old proposal.'], '30' => ['30 minutes', ''], '60' => ['60 minutes', 'Suggested.'], '120' => ['2 hours', '']]],
        'RESET_LINK_MINUTES' => ['Forgot password: the reset link works for …', 'Recommended: 30 minutes – enough to open the e-mail, short enough that an old mail is useless.', '30', [
            '15' => ['15 minutes', ''], '30' => ['30 minutes', 'Suggested.'], '60' => ['1 hour', ''], '1440' => ['1 day', '']]],
        'SECURE_FORMS' => ['Secure pages (R-G_CORE-59): which pages ask the phone-app code before they open?', 'Recommended: bank accounts, PayPal, supplier banks and tax returns – they hold money details; the code is asked once per session and again after the idle time.', 't_account_bank,t_account_paypal,supplier_bank,tax_period,tax_entry', []],
        'OWN_SHOP_ROLES' => ['Data scope: which roles see only their own shops (R-G_CORE-63)?', 'Recommended: VA and Team lead – they work for given shops; a person of such a role with no shop sees no shop rows. Other roles see every shop of their companies (a shop limit set on one login still applies).', 'VA,TL', []],
        'ABSENCE_CALENDAR_ROLES' => ['Tasks: who may open the absence calendar (who is away, who covers – names only, never the reason)?', 'Recommended: Owner, Partner, Manager and TL – the same people who see "Who is away today" on Home and hand out the work.', 'OWNER,PARTNER,MANAGER,TL', []],
        'PACKING_FEE_PER' => ['Investor invoicing: the packing fee of a sale line is charged …', 'Recommended: once per sale line – packing labour is per parcel, not per unit.', 'SALE_LINE', ['SALE_LINE' => ['once per sale line', 'Suggested.'], 'UNIT' => ['per unit (at least 1)', 'When the 3PL bills packing per item.']]],
        'STORAGE_FEE_PER' => ['Investor invoicing: the storage fee of a sale line is charged …', 'Recommended: per unit (at least 1) – storage is paid for every unit that sat on the shelf.', 'UNIT', ['UNIT' => ['per unit (at least 1)', 'Suggested.'], 'SALE_LINE' => ['once per sale line', 'When the 3PL bills storage per order.']]],
        'REFUND_CHARGES_PACKING_FEE' => ['Investor invoicing: a refunded line is still charged the packing fee?', 'Recommended: yes – the parcel was packed before it was refunded.', 'Y', ['Y' => ['yes', 'Suggested.'], 'N' => ['no', 'Refunds carry no packing fee.']]],
        'REFUND_CHARGES_STORAGE_FEE' => ['Investor invoicing: a refunded line is still charged the storage fee?', 'Recommended: yes – the unit was stored before it was sold.', 'Y', ['Y' => ['yes', 'Suggested.'], 'N' => ['no', 'Refunds carry no storage fee.']]],
        /* R-G_CORE-85 the settings shell of investor invoicing (old config/invoicing.php) – what they do is in R-D-81 / R-D-82 */
        'LOGIN_CODE_ROLES' => ['Sign-in: which roles get a WhatsApp code as the second step when they have no authenticator app?', 'Recommended: Owner, Finance and Partner (A10). The code is asked only when the person has a WhatsApp number, a WhatsApp connection is set up and the LOGIN_CODE template is approved – nobody is locked out.', 'OWNER,FINANCE,PARTNER', []],
        'INVOICING_ADMIN_ROLES' => ['Investor invoicing: who may settle a month, make, reopen and cancel investor documents?', 'Recommended: Owner, Manager and Finance – the old invoicing admins (ADMN-SUPER, ADMN) and the people who run the money now.', 'OWNER,MANAGER,FINANCE', []],
        'INV_MULTI_INVESTOR_SPLIT' => ['Investor invoicing: a sale line of a product with more than one investor is …', 'Recommended: divided equally – the old rule (multi_investor_split = equal).', 'EQUAL', ['EQUAL' => ['divided equally between the investors', 'Suggested.'], 'FIRST' => ['given to the first investor linked to the product', 'When one investor is the lead of a shared product.']]],
        'INV_LANDING_FIELD' => ['Investor invoicing: the landing cost of a unit is read from …', 'Recommended: the product\'s landing price (a secure field) – the old landing_price.', 'LANDING_PRICE', ['LANDING_PRICE' => ['the product\'s landing price (secure field)', 'Suggested.'], 'LOT_COST' => ['the cost of the lots the line was sold from (FIFO)', 'When landing prices are not kept up to date.']]],
        'INV_CURRENCY' => ['Investor invoicing: invoices and statements are made in …', 'Recommended: GBP £ – the old setting; every figure of the settlement is in the base currency.', 'GBP', ['GBP' => ['GBP £', 'Suggested.'], 'BASE' => ['the company\'s base currency', 'When the company keeps its books in another currency (the settlement figures are in the base currency).']]],
        'BLOCK_UNASSIGNED_INVESTOR' => ['Investor invoicing: sale lines of products with no investor …', 'Recommended: block the settlement – otherwise that investor share is invoiced to nobody.', 'Y', ['Y' => ['block: add the investor first', 'Suggested.'], 'N' => ['warn only: their share is not invoiced', 'For a month that must go out now.']]],
        'EXCEPTIONS_DIGEST_ROLES' => ['Tasks: who gets the daily exceptions digest (what the system found, approvals and rights requests waiting, failed messages, Health warnings)?', 'Recommended: Owner only – the team digest already goes to Partners and Managers; the exceptions are the Owner\'s list.', 'OWNER', []],
        'OLD_ENTRY_OWNER_REQUIRED' => ['Old purchases (Old entry / historical CSV): must the owner entity (buyer) be given?', 'Recommended: yes – a lot without an owner has nobody to give its profit to; the old tool allowed it empty.', 'Y', ['Y' => ['required', 'an entry without the owner is refused'], 'N' => ['optional', 'as the old tool: lots may have no owner']]],
        'OLD_ENTRY_APPROVAL' => ['Old purchases with received goods: does the stock wait for an approval?', 'Recommended: yes, unless the Owner entered it – an old entry makes lots and stock value, so a second person checks it first (as the old tool: "PENDING APPROVAL – stock unaffected until a Partner approves").', 'NOT_OWNER', ['NOT_OWNER' => ['wait, unless the Owner entered it', 'the Owner or a Manager approves on Approvals'], 'NEVER' => ['never wait', 'the stock is posted at once']]],
        'OLD_ENTRY_BALANCE_DUE_DAYS' => ['Old purchases: the open balance of a part-paid order is due … after the order date', 'Recommended: 30 days – it shows on the dashboard and raises SRC_PAY_DUE; the date can be changed on the term.', '30', ['14' => ['14 days', ''], '30' => ['30 days', ''], '45' => ['45 days', ''], '60' => ['60 days', '']]],
        'PORTAL_CAN_RECORD_PAYMENT' => ['Investor portal: may an investor record a payment on his own invoice?', 'Recommended: yes (old tool) – he records what he paid with the proof; the office still sees every payment.', 'Y', ['Y' => ['yes', 'only on his own unpaid / part-paid documents'], 'N' => ['no', 'only the office records payments']]],
        'PORTAL_CAN_SEND_INVOICE' => ['Investor portal: may an investor e-mail his own invoice to the Account Owner?', 'Recommended: yes (old tool).', 'Y', ['Y' => ['yes', 'to the recipient e-mail of the document'], 'N' => ['no', 'only the office sends']]],
        'PORTAL_CAN_GENERATE' => ['Investor portal: may an investor make his own invoice of a settled month?', 'Recommended: no – the office makes the month\'s documents together, so numbers and the owner statements stay in one run; switch on when investors should not wait for the office.', 'N', ['N' => ['no', 'only the office generates'], 'Y' => ['yes', 'an investor can generate only his own INVESTOR / SELF-SETTLEMENT document']]],
        'LISTING_VARIATION_COLUMN' => ['eBay active-listings file: is the column "Variation details" needed?', 'Recommended: optional – a shop without variations exports the file without it; when it is there it is added to the title.', 'OPTIONAL', ['OPTIONAL' => ['optional', 'used when present'], 'REQUIRED' => ['required', 'as the old tool: a file without it is refused']]],
        'AUDIT_PRICE_TOLERANCE' => ['Purchase audit: a buy amount counts as different from the supplier\'s (Amazon / GD) when it is off by more than …', 'Recommended: £0.01 – a penny of rounding is not a problem; the old tool flagged any difference.', '0.01', ['0' => ['nothing (any difference)', 'as the old tool'], '0.01' => ['£0.01', 'rounding is not flagged'], '0.05' => ['£0.05', ''], '0.10' => ['£0.10', '']]],
        'DQ_TASK_BELOW_PCT' => ['Data quality: a master record less complete than … % gets a task to fill it', 'Recommended: 60 % – only records with real gaps get a task; at most 200 new tasks a night.', '60', ['0' => ['No tasks', 'the score is still shown'], '40' => ['Below 40 %', ''], '60' => ['Below 60 %', ''], '80' => ['Below 80 %', '']]],
        'DS_HOLD_DAYS' => ['GD audit: a hold order (HO…) not used in a sale after … days gets a "cancel hold" task', 'Recommended: 14 days – long enough for a slow sale, short enough not to tie up money.', '14', ['7' => ['7 days', ''], '14' => ['14 days', ''], '21' => ['21 days', ''], '30' => ['30 days', '']]],
        'LOT_SPLIT_BY' => ['GRN: the lots of a PO with partner agreements are split by …', 'Recommended: the capital each partner put into the PO (an agreement with no capital falls back to the profit share %).', 'CAPITAL', ['CAPITAL' => ['committed capital', 'pro rata to the capital of each ACTIVE PO agreement'], 'SHARE_PCT' => ['profit share %', 'pro rata to the profit share % of each ACTIVE PO agreement']]],
        'ACC_REMINDERS_UNASSIGNED' => ['Tax & filings: who gets the reminders of a filing that has no accountant assigned?', 'Recommended: the Owner – a filing with nobody on it is the one most likely to be missed.', 'OWNER', ['OWNER' => ['The Owner', 'the Owner logins get them'], 'NOBODY' => ['Nobody', 'only filings with an accountant remind']]],
        'INV_VA_JOB_ROW' => ['Invoicing: which VA job-history row gives the VA salary of a month?', 'Recommended: the row valid on the month end (start on or before it, not ended) – a later pay change never rewrites an old month. The old tool took the latest row; that result is shown beside it when it differs.', 'MONTH_END', ['MONTH_END' => ['the row valid on the month end', 'start ≤ month end, no end date or end ≥ month end'], 'LATEST' => ['the latest row (old tool)', 'the newest job-history row by entry']]],
        'HIDDEN_MARGIN_ROLES' => ['Owner Home › Invoicing / Profit: who sees the row "Hidden margin"?', 'Recommended: Owner only – a partner\'s share is worked out on the net profit, so the margin kept by the company stays with the Owner.', 'OWNER', []],
    ];

    /** Multiple choices: key → where the options come from (party roles = roles of people, login roles = roles of logins). Value = comma list or ALL. */
    public const MULTI = ['DIRECTOR_ROLES' => 'party_roles', 'LOGIN_CODE_ROLES' => 'login_roles', 'INVOICING_ADMIN_ROLES' => 'login_roles', 'HIDDEN_MARGIN_ROLES' => 'login_roles', 'EXCEPTIONS_DIGEST_ROLES' => 'login_roles', 'ABSENCE_CALENDAR_ROLES' => 'login_roles', 'FBA_LOCATION_KINDS' => 'list:LOCATION_KIND', 'TASK_PROOF_OFF_ROLES' => 'login_roles', 'CHANNEL_SWITCH_ROLES' => 'login_roles', 'ABSENCE_ROLES' => 'login_roles', 'DELEGATION_ROLES' => 'login_roles', 'CATEGORY_ROLES' => 'login_roles', 'COUNT_TRIGGERS' => 'inline', 'CLOSE_WAIVABLE' => 'close_checks', 'SECURE_FORMS' => 'secure_forms', 'OWN_SHOP_ROLES' => 'login_roles'];

    /** [code => [label, effect]] of a multiple choice (All first). */
    public static function options(string $key): array
    {
        if (! isset(self::MULTI[$key])) return self::LIST[$key][3];
        $src = self::MULTI[$key];
        if ($src === 'inline') return ['ALL' => ['All', 'Every one of them.']] + self::LIST[$key][3];
        if ($src === 'secure_forms') { $o = ['ALL' => ['Every page', '']]; foreach (\Illuminate\Support\Facades\DB::table('sys_registry')->where('form_kind', 'module')->select('form_key', 'form_title')->distinct()->orderBy('form_title')->get() as $f) $o[$f->form_key] ??= [(string) $f->form_title, '']; foreach (explode(',', self::LIST[$key][2]) as $d) $o[$d] ??= [$d, '']; return $o; }
        if ($src === 'close_checks') { $o = ['ALL' => ['All checks', 'Every check may be waived.']]; foreach (CloseService::CHECKS as $c => $x) $o[$c] = [$x[0], '']; return $o; }
        if (str_starts_with($src, 'list:')) {      // a list of the registry (fixed lists the program uses have no + Add)
            $o = ['ALL' => ['All', 'Every value of the list.']];
            foreach (\Illuminate\Support\Facades\DB::table('sys_field_options')->where('list_code', substr($src, 5))->where('f_active', 1)->orderBy('sort')->get(['value', 'label']) as $r) $o[strtoupper((string) $r->value)] = [(string) $r->label, ''];
            foreach (explode(',', self::LIST[$key][2]) as $d) $o[$d] ??= [$d, 'older name, kept'];
            return $o;
        }
        $o = ['ALL' => ['All roles', 'Every role, also roles added later.']];
        if ($src === 'party_roles') foreach (\Illuminate\Support\Facades\DB::table('cat_account_types')->where('f_active', 1)->whereNotNull('role_code')->where('role_code', '<>', '')->orderBy('sort')->orderBy('account_type_name')->get(['role_code', 'account_type_name']) as $r) $o[strtoupper((string) $r->role_code)] ??= [(string) $r->account_type_name, ''];
        else foreach (Perm::roles() as $code => $r) if ($r['active']) $o[$code] = [$r['label'], ''];
        foreach (explode(',', self::LIST[$key][2]) as $d) $o[$d] ??= [ucfirst(strtolower($d)), ''];      // the recommended roles are always offered
        return $o;
    }

    /** The roles of a multiple choice as a list (ALL → every option). */
    public static function list(string $key, ?int $ent): array
    {
        $v = array_values(array_filter(array_map('trim', explode(',', self::MULTI[$key] === 'secure_forms' ? self::get($key, $ent) : strtoupper(self::get($key, $ent))))));
        return in_array('ALL', $v, true) || in_array('all', $v, true) ? array_values(array_diff(array_keys(self::options($key)), ['ALL'])) : $v;
    }

    /** Is $role in the multiple choice $key? (ALL → yes) */
    public static function has(string $key, ?int $ent, ?string $role): bool
    {
        if ($role === null || $role === '') return false;
        $v = array_map(fn ($x) => strtoupper(trim($x)), explode(',', self::get($key, $ent)));
        return in_array('ALL', $v, true) || in_array(strtoupper($role), $v, true);
    }

    /** Choices a shop can have its own value for (several shops, several values); the company value is the default for every other shop. */
    public const PER_SHOP = ['STOCK_COST_LAYER', 'CARRIER_FILE_DEFAULT', 'REFUND_ON_CONFIRMED', 'PROFIT_SHARE_AFTER_OVERHEADS', 'CLOSE_FIRST_FILE_PARTIAL', 'LATE_LINES_LOCKED_MONTH', 'WH_UNSHIPPED_AT_CONFIRM', 'DEFAULT_PROFIT_ROUTE', 'LOT_OWNER_SHARE', 'TEMU_SALE_VALUE', 'DS_MIN_MARGIN', 'VA_EXPENSES_BEFORE_SHARE', 'VA_SALARY_SOURCE', 'OUTPUT_VAT_MODE', 'STATEMENT_SEND'];

    /** The shop's own value if it has one, else the company default, else the recommended option. No company yet (first sign-in after install) → the global value or the recommended one. */
    public static function get(string $key, ?int $ent, ?int $shop = null): string
    {
        if ($shop && in_array($key, self::PER_SHOP, true) && ($v = Settings::get($key . '@' . $shop, $ent, null)) !== null && $v !== '') return (string) $v;
        return (string) (Settings::get($key, $ent, null) ?? self::LIST[$key][2]);
    }

    /** Give one shop its own value ('' = follow the company default again). */
    public static function setShop(string $key, int $shop, string $value, int $ent): void
    {
        if (! in_array($key, self::PER_SHOP, true)) throw new \InvalidArgumentException('This choice is the same for the whole company.');
        if ($value !== '' && ! isset(self::LIST[$key][3][$value])) throw new \InvalidArgumentException('Choose one of the options shown.');
        if (! \Illuminate\Support\Facades\DB::table('t_branches')->where('id', $shop)->where('fk_id_ent', $ent)->exists()) throw new \InvalidArgumentException('Shop not found.');
        $old = Settings::get($key . '@' . $shop, $ent, null);
        Settings::set($key . '@' . $shop, $value, $ent, Context::userId());
        Audit::log('sys_settings', null, 'UPDATE', ['choice' => $key, 'shop' => $shop, 'from' => $old ?? 'company default', 'to' => $value === '' ? 'company default' : $value], null, $ent);
    }

    public static function all(int $ent): array
    {
        $out = [];
        foreach (self::LIST as $key => $row) {
            [$q, $why, $def] = $row; $opts = self::options($key); $fact = ! empty($row[4]); $multi = isset(self::MULTI[$key]);
            $cur = self::get($key, $ent); if (! $multi && ! isset($opts[$cur])) $cur = $def;
            $recList = $multi ? explode(',', $def) : [$def];
            $shops = [];
            if (in_array($key, self::PER_SHOP, true)) foreach (\Illuminate\Support\Facades\DB::table('t_branches')->where('fk_id_ent', $ent)->where('f_active', 1)->orderBy('shop_name')->get(['id', 'shop_name']) as $b) {
                $v = Settings::get($key . '@' . $b->id, $ent, null); $shops[] = ['id' => $b->id, 'name' => $b->shop_name, 'value' => $v !== null && $v !== '' && isset($opts[$v]) ? (string) $v : ''];
            }
            $out[] = ['key' => $key, 'question' => $q, 'why' => $why, 'value' => $cur, 'recommended' => $fact ? null : $def, 'per_shop' => in_array($key, self::PER_SHOP, true), 'shops' => $shops,
                'options' => array_map(fn ($v, $o) => ['value' => (string) $v, 'label' => $o[0], 'effect' => $o[1], 'recommended' => ! $fact && in_array((string) $v, $recList, true)], array_keys($opts), $opts), 'fact' => $fact,
                'multi' => $multi, 'values' => $multi ? array_values(array_filter(explode(',', self::MULTI[$key] === 'secure_forms' ? $cur : strtoupper($cur)))) : null, 'add_url' => $multi ? (self::MULTI[$key] === 'party_roles' ? '#/list/cat_account_type' : (self::MULTI[$key] === 'login_roles' ? '#/rights' : null)) : null];      // inline / list / close_checks: values the program uses – no + Add
        }
        return $out;
    }

    public static function set(string $key, string $value, int $ent): void
    {
        if (! isset(self::LIST[$key])) throw new \InvalidArgumentException('Unknown choice.');
        if (isset(self::MULTI[$key])) {
            $v = array_values(array_unique(array_filter(array_map('trim', explode(',', self::MULTI[$key] === 'secure_forms' ? $value : strtoupper($value))))));
            $what = str_ends_with(self::MULTI[$key], '_roles') ? 'role' : 'value';
            if (! $v) throw new \InvalidArgumentException("Choose at least one $what (or All).");
            $ok = self::options($key); foreach ($v as $x) if (! isset($ok[$x])) throw new \InvalidArgumentException("Choose {$what}s from the list ($x is not one).");
            $value = in_array('ALL', $v, true) ? 'ALL' : implode(',', $v);
        } elseif (! isset(self::LIST[$key][3][$value])) throw new \InvalidArgumentException('Choose one of the options shown.');
        $old = self::get($key, $ent);
        Settings::set($key, $value, $ent, Context::userId());
        Audit::log('sys_settings', null, 'UPDATE', ['choice' => $key, 'from' => $old, 'to' => $value], null, $ent);
    }
}
