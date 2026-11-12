# Sample money files (all made up)

These files show the layouts the tool reads on **Money › Payouts & settlements › Load money file**. No real order, buyer or amount is in them.

| File | Format | Where the layout comes from |
|---|---|---|
| amazon_date_range_transactions_made_up.csv | Amazon date range report, type Transaction (Payments › Reports repository), CSV | The layout of a real report of the owner (Jul–Sep 2026). The whole real report (4,632 lines, 21 settlements) was loaded and booked in a trial: every line adds up, the ledger balances. |
| amazon_settlement_v2_made_up.txt | Amazon settlement report, flat file V2 (tab separated) | Amazon's published column list (SP-API report type GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE_V2). Not checked on a real Amazon file yet. |
| ebay_transaction_report_made_up.csv | eBay Seller Hub › Payments › Reports › Transaction report | The layout of a real report of the owner (Oct 2026): notes block, header line, `--` for empty, dates like `5 Oct 2026`. |
| onbuy_table_made_up.csv | Any table, read by column names (wide: one column per amount) | No published OnBuy format was found. Type the payout reference in the form. |
| temu_funds_table_made_up.csv | Any table, read by column names (long: type + amount) | Temu gives its statement as .xlsx (Seller Center › Finances › Payments › Payouts › View statement details › Download › Excel) but does not publish its layout; every sheet of the workbook is tried. Temu's *order list* export is refused: it is not a money file. |
