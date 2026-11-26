<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MR-1541 practice company (training mode): one company with demo data, marked f_sandbox. It is its own company, so nothing of the real companies
 * is touched; messages queued in it never leave the tool (Messenger marks them IGNORED), it is left out of "All companies" sums and the Owner
 * opens it from the company switcher like any company.
 */
class Sandbox
{
    public static function id(): ?int { return Schema::hasColumn('t_enterprise', 'f_sandbox') ? (DB::table('t_enterprise')->where('f_sandbox', 1)->value('id') ?: null) : null; }

    public static function is(?int $ent): bool { static $c = []; if (! $ent) return false; return $c[$ent] ??= Schema::hasColumn('t_enterprise', 'f_sandbox') && (bool) DB::table('t_enterprise')->where('id', $ent)->value('f_sandbox'); }

    /** Make the practice company once (Owner) with a warehouse, three products and opening stock; returns its id. */
    public static function create(object $u): array
    {
        if (($u->role_code ?? '') !== 'OWNER') throw new \InvalidArgumentException('Only the Owner makes the practice company.');
        if ($id = self::id()) return ['id' => $id, 'created' => false, 'message' => 'The practice company is there already – open it from the company switcher.'];
        return DB::transaction(function () {
            $ent = (int) DB::table('t_enterprise')->insertGetId(RecordService::fillNotNull('t_enterprise', ['ent_name' => 'Practice company (training)', 'ent_short' => 'PRAC', 'iso_ent' => 'PRAC', 'f_active' => 1, 'f_sandbox' => 1]));
            $wh = (int) DB::table('cat_inventory_locations')->insertGetId(RecordService::fillNotNull('cat_inventory_locations', ['location_name' => 'Practice warehouse', 'kind' => 'WAREHOUSE', 'fk_id_ent' => $ent, 'f_sellable' => 1, 'f_active' => 1]));
            $lines = [];
            foreach ([['PRAC-MUG', 'Practice mug', 2.40, 40], ['PRAC-BOWL', 'Practice dog bowl', 3.10, 25], ['PRAC-LAMP', 'Practice desk lamp', 6.75, 8]] as [$sku, $name, $cost, $qty]) {
                $p = (int) DB::table('t_product_vendors')->insertGetId(RecordService::fillNotNull('t_product_vendors', ['fk_id_ent' => $ent, 'SKU_VENDOR' => $sku, 'product_name_vendor' => $name, 'f_active' => 1, 'e_product_type' => 'pool', 'landing_price' => $cost, 'min_level' => 10, 'f_alert_min_level' => 1]));
                $lines[] = ['product' => $p, 'qty' => $qty, 'location' => $wh, 'unit_cost' => $cost];
            }
            StockService::receive($ent, $lines, 'OPENING', null, ['lot_source' => 'OPENING']);
            Audit::log('t_enterprise', $ent, 'INSERT', ['f_sandbox' => [null, 1]], 'Practice company made', $ent);
            return ['id' => $ent, 'created' => true, 'message' => 'Practice company made with a warehouse, 3 products and opening stock. Switch to it from the company switcher; messages there never leave the tool.'];
        });
    }
}
