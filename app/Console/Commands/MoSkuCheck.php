<?php

namespace App\Console\Commands;

use App\Services\SkuResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Read only: which "SKU not found" lines of sales files (and unmatched sale lines) the SKU resolver would find now, and how. */
class MoSkuCheck extends Command
{
    protected $signature = 'mo:sku-check {--company=1}';
    protected $description = 'Show which unknown sale SKUs are now found (shop prefix, ASIN, item id, listing SKU) – writes nothing';

    public function handle(): int
    {
        $ent = (int) $this->option('company'); $found = 0; $all = 0; $bundle = 0;
        $rows = DB::table('t_sale_import_errors as e')->join('t_sale_imports as i', 'i.id', '=', 'e.fk_id_import')->where('i.fk_id_ent', $ent)->where('e.reason', 'like', 'SKU not found%')
            ->selectRaw('e.sku, i.fk_id_marketplace mp, COUNT(*) n')->groupBy('e.sku', 'i.fk_id_marketplace')->get()
            ->concat(DB::table('t_sales')->where('fk_id_ent', $ent)->where('sale_source', 'unmatched')->where('sku', 'not like', 'NOSKU-%')->selectRaw('sku, fk_id_marketplace mp, COUNT(*) n')->groupBy('sku', 'fk_id_marketplace')->get());
        foreach ($rows as $r) { $all++; if (str_contains((string) $r->sku, '+')) { $bundle++; continue; }
            $x = SkuResolver::find($ent, (int) $r->mp, (string) $r->sku);
            if ($x) { $found++; $this->line(mb_substr((string) $r->sku, 0, 50) . ' → ' . DB::table('t_product_vendors')->where('id', $x[0])->value('SKU_VENDOR') . ' (' . $x[1] . ', ' . $r->n . ' line(s))'); } }
        $this->info("SKUs checked: $all · found now: $found · bundles (A+B, never guessed): $bundle · still unknown: " . ($all - $found - $bundle));
        return 0;
    }
}
