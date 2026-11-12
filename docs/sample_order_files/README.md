# Sample order files (all made up)

Loaded on **Orders › Load order file**. The format is found from the file.

| File | Format | Where the layout comes from |
|---|---|---|
| ebay_orders_report_made_up.csv | eBay Seller Hub › Orders › Download report | The header of the owner's real April 2026 report (the test `test_the_real_ebay_orders_report_layout_is_read_line_by_line` uses the full real header). Dates like `30-Apr-26`, amounts with `£`, `--` where eBay hides the buyer. An order with several items: a head line, then one line per item. |
| temu_order_list_made_up.csv | Temu Seller Center › Orders › Export | The header of the owner's real Temu order list. Cancelled lines are left out. The sale value is Temu's base price by default (Settings › Choices: "Temu order list: which price is the sale value"). |
