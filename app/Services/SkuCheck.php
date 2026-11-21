<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * SKU uniqueness and live check (R-D-2). Scope = the company (old: whole table).
 * - exact trimmed SKU in the same company → block "This SKU already exists – open it" (link to the product; the typed value is kept, never cleared);
 * - normalised match (upper case, spaces / underscores → "-", repeated "-" as one, leading zeros of a number ignored) → warn "Looks like SKU X – use it instead?";
 * - no match → "SKU is available".
 * The browser asks 500 ms after the last key (product form, child cards); the server repeats the exact check on every save with the same message.
 */
class SkuCheck
{
    public const BLOCK = 'This SKU already exists – open it';

    public static function norm(string $sku): string
    {
        $s = preg_replace('/[\s_]+/u', '-', mb_strtoupper(trim($sku)));
        $s = preg_replace('/-+/', '-', $s);
        return preg_replace('/(?<!\d)0+(?=\d)/', '', $s);
    }

    /** The product with exactly this SKU (trimmed) in the company, other than $exceptId. */
    public static function exact(int $ent, string $sku, ?int $exceptId = null): ?int
    {
        $sku = trim($sku); if ($sku === '') return null;
        $id = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->where('SKU_VENDOR', $sku)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->value('id');
        return $id ? (int) $id : null;
    }

    /** Live answer: ok + level (block / warn / ok) + message + link. */
    public static function check(int $ent, string $sku, ?int $exceptId = null): array
    {
        $sku = trim($sku);
        if ($sku === '') return ['ok' => true, 'level' => 'empty', 'message' => null, 'value' => $sku];
        if ($id = self::exact($ent, $sku, $exceptId)) return ['ok' => false, 'level' => 'block', 'message' => self::BLOCK, 'link' => '#/rec/t_product_vendor/' . $id, 'match' => (string) DB::table('t_product_vendors')->where('id', $id)->value('SKU_VENDOR'), 'value' => $sku];
        $n = self::norm($sku);
        $alike = DB::table('t_product_vendors')->where('fk_id_ent', $ent)->when($exceptId, fn ($q) => $q->where('id', '<>', $exceptId))->whereNotNull('SKU_VENDOR')->where('SKU_VENDOR', '<>', '')
            ->orderBy('id')->select(['id', 'SKU_VENDOR'])->cursor()->first(fn ($r) => self::norm((string) $r->SKU_VENDOR) === $n);
        if ($alike) return ['ok' => true, 'level' => 'warn', 'message' => "Looks like SKU {$alike->SKU_VENDOR} – use it instead?", 'link' => '#/rec/t_product_vendor/' . $alike->id, 'match' => $alike->SKU_VENDOR, 'value' => $sku];
        return ['ok' => true, 'level' => 'ok', 'message' => 'SKU is available', 'value' => $sku];
    }

    /** Save check of the product form (errors keyed by field name). */
    public static function onSave(array &$data, array $old, ?int $id): array
    {
        if (! array_key_exists('SKU_VENDOR', $data)) return [];
        $data['SKU_VENDOR'] = trim((string) $data['SKU_VENDOR']);
        if ($data['SKU_VENDOR'] === '') return [];
        $ent = (int) ($data['fk_id_ent'] ?? $old['fk_id_ent'] ?? Context::ent());
        return self::exact($ent, $data['SKU_VENDOR'], $id) ? ['sku_vendor' => self::BLOCK] : [];
    }
}
