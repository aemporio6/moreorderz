<?php

use App\Services\FinanceAlerts;
use App\Services\PartnerOnboarding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R-FIN-66 / 67: the ten finance alert rules (on, Owner, default channels), their task rules FIN_FA1 … FIN_FA10 and the WhatsApp template FIN_ALERT.
 * R-FIN-3: partners who already have a linked login keep working (all six items ticked once, audited as a migration); the partner-login form
 * picks from logins with role PARTNER (it listed people before, which stored the wrong id).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (FinanceAlerts::RULES as $code => [$label, $channels, $sort])
            if (! DB::table('fin_alert_rules')->where('code', $code)->exists())
                DB::table('fin_alert_rules')->insert(['code' => $code, 'label' => $label, 'to_roles' => 'OWNER', 'channels' => $channels, 'is_active' => 1, 'sort' => $sort]);
        foreach (FinanceAlerts::RULES as $code => [$label])
            if (! DB::table('sys_rules')->where('rule_code', 'FIN_' . $code)->exists())
                DB::table('sys_rules')->insert(['rule_code' => 'FIN_' . $code, 'name' => "Finance alert $code: $label", 'module' => 'Finance', 'level' => 'NORMAL', 'assign_to' => 'role:OWNER', 'due_days' => 1,
                    'handler' => 'FinanceAlerts::run', 'f_active' => 1, 'priority' => 'HIGH', 'category_id' => 10, 'dedupe_key' => "FIN_$code:<alert>", 'auto_close' => 'alert resolved', 'fix_link' => '#/fin-alerts']);
        if (! DB::table('wa_templates')->where('code', 'FIN_ALERT')->exists())
            DB::table('wa_templates')->insert(['code' => 'FIN_ALERT', 'title' => 'Finance alert', 'audience' => 'OWNER', 'body' => 'Finance alert {code}: {text} {link}', 'f_active' => 1]);
        foreach (DB::table('fin_partner_users')->pluck('investor_id')->unique() as $p)
            if (! DB::table('fin_onboarding')->where('investor_id', $p)->exists())
                DB::table('fin_onboarding')->insert(['investor_id' => $p] + array_fill_keys(array_keys(PartnerOnboarding::ITEMS), 1) + ['updated_at' => now()]);
        DB::table('sys_registry')->where('form_key', 'fin_partner_user')->where('field_name', 'user_id')->update(['source' => 'table:t_users?role_code=PARTNER', 'label' => 'Partner login', 'add_form' => null]);
    }

    public function down(): void
    {
        DB::table('sys_rules')->where('rule_code', 'like', 'FIN_FA%')->delete();
        DB::table('fin_alert_rules')->whereIn('code', array_keys(FinanceAlerts::RULES))->delete();
        DB::table('wa_templates')->where('code', 'FIN_ALERT')->delete();
        DB::table('sys_registry')->where('form_key', 'fin_partner_user')->where('field_name', 'user_id')->update(['source' => 'table:t_account?login=1', 'label' => 'User', 'add_form' => 't_account']);
    }
};
