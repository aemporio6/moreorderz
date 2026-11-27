<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 27-11 07:24 – "do not ask me, give me the option": the Owner decides, on one screen (Setup › Workspaces & bank links):
 *   workspaces – keep / switch off / switch on again / delete. Delete only when the workspace is empty; else the screen says what is in it.
 *                The workspace you are working in and the last active one can never be switched off (nothing breaks).
 *   bank accounts of people and companies – each one: which of our companies it belongs to, which Money bank account it is
 *                (an existing one, or made from it in one press), or "kept apart". Every choice can be changed again; each is written to the audit log.
 * Account numbers are shown masked (last 4); no PIN, password or security answer is ever read here.
 */
class OwnerOptions
{
    /** tables that make a workspace "not empty" => label */
    private const HOLDS = ['t_users' => 'user(s)', 't_product_vendors' => 'product(s)', 't_sales' => 'sale line(s)', 't_src_po' => 'purchase order(s)', 't_branches' => 'shop(s)', 't_account' => 'person / company record(s)',
        'cat_company_house' => 'company record(s)', 'fin_lots' => 'stock lot(s)', 'fin_bank_accounts' => 'Money bank account(s)'];

    private static function holds(int $ent): array
    {
        $out = [];
        foreach (self::HOLDS as $t => $label) { if (! Schema::hasTable($t)) continue; $col = Schema::hasColumn($t, 'fk_id_ent') ? 'fk_id_ent' : (Schema::hasColumn($t, 'company_id') ? 'company_id' : null); if (! $col) continue;
            $n = (int) DB::table($t)->where($col, $ent)->count(); if ($n) $out[] = "$n $label"; }
        return $out;
    }

    private static function mask(?string $v): string { $v = preg_replace('/\s+/', '', (string) $v); return $v === '' ? '' : '••••' . mb_substr($v, -4); }

