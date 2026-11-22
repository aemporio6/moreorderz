<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * RULES_D_PRODUCTS R-D-81 settlement line amounts and R-D-82 month settlement (investor invoicing route), old messages kept.
 * A sales month = one import (t_sale_imports) of a shop; lines = its Confirmed / Refunded sale lines. Settle stores the period (t_inv_periods, CONFIRMED,
 * fk_id_import) and one row per investor (t_inv_period_investors); the documents are then made by InvestorDocs (R-D-83).
 * The switches are Settings › Choices: PACKING_FEE_PER, STORAGE_FEE_PER, REFUND_CHARGES_PACKING_FEE, REFUND_CHARGES_STORAGE_FEE, BLOCK_UNASSIGNED_INVESTOR.
 */
class InvestorSettle
{
    public const LOSS = ['Product' => 'Product Loss', 'Carrier' => 'Carrier Loss', 'Packing' => 'Packing Loss', 'Carrier_Packing' => 'Carrier + Packing Loss', 'Product_Carrier' => 'Product + Carrier Loss',
        'Product_Packing' => 'Product + Packing Loss', 'Product_Carrier_Packing' => 'Product + Packing + Carrier Loss'];

    private static function c(int $ent, string $k): string { return (string) Choices::get($k, $ent); }
    private static function refunded(object $s): bool { return $s->status === 'Refunded' || ! empty($s->refunded_at); }

    /** The fees of the product (else its parent) for a marketplace (R-D-23 shipping tab). */
    private static function fees(int $product, int $mp): array
    {
        $p = DB::table('t_product_vendors')->where('id', $product)->first(['id', 'f_id_parent', 'landing_price']);
        foreach (array_filter([$product, (int) ($p->f_id_parent ?? 0)]) as $id) {
            $r = DB::table('t_product_marketplace_shipping')->where('fk_id_product', $id)->where('fk_id_marketplace', $mp)->orderByDesc('id')->first(['packing_fee', 'storage_fee']);
            if ($r) return [(float) $r->packing_fee, (float) $r->storage_fee, true];
        }
        return [0.0, 0.0, false];
    }

    private static function landing(int $product): float
    {
        $p = DB::table('t_product_vendors')->where('id', $product)->first(['landing_price', 'f_id_parent']);
        $l = (float) ($p->landing_price ?? 0);
        if ($l == 0.0 && ! empty($p->f_id_parent)) $l = (float) DB::table('t_product_vendors')->where('id', $p->f_id_parent)->value('landing_price');
        return $l;
    }

    /** R-D-81 one line. */
    public static function line(int $ent, object $s, int $mp): array
    {
        $qty = max(0, (int) $s->qty); $ref = self::refunded($s); $nat = (string) ($s->refund_loss_nature ?? '');
        $has = fn (string $w) => str_contains($nat, $w);
        [$pf, $sf, $found] = self::fees((int) $s->fk_product_id, $mp);
        $packFee = $pf * (self::c($ent, 'PACKING_FEE_PER') === 'UNIT' ? max(1, $qty) : 1);
        $storFee = $sf * (self::c($ent, 'STORAGE_FEE_PER') === 'SALE_LINE' ? 1 : max(1, $qty));
        $revenue = (float) $s->total_amount; $raw = (float) $s->raw_price; $land = self::landing((int) $s->fk_product_id);
        if (! $ref) return ['units' => $qty, 'billed_qty' => $qty, 'revenue' => $revenue, 'capital' => round($raw * $qty, 2), 'actual' => round($land * $qty, 2), 'shipping' => (float) $s->carrier_rate,
            'packing_material' => (float) $s->packing_rate, 'packing_fee' => round($packFee, 2), 'storage_fee' => round($storFee, 2), 'refund_adj' => 0.0, 'fees_found' => $found, 'refunded' => false];
        $prod = $has('Product');
        $l = ['units' => 0, 'billed_qty' => $prod ? $qty : 0, 'revenue' => $revenue, 'capital' => $prod ? round($raw * $qty, 2) : 0.0, 'actual' => $prod ? round($land * $qty, 2) : 0.0,
            'shipping' => $has('Carrier') ? (float) $s->carrier_rate : 0.0, 'packing_material' => $has('Packing') ? (float) $s->packing_rate : 0.0,
            'packing_fee' => self::c($ent, 'REFUND_CHARGES_PACKING_FEE') === 'N' ? 0.0 : round($packFee, 2), 'storage_fee' => self::c($ent, 'REFUND_CHARGES_STORAGE_FEE') === 'N' ? 0.0 : round($storFee, 2), 'fees_found' => $found, 'refunded' => true];
        $l['refund_adj'] = round($l['revenue'] - $l['capital'] - $l['shipping'] - $l['packing_material'] - (float) $s->profit, 2);      // the line before 3PL fees = its Sales profit
        return $l;
    }

