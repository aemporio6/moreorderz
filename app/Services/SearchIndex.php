<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** "Search everything": one row per record in sys_search_index, refreshed on every save. Secret fields never go in. */
class SearchIndex
{
    public static function put(string $formKey, string $table, int $id, ?int $ent = null): void
    {
        $row = (array) DB::table($table)->where(Registry::pk($table), $id)->first();
        if (! $row) return;
        $title = null; $parts = []; $amount = null; $phone = null; $email = null;
        foreach (Registry::fields($formKey) as $f) {
            $c = $f['column_name'];
            if (! $c || $f['secret'] || ! array_key_exists($c, $row) || $row[$c] === null || $row[$c] === '') continue;
            $v = (string) $row[$c];
            if (in_array($f['type'], ['text', 'textarea', 'email', 'phone'], true)) {
                $title ??= $v;
                $parts[] = $v;
                if ($f['type'] === 'phone') $phone ??= preg_replace('/\D+/', '', $v);
                if ($f['type'] === 'email') $email ??= mb_strtolower($v);
            } elseif ($f['type'] === 'money') {
                $amount ??= (float) $v;
            }
        }
        $lc = Registry::labelColumn($table);
        if ($lc && ! empty($row[$lc])) $title = (string) $row[$lc];
        $code = null;
        foreach ($row as $k => $v) if ($v && (str_ends_with($k, '_code') || str_ends_with($k, '_no') || $k === 'sku') && is_string($v)) { $code = $v; break; }
        DB::table('sys_search_index')->updateOrInsert(['entity' => $table, 'entity_id' => $id], [
            'fk_id_ent' => $ent ?? ($row['fk_id_ent'] ?? Context::ent() ?? 0), 'department' => $formKey,
            'title' => mb_substr(trim(($code && $code !== $title ? $code . ' · ' : '') . ($title ?? "#$id")), 0, 255),
            'subtitle' => mb_substr(Registry::fields($formKey)[0]['form_title'], 0, 255),
            'search_text' => mb_substr(implode(' ', array_unique(array_merge([$code], $parts))), 0, 4000),
            'url' => "#/rec/$formKey/$id", 'updated_at' => now(), 'amount_value' => $amount, 'phone_norm' => $phone ? mb_substr($phone, 0, 30) : null, 'email_norm' => $email ? mb_substr($email, 0, 150) : null,
        ]);
    }

    public static function find(string $q, ?int $ent, int $limit = 20): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $digits = preg_replace('/\D+/', '', $q);
        return DB::table('sys_search_index')
            ->when($ent, fn ($x) => $x->where(fn ($w) => $w->where('fk_id_ent', $ent)->orWhere('fk_id_ent', 0)))
            ->where(function ($w) use ($q, $digits) {
                $w->where('title', 'like', "%$q%")->orWhere('search_text', 'like', "%$q%");
                if (strlen($digits) >= 5) $w->orWhere('phone_norm', 'like', "%$digits%");
            })
            ->orderByRaw('CASE WHEN title LIKE ? THEN 0 ELSE 1 END', ["$q%"])->orderByDesc('updated_at')->limit($limit)
            ->get(['title', 'subtitle', 'url', 'department'])->map(fn ($r) => (array) $r)->all();
    }
}
