<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Shipping per marketplace incl. 3PL fees (R-D-23).
 * - Company 3PL tariff per marketplace: packing fee + storage fee, each "per order" (once per sale line) or "per unit" (old config: packing per order, storage per unit).
 * - New product marketplace rows take their fees from the tariff, so a new product is never £0 (fee_source TARIFF); typed fees win (TYPED).
 * - Fees are clamped to ≥ 0 at 2 dp. They feed the margin and the price guard (ProductPrices) through feeCost().
 * - Nightly self-check: sold products with no packing / storage fee on that marketplace → task PRD_FEE_MISSING (one per product), closes when filled.
 */
class ThreePlTariff
{
    public const PER = ['ORDER' => 'per order (once per sale line)', 'UNIT' => 'per unit'];

    public static function money($v): float { return round(max(0.0, (float) $v), 2); }

    /** The tariff of one marketplace, or null. */
    public static function of(int $ent, int $mp): ?object
    {
        return DB::table('t_3pl_tariffs')->where('fk_id_ent', $ent)->where('fk_id_marketplace', $mp)->first();
    }

    public static function page(int $ent): array
    {
        $t = DB::table('t_3pl_tariffs')->where('fk_id_ent', $ent)->get()->keyBy('fk_id_marketplace'); $lc = Registry::labelColumn('cat_marketplaces') ?: 'id';
        $rows = DB::table('cat_marketplaces')->where('f_active', 1)->orderBy('marketplace_short')->get(['id', DB::raw("COALESCE(NULLIF(marketplace_short, ''), NULLIF($lc, ''), id) as name")])
            ->map(fn ($m) => ['marketplace' => (int) $m->id, 'name' => $m->name, 'packing_fee' => (float) ($t[$m->id]->packing_fee ?? 0), 'packing_fee_per' => $t[$m->id]->packing_fee_per ?? 'ORDER',
                'storage_fee' => (float) ($t[$m->id]->storage_fee ?? 0), 'storage_fee_per' => $t[$m->id]->storage_fee_per ?? 'UNIT', 'set' => isset($t[$m->id])])->all();
        return ['rows' => $rows, 'per' => self::PER, 'missing' => DB::table('tasks')->where('fk_id_ent', $ent)->where('rule_code', 'PRD_FEE_MISSING')->whereNotIn('status', ['DONE', 'APPROVED', 'CANCELLED'])->count()];
    }

    public static function save(int $ent, int $mp, array $d, ?object $u): array
    {
        Perm::need($u, 'stock.setPrice');
        if (! DB::table('cat_marketplaces')->where('id', $mp)->exists()) throw new \InvalidArgumentException('Choose the marketplace.');
        foreach (['packing_fee', 'storage_fee'] as $k) if (isset($d[$k]) && $d[$k] !== '' && ! is_numeric($d[$k])) throw new \InvalidArgumentException(ucfirst(str_replace('_', ' ', $k)) . ' must be a number.');
        foreach (['packing_fee_per', 'storage_fee_per'] as $k) if (isset($d[$k]) && ! isset(self::PER[$d[$k]])) throw new \InvalidArgumentException('Choose per order or per unit.');
        $old = self::of($ent, $mp);
        $row = ['packing_fee' => self::money($d['packing_fee'] ?? ($old->packing_fee ?? 0)), 'packing_fee_per' => $d['packing_fee_per'] ?? ($old->packing_fee_per ?? 'ORDER'),
            'storage_fee' => self::money($d['storage_fee'] ?? ($old->storage_fee ?? 0)), 'storage_fee_per' => $d['storage_fee_per'] ?? ($old->storage_fee_per ?? 'UNIT'), 'updated_by' => $u->id ?? null, 'updated_at' => now()];
        DB::table('t_3pl_tariffs')->updateOrInsert(['fk_id_ent' => $ent, 'fk_id_marketplace' => $mp], $row);
        Audit::log('t_3pl_tariffs', $mp, 'UPDATE', ['old' => $old ? ['packing' => (float) $old->packing_fee, 'storage' => (float) $old->storage_fee] : null, 'new' => ['packing' => $row['packing_fee'], 'storage' => $row['storage_fee']]], null, $ent);
        return ['message' => '3PL tariff saved: packing £' . number_format($row['packing_fee'], 2) . ' per ' . strtolower($row['packing_fee_per']) . ', storage £' . number_format($row['storage_fee'], 2) . ' per ' . strtolower($row['storage_fee_per']) . '. New product rows start with these fees.'];
    }

    /** Defaults for a product marketplace row that has no fees yet. */
    public static function defaults(int $ent, int $mp): array
    {
        $t = self::of($ent, $mp);
        return ['packing_fee' => (float) ($t->packing_fee ?? 0), 'storage_fee' => (float) ($t->storage_fee ?? 0), 'from_tariff' => (bool) $t];
    }

    /** 3PL fees of a sale line of $qty units: packing per order once, per unit × qty; storage the same way. */
    public static function feeCost(int $ent, int $mp, float $packingFee, float $storageFee, int $qty = 1): float
    {
        $t = self::of($ent, $mp); $q = max(1, $qty);
        return round($packingFee * (($t->packing_fee_per ?? 'ORDER') === 'UNIT' ? $q : 1) + $storageFee * (($t->storage_fee_per ?? 'UNIT') === 'UNIT' ? $q : 1), 2);
    }

    /** Nightly self-check: products sold in the last 90 days on a marketplace whose row has no packing or storage fee. */
    public static function missingTasks(int $ent, ?string $today = null): array
    {
        $from = date('Y-m-d', strtotime(($today ?? date('Y-m-d')) . ' -90 days'));
        $sold = DB::table('t_sales as s')->join('t_branches as b', 'b.id', '=', 's.fk_id_branch')->where('s.fk_id_ent', $ent)->whereNotNull('s.fk_product_id')->where('s.d_order', '>=', $from)
            ->distinct()->get(['s.fk_product_id as p', 'b.fk_id_marketplace as mp']);
        $fees = DB::table('t_product_marketplace_shipping')->whereIn('fk_id_product', $sold->pluck('p')->unique()->all() ?: [0])->get()->keyBy(fn ($r) => $r->fk_id_product . ':' . $r->fk_id_marketplace);
        $byProduct = [];
        foreach ($sold as $r) { $f = $fees[$r->p . ':' . $r->mp] ?? null; if (! $f || ((float) $f->packing_fee <= 0 && (float) $f->storage_fee <= 0)) $byProduct[(int) $r->p][] = (int) $r->mp; }
        $problems = [];
        foreach ($byProduct as $p => $mps) {
            $sku = DB::table('t_product_vendors')->where('id', $p)->value('SKU_VENDOR');
            $names = DB::table('cat_marketplaces')->whereIn('id', $mps)->pluck('marketplace_short')->filter()->implode(', ');
            $problems[] = ['key' => [$p], 'title' => "Sold with no packing / storage fee: $sku" . ($names ? " on $names" : ''), 'opt' => ['module' => 't_product_vendors', 'record_id' => $p, 'fix_url' => "#/prices/$p"]];
        }
        return TaskEngine::sync('PRD_FEE_MISSING', $problems, null, $ent);
    }
}