    /** Investors of a product (its own links, else the parent's): account ids. */
    public static function investors(int $product): array
    {
        $ids = DB::table('t_product_investors')->where('fk_id_product', $product)->pluck('fk_id_account')->map(fn ($x) => (int) $x)->filter()->unique()->values()->all();
        if ($ids) return $ids;
        $parent = (int) DB::table('t_product_vendors')->where('id', $product)->value('f_id_parent');
        return $parent ? DB::table('t_product_investors')->where('fk_id_product', $parent)->pluck('fk_id_account')->map(fn ($x) => (int) $x)->filter()->unique()->values()->all() : [];
    }

    /** R-D-82 the calculation of a month (preview; nothing is written). $in = advertising, other_expenses(+note), extra_3pl(+note), va_salary, owner_fixed, handler_fixed. */
    public static function calc(int $ent, int $importId, array $in = []): array
    {
        $imp = DB::table('t_sale_imports')->where('id', $importId)->where('fk_id_ent', $ent)->first() ?? throw new \InvalidArgumentException('Sales month not found.');
        $shop = DB::table('t_branches')->where('id', $imp->fk_id_branch)->first(); $mon = date('F Y', mktime(0, 0, 0, (int) $imp->period_month, 1, (int) $imp->period_year));
        $from = sprintf('%04d-%02d-01', $imp->period_year, $imp->period_month); $to = date('Y-m-t', strtotime($from));
        if ($imp->status !== 'Confirmed' && $imp->status !== 'CONFIRMED') throw new \InvalidArgumentException('Sales for ' . ($shop->shop_name ?? 'the shop') . " - $mon are not confirmed yet (status: {$imp->status}). Confirm them in Sales > Imported months first.");
        if (DB::table('t_inv_periods')->where('fk_id_import', $importId)->where('status', 'CONFIRMED')->exists()) throw new \InvalidArgumentException('This month is already settled for invoicing.');
        $split = WaterfallService::split($ent, (int) $imp->fk_id_branch, $to) ?? throw new \InvalidArgumentException('No profit split is set. Add a default split in Invoicing > Profit split.');
        if (empty($shop->fk_id_owner)) throw new \InvalidArgumentException('This shop has no Account Owner set (t_branches.fk_id_owner).');
        $handler = $split->fk_id_handler_party ?: DB::table('t_inv_parties')->where('f_is_sbs', 1)->where('f_active', 1)->whereIn('fk_id_ent', [$ent, 0])->orderByDesc('fk_id_ent')->value('id');
        if (! $handler) throw new \InvalidArgumentException('Safe Buy Stores Limited party is missing. Add it in Invoicing > Parties.');
        $sales = DB::table('t_sales')->where('fk_id_import', $importId)->where(fn ($w) => $w->whereIn('status', ['Confirmed', 'Refunded'])->orWhereNotNull('refunded_at'))->orderBy('d_order')->orderBy('id')->get();
        if (! $sales->count()) throw new \InvalidArgumentException('This month has no confirmed or refunded sale lines.');
        $va = $imp->fk_id_va_account ?: ($shop->fk_id_seller ?? null);
        $warn = []; $tot = array_fill_keys(['units', 'revenue', 'capital', 'actual', 'shipping', 'packing_material', 'packing_fee', 'storage_fee', 'refund_adj'], 0.0);
        $per = []; $noInv = 0; $multi = 0; $noFee = 0; $lines = [];
        foreach ($sales as $s) {
            $l = self::line($ent, $s, (int) $imp->fk_id_marketplace); if (! $l['fees_found']) $noFee++;
            foreach ($tot as $k => $_) $tot[$k] += $l[$k];
            $inv = self::investors((int) $s->fk_product_id); if (! $inv) { $noInv++; $inv = [0]; } elseif (count($inv) > 1) $multi++;
            $part = 1 / count($inv);
            foreach ($inv as $a) {
                $per[$a] ??= array_fill_keys(array_keys($tot), 0.0) + ['sales' => 0];
                foreach ($tot as $k => $_) $per[$a][$k] += $l[$k] * $part; $per[$a]['sales']++;
            }
            $lines[] = ['sale' => (int) $s->id, 'sku' => $s->sku, 'raw' => (float) $s->raw_price, 'investors' => $inv] + $l;
        }
        $tot = array_map(fn ($v) => round($v, 2), $tot);
        if ($noInv) { $m = "$noInv sale line(s) are for products with no investor in Inventory (Vendor tab > Investors)."; if (self::c($ent, 'BLOCK_UNASSIGNED_INVESTOR') !== 'N') throw new \InvalidArgumentException($m . ' Add the investor before settling.'); $warn[] = $m . ' Their investor share will not be invoiced to anyone.'; }
        if ($multi) $warn[] = "$multi sale line(s) are for products with more than one investor. Each line is divided equally between them.";
        if ($noFee) $warn[] = "$noFee sale line(s) have no packing fee / storage fee set on the product's Shipping tab for this marketplace. They count as £0.";
        $mode = fn ($m) => strtoupper((string) $m);
        $oMode = $mode($split->owner_mode ?: 'SHARE'); $vMode = $mode($split->va_mode ?: 'SHARE'); $hMode = $mode($split->handler_mode ?: 'SHARE');
        $num = fn (string $k, float $def) => array_key_exists($k, $in) && $in[$k] !== '' && $in[$k] !== null ? round((float) $in[$k], 2) : $def;
        $jh = $va ? DB::table('t_account_job_history')->where('fk_id_account', $va)->where('fk_id_marketplace', $imp->fk_id_marketplace)->orderByDesc('id')->first(['salary', 'pound_exchange_rate']) : null;      /* old: the latest row by id */
        $jobSalary = $jh ? ((float) $jh->pound_exchange_rate > 0 ? round((float) $jh->salary / (float) $jh->pound_exchange_rate, 2) : (float) $jh->salary) : 0.0;
        $vaSalary = in_array($vMode, ['SALARY', 'SALARY_SHARE'], true) ? $num('va_salary', (float) ($split->va_salary_override ?: $jobSalary)) : 0.0;
        if (in_array($vMode, ['SALARY', 'SALARY_SHARE'], true) && $vaSalary == 0.0) $warn[] = 'VA is on a salary but no salary was found in the VA job history. Enter it below.';
        $ownerFixed = in_array($oMode, ['FIXED', 'FIXED_SHARE'], true) ? $num('owner_fixed', (float) $split->owner_fixed) : 0.0;
        $handlerFixed = in_array($hMode, ['FIXED', 'FIXED_SHARE'], true) ? $num('handler_fixed', (float) $split->handler_fixed) : 0.0;
        if ($oMode === 'FIXED_SHARE') $warn[] = 'Account Owner gets a fixed fee AND a profit share. The agreement says to avoid paying twice for the same contribution.';
        if ($hMode === 'FIXED_SHARE') $warn[] = 'Handler gets a fixed fee AND a profit share. Check this is intended.';
        $adv = $num('advertising', 0); $other = $num('other_expenses', 0); $extra = $num('extra_3pl', 0); $mpFee = round((float) $imp->marketplace_fee, 2);
        $tpl = $tot['shipping'] + $tot['packing_material'] + $tot['packing_fee'] + $tot['storage_fee'] + $extra;
        $gross = round($tot['revenue'] - $tot['capital'] - $tot['refund_adj'] - $mpFee - $tpl - $adv - $other, 2);
        $fnp = round($gross - $vaSalary - $ownerFixed - $handlerFixed, 2);
        $prev = DB::table('t_inv_periods')->where('fk_id_ent', $ent)->where('fk_id_branch', $imp->fk_id_branch)->where('fk_id_marketplace', $imp->fk_id_marketplace)->where('status', 'CONFIRMED')->where('d_to', '<', $from)->orderByDesc('d_to')->orderByDesc('id')->first();
        $lossBf = round((float) ($prev->loss_cf ?? 0), 2); if ($lossBf > 0) $warn[] = 'A loss of £' . number_format($lossBf, 2) . ' from an earlier month is recovered first.';
        $dist = round($fnp - $lossBf, 2); $lossCf = 0.0;
        $pct = ['investor' => (float) $split->investor_pct, 'owner' => in_array($oMode, ['FIXED'], true) ? 0.0 : (float) $split->owner_pct, 'va' => $vMode === 'SALARY' ? 0.0 : (float) $split->va_pct, 'handler' => $hMode === 'FIXED' ? 0.0 : (float) $split->handler_pct];
        $sh = array_fill_keys(array_keys($pct), 0.0);
        if ($dist <= 0) { $lossCf = round(-$dist, 2); $warn[] = 'No profit to share this month. Loss carried forward: £' . number_format($lossCf, 2) . '.'; }
        else {
            foreach ($pct as $k => $p) $sh[$k] = round($dist * $p / 100, 2);
            if (abs(array_sum($pct) - 100) < 0.0001) $sh['handler'] = round($dist - $sh['investor'] - $sh['owner'] - $sh['va'], 2);      // the handler gets the rounding remainder
        }
        // investor pool by each investor's own profit (allocated shop costs by revenue share; equally when revenue is 0); only positive, id 0 gets none
        $alloc = $mpFee + $adv + $other + $extra + $vaSalary + $ownerFixed + $handlerFixed + $lossBf; $rev = $tot['revenue']; $n = count($per);
        $inv = [];
        foreach ($per as $a => $x) {
            $direct = $x['shipping'] + $x['packing_material'] + $x['packing_fee'] + $x['storage_fee'];
            $al = $rev != 0.0 ? $alloc * $x['revenue'] / $rev : $alloc / max(1, $n);
            $inv[$a] = ['account' => (int) $a, 'sales' => $x['sales'], 'units' => (int) round($x['units']), 'revenue' => round($x['revenue'], 2), 'capital' => round($x['capital'], 2), 'actual' => round($x['actual'], 2),
                'direct' => round($direct, 2), 'refund_adj' => round($x['refund_adj'], 2), 'allocated' => round($al, 2), 'profit' => round($x['revenue'] - $x['capital'] - $direct - $x['refund_adj'] - $al, 2), 'weight' => 0.0, 'share' => 0.0];
        }
        $pos = array_filter($inv, fn ($x) => $x['account'] > 0 && $x['profit'] > 0); $sum = array_sum(array_column($pos, 'profit'));
        foreach ($pos as $a => $x) { $inv[$a]['weight'] = round(100 * $x['profit'] / $sum, 4); $inv[$a]['share'] = round($sh['investor'] * $x['profit'] / $sum, 2); }
        if ($pos && $sh['investor'] > 0) { $big = collect($pos)->sortByDesc('profit')->keys()->first();      /* the rounding remainder goes to the largest share */ $inv[$big]['share'] = round($inv[$big]['share'] + $sh['investor'] - array_sum(array_column($inv, 'share')), 2); }
        return ['import' => (int) $importId, 'shop' => $shop->shop_name ?? '', 'month' => $mon, 'from' => $from, 'to' => $to, 'totals' => $tot, 'marketplace_fee' => $mpFee, 'advertising' => $adv, 'other_expenses' => $other, 'extra_3pl' => $extra,
            'va_salary' => $vaSalary, 'owner_fixed' => $ownerFixed, 'handler_fixed' => $handlerFixed, 'gross_profit' => $gross, 'final_net_profit' => $fnp, 'loss_bf' => $lossBf, 'distributable' => $dist, 'loss_cf' => $lossCf,
            'pct' => $pct, 'shares' => $sh, 'modes' => ['owner' => $oMode, 'va' => $vMode, 'handler' => $hMode], 'split' => (int) $split->id, 'owner' => (int) $shop->fk_id_owner, 'va' => $va ? (int) $va : null, 'handler' => (int) $handler,
            'investors' => array_values($inv), 'lines' => $lines, 'warnings' => $warn];
    }

