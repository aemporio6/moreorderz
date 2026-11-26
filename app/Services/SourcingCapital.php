<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MR-0301 / 0302 (old T_src_investment, T_src_investor): one partner, not three. A sourcing investor is linked to the partner account it is
 * (by name the first time, or chosen); every capital put into a cycle / PO (t_src_investments, base amount) is the partner's CAPITAL_IN in the
 * partner ledger – so Partners › Each partner shows it, and his share, return and balance count it. Cancelled = taken out again. Once per investment.
 */
class SourcingCapital
{
    /** The partner account of a sourcing investor: its own link, else one partner whose name matches (company or person). */
    public static function partnerOf(int $investorId): ?int
    {
        $i = DB::table('t_src_investors')->where('id', $investorId)->first(); if (! $i) return null;
        if (! empty($i->fk_id_account)) return (int) $i->fk_id_account;
        $names = array_values(array_filter(array_map(fn ($n) => mb_strtolower(trim((string) $n)), [$i->investor_name, $i->company_name])));
        if (! $names) return null; $hit = [];
        foreach (PartnerView::accounts((int) $i->fk_id_ent) as $acc) {
            $n = mb_strtolower(PartnerView::name($acc)); $co = trim(explode(' – ', $n)[0]);
            foreach ($names as $x) if ($x !== '' && ($x === $co || $x === $n || str_starts_with($co, $x))) { $hit[$acc] = 1; break; }
        }
        if (count($hit) !== 1) return null;      // none or unclear: the Owner links it on the investor
        $acc = (int) array_key_first($hit); DB::table('t_src_investors')->where('id', $investorId)->update(['fk_id_account' => $acc]); return $acc;
    }

    /** Bring one investment into the partner ledger (or take it out when cancelled / inactive). Returns what happened. */
    public static function sync(int $investmentId): string
    {
        $v = DB::table('t_src_investments')->where('id', $investmentId)->first(); if (! $v) return 'gone';
        $ref = 'SRC-INV-' . $investmentId; $have = DB::table('fin_investor_ledger')->where('reference_no', $ref)->first();
        $live = (int) $v->f_active === 1 && ! in_array(strtoupper((string) $v->status), ['CANCELLED', 'REJECTED'], true) && (float) ($v->base_amount ?: $v->invest_amount) > 0;
        $acc = $v->fk_id_investor ? self::partnerOf((int) $v->fk_id_investor) : null;
        if (! $live || ! $acc) { if ($have) { DB::table('fin_investor_ledger')->where('id', $have->id)->delete(); Audit::log('fin_investor_ledger', (int) $have->id, 'DELETE', ['capital' => [$have->amount, null]], 'Sourcing investment ' . ($live ? 'has no partner' : 'cancelled'), (int) $v->fk_id_ent); return 'removed'; } return $acc ? 'not live' : 'no partner'; }
        $row = ['investor_id' => $acc, 'company_id' => (int) $v->fk_id_ent, 'entry_date' => $v->invest_date ?: substr((string) $v->created_at, 0, 10), 'type' => 'CAPITAL_IN',
            'amount' => round((float) ($v->base_amount ?: $v->invest_amount), 2), 'reference_no' => $ref, 'note' => mb_substr('Sourcing investment ' . ($v->invest_code ?: '#' . $investmentId) . ($v->fk_id_cycle ? ' · cycle ' . DB::table('t_src_cycles')->where('id', $v->fk_id_cycle)->value('cycle_code') : ''), 0, 250)];
        if ($have) { DB::table('fin_investor_ledger')->where('id', $have->id)->update($row); return 'updated'; }
        $id = (int) DB::table('fin_investor_ledger')->insertGetId($row + ['created_at' => now()]);
        Audit::log('fin_investor_ledger', $id, 'INSERT', ['capital' => [null, $row['amount']]], 'From sourcing investment ' . $ref, (int) $v->fk_id_ent);
        return 'added';
    }

    public static function syncAll(int $ent): array
    {
        $n = ['added' => 0, 'updated' => 0, 'removed' => 0, 'no partner' => 0, 'not live' => 0, 'gone' => 0];
        foreach (DB::table('t_src_investments')->where('fk_id_ent', $ent)->pluck('id') as $id) $n[self::sync((int) $id)]++;
        return $n;
    }
}
