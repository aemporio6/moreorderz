# Form standard audit

Made by `FormStandard::audit()` (batch 7e-103, 21 Nov 2026). Checked again on every test run by `FormStandardTest` over every form.

## Summary

| Check | Result |
|---|---|
| Dropdown fields (select / multi-select) | 1538 |
| Take their values from one central master (table, person role, reference, central list) | 1538 of 1538 |
| Have **+ Add** (a form or a new list value) | 1465 |
| Fixed lists (the values drive the program – no Add, developer only) | 67 |
| Made by the system only (imports, drivers, device slots) – no Add, with the reason | 6 |
| Without Add and without a reason | **0** |
| Take several values (All / None in the dropdown) | 28 |
| Have a default | 293 (every dropdown also shows the 3 values this person used lately) |
| Long lists shown in groups (product type, marketplace, country, role, kind, status) | 404 |
| Create menu entries (built from the registry) | 234 (was 143 from a hand-kept list) |

## What changed in this batch

- 87 dropdowns kept their own options inside the form → each now reads a central list (82 lists; 60 of them fixed because the program uses the values).
- The money categories got a form (Money category), so their 9 dropdowns have + Add; a system category keeps its code, direction and place in the profit.
- `table:x?col=value` sources now really narrow the list (an expense shows only money-out categories) and + Add from there fills the same value.
- Products show as "SKU · name"; banks by their full name.
- Every e-mail and link field of every form is checked when it is left; a typo gets **Use “…”** that puts the right value in. A dropdown with no match shows **Did you mean …**.
- The Owner sets who sees a field (Hidden / Read-only / Can change per role) from the eye next to its label.
- The Create menu lists every main form of every module by itself; a form switched off in sys_forms stays out; child lines are made inside their parent.

## Exceptions with their reason

### System (6)

| Form | Field | Source | Why |
|---|---|---|---|
| customer_case | sale | table:t_sale_orders | orders come from the sales import |
| fin_ledger_entry | statement_id | table:fin_statements | statements come from the bank import |
| fin_statement_query | statement_id | table:fin_statements | statements come from the bank import |
| printer_profile | fk_id_adapter | table:print_adapters | the printer drivers the tool can talk to |
| t_account_mobile | slotno | chain:device_slots | the free slots of the chosen device |
| t_sale_order_reminders | fk_id_t_sale_orders | table:t_sale_orders | orders come from the sales import |

### Fixed (67)

