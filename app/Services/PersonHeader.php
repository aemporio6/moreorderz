<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Person header, count chips and health (R-C-18). One chip per sub-list with the count of ALL rows of the person (active and inactive, as old findTotalOf /
 * findTotalMarketPlace); job history is a chip too. Each chip and the person get a health colour from the Account kit rules (sys_due_sources):
 * red = a date of the list is past and not marked done, amber = a date falls in its remind window, grey = a missing link (a seller marketplace account without
 * a shop), else none. The header also shows code, names, kind badge and roles.
 */
class PersonHeader
{
    /** chip => [label, table, extra where] */
    public const CHIPS = [
        'addresses' => ['Addresses', 't_account_addresses', null], 'documents' => ['Documents', 't_account_doc', null], 'mobiles' => ['Mobiles', 't_account_mobiles', null],
        'emails' => ['E-mails', 't_account_emails', null], 'photo_ids' => ['Photo IDs', 't_account_photo_ids', null], 'banks' => ['Banks', 't_account_banks', null],
        'mp_seller' => ['Marketplace (Seller)', 't_account_marketplaces', "market_place_type = 'Seller'"], 'mp_buyer' => ['Marketplace (Buyer)', 't_account_marketplaces', "market_place_type = 'Buyer'"],
        'pcs' => ['PCs', 't_account_pc', null], 'ip_accounts' => ['IP accounts', 't_account_ipburgers', null], 'cards' => ['Cards', 't_account_credit_cards', null],
        'company_houses' => ['Company houses', 't_account_company_houses', null], 'paypal' => ['PayPal', 't_account_paypal', null], 'social' => ['Social media', 't_account_social_media', null],
        'subscriptions' => ['Subscriptions', 't_account_subscriptions', null], 'job_history' => ['Job history', 't_account_job_history', null],
    ];
    private const RANK = ['red' => 3, 'amber' => 2, 'grey' => 1, null => 0];

    public static function for(int $person, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $p = DB::table('t_account')->where('id', $person)->first() ?? throw new \InvalidArgumentException('Person not found.');
        $sources = DB::table('sys_due_sources')->where('f_active', 1)->get()->groupBy('source_table');
        $chips = []; $worst = null; $why = [];
        foreach (self::CHIPS as $key => [$label, $table, $where]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'fk_id_account')) continue;
            $q = DB::table($table)->where('fk_id_account', $person)->when($where, fn ($w) => $w->whereRaw($where));
            $count = (clone $q)->count(); $health = null; $note = null;
            foreach ($sources[$table] ?? [] as $s) {
                if (! Schema::hasColumn($table, $s->date_column)) continue;
                $rows = (clone $q)->whereNotNull($s->date_column)->when($s->where_rule, fn ($w) => $w->whereRaw('(' . $s->where_rule . ')'))->get(['id', $s->date_column . ' as d']);
                foreach ($rows as $r) {
                    $d = substr((string) $r->d, 0, 10); if ($d === '' || $d === '0000-00-00') continue;
                    $done = DB::table('sys_due_items')->where('fk_id_source', $s->id)->where('record_id', $r->id)->where('due_date', $d)->whereNotNull('done_at')->exists();
                    if ($done) continue;
                    $h = $d < $today ? 'red' : ($d <= date('Y-m-d', strtotime("$today +{$s->remind_days} days")) ? 'amber' : null);
                    if (self::RANK[$h] > self::RANK[$health]) { $health = $h; $note = ($s->name ?: $s->date_column) . ' ' . ($h === 'red' ? 'was due' : 'is due') . " $d"; }
                }
            }
            if ($key === 'mp_seller' && ! $health && Schema::hasColumn($table, 'fk_id_shop') && (clone $q)->where(fn ($w) => $w->whereNull('fk_id_shop')->orWhere('fk_id_shop', 0))->exists()) { $health = 'grey'; $note = 'a seller account without a shop'; }
            if ($health && self::RANK[$health] > self::RANK[$worst]) $worst = $health;
            if ($note) $why[] = "$label: $note";
            $form = RecordService::formOfTable($table);
            $chips[] = ['key' => $key, 'label' => $label, 'count' => $count, 'health' => $health, 'note' => $note, 'url' => $form ? "#/list/$form?f[fk_id_account]=$person" : null];
        }
        $roles = DB::table('t_account_in_types as i')->join('cat_account_types as t', 't.id', '=', 'i.fk_id_account_type')->where('i.fk_id_account', $person)->where('i.f_active', 1)->orderBy('t.account_type_name')->pluck('t.account_type_name')->all();
        return ['id' => (int) $p->id, 'code' => $p->account_code, 'name' => trim(implode(' ', array_filter([$p->title ?? null, $p->first_name, $p->sur_name]))), 'kind' => $p->l_account_kind ?? null, 'party_type' => $p->l_party_type ?? null,
            'roles' => $roles, 'chips' => $chips, 'health' => $worst, 'health_why' => $why];
    }
}
