<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * 12.9 Merge duplicates by hand: people / companies (t_account), suppliers (t_src_suppliers) and products (t_product_vendors) that are the same thing twice.
 * Look-alikes are found by the same normalised name, the same WhatsApp / phone digits, or the same SKU written differently.
 * Merge = every link that points to the record folded in is moved to the record kept (links are found from the registry and from the usual link
 * column names), the folded record is switched off, and each moved row is written down, so Undo puts every row back. Owner / Manager only.
 * A product with stock still on its lots, or with order lines not confirmed yet, cannot be folded in: stock and the books stay exact.
 */
class Merges
{
    /** entity → [table, label, name SQL, extra key SQL, merge-map entity, link column names besides the registry] */
    public const ENTITIES = [
        'PARTY' => ['t_account', 'People & companies', "LOWER(TRIM(REGEXP_REPLACE(CONCAT(COALESCE(first_name,''), ' ', COALESCE(sur_name,'')), '[[:space:]]+', ' ')))", "NULLIF(REGEXP_REPLACE(COALESCE(whatsapp_number,''), '[^0-9]', ''), '')", 'PARTY', ['fk_id_account', 'investor_id']],
        'SUPPLIER' => ['t_src_suppliers', 'Suppliers', "LOWER(TRIM(REGEXP_REPLACE(REGEXP_REPLACE(supplier_name, '[^A-Za-z0-9 ]', ''), '[[:space:]]+', ' ')))", "NULLIF(REGEXP_REPLACE(COALESCE(phone_whatsapp,''), '[^0-9]', ''), '')", 'SUPPLIER', ['fk_id_supplier', 'supplier_id']],
        'PRODUCT' => ['t_product_vendors', 'Products', "UPPER(REGEXP_REPLACE(SKU_VENDOR, '[^A-Za-z0-9]', ''))", 'NULL', 'PRODUCT', ['fk_id_product', 'fk_product_id', 'product_id']],
    ];

    private static function ent(string $e): array { return self::ENTITIES[$e] ?? throw new \InvalidArgumentException('Choose people, suppliers or products.'); }

    private static function label(string $e, object $r): string
    {
        return match ($e) { 'PARTY' => trim(($r->first_name ?? '') . ' ' . ($r->sur_name ?? '')) ?: (string) ($r->account_code ?? '#' . $r->id), 'SUPPLIER' => (string) $r->supplier_name, default => $r->SKU_VENDOR . ' · ' . $r->product_name_vendor };
    }

    /** Every (table, column) that holds a link to the entity's table. */
    public static function links(string $e): array
    {
        [$table, , , , , $names] = self::ent($e);
        $reg = DB::table('sys_registry')->where('source', 'table:' . $table)->whereNotNull('table_name')->whereNotNull('column_name')->distinct()->get(['table_name', 'column_name'])->map(fn ($x) => [$x->table_name, $x->column_name])->all();
        $byName = collect(DB::select('SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name IN (' . implode(',', array_fill(0, count($names), '?')) . ')', $names))->map(fn ($x) => [$x->t, $x->c])->all();
        $out = [];
        foreach (array_merge($reg, $byName) as [$t, $c]) {
            if ($t === $table || str_starts_with($t, 'old_') || ! DB::getSchemaBuilder()->hasColumn($t, $c) || ! DB::getSchemaBuilder()->hasColumn($t, 'id')) continue;
            $out["$t.$c"] = [$t, $c];
        }
        return array_values($out);
    }