    /** Settle: one transaction, the period CONFIRMED + a row per investor. Needs the "checked" tick. */
    public static function settle(int $ent, int $importId, array $in, object $u): array
    {
        if (empty($in['checked'])) throw new \InvalidArgumentException('Tick the box to confirm you have checked the figures.');
        if (mb_strlen((string) ($in['notes'] ?? '')) > 500) throw new \InvalidArgumentException('Notes are at most 500 characters.');
        return DB::transaction(function () use ($ent, $importId, $in, $u) {
            DB::table('t_sale_imports')->where('id', $importId)->lockForUpdate()->first();      // locks the import row
            $c = self::calc($ent, $importId, $in); $t = $c['totals']; $imp = DB::table('t_sale_imports')->where('id', $importId)->first();
            $pid = DB::table('t_inv_periods')->insertGetId(RecordService::fillNotNull('t_inv_periods', ['fk_id_ent' => $ent, 'fk_id_import' => $importId, 'fk_id_marketplace' => $imp->fk_id_marketplace, 'fk_id_branch' => $imp->fk_id_branch,
                'd_from' => $c['from'], 'd_to' => $c['to'], 'status' => 'CONFIRMED', 'sales_count' => count($c['lines']), 'units' => (int) $t['units'], 'revenue' => $t['revenue'], 'capital' => $t['capital'], 'actual_cost' => $t['actual'],
                'marketplace_fees' => $c['marketplace_fee'], 'shipping' => $t['shipping'], 'packing_material' => $t['packing_material'], 'packing_fee' => $t['packing_fee'], 'storage_fee' => $t['storage_fee'], 'extra_3pl' => $c['extra_3pl'],
                'extra_3pl_note' => mb_substr((string) ($in['extra_3pl_note'] ?? ''), 0, 255), 'advertising' => $c['advertising'], 'other_expenses' => $c['other_expenses'], 'other_expenses_note' => mb_substr((string) ($in['other_expenses_note'] ?? ''), 0, 255),
                'refund_adjustment' => $t['refund_adj'], 'va_salary' => $c['va_salary'], 'owner_fixed' => $c['owner_fixed'], 'handler_fixed' => $c['handler_fixed'], 'gross_profit' => $c['gross_profit'], 'final_net_profit' => $c['final_net_profit'],
                'loss_bf' => $c['loss_bf'], 'distributable' => $c['distributable'], 'loss_cf' => $c['loss_cf'], 'investor_pct' => $c['pct']['investor'], 'owner_pct' => $c['pct']['owner'], 'va_pct' => $c['pct']['va'], 'handler_pct' => $c['pct']['handler'],
                'owner_mode' => $c['modes']['owner'], 'va_mode' => $c['modes']['va'], 'handler_mode' => $c['modes']['handler'], 'investor_pool' => $c['shares']['investor'], 'owner_share' => $c['shares']['owner'], 'va_share' => $c['shares']['va'],
                'handler_share' => $c['shares']['handler'], 'fk_id_split' => $c['split'], 'fk_owner_account' => $c['owner'], 'fk_va_account' => $c['va'] ?? 0, 'fk_handler_party' => $c['handler'], 'calc_json' => json_encode(['warnings' => $c['warnings'], 'lines' => $c['lines']]),
                'notes' => mb_substr((string) ($in['notes'] ?? ''), 0, 500), 'confirmed_by' => $u->id, 'confirmed_at' => now()]));
            foreach ($c['investors'] as $x) if ($x['account'] > 0) DB::table('t_inv_period_investors')->insert(RecordService::fillNotNull('t_inv_period_investors', ['fk_id_period' => $pid, 'fk_id_investor_account' => $x['account'], 'sales_count' => $x['sales'], 'units' => $x['units'],
                'revenue' => $x['revenue'], 'capital' => $x['capital'], 'actual_cost' => $x['actual'], 'direct_costs' => $x['direct'], 'refund_adjustment' => $x['refund_adj'], 'allocated_costs' => $x['allocated'], 'profit_before_split' => $x['profit'],
                'weight_pct' => $x['weight'], 'share_amount' => $x['share'], 'f_self' => $x['account'] === $c['owner'] ? 1 : 0]));
            Audit::log('t_inv_periods', (int) $pid, 'INSERT', ['import' => $importId, 'fnp' => $c['final_net_profit'], 'distributable' => $c['distributable']], 'month settled', $ent);
            return ['period' => (int) $pid, 'message' => 'Month settled. You can now generate the invoices.', 'warnings' => $c['warnings']];
        });
    }
}
