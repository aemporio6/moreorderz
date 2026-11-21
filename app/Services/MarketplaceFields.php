<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Marketplace field builder (R-A_SALES-67): listing fields per marketplace and their product values.
 * - Label → field_name slug "<marketplace>_<label>" (≤ 140 chars, numeric suffix when taken); the field_name never changes on edit.
 * - Types: text, textarea, number (step 1), decimal (0.01), date, select (options one per line or comma-separated → JSON), checkbox (Yes / No, hidden 0), url, file.
 * - Required, Sort; delete is soft (f_active 0) and product values are kept.
 * - Product values: t_product_marketplace_values, upsert per product / marketplace / field, saved per tab (one marketplace at a time).
 * - exportColumns() hands the values to a channel export (field_name => value per product).
 * The generic form (marketplace_field) uses the same rules through FormRules.
 */
class MarketplaceFields
{
    public const TYPES = ['text' => 'Text', 'textarea' => 'Long text', 'number' => 'Whole number', 'decimal' => 'Decimal (0.01)', 'date' => 'Date', 'select' => 'Select', 'checkbox' => 'Yes / No', 'url' => 'Link (URL)', 'file' => 'File'];

    public static function slug(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(\Illuminate\Support\Str::ascii($s))), '_'); }

    private static function mpSlug(int $mp): string
    {
        $m = DB::table('cat_marketplaces')->where('id', $mp)->first(['marketplace_short', 'marketplace_descr', 'iso_code']);
        return self::slug((string) ($m->marketplace_short ?: ($m->marketplace_descr ?: ($m->iso_code ?? '')))) ?: 'mp' . $mp;
    }

    /** The field_name a new label gets (live preview). */
    public static function nameFor(int $mp, string $label, ?int $exceptId = null): string
    {
        $base = mb_substr(self::mpSlug($mp) . '_' . self::slug($label), 0, 140); $base = rtrim($base, '_'); $name = $base; $n = 2;
        while (DB::table('cat_marketplace_fields')->where('fk_id_marketplace', $mp)->where('field_name', $name)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->exists())
            $name = mb_substr($base, 0, 140 - strlen("_$n")) . '_' . $n++;
        return $name;
    }

    /** Options typed one per line or comma-separated → JSON list (no blanks, no repeats). */
    public static function options($raw): ?string
    {
        if ($raw === null || $raw === '') return null;
        if (is_string($raw) && ($j = json_decode($raw, true)) && is_array($j)) $list = $j;
        else $list = is_array($raw) ? $raw : preg_split(str_contains((string) $raw, "\n") ? '/\r?\n/' : '/,/', (string) $raw);
        $list = array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), $list), fn ($x) => $x !== '')));
        return $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : null;
    }

    /** Checks and fills one field row (used by the builder and the generic form). $data is changed in place. Returns errors. */
    public static function prepare(array &$data, array $old = [], ?int $id = null): array
    {
        $e = []; $val = fn ($c) => array_key_exists($c, $data) ? $data[$c] : ($old[$c] ?? null);
        $label = trim((string) $val('field_label')); $mp = (int) $val('fk_id_marketplace'); $type = (string) ($val('field_type') ?: 'text');
        if ($label === '') $e['field_label'] = 'Field label is required';
        if (! $mp) $e['fk_id_marketplace'] = 'Choose the marketplace';
        if (! isset(self::TYPES[$type])) $e['field_type'] = 'Choose a field type';
        if ($type === 'select') { $o = self::options($val('field_options')); if (! $o) $e['field_options'] = 'provide options for the Select field'; else $data['field_options'] = $o; }
        elseif (array_key_exists('field_options', $data)) $data['field_options'] = null;
        if ($e) return $e;
        $data['field_label'] = mb_substr($label, 0, 150);
        if ($id && ! empty($old['field_name'])) { $data['field_name'] = $old['field_name']; if ((int) $old['fk_id_marketplace'] !== $mp) $e['fk_id_marketplace'] = 'A field cannot move to another marketplace – add a new one.'; }      // the field_name never changes on edit
        else $data['field_name'] = self::nameFor($mp, $label);
        return $e;
    }

    public static function list(int $mp, bool $withDeleted = false): array
    {
        return DB::table('cat_marketplace_fields')->where('fk_id_marketplace', $mp)->when(! $withDeleted, fn ($q) => $q->where('f_active', 1))->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($f) => ['id' => (int) $f->id, 'label' => $f->field_label, 'name' => $f->field_name, 'type' => $f->field_type, 'options' => $f->field_options ? json_decode($f->field_options, true) : [], 'required' => (bool) $f->f_required, 'sort' => (int) $f->sort_order, 'active' => (bool) $f->f_active,
                'values' => DB::table('t_product_marketplace_values')->where('fk_id_field', $f->id)->whereNotNull('field_value')->where('field_value', '<>', '')->count()])->all();
    }

    public static function save(array $d, ?int $id = null): array
    {
        $old = $id ? (array) (DB::table('cat_marketplace_fields')->where('id', $id)->first() ?? throw new \InvalidArgumentException('Field not found.')) : [];
        $row = array_intersect_key($d, array_flip(['fk_id_marketplace', 'field_label', 'field_type', 'field_options', 'f_required', 'sort_order']));
        if ($e = self::prepare($row, $old, $id)) throw new ValidationErrors($e);
        $row['f_required'] = (int) (bool) ($row['f_required'] ?? $old['f_required'] ?? 0); $row['sort_order'] = (int) ($row['sort_order'] ?? $old['sort_order'] ?? 0);
        if ($id) { DB::table('cat_marketplace_fields')->where('id', $id)->update($row); Audit::log('cat_marketplace_fields', $id, 'UPDATE', array_diff_assoc(array_map('strval', $row), array_map('strval', array_intersect_key($old, $row)))); }
        else { $id = (int) DB::table('cat_marketplace_fields')->insertGetId($row + ['f_active' => 1, 'created_by' => (int) Context::userId(), 'created_at' => now()]); Audit::log('cat_marketplace_fields', $id, 'INSERT', $row); }
        return ['id' => $id, 'field_name' => $row['field_name'], 'message' => 'Field saved as ' . $row['field_name'] . '.'];
    }

    /** Soft delete: f_active 0; product values are kept. */
    public static function delete(int $id): array
    {
        DB::table('cat_marketplace_fields')->where('id', $id)->update(['f_active' => 0]); Audit::log('cat_marketplace_fields', $id, 'DELETE', ['f_active' => 0], 'Soft delete – product values kept');
        return ['message' => 'Field removed. The product values are kept.'];
    }

    /** One product: a tab per marketplace that has fields, with the values. */
    public static function product(int $product): array
    {
        $vals = DB::table('t_product_marketplace_values')->where('fk_id_product', $product)->pluck('field_value', 'fk_id_field');
        $mps = DB::table('cat_marketplace_fields as f')->join('cat_marketplaces as m', 'm.id', '=', 'f.fk_id_marketplace')->where('f.f_active', 1)->distinct()->orderBy('m.id')
            ->get(['m.id', DB::raw("COALESCE(NULLIF(m.marketplace_short,''), m.marketplace_descr) as name")]);
        return ['product' => DB::table('t_product_vendors')->where('id', $product)->first(['id', 'SKU_VENDOR as sku', 'product_name_vendor as name']), 'types' => self::TYPES,
            'tabs' => $mps->map(fn ($m) => ['id' => (int) $m->id, 'name' => $m->name, 'fields' => array_map(fn ($f) => $f + ['value' => $vals[$f['id']] ?? null], self::list((int) $m->id))])->all()];
    }

    /** Save one tab (one marketplace) of a product: upsert per field. $files = [field id => UploadedFile]. */
    public static function saveValues(int $product, int $mp, array $values, array $files = []): array
    {
        DB::table('t_product_vendors')->where('id', $product)->exists() || throw new \InvalidArgumentException('Product not found.');
        $e = []; $rows = [];
        foreach (self::list($mp) as $f) {
            $k = $f['id']; $given = array_key_exists($k, $values) || array_key_exists((string) $k, $values) || isset($files[$k]);
            $cur = DB::table('t_product_marketplace_values')->where('fk_id_product', $product)->where('fk_id_field', $k)->value('field_value');
            $v = $values[$k] ?? $values[(string) $k] ?? null; $v = is_string($v) ? trim($v) : $v;
            switch ($f['type']) {
                case 'checkbox': $v = in_array($v, [1, '1', true, 'true', 'Yes', 'yes', 'on'], true) ? '1' : '0'; break;
                case 'number': if ($v !== null && $v !== '') { if (! preg_match('/^-?\d+$/', (string) $v)) $e[$k] = $f['label'] . ': a whole number'; } break;
                case 'decimal': if ($v !== null && $v !== '') { if (! is_numeric($v)) $e[$k] = $f['label'] . ': a number'; else $v = number_format(round((float) $v, 2), 2, '.', ''); } break;
                case 'date': if ($v !== null && $v !== '' && ! (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) && strtotime((string) $v))) $e[$k] = $f['label'] . ': a date (YYYY-MM-DD)'; break;
                case 'url': if ($v !== null && $v !== '' && ! filter_var($v, FILTER_VALIDATE_URL)) $e[$k] = $f['label'] . ': a full link starting with http'; break;
                case 'select': if ($v !== null && $v !== '' && ! in_array($v, $f['options'], true)) $e[$k] = $f['label'] . ': choose one of the list'; break;
                case 'file':
                    if (isset($files[$k]) && $files[$k] instanceof UploadedFile) { $v = $files[$k]->store('uploads/marketplace_files'); }
                    else { $v = $cur; $given = true; }      // keeps the current file when none is chosen
                    break;
            }
            if (! $given && $f['type'] !== 'checkbox') $v = $cur;
            if ($f['required'] && ($v === null || $v === '' || ($f['type'] === 'checkbox' && false))) $e[$k] = $f['label'] . ' is required';
            $rows[$k] = $v === '' ? null : $v;
        }
        if ($e) throw new ValidationErrors($e);
        foreach ($rows as $k => $v) DB::table('t_product_marketplace_values')->updateOrInsert(['fk_id_product' => $product, 'fk_id_field' => $k], ['fk_id_marketplace' => $mp, 'field_value' => $v, 'created_by' => (int) Context::userId(), 'updated_at' => now()]);
        Audit::log('t_product_vendors', $product, 'UPDATE', ['marketplace_fields' => $mp, 'count' => count($rows)], 'Marketplace fields saved');
        return ['saved' => count($rows), 'message' => count($rows) . ' marketplace field(s) saved.'];
    }

    /** For a channel export: [product id => [field_name => value]] of one marketplace (active fields only). */
    public static function exportColumns(int $mp, array $products): array
    {
        $f = DB::table('cat_marketplace_fields')->where('fk_id_marketplace', $mp)->where('f_active', 1)->orderBy('sort_order')->pluck('field_name', 'id');
        $out = array_fill_keys($products, array_fill_keys($f->values()->all(), null));
        foreach (DB::table('t_product_marketplace_values')->whereIn('fk_id_product', $products ?: [0])->whereIn('fk_id_field', $f->keys()->all() ?: [0])->get() as $v) $out[$v->fk_id_product][$f[$v->fk_id_field]] = $v->field_value;
        return $out;
    }
}
