<?php

namespace App\Console\Commands;

use App\Services\Audit;
use App\Services\RecordService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Live 26-11: the old MoreOrderz sourcing rows point their supplier at the PARTY (t_account id, e.g. VENDOR-108), while the new tool's
 * supplier dropdowns read the supplier list (t_src_suppliers, D2: a supplier = a party + its supplier profile). This makes one supplier
 * profile for every party the old sourcing rows use (and every party with the role Vendor / Supplier), linked to the party, and points the
 * old rows at it. Without --apply it only counts. Run once after the old-data import; a second run changes nothing.
 */
class MoSuppliersFromParties extends Command
{
    protected $signature = 'mo:suppliers-from-parties {--apply : write (without it: count only)}';
    protected $description = 'Supplier list from the old parties, old sourcing rows pointed at it';

    private const COLS = [['t_src_po', 'fk_id_supplier'], ['t_src_payments', 'fk_id_supplier'], ['t_src_cycle_products', 'fk_id_supplier'], ['t_src_rfq', 'fk_id_supplier'], ['t_src_samples', 'fk_id_supplier'],
        ['t_src_qc', 'fk_id_supplier'], ['t_src_shipments', 'fk_id_supplier'], ['src_research_suppliers', 'fk_id_supplier'], ['t_product_purchases', 'fk_id_supplier']];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply'); $known = DB::table('t_src_suppliers')->pluck('id')->map(fn ($x) => (int) $x)->flip();
        $used = [];      // party ids the old rows use (a value that is not a supplier id but is a party)
        foreach (self::COLS as [$t, $c]) if (DB::getSchemaBuilder()->hasTable($t) && DB::getSchemaBuilder()->hasColumn($t, $c))
            foreach (DB::table($t)->whereNotNull($c)->where($c, '>', 0)->distinct()->pluck($c) as $v) if (! isset($known[(int) $v])) $used[(int) $v] = true;
        $roles = DB::table('cat_account_types')->whereRaw("UPPER(COALESCE(role_code, '')) IN ('VENDOR', 'SUPPLIER') OR UPPER(COALESCE(account_type_name, '')) IN ('VENDOR', 'SUPPLIER')")->pluck('id');
        foreach (DB::table('t_account_in_types')->whereIn('fk_id_account_type', $roles ?: [0])->where('f_active', 1)->pluck('fk_id_account') as $a) $used[(int) $a] = true;
        $parties = DB::table('t_account')->whereIn('id', array_keys($used) ?: [0])->get(['id', 'fk_id_ent', 'account_code', 'first_name', 'middle_name', 'sur_name', 'f_active']);
        $map = []; $made = 0;
        foreach ($parties as $p) {
            $have = DB::table('t_src_suppliers')->where('fk_id_account', $p->id)->value('id');
            if ($have) { $map[$p->id] = (int) $have; continue; }
            $name = trim(preg_replace('/\s+/', ' ', $p->first_name . ' ' . $p->middle_name . ' ' . $p->sur_name)) ?: (string) $p->account_code;
            $made++; if (! $apply) $map[$p->id] = 0;
            if ($apply) $map[$p->id] = (int) DB::table('t_src_suppliers')->insertGetId(RecordService::fillNotNull('t_src_suppliers', ['fk_id_ent' => (int) $p->fk_id_ent ?: 1, 'supplier_code' => mb_substr((string) ($p->account_code ?: 'SUP-' . $p->id), 0, 40),
                'supplier_name' => mb_substr($name, 0, 150), 'fk_id_account' => $p->id, 'notes' => 'From the old MoreOrderz party ' . $p->account_code, 'f_active' => (int) $p->f_active, 'created_at' => now()]));
        }
        $moved = 0;
        foreach (self::COLS as [$t, $c]) if (DB::getSchemaBuilder()->hasTable($t) && DB::getSchemaBuilder()->hasColumn($t, $c))
        {
            $m = array_filter($map, fn ($sup, $acc) => ! isset($known[$acc]), ARRAY_FILTER_USE_BOTH); if (! $m) continue;
            if (! $apply) { $moved += DB::table($t)->whereIn($c, array_keys($m))->count(); continue; }
            $case = 'CASE ' . $c . ' ' . implode(' ', array_map(fn ($acc, $sup) => 'WHEN ' . (int) $acc . ' THEN ' . (int) $sup, array_keys($m), $m)) . ' END';      // one statement: every row read with its old value (no id moved twice)
            $moved += DB::table($t)->whereIn($c, array_keys($m))->update([$c => DB::raw($case)]);
        }
        if ($apply) Audit::log('t_src_suppliers', null, 'INSERT', ['FROM_PARTIES' => [null, "$made suppliers, $moved rows pointed"]], 'Old sourcing suppliers = parties (D2)', null);
        $this->info(($apply ? '' : 'DRY RUN – nothing written. ') . count($parties) . " parties used as suppliers · $made supplier profiles " . ($apply ? 'made' : 'to make') . " · $moved old rows " . ($apply ? 'pointed at them' : 'to point'));
        return 0;
    }
}
