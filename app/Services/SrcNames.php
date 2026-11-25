<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * R-B_SOURCING-12 one place that turns a typed name into a record, the same on the forms and on the CSV imports, with the same log lines.
 * - supplier: an id, else the exact name (case and outer spaces ignored), else "contains" (lowest id). Not found → an error, never created.
 * - forwarder: an id, else contains; not found → WARN, left empty.
 * - owner: an id, else the exact investor / account name or code; not found → WARN, left empty (Old entry then blocks the save when the owner is required).
 * - currency: code not in Setup › Currencies → WARN, stored without a currency id.
 * - warehouse: contains-match on the location name; not found → WARN, lot location empty.
 * - brand: exact name only; never created by an import – the row offers "+ Add brand '<x>'" (addBrand), else it is saved with an empty brand + WARN.
 */
class SrcNames
{
    public static function supplierMissing(string $x): string { return "Supplier '$x' not found - add the supplier first (never created silently)"; }

    public static function supplier(int $ent, mixed $v): ?object
    {
        $q = fn () => DB::table('t_src_suppliers')->where('fk_id_ent', $ent)->where('f_active', 1);
        if (is_int($v) || ctype_digit((string) $v)) return $q()->where('id', (int) $v)->first();
        $t = trim((string) $v); if ($t === '') return null;
        return $q()->whereRaw('LOWER(TRIM(supplier_name)) = ?', [mb_strtolower($t)])->orderBy('id')->first()      /* exact first … */
            ?? $q()->where('supplier_name', 'like', '%' . addcslashes($t, '%_\\') . '%')->orderBy('id')->first();      /* … then contains, the lowest id */
    }

    public static function forwarder(int $ent, mixed $v, array &$log): ?int
    {
        $t = trim((string) $v); if ($t === '') return null;
        $q = DB::table('t_src_forwarders')->where('fk_id_ent', $ent);
        $id = ctype_digit($t) ? $q->where('id', (int) $t)->value('id') : $q->where('forwarder_name', 'like', '%' . addcslashes($t, '%_\\') . '%')->orderBy('id')->value('id');
        if (! $id) $log[] = "WARN: forwarder '$t' not found (Logistics role) - left empty";
        return $id ? (int) $id : null;
    }

    public static function owner(int $ent, mixed $v, array &$log): ?int
    {
        $t = trim((string) $v); if ($t === '') return null;
        if (ctype_digit($t) && DB::table('t_account')->where('id', (int) $t)->where('fk_id_ent', $ent)->exists()) return (int) $t;
        $id = DB::table('t_account')->where('fk_id_ent', $ent)->where(fn ($w) => $w->where('account_code', $t)->orWhereRaw("LOWER(TRIM(CONCAT_WS(' ', first_name, sur_name))) = ?", [mb_strtolower($t)]))->orderBy('id')->value('id');
        if (! $id) $log[] = "WARN: owner entity '$t' not found in Setup > Investor Master - left empty, pick it on the cycle (never created silently)";
        return $id ? (int) $id : null;
    }

    public static function currency(mixed $code, array &$log): ?int
    {
        $c = strtoupper(trim((string) $code)); if ($c === '') return null;
        $id = DB::table('cat_currency')->whereRaw('UPPER(currency_code) = ?', [$c])->orderByDesc('f_active')->value('id');
        if (! $id) $log[] = "WARN: currency '$c' not found in Setup > Currencies - stored without currency id";
        return $id ? (int) $id : null;
    }

    public static function warehouse(int $ent, mixed $v, array &$log, string $ref = ''): ?int
    {
        $t = trim((string) $v); if ($t === '') return null;
        if (ctype_digit($t) && DB::table('cat_inventory_locations')->where('id', (int) $t)->exists()) return (int) $t;
        $id = DB::table('cat_inventory_locations')->where('fk_id_ent', $ent)->where('f_active', 1)->where('location_name', 'like', '%' . addcslashes($t, '%_\\') . '%')->orderByRaw("kind = 'WAREHOUSE' DESC")->orderBy('id')->value('id');
        if (! $id) $log[] = ($ref !== '' ? "[$ref] " : '') . "WARN: warehouse '$t' not found - lot location empty";
        return $id ? (int) $id : null;
    }

    /** Exact name (case-insensitive, trimmed). Not found: nothing is created – the caller shows "+ Add brand '<x>'". */
    public static function brand(int $ent, mixed $v): ?int
    {
        $t = trim((string) $v); if ($t === '') return null;
        if (ctype_digit($t)) return DB::table('cat_brands')->where('id', (int) $t)->exists() ? (int) $t : null;
        $id = DB::table('cat_brands')->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0)->orWhereNull('fk_id_ent'))->whereRaw('LOWER(TRIM(brand_name)) = ?', [mb_strtolower($t)])->orderBy('id')->value('id');
        return $id ? (int) $id : null;
    }

    public static function brandWarn(string $x): string { return "WARN: brand '$x' not found - line saved with an empty brand (press + Add brand '$x' on the check screen first to keep it)"; }

    /** "+ Add brand '<x>'" – a person adds it knowingly from the check screen or the form (the same name is never added twice). */
    public static function addBrand(int $ent, string $name, object $u): array
    {
        Perm::need($u, 'buying.BUY'); $name = mb_substr(trim($name), 0, 150); if ($name === '') throw new \InvalidArgumentException('Type the brand name.');
        if ($id = self::brand($ent, $name)) return ['id' => $id, 'created' => false, 'message' => "Brand '$name' is already in the list."];
        $id = (int) DB::table('cat_brands')->insertGetId(RecordService::fillNotNull('cat_brands', ['fk_id_ent' => $ent, 'brand_name' => $name, 'f_active' => 1, 'created_by' => $u->id, 'created_at' => now()]));
        Audit::log('cat_brands', $id, 'INSERT', ['brand_name' => $name, 'from' => 'import check screen'], null, $ent);
        return ['id' => $id, 'created' => true, 'log' => "NEW: brand '$name' created", 'message' => "Brand '$name' added."];
    }
}