    public static function suggestions(string $e): array
    {
        [$table, , $nameSql, $keySql] = self::ent($e); $ent = (int) Context::ent();
        $base = DB::table($table)->where('f_active', 1)->when($ent && DB::getSchemaBuilder()->hasColumn($table, 'fk_id_ent'), fn ($q) => $q->where('fk_id_ent', $ent));
        $groups = [];
        foreach ([[$nameSql, 'same name'], [$keySql, 'same WhatsApp / phone']] as [$sql, $why]) {
            if ($sql === 'NULL') continue;
            $inner = (clone $base)->selectRaw("id, $sql AS k");
            foreach (DB::query()->fromSub($inner, 'x')->whereNotNull('k')->where('k', '<>', '')->groupBy('k')->havingRaw('COUNT(*) > 1')->selectRaw('k, GROUP_CONCAT(id ORDER BY id) ids')->limit(200)->get() as $g) {
                $ids = array_map('intval', explode(',', $g->ids)); $key = implode(',', $ids);
                $groups[$key] ??= ['ids' => $ids, 'why' => []]; $groups[$key]['why'][] = $e === 'PRODUCT' ? 'same SKU written differently' : $why;
            }
        }
        $dismissed = DB::table('sys_merge_map')->where('entity', self::ent($e)[4])->where('status', 'REJECTED')->get(['source_id', 'master_id'])->map(fn ($x) => min($x->source_id, $x->master_id) . '-' . max($x->source_id, $x->master_id))->flip();
        $out = [];
        foreach ($groups as $g) {
            $rows = DB::table($table)->whereIn('id', $g['ids'])->get();
            if (count($g['ids']) === 2 && isset($dismissed[min($g['ids']) . '-' . max($g['ids'])])) continue;
            $out[] = ['why' => implode(', ', array_unique($g['why'])), 'records' => $rows->map(fn ($r) => ['id' => $r->id, 'label' => self::label($e, $r), 'code' => $r->account_code ?? $r->supplier_code ?? $r->SKU_VENDOR ?? null, 'links' => self::countLinks($e, (int) $r->id)])->sortByDesc('links')->values()];   // the record with most links is ticked to keep
        }
        return $out;
    }

    private static function countLinks(string $e, int $id): int
    {
        $n = 0; foreach (self::links($e) as [$t, $c]) $n += DB::table($t)->where($c, $id)->count();
        return $n;
    }

    /** Fold $sourceId into $masterId. */
    public static function merge(string $e, int $masterId, int $sourceId, string $reason): array
    {
        if (! in_array(Context::role(), ['OWNER', 'MANAGER'], true)) throw new \InvalidArgumentException('Only the Owner or a Manager merges records.');
        [$table, , , , $mapEntity] = self::ent($e);
        if ($masterId === $sourceId) throw new \InvalidArgumentException('Choose two different records.');
        if (mb_strlen(trim($reason)) < 3) throw new \InvalidArgumentException('Say why these are the same (a few words).');
        $ent = (int) Context::ent(); $hasEnt = DB::getSchemaBuilder()->hasColumn($table, 'fk_id_ent');
        $m = DB::table($table)->where('id', $masterId)->when($ent && $hasEnt, fn ($q) => $q->where('fk_id_ent', $ent))->first() ?? throw new \InvalidArgumentException('The record to keep was not found.');
        $s = DB::table($table)->where('id', $sourceId)->when($ent && $hasEnt, fn ($q) => $q->where('fk_id_ent', $ent))->first() ?? throw new \InvalidArgumentException('The record to fold in was not found.');
        if ($e === 'PRODUCT') {
            if ((int) DB::table('fin_lots')->where('fk_id_product', $sourceId)->sum('qty_left') > 0) throw new \InvalidArgumentException('The product to fold in still has stock on its lots. Move or sell it first (or give the units to the kept product with a stock adjustment): stock and the books must stay exact.');
            if (DB::table('t_sales')->where('fk_product_id', $sourceId)->whereIn('status', ['Pending'])->where('f_locked', 0)->exists()) throw new \InvalidArgumentException('The product to fold in has order lines not confirmed yet. Confirm or cancel them first.');
        }
        return DB::transaction(function () use ($e, $table, $mapEntity, $m, $s, $reason) {
            $mid = DB::table('sys_merge_map')->insertGetId(['entity' => $mapEntity, 'source_table' => $table, 'source_id' => $s->id, 'master_table' => $table, 'master_id' => $m->id, 'match_score' => 100, 'status' => 'CONFIRMED', 'decided_by' => Context::userId(), 'decided_at' => now()]);
            $moved = 0; $tables = [];
            foreach (self::links($e) as [$t, $c]) {
                $ids = DB::table($t)->where($c, $s->id)->pluck('id')->all(); if (! $ids) continue;
                try { DB::table($t)->whereIn('id', $ids)->update([$c => $m->id]); }
                catch (\Illuminate\Database\QueryException $x) { throw new \InvalidArgumentException("Both records have a row in $t that may exist only once (column $c). Remove the repeat there first, then merge."); }
                foreach (array_chunk($ids, 500) as $ch) DB::table('mo_merge_moves')->insert(array_map(fn ($id) => ['fk_id_merge' => $mid, 'table_name' => $t, 'column_name' => $c, 'row_id' => $id], $ch));
                $moved += count($ids); $tables[$t] = ($tables[$t] ?? 0) + count($ids);
            }
            $set = ['f_active' => 0];
            if (DB::getSchemaBuilder()->hasColumn($table, 'notes')) $set['notes'] = mb_substr(trim(($s->notes ?? '') . " Merged into #{$m->id}: $reason"), 0, 1000);
            if (DB::getSchemaBuilder()->hasColumn($table, 'remarks')) $set['remarks'] = mb_substr(trim(($s->remarks ?? '') . " Merged into #{$m->id}: $reason"), 0, 1000);
            DB::table($table)->where('id', $s->id)->update($set);
            Audit::log($table, $s->id, 'MERGE', ['into' => $m->id, 'rows_moved' => $moved, 'tables' => $tables], $reason, (int) Context::ent());
            return ['merge' => $mid, 'moved' => $moved, 'tables' => $tables, 'message' => "Merged: $moved linked row(s) now point to the kept record. Undo is on the Merged list."];
        });
    }

