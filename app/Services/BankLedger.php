<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-FIN-31 Ledger / Accountant view of the bank lines. Filters: company (incl. All companies the person may see), date from–to (default
 * 1 Jan of this year → today), category incl. "No category yet", status (Unmatched / Matched by rule / Matched by hand / Ignored), text
 * (description, payee, reference, bank id, category code or name). Footer totals over every filtered line (not only the shown page):
 * money in, money out, net – in the company currency (amount_gbp). The year export (R-FIN-33) and the valuation (R-FIN-36) are their own screens.
 */
class BankLedger
{
    public const STATUSES = ['UNMATCHED' => 'Unmatched', 'AUTO' => 'Matched by rule', 'MANUAL' => 'Matched by hand', 'IGNORED' => 'Ignored'];

    /** Companies the person may see (All = these). */
    public static function companies(?object $u): array
    {
        $q = DB::table('t_enterprise')->where('f_active', 1);
        if ($u && $u->role_code !== 'OWNER') $q->where('id', (int) ($u->fk_id_ent ?: Context::ent()));
        return $q->orderBy('ent_name')->get(['id', 'ent_name as name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])->all();
    }

    public static function filters(array $in, ?object $u): array
    {
        $d = fn ($v, $def) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $def;
        $from = $d($in['from'] ?? null, date('Y-01-01')); $to = $d($in['to'] ?? null, date('Y-m-d')); if ($from > $to) [$from, $to] = [$to, $from];
        $allowed = array_column(self::companies($u), 'id'); $c = $in['company'] ?? (string) Context::ent();
        $companies = $c === 'all' ? $allowed : array_values(array_intersect($allowed, [(int) $c]));
        return ['company' => $c === 'all' ? 'all' : (string) ($companies[0] ?? Context::ent()), 'companies' => $companies ?: [(int) Context::ent()], 'from' => $from, 'to' => $to,
            'category' => (string) ($in['category'] ?? ''), 'status' => isset(self::STATUSES[$in['status'] ?? '']) ? $in['status'] : '', 'q' => trim((string) ($in['q'] ?? ''))];
    }

    public static function apply($q, array $f)
    {
        $q->whereIn('t.company_id', $f['companies'])->whereBetween('t.txn_date', [$f['from'], $f['to']]);
        if ($f['category'] === 'NONE') $q->where(fn ($w) => $w->whereNull('t.category_code')->orWhere('t.category_code', ''));
        elseif ($f['category'] !== '') $q->where('t.category_code', $f['category']);
        if ($f['status'] !== '') $q->where('t.match_status', $f['status']);
        if ($f['q'] !== '') { $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $q->where(fn ($w) => $w->where('t.description', 'like', $like)->orWhere('t.counterparty', 'like', $like)->orWhere('t.reference_no', 'like', $like)->orWhere('t.bank_txn_id', 'like', $like)->orWhere('t.category_code', 'like', $like)
                ->orWhereRaw('t.category_code COLLATE utf8mb4_unicode_ci IN (SELECT c.code COLLATE utf8mb4_unicode_ci FROM fin_categories c WHERE c.name LIKE ?)', [$like])); }      // the two tables differ in collation
        return $q;
    }

    public static function totals($q): array
    {
        $r = $q->selectRaw('COUNT(*) n, COALESCE(SUM(CASE WHEN COALESCE(t.amount_gbp, t.amount) > 0 THEN COALESCE(t.amount_gbp, t.amount) ELSE 0 END), 0) i, COALESCE(SUM(CASE WHEN COALESCE(t.amount_gbp, t.amount) < 0 THEN COALESCE(t.amount_gbp, t.amount) ELSE 0 END), 0) o')->first();
        return ['lines' => (int) $r->n, 'money_in' => round((float) $r->i, 2), 'money_out' => round(abs((float) $r->o), 2), 'net' => round((float) $r->i + (float) $r->o, 2)];
    }
}
