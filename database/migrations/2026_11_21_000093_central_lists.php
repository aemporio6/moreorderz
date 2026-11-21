<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner's form standard (21 Nov 2026): every dropdown takes its values from ONE central list. The 87 dropdowns that kept their own
 * options inside the form ("inline") get a central list (sys_field_options); a list whose values drive the program is marked fixed
 * (sys_field_lists.f_fixed – no "+ Add", the values are changed by the developer only). The same change is in database/meta/field_registry.csv.
 */
return new class extends Migration {
    /** form.field whose values the code uses (FIFO, CONFIRMED, FBA …) */
    public const FIXED = [
            'acc_obligation.completion',
            'acc_obligation.entity_type',
            'acc_obligation_type.authority',
            'acc_obligation_type.completion',
            'aud_expense_rule.method',
            'aud_run.status',
            'car_preset.cp_scope_mode',
            'cat_reference.add_rule',
            'daily_order_entry.f_status',
            'drp_inventory.drp_dup_mode',
            'fin_capital_call_response.status',
            'fin_company_setting.distribution_schedule',
            'fin_investor_agreement.partner_type',
            'fin_investor_agreement.payout_schedule',
            'fin_ledger_entry.type',
            'fin_loan.direction',
            'fin_lot.landed_status',
            'fin_lot.source',
            'fin_opening_balance.type',
            'icc.fulfil',
            'imp_report_type.period_rule',
            'inv_profit_split.handler_mode',
            'inv_profit_split.owner_mode',
            'inv_profit_split.va_mode',
            'invoice_calculation.status',
            'location_generator.level_style',
            'marketplace.payout',
            'product.cost_method',
            'return_intake.resolved_as',
            'stock_count.status',
            'stock_reservation.reason',
            'sys_approval_rule.risk',
            't_account_marketplace.market_place_type',
            't_inv_settings.handler_mode',
            't_inv_settings.owner_mode',
            't_inv_settings.party_type',
            't_inv_settings.va_mode',
            't_product_vendor.cost_method',
            't_product_vendor.icc_status',
            't_sale_order.sale_order_status',
            'task_comment.via',
            'task_delegation.rights',
            'task_edit.quality',
            'task_edit.source',
            'task_edit.stuck_on_type',
            'tax_category.section',
            'tax_line_item.line_role',
            'tax_line_item.tax_treatment',
            'tax_period.accounts_type',
            'tax_period.status',
            'warehouse_settings.wh_barcode_type',
            'warehouse_settings.wh_cycle_count_mode',
            'warehouse_settings.wh_loc_level_style',
            'warehouse_settings.wh_loss_lot_rule',
            'warehouse_settings.wh_pick_mode',
            'warehouse_settings.wh_pick_strategy',
            'warehouse_settings.wh_preset',
            'warehouse_settings.wh_replen_mode',
            'warehouse_settings.wh_reserve_on',
            'warehouse_settings.wh_return_new_to',
            'warehouse_settings.wh_return_opened_to',
            'warehouse_settings.wh_scan_mode',
            'warehouse_settings.wh_storage_mode',
            'warehouse_settings.wh_unshipped_at_confirm',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('sys_field_lists')) Schema::create('sys_field_lists', function ($t) {
            $t->increments('id'); $t->string('list_code', 60)->unique(); $t->string('title', 150)->nullable(); $t->tinyInteger('f_fixed')->default(0); $t->string('note', 255)->nullable(); $t->timestamps();
        });
        foreach (DB::table('sys_registry')->where('source', 'inline')->whereNotNull('options_full')->orderBy('id')->get(['id', 'form_key', 'field_name', 'label', 'options_full']) as $f) {
            $opts = json_decode((string) $f->options_full, true) ?: []; if (! $opts) continue;
            $vals = array_map(fn ($o) => (string) ($o[0] ?? ''), $opts); sort($vals);
            $base = trim(preg_replace('/[^A-Z0-9]+/', '_', strtoupper($f->field_name)), '_'); $code = null;
            foreach ([$base, trim(preg_replace('/[^A-Z0-9]+/', '_', strtoupper($f->form_key)), '_') . '_' . $base] as $try) {
                $have = DB::table('sys_field_options')->where('list_code', $try)->pluck('value')->map(fn ($v) => (string) $v)->sort()->values()->all();
                if (! $have || $have === $vals) { $code = $try; break; }
            }
            $code ??= 'F' . $f->id . '_' . $base;
            foreach (array_values($opts) as $i => $o) if (! DB::table('sys_field_options')->where('list_code', $code)->where('value', (string) $o[0])->exists())
                DB::table('sys_field_options')->insert(['list_code' => $code, 'value' => (string) $o[0], 'label' => (string) ($o[1] ?? $o[0]), 'sort' => ($i + 1) * 10, 'f_active' => 1]);
            $fixed = in_array($f->form_key . '.' . $f->field_name, self::FIXED, true);
            $l = DB::table('sys_field_lists')->where('list_code', $code)->first();
            if (! $l) DB::table('sys_field_lists')->insert(['list_code' => $code, 'title' => $f->label, 'f_fixed' => $fixed ? 1 : 0, 'note' => $fixed ? 'Values drive the program – changed by the developer only.' : null, 'created_at' => now(), 'updated_at' => now()]);
            elseif ($fixed && ! $l->f_fixed) DB::table('sys_field_lists')->where('id', $l->id)->update(['f_fixed' => 1, 'note' => 'Values drive the program – changed by the developer only.', 'updated_at' => now()]);
            DB::table('sys_registry')->where('id', $f->id)->update(['source' => 'list:' . $code]);
        }
        \App\Services\Registry::forget();
    }
    public function down(): void {}
};