| Form | Field | Source | Why |
|---|---|---|---|
| acc_obligation | entity_type | list:ENTITY_TYPE | values drive the program |
| acc_obligation | completion | list:COMPLETION | values drive the program |
| acc_obligation_type | authority | list:AUTHORITY | values drive the program |
| acc_obligation_type | completion | list:COMPLETION | values drive the program |
| aud_expense_rule | method | list:METHOD | values drive the program |
| aud_run | status | list:AUD_RUN_STATUS | values drive the program |
| car_preset | cp_scope_mode | list:CP_SCOPE_MODE | values drive the program |
| cat_reference | add_rule | list:ADD_RULE | values drive the program |
| daily_order_entry | f_status | list:F_STATUS | values drive the program |
| drp_inventory | drp_dup_mode | list:DRP_DUP_MODE | values drive the program |
| fin_capital_call_response | status | list:FIN_CAPITAL_CALL_RESPONSE_STATUS | values drive the program |
| fin_category | direction | list:FIN_DIRECTION | values drive the program |
| fin_category | profit_role | list:FIN_PROFIT_ROLE | values drive the program |
| fin_company_setting | distribution_schedule | list:DISTRIBUTION_SCHEDULE | values drive the program |
| fin_investor_agreement | partner_type | list:PARTNER_TYPE | values drive the program |
| fin_investor_agreement | payout_schedule | list:PAYOUT_SCHEDULE | values drive the program |
| fin_ledger_entry | type | list:FIN_LEDGER_ENTRY_TYPE | values drive the program |
| fin_loan | direction | list:DIRECTION | values drive the program |
| fin_lot | source | list:FIN_LOT_SOURCE | values drive the program |
| fin_lot | landed_status | list:FIN_LOT_LANDED_STATUS | values drive the program |
| fin_opening_balance | type | list:TYPE | values drive the program |
| icc | fulfil | list:FULFIL | values drive the program |
| imp_report_type | period_rule | list:PERIOD_RULE | values drive the program |
| inv_profit_split | owner_mode | list:OWNER_MODE | values drive the program |
| inv_profit_split | va_mode | list:VA_MODE | values drive the program |
| inv_profit_split | handler_mode | list:HANDLER_MODE | values drive the program |
| invoice_calculation | status | list:STATUS | values drive the program |
| location_generator | level_style | list:LEVEL_STYLE | values drive the program |
| marketplace | payout | list:PAYOUT | values drive the program |
| pick_task | pick_mode | list:WH_PICK_MODE | values drive the program |
| product | cost_method | list:COST_METHOD | values drive the program |
| return_intake | resolved_as | list:RESOLVED_AS | values drive the program |
| stock_count | status | list:STOCK_COUNT_STATUS | values drive the program |
| stock_reservation | reason | list:REASON | values drive the program |
| sys_approval_rule | risk | list:RISK | values drive the program |
| t_account_marketplace | market_place_type | list:MARKET_PLACE_TYPE | values drive the program |
| t_inv_settings | party_type | list:T_INV_SETTINGS_PARTY_TYPE | values drive the program |
| t_inv_settings | owner_mode | list:OWNER_MODE | values drive the program |
| t_inv_settings | va_mode | list:VA_MODE | values drive the program |
| t_inv_settings | handler_mode | list:HANDLER_MODE | values drive the program |
| t_product_vendor | icc_status | list:ICC_STATUS | values drive the program |
| t_product_vendor | cost_method | list:T_PRODUCT_VENDOR_COST_METHOD | values drive the program |
| t_sale_order | sale_order_status | list:SALE_ORDER_STATUS | values drive the program |
| task_comment | via | list:VIA | values drive the program |
| task_delegation | rights | list:RIGHTS | values drive the program |
| task_edit | stuck_on_type | list:STUCK_ON_TYPE | values drive the program |
| task_edit | quality | list:QUALITY | values drive the program |
| task_edit | source | list:SOURCE | values drive the program |
| tax_category | section | list:SECTION | values drive the program |
| tax_line_item | line_role | list:LINE_ROLE | values drive the program |
| tax_line_item | tax_treatment | list:TAX_TREATMENT | values drive the program |
| tax_period | accounts_type | list:ACCOUNTS_TYPE | values drive the program |
| tax_period | status | list:TAX_PERIOD_STATUS | values drive the program |
| warehouse_settings | wh_reserve_on | list:WH_RESERVE_ON | values drive the program |
| warehouse_settings | wh_pick_mode | list:WH_PICK_MODE | values drive the program |
| warehouse_settings | wh_scan_mode | list:WH_SCAN_MODE | values drive the program |
| warehouse_settings | wh_return_new_to | list:WH_RETURN_NEW_TO | values drive the program |
| warehouse_settings | wh_replen_mode | list:WH_REPLEN_MODE | values drive the program |
| warehouse_settings | wh_preset | list:WH_PRESET | values drive the program |
| warehouse_settings | wh_pick_strategy | list:WH_PICK_STRATEGY | values drive the program |
| warehouse_settings | wh_unshipped_at_confirm | list:WH_UNSHIPPED_AT_CONFIRM | values drive the program |
| warehouse_settings | wh_return_opened_to | list:WH_RETURN_OPENED_TO | values drive the program |
| warehouse_settings | wh_cycle_count_mode | list:WH_CYCLE_COUNT_MODE | values drive the program |
| warehouse_settings | wh_loc_level_style | list:WH_LOC_LEVEL_STYLE | values drive the program |
| warehouse_settings | wh_barcode_type | list:WH_BARCODE_TYPE | values drive the program |
| warehouse_settings | wh_storage_mode | list:WH_STORAGE_MODE | values drive the program |
| warehouse_settings | wh_loss_lot_rule | list:WH_LOSS_LOT_RULE | values drive the program |