    public static function undo(int $mergeId): array
    {
        if (! in_array(Context::role(), ['OWNER', 'MANAGER'], true)) throw new \InvalidArgumentException('Only the Owner or a Manager undoes a merge.');
        $mm = DB::table('sys_merge_map')->where('id', $mergeId)->first() ?? throw new \InvalidArgumentException('Merge not found.');
        $hand = in_array($mm->entity, ['PARTY', 'SUPPLIER', 'PRODUCT'], true) && (float) $mm->match_score === 100.0 && $mm->source_table === $mm->master_table;
        if ($mm->status !== 'CONFIRMED' || ! $hand) throw new \InvalidArgumentException('Only a merge made by hand can be undone here (merges made while the old data came in stay).');
        return DB::transaction(function () use ($mm, $mergeId) {
            $n = 0;
            foreach (DB::table('mo_merge_moves')->where('fk_id_merge', $mergeId)->get()->groupBy(fn ($x) => $x->table_name . '.' . $x->column_name) as $rows) {
                $f = $rows->first(); DB::table($f->table_name)->whereIn('id', $rows->pluck('row_id'))->where($f->column_name, $mm->master_id)->update([$f->column_name => $mm->source_id]); $n += $rows->count();
            }
            DB::table($mm->source_table)->where('id', $mm->source_id)->update(['f_active' => 1]);
            DB::table('sys_merge_map')->where('id', $mergeId)->update(['status' => 'REJECTED', 'decided_by' => Context::userId(), 'decided_at' => now()]);
            Audit::log($mm->source_table, (int) $mm->source_id, 'UNMERGE', ['from' => $mm->master_id, 'rows_moved_back' => $n], null, (int) Context::ent());
            return ['moved_back' => $n, 'message' => "Undone: $n row(s) point to the folded record again and it is active again."];
        });
    }

    /** "Not the same": the pair is not suggested again. */
    public static function dismiss(string $e, int $a, int $b): void
    {
        [$table, , , , $mapEntity] = self::ent($e);
        DB::table('sys_merge_map')->insert(['entity' => $mapEntity, 'source_table' => $table, 'source_id' => max($a, $b), 'master_table' => $table, 'master_id' => min($a, $b), 'match_score' => 0, 'status' => 'REJECTED', 'decided_by' => Context::userId(), 'decided_at' => now()]);
    }
}