    public static function page(int $ent): array
    {
        $ws = DB::table('t_enterprise')->orderBy('id')->get(['id', 'ent_name', 'f_active'])->map(function ($e) use ($ent) { $h = self::holds((int) $e->id);
            return ['id' => (int) $e->id, 'name' => $e->ent_name, 'active' => (bool) $e->f_active, 'current' => (int) $e->id === $ent, 'holds' => $h, 'empty' => ! $h]; })->all();
        $money = DB::table('fin_bank_accounts')->where('company_id', $ent)->orderBy('bank_name')->get(['id', 'bank_name', 'account_label', 'currency', 'is_active']);
        $banks = DB::table('t_account_banks as b')->join('t_account as a', 'a.id', '=', 'b.fk_id_account')->leftJoin('cat_banks as k', 'k.id', '=', 'b.fk_bank_id')->leftJoin('cat_currency as c', 'c.id', '=', 'b.fk_currency_id')
            ->where('a.fk_id_ent', $ent)->orderBy('a.first_name')->orderBy('b.id')->limit(1000)
            ->get(['b.id', 'b.f_active', 'b.account_no', 'b.iban_no', 'b.account_type', 'b.fk_id_company_house', 'b.fk_id_fin_bank', 'b.f_kept_apart', 'k.bank_name', 'c.currency_code', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as holder")])
            ->map(fn ($b) => ['id' => (int) $b->id, 'holder' => $b->holder, 'bank' => $b->bank_name ?: 'Bank not set', 'number' => self::mask($b->account_no ?: $b->iban_no), 'type' => $b->account_type, 'currency' => $b->currency_code,
                'active' => (bool) $b->f_active, 'company' => $b->fk_id_company_house ? (int) $b->fk_id_company_house : null, 'money' => $b->fk_id_fin_bank ? (int) $b->fk_id_fin_bank : null, 'apart' => (bool) $b->f_kept_apart,
                'state' => $b->f_kept_apart ? 'APART' : ($b->fk_id_fin_bank ? 'MONEY' : ($b->fk_id_company_house ? 'COMPANY' : 'OPEN'))])->all();
        return ['workspaces' => $ws, 'banks' => $banks, 'money' => $money, 'companies' => DB::table('cat_company_house')->whereIn('fk_id_ent', [0, $ent])->where('f_active', 1)->orderBy('company_name')->get(['id', 'company_name as name']),
            'open' => count(array_filter($banks, fn ($b) => $b['state'] === 'OPEN'))];
    }

    public static function workspace(int $current, int $id, string $action): string
    {
        $e = DB::table('t_enterprise')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Workspace not found.');
        if ($action === 'on') { DB::table('t_enterprise')->where('id', $id)->update(['f_active' => 1]); Audit::log('t_enterprise', $id, 'UPDATE', ['f_active' => [0, 1]], 'Workspace switched on', $current); return "{$e->ent_name} is on again."; }
        if ($id === $current) throw new \InvalidArgumentException('You are working in this workspace – choose another one on the top bar first.');
        if (DB::table('t_enterprise')->where('f_active', 1)->where('id', '<>', $id)->count() < 1) throw new \InvalidArgumentException('This is the last active workspace – it stays.');
        if ($action === 'off') {
            DB::table('t_enterprise')->where('id', $id)->update(['f_active' => 0]); Audit::log('t_enterprise', $id, 'UPDATE', ['f_active' => [1, 0]], 'Workspace switched off', $current);
            $u = (int) DB::table('t_users')->where('fk_id_ent', $id)->where('f_active', 1)->count();
            return "{$e->ent_name} is switched off – nothing was deleted; it can be switched on again." . ($u ? " Note: $u user(s) belong to it and cannot work until it is on again or they are moved." : '');
        }
        if ($action === 'delete') {
            $h = self::holds($id); if ($h) throw new \InvalidArgumentException("{$e->ent_name} is not empty: " . implode(', ', $h) . '. Switch it off instead – nothing is lost.');
            DB::table('t_enterprise')->where('id', $id)->delete(); Audit::log('t_enterprise', $id, 'DELETE', ['ent_name' => [$e->ent_name, null]], 'Empty workspace deleted', $current); return "{$e->ent_name} is deleted (it was empty).";
        }
        throw new \InvalidArgumentException('Choose keep, switch off or delete.');
    }

    /** $d: company (id | '' ), money (id | 'new' | ''), apart (bool). */
    public static function bank(int $ent, int $id, array $d): string
    {
        $b = DB::table('t_account_banks as b')->join('t_account as a', 'a.id', '=', 'b.fk_id_account')->leftJoin('cat_banks as k', 'k.id', '=', 'b.fk_bank_id')->leftJoin('cat_currency as c', 'c.id', '=', 'b.fk_currency_id')->where('b.id', $id)->where('a.fk_id_ent', $ent)
            ->first(['b.id', 'b.account_no', 'b.iban_no', 'b.fk_id_company_house', 'b.fk_id_fin_bank', 'b.f_kept_apart', 'k.bank_name', 'c.currency_code', DB::raw("TRIM(CONCAT_WS(' ', a.first_name, a.sur_name)) as holder")]) ?? throw new \InvalidArgumentException('Bank account not found.');
        $new = ['fk_id_company_house' => $b->fk_id_company_house, 'fk_id_fin_bank' => $b->fk_id_fin_bank, 'f_kept_apart' => (int) $b->f_kept_apart]; $said = [];
        if (! empty($d['apart'])) { $new = ['fk_id_company_house' => null, 'fk_id_fin_bank' => null, 'f_kept_apart' => 1]; $said[] = 'kept apart'; }
        else {
            if (array_key_exists('company', $d)) { $c = (int) $d['company'] ?: null; if ($c && ! DB::table('cat_company_house')->where('id', $c)->whereIn('fk_id_ent', [0, $ent])->exists()) throw new \InvalidArgumentException('Company not found.');
                $new['fk_id_company_house'] = $c; $new['f_kept_apart'] = 0; $said[] = $c ? 'company: ' . DB::table('cat_company_house')->where('id', $c)->value('company_name') : 'no company'; }
            if (array_key_exists('money', $d)) {
                if ($d['money'] === 'new') {
                    $cur = strtoupper((string) ($b->currency_code ?: Fx::base($ent)));
                    $m = (int) DB::table('fin_bank_accounts')->insertGetId(RecordService::fillNotNull('fin_bank_accounts', ['company_id' => $ent, 'bank_name' => mb_substr((string) ($b->bank_name ?: 'Bank'), 0, 100),
                        'account_label' => mb_substr(trim(($b->holder ?: '') . ' ' . self::mask($b->account_no ?: $b->iban_no)), 0, 100), 'currency' => $cur, 'opening_balance' => 0, 'opening_date' => date('Y-m-d'), 'is_active' => 1]));
                    $new['fk_id_fin_bank'] = $m; $said[] = 'a new Money bank account was made from it (opening balance 0 – set it under Money › Bank)';
                } else { $m = (int) $d['money'] ?: null; if ($m && ! DB::table('fin_bank_accounts')->where('id', $m)->where('company_id', $ent)->exists()) throw new \InvalidArgumentException('Money bank account not found.');
                    if ($m && DB::table('t_account_banks')->where('fk_id_fin_bank', $m)->where('id', '<>', $id)->exists()) throw new \InvalidArgumentException('That Money bank account is already linked to another bank account – unlink it there first.');
                    $new['fk_id_fin_bank'] = $m; $said[] = $m ? 'linked to the Money bank account' : 'not a Money bank account'; }
                $new['f_kept_apart'] = 0;
            }
        }
        if (! $said) throw new \InvalidArgumentException('Nothing was chosen.');
        DB::table('t_account_banks')->where('id', $id)->update($new);
        Audit::log('t_account_banks', $id, 'UPDATE', ['company' => [$b->fk_id_company_house, $new['fk_id_company_house']], 'money_account' => [$b->fk_id_fin_bank, $new['fk_id_fin_bank']], 'kept_apart' => [(int) $b->f_kept_apart, $new['f_kept_apart']]], 'Owner option', $ent);
        return ucfirst(implode('; ', $said)) . '.';
    }
}
